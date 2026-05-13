# Config reference

> **Audience:** operator or developer looking up "what is this knob and where does it live?" Two sources of operator-tunable values exist: `Simple-PHP-IPAM/config.php` (server-level bootstrap, file-only) and the `settings` DB table (admin-tunable at runtime via the Settings page). This doc is the index. Procedure for adding a new setting is in `adding-a-setting.md`.

---

## Two surfaces, two purposes

| Surface | Purpose | Mutable how | Survives DB reset? |
|---|---|---|---|
| `config.php` | Bootstrap values that must exist before the DB is reachable; server-level secrets | Edit the file on the server | Yes |
| `settings` table | Everything an admin should change without shell access | Settings page (or `ipam_setting_set()` programmatically) | No (DB restore replaces values) |

**Rule of thumb:** if it's a credential that protects DB-stored data, it goes in `config.php` and never moves. If admins might change it more than once a year, it's a setting. If neither, hard-code it.

---

## `config.php` — file-only keys

`config.php` is loaded by both `init.php` (browser paths) and `api.php` / `status.php` (stateless paths) and exposed as `$config` / `$GLOBALS['config']`. Default values are minimal; an install commonly overrides `db_driver`, `db_path`, and `bootstrap_admin`.

| Key | Type | Default | Purpose |
|---|---|---|---|
| `db_driver` | string | `'sqlite'` | DB engine: `sqlite`, `mysql`, or `pgsql`. Selects the corresponding `Dialect` subclass and connection logic in `ipam_db()`. |
| `db_path` | string | `__DIR__ . '/data/ipam.sqlite'` | SQLite file path. Required when `db_driver=sqlite`. Ignored otherwise. |
| `db_host` | string | — | MySQL/PostgreSQL host. Required when not SQLite. |
| `db_port` | int | engine-default | MySQL 3306 / PostgreSQL 5432. |
| `db_name` | string | — | MySQL/PostgreSQL database name. |
| `db_user`, `db_pass` | string | — | MySQL/PostgreSQL credentials. |
| `session_name` | string | `'IPAMSESSID'` | Cookie name. `init.php` namespaces it per install (`IPAMSESSID_<8-char-hash-of-path>`) to avoid collisions when multiple instances run on the same domain. |
| `force_https` | bool | `true` | If true, `init.php` 301-redirects HTTP → HTTPS. Disable only behind a TLS-terminating reverse proxy that doesn't pass `X-Forwarded-Proto`. |
| `bootstrap_admin` | array | `{username: 'admin', password: 'ChangeMeNow!12345'}` | Used **only** when the `users` table is empty. The user is created on first request; rotate the password immediately. |
| `demo_mode` | array | disabled | Demo instance gate. `enabled` toggles the daily-reset behaviour; `gate` is an opaque session-marker; `site_key` / `secret_key` are reCAPTCHA v3 keys gating the public demo. Production installs leave this off. |
| `app_secret` | string | required for v2.9.0+ encryption | Root encryption key (32+ bytes, hex). Encrypts TOTP secrets, backup-destination credentials, IPAMBKP2 backups, and HKDF-derives per-tenant keys in v4.0.0+. **Never in the DB — invariant #12.** If missing, encryption-dependent features refuse to operate. |

`config.php` is not shipped with non-default values; deployments edit it post-extraction. The `upgrade.sh` flow preserves it across upgrades by excluding it from the `rsync --delete`.

---

## Settings registry — admin-tunable

The settings registry is defined in `lib.php` → `ipam_setting_definitions()` and grouped by `ipam_setting_groups()`. Reading goes through `ipam_setting('key.name')`; writing goes through `ipam_setting_set('key.name', $value)`. The settings UI at `settings.php` reads the registry directly — there is no per-setting hand-wiring.

### Resolution order (cascade)

Each `ipam_setting('foo')` walks four layers, top-down, returning the first hit:

1. **Tenant-scoped row** — `WHERE tenant_id = :t AND key = :k` (only when `$tenantId` is passed; v3.x callers always pass `null`).
2. **Global row** — `WHERE tenant_id IS NULL AND key = :k`.
3. **`$GLOBALS['config']` back-compat** — if the registry entry has a `config_key` and the key is set in `config.php`. Lets installs that haven't migrated their `config.php` values still get the right answer.
4. **Registry default** — `ipam_setting_definitions()['key']['default']`.

Per-request memoisation wraps the cascade. The same lookup will gain tenant-layer support automatically when v4.0.0 multi-tenancy ships; no caller changes needed.

### Setting groups

The current groups (rendered as sections on the Settings page):

| Group | Purpose |
|---|---|
| `branding` | Display name, timezone, theme defaults |
| `security` | Session lifetime, login lockout policy |
| `step_up` | Step-up auth policy (full reference in `auth-model.md`) |
| `mfa` | Available 2FA methods + enforcement |
| `password_policy` | Local password complexity + rotation |
| `alert` | Subnet utilization email alerts |
| `update_check` | GitHub release checker for the upgrade banner |
| `login_protection` | Bot/abuse mitigation on the login form |
| `recaptcha_enterprise` | Google reCAPTCHA Enterprise backend verification |
| `oidc` | OpenID Connect SSO |
| `housekeeping` | Temp cleanup, log pruning, alert-check intervals |
| `backup` | Database backup schedule and retention |
| `limits` | Upload size caps (CSV, SQL imports) |
| `api` | API rate limiting + bulk-write caps |
| `display` | Utilization thresholds, auto-reserve, UI toggles |
| `smtp` | Direct SMTP delivery (falls back to `mail()` when disabled) |
| `webhooks` | Outbound HMAC-signed HTTP callbacks |

### Registry entry shape

```php
'security.example_threshold' => [
    'label'       => 'Example threshold',
    'description' => 'One-sentence explanation that will appear under the field.',
    'type'        => 'int',     // string|int|bool|float|json|password
    'group'       => 'security',
    'default'     => 60,
    'sensitive'   => false,     // true → masked in audit + password input in UI
    'config_key'  => 'example_threshold',  // legacy back-compat (omit if new)
    'min'         => 1,         // type-specific validation
],
```

The per-setting reference (what every key does, valid range, default) is intentionally **not** in this doc — the registry definition is the source of truth and includes a `description` for every key, which the Settings page renders. Duplicating it here would drift. To enumerate keys, read `ipam_setting_definitions()` in `lib.php` or open the Settings page.

### Sensitive values

A registry entry with `sensitive: true`:

- Renders as a password input in the Settings UI (with a "Reveal" button gated by step-up auth).
- Is masked as `***` in the `audit_log` `details` field.
- Is hidden from `?reveal=0` paths in the settings API.

Reveal goes through `ipam_sudo_verify()` — see `auth-model.md` → Step-up. Add a sudo gate when introducing any new sensitive setting; the gate is per-action, not per-setting.

---

## Override patterns

### Environment variable

The codebase does not read env vars directly at runtime. The pattern operators use is:

```php
// In config.php
return [
    'db_driver' => getenv('IPAM_DB_DRIVER') ?: 'sqlite',
    'db_host'   => getenv('IPAM_DB_HOST') ?: '',
    // …
];
```

This is operator-managed, not enforced by the codebase. The Docker testing harness (`testing/playwright/bootstrap-app.sh`) uses this pattern.

### Per-install via `config.php` edit

Most production deployments edit `config.php` directly to set `db_driver`, `db_path` / `db_host`, `app_secret`, and `force_https`. Then admin-tunable values are set via the Settings UI post-bootstrap.

### Per-test override

Tests construct `$config` manually, bypassing `config.php`. See `tests/MigrationTest.php` for the in-memory SQLite pattern.

---

## Setting vs config decision table

| Value | Surface | Why |
|---|---|---|
| `app_secret` | `config.php` | Root encryption key; must exist before DB. Storing it in DB defeats the model (invariant #12). |
| DB driver / connection params | `config.php` | Must exist before DB is reachable. |
| `force_https` | `config.php` | Applied during early bootstrap before any setting lookup. |
| `bootstrap_admin` | `config.php` | Used only when users table empty; eliminated after first admin exists. |
| Session lifetime | Setting (`security.session_idle_seconds`) | Admins tune this; not a secret. |
| SMTP host/port/credentials | Setting (`smtp.*`) | Admins change MTA without a redeploy. |
| OIDC issuer + client | Setting (`oidc.*`) | Same — runtime-changeable. |
| OIDC client secret | Setting with `sensitive: true` | Sensitive but DB-storable; protected via the Settings reveal step-up gate. |
| Backup destination credentials | Encrypted-with-`app_secret` row in `backup_destinations` | Per-destination secrets; the encryption key stays in `config.php` per invariant #12. |
| Vault key | `config.php` (or operator-typed) | Same invariant — DB-encryption keys never live in the DB. |

---

## Cross-references

- `adding-a-setting.md` — procedure for adding a new admin-tunable setting.
- `auth-model.md` → Step-up — sensitive-value reveal mechanics.
- `security-model.md` → Cryptography — what each key protects.
- `lib.php` → `ipam_setting_definitions()` — per-setting source of truth (the registry).
- `docs/configuration.md` — operator-facing reference.

---

## Update protocol

- New key added to `config.php` (rare) → update the "config.php" table here in the same PR.
- New setting group → add to "Setting groups" table.
- Resolution order changed → update "Resolution order (cascade)" together with the implementation.
- Per-setting reference stays in the registry; do not duplicate it here.
