# Adding a setting

> Procedure for introducing a new admin-tunable setting. Settings live in the `settings` DB table behind `ipam_setting()` / `ipam_setting_set()` and are surfaced through the registry in `lib.php` → `ipam_setting_definitions()`. Use this doc when adding any user-tunable value that should persist across upgrades.
>
> **Don't add a new top-level key to `config.php`** — `config.php` is reserved for server-level secrets (`app_secret`, DB credentials, OIDC client secret). Everything else goes through the settings registry.

---

## Decide first: setting, config, or hard-coded?

| Choice | When to use |
|---|---|
| **Setting** (registry + DB) | Admin should be able to change at runtime via the Settings UI. Examples: `security.session_idle_seconds`, `branding.site_name`, `smtp.host`. |
| **`config.php`** | Server-level secret or bootstrap value that must exist before the DB is reachable. Examples: `app_secret`, DB DSN, `session_name`. **No new entries unless absolutely necessary** — the registry's `config_key` back-compat handles legacy values. |
| **Hard-coded** | Internal magic number with no plausible reason to expose to admins. Examples: max migration batch size, internal cache TTL. |

If you're adding something that admins will change more than once a year, it's a setting. If it's a credential that protects DB data, it's `config.php` and can never move.

---

## The `ipam_setting()` resolution order

Reading a setting walks four layers, top-down, returning the first hit:

1. **Tenant-scoped row** — `WHERE tenant_id = :t AND key = :k` (only when `$tenantId` is passed; v3.x callers always pass `null`).
2. **Global row** — `WHERE tenant_id IS NULL AND key = :k`.
3. **`$GLOBALS['config']` back-compat** — only if the registry entry has a `config_key` and the key is set in `config.php`. Lets installs that haven't migrated their `config.php` values still get the right answer.
4. **Registry default** — `ipam_setting_definitions()['key']['default']`.
5. **Caller-supplied default** — `$default` argument to `ipam_setting()`. Hit only when there's no registry entry at all.

Per-request memoisation wraps all of this — repeated `ipam_setting('foo')` calls in the same request hit the in-memory cache, not the DB.

**The cascade is the v4.0.0 contract.** The same lookup will gain tenant-layer support automatically when multi-tenancy ships; no caller changes needed.

---

## Step-by-step

### 1. Pick a key

- **Format:** `<group>.<name>`, all lowercase, dot-separated. Example: `security.session_idle_seconds`, `smtp.host`, `branding.site_name`.
- **Group** must match an existing entry in `ipam_setting_groups()` (branding, security, mail, smtp, scanner, backup, etc.) or you must add a new group.
- **Avoid** generic words like `enabled`, `value`, `config` standing alone — they read poorly in the audit log (`setting.update enabled`).

### 2. Add the registry entry in `lib.php` → `ipam_setting_definitions()`

```php
'security.example_threshold' => [
    'label'       => 'Example threshold',
    'description' => 'One-sentence explanation that will appear under the field on settings.php.',
    'type'        => 'int',                          // string|int|bool|float|json|password
    'group'       => 'security',                     // must exist in ipam_setting_groups()
    'default'     => 60,
    'sensitive'   => false,                          // true → masked in audit log + settings UI
    'config_key'  => 'example_threshold',            // legacy config.php key (omit if new in v3.x+)
    'min'         => 1,                              // type-specific validation hint
],
```

Required keys: `label`, `description`, `type`, `group`, `default`.

Optional keys:
- `sensitive` — `true` for credentials/keys; the audit-log entry shows `***` instead of the raw value, and the settings UI uses a password input.
- `config_key` — string or array path; lets the resolver fall back to `$GLOBALS['config']` for installs that haven't migrated this key into the DB. **Only set this if a corresponding `config.php` key existed in a previous version.** New settings in v3.x+ should leave it off.
- `min` / `max` — numeric bounds (used by `ipam_setting_set()` validation and the settings form).
- `options` — fixed dropdown values, either a literal array or a `'@callable'` sigil for dynamic lists (see `'@timezone'` for the canonical example).

### 3. Surface it in `settings.php`

The settings page reads the registry directly — no hand-wiring per setting. Verify your new entry appears in the right group on the rendered page. If it doesn't, the most likely cause is a typo in `group` (must match `ipam_setting_groups()` exactly).

### 4. Use it from code

```php
$threshold = (int) ipam_setting('security.example_threshold');
```

The cast is defensive — `ipam_setting()` returns `mixed` because the registry covers every type. Use `to_int()` / `to_str()` for input you don't fully control.

### 5. Migration considerations

**You usually do not need a migration.** The registry default takes effect for any install where the row doesn't exist — reads cascade through to step 4 (registry default).

**You DO need a migration when:**
- The setting replaces a hard-coded value that was previously different per install (write a migration that backfills from the old source).
- The setting replaces a top-level `config.php` key for installs that have actively migrated to the DB layer (one-time `INSERT ... WHERE NOT EXISTS` with the value from `$config`).

When you do write a migration, follow `adding-a-migration.md` and use `ipam_setting_set($db, 'key', $value, null, null)` rather than raw SQL — that ensures the type-encoding and audit log entries are consistent with runtime writes.

### 6. Sensitive-value handling

For credentials/secrets:

- Set `'sensitive' => true` — masks the value in the audit log (`setting.update`) and the settings UI.
- The value is still stored **plaintext** in the `settings.value` column. There is no per-setting encryption layer in v3.x. If you need encryption-at-rest, the value belongs in `config.php` (encrypted by filesystem permissions / disk encryption), not in `settings`.
- Never log the raw value in `error_log()` or in custom audit entries.

### 7. Cache invalidation

`ipam_setting_set()` calls `ipam_setting_cache_bust($key)` automatically. If you write a setting through any other path (raw SQL in a migration, for instance), call `ipam_setting_cache_bust()` afterwards or the next read in the same request will return the stale cached value.

---

## Removing or renaming a setting

- **Renaming a key:** add a deprecation entry in `ipam_setting_deprecated_keys()` and write a migration that copies the old row to the new key. Keep the deprecation entry for at least one MAJOR release cycle so downgrades and reads of old data don't silently lose the value.
- **Removing entirely:** drop the registry entry, add to `ipam_setting_deprecated_keys()`, and write a migration that deletes the row. Reads of the removed key will fall through to the caller-supplied default.

The deprecated-keys list is what `ipam_config_stale_keys()` consults when warning admins about leftover `config.php` entries — keep it accurate or you'll either nag falsely or fail to nag truly stale entries.

---

## Recurring pitfalls

- **Dotted whitelist entries don't work.** `ipam_config_stale_keys()` walks `array_keys($config)` only (top level). Adding `'session.absolute_lifetime_minutes'` to the whitelist is a silent no-op; use the top-level `'session'` key. v3.15.1 hotfix root cause.
- **`config_key` on a never-existed setting is harmless but misleading.** Set it only when a corresponding `config.php` key actually existed in a previous version. Otherwise reviewers will assume there's a backwards-compat dance to worry about.
- **Forgetting `'group'`** drops the setting onto the page in an "Other" bucket (or hides it entirely depending on `settings.php` rendering). Always specify a real group.
- **Reading a setting before `init.php` has run** returns the registry default — the DB isn't open. `api.php` and `status.php` open the DB themselves; if you read a setting in code reachable from either, make sure `ipam_db()` has been called first.
- **Sensitive values logged via `error_log()`** appear in the Apache log even though the audit log masks them. Always mask in custom logging too.

---

## Cross-references

- `CLAUDE.md` → "Multi-tenancy model" → "Settings cascade" — the v4.0.0 tenant→global fallback contract.
- `CLAUDE.md` → "`app_secret` and per-tenant key derivation" — why some values must stay in `config.php` forever.
- `adding-a-migration.md` — when a setting needs a backfill migration.
- `lessons-learned.md` §5 — the dotted-whitelist no-op incident.
