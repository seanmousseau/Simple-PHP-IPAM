# Coding guide

> **Audience:** developer or agent writing PHP, JS, or CSS in this repo. Project-specific conventions only — language-level basics (PSR-12, semantic versioning) aren't restated. The non-negotiable rules at the bottom are gates: PRs that violate them get bounced.

---

## Project shape

Procedural PHP, no framework. All shared functions live in `Simple-PHP-IPAM/lib.php`. Page handlers `require __DIR__ . '/init.php'` at the top (browser pages) or `config.php` + `lib.php` directly (`api.php`, `status.php`). Subsystem code that grew too large for `lib.php` lives in `Simple-PHP-IPAM/lib/<subsystem>.php` and is `require`d on demand by handlers that need it (e.g. `lib/auth_step_up.php`, `lib/backup.php`).

No namespaces. No DI container. No autoloader for project code (Composer's autoloader is loaded for vendored packages only).

---

## When to add a function vs a class

**Default to functions in `lib.php`.** Classes are reserved for cases where the abstraction provides per-implementation polymorphism that procedural dispatch would muddy. The current production examples:

- `Dialect` / `SqliteDialect` / `MysqlDialect` / `PgsqlDialect` — per-engine SQL differences.
- Vendored library classes (`PHPMailer`, `lbuchs\WebAuthn`) — third-party.

If you're tempted to add a class for a "service" or "manager" or "repository", write functions first. The codebase's procedural shape is intentional and a churn-reducing constraint, not an oversight. Full reasoning in `runtime-dependency-policy.md` → "When to use classes vs functions".

---

## Naming

| Surface | Convention | Example |
|---|---|---|
| Functions in `lib.php` | `snake_case`, prefix `ipam_` for subsystem-scoped helpers, no prefix for utility helpers | `ipam_db()`, `ipam_bind_binary()`, `parse_cidr()`, `e()` |
| Files in `Simple-PHP-IPAM/` | `snake_case.php`, page name matches URL slug | `change_password.php`, `scan_run.php` |
| Files in `Simple-PHP-IPAM/lib/` | `snake_case.php`, named for subsystem | `lib/auth_step_up.php`, `lib/backup.php` |
| Files in `Simple-PHP-IPAM/views/` | `_partial_name.php` for shared partials; `view_pagename_section.php` for page-scoped extracts | `views/_step_up_prompt.php` |
| Tables | `snake_case`, plural | `subnets`, `address_history` |
| Columns | `snake_case`, suffix `_bin` for binary, `_at` for timestamps, `_id` for FKs | `network_bin`, `last_login_at`, `vrf_id` |
| Audit actions | `<entity>.<verb>` lowercase | `subnet.create`, `auth.sudo_passed` |
| Config keys | `snake_case`, dotted groups | `smtp.enabled`, `auth.step_up.ttl_seconds` |
| Setting registry keys | matches config key; `_secret` suffix marks sensitive values | `recaptcha_secret`, `oidc_client_secret` |

---

## Output, sanitisation, and the `e()` rule

Every HTML output goes through `e()`:

```php
<td><?= e($row['hostname']) ?></td>
```

`e()` is registered as the Semgrep XSS sanitiser in `.semgrep/rules.yml` — bypassing it triggers `ipam-xss-unsanitized-echo`. There is no second sanitiser; do not introduce one.

JSON output uses `json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)` and emits `Content-Type: application/json` before the body. The API helpers in `lib.php` (`api_json_response()`, `api_error()`) handle this; route new endpoints through them.

URL parameters in redirects pass through `validate_return_path()` (relative paths only — see `auth-model.md` Bug Z arc). Never `header("Location: " . $_GET['next'])` raw.

---

## Validation patterns

| Input class | Validator | Rejects |
|---|---|---|
| IP address (single) | `normalize_ip()` | Anything `inet_pton()` rejects |
| CIDR | `parse_cidr()` | Malformed CIDR; returns null |
| Status enum | `normalize_status()` | Anything not `used` / `reserved` / `free` |
| Tag name | length 1–50, regex `^[A-Za-z0-9 _\-\.]+$` | Reserved chars |
| Hex colour | regex `^#[0-9a-fA-F]{6}$` | Short form, named colours |
| Username | regex `^[A-Za-z0-9_.\-]+$`, length 3–64 | Spaces, @, special chars |
| MAC | none — free-form, see invariant 20 | — |
| Return path (redirect) | `validate_return_path()` | Absolute URLs, `//`, `\\` |

Validate at the entry point (page handler or API handler). Internal helpers trust their inputs.

---

## Database access

Always use prepared statements with named placeholders. Never concatenate user input into SQL.

```php
$stmt = $db->prepare("SELECT * FROM subnets WHERE id=:id");
$stmt->execute([':id' => $id]);
```

Binary blobs (`ip_bin`, `network_bin`, any binary column on any engine) **must** be bound via `ipam_bind_binary()`:

```php
$stmt = $db->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin) VALUES (:sid, :ip, :ip_bin)");
$stmt->bindValue(':sid', $sid, PDO::PARAM_INT);
$stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
ipam_bind_binary($stmt, ':ip_bin', $ip_bin);
$stmt->execute();
```

This is design-document invariant #2 — it's a `PARAM_LOB` wrapper that produces correct affinity on SQLite, correct VARBINARY handling on MySQL, and correct BYTEA on Postgres.

For multi-engine SQL differences (`NOW()` vs `datetime('now')`, `RETURNING` vs `lastInsertId()`, conflict resolution syntax), go through `ipam_dialect()`:

```php
$nowExpr = ipam_dialect()->now();
$db->prepare("UPDATE users SET last_login_at = $nowExpr WHERE id=:id")
   ->execute([':id' => $uid]);
```

`fetch()` returns `false` on no rows — **never** `null`. Check with `if ($row)` not `if ($row !== null)`.

---

## Auth helpers

Every protected page calls `require_login()` at the top. Admin-only pages call `require_role('admin')` *instead* — it invokes `require_login()` as its first statement (see `lib.php`), so calling both back-to-back is redundant. Write handlers add `require_write_access()` so readonly accounts hit the right 403.

```php
require __DIR__ . '/init.php';
require_role('admin'); // calls require_login() internally — do not call it again

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    require_write_access();
    // … handler body
}
```

CSRF: every browser POST handler calls `csrf_require()` first. Every form includes `<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">`. `api.php` is the only exempt surface (stateless Bearer auth).

Sudo-class actions (vault-key reveal, DB import, API key creation, MFA disable, sensitive setting reveal) add a step-up check between the role check and the side effect. See `auth-model.md` → Step-up section for the calling pattern and the "how to add a sensitive action" recipe.

Self-protection guards on `users.php` actions (`toggle_active`, `set_role`, `unlink_oidc`, `delete`) — see `security-model.md`. Apply the same pattern if you add a new admin-on-admin action.

---

## Audit logging

Every state-changing action emits one row to `audit_log`:

```php
audit($db, 'subnet.create', 'subnet', $newId, json_encode(['cidr' => $cidr]));
```

Action verbs follow `<entity>.<verb>` and reuse the existing vocabulary so log queries (`WHERE action LIKE 'subnet.%'`) stay consistent. **Full vocabulary lives in `audit-actions.md`.** Adding a new verb requires a row in that table in the same PR.

`audit_log` is append-only — design-document invariant #5. Triggers abort UPDATE/DELETE. Do not write code that tries to rewrite history.

---

## Comments policy

Default to none. Code-as-documentation: names carry meaning; if a function is hard to follow, simplify it.

Write a comment only when:

- The WHY is non-obvious. A future reader can derive WHAT from the code; can they derive WHY?
- A workaround exists for a specific upstream bug. Reference the upstream issue and the version the workaround can be removed at.
- An invariant is being enforced at a non-obvious line. Reference the design-document invariant number.

Never:

- Restate what the next line does.
- Add `// TODO`. Use the cleanup backlog in `cleanup.md` instead, or open an issue.
- Reference issue numbers as the only context — they rot. Reference commit SHAs and stable identifiers (RFC numbers, design-document invariants, version constants).
- Write multi-paragraph comment blocks. If the WHY needs more than three lines, write a section in the appropriate doc and link to it.

### Docblocks are not inline comments

The "default to none" rule above is about *inline* comments. It does **not**
apply to docblocks. A docblock on a function is its **contract** — and the
`lib/*.php` decomposition created a large new cross-module surface that needs
one.

Write or update a docblock whenever you add or change a function that is:

- exported from a `lib/*.php` module (called across module / file boundaries), or
- a page-handler controller function (e.g. `*_handle_post()`), or
- non-trivial in its inputs, return shape, side effects, or preconditions.

State the *contract*, not the implementation: `@param` / `@return` with precise
types (array shapes where they matter), side effects, and preconditions the
caller must satisfy — e.g. "caller must have run `csrf_require()`", "`$cutoff`
must be a UTC timestamp", "must run inside a transaction". A trivial private
helper with a self-evident signature does not need one.

This is enforced **opportunistically, on changed code, at PR time** (see
"PR-time gates"): a new or modified function on the public / cross-module
surface that lacks a current, accurate docblock is a review finding. It does
**not** mandate a back-fill sweep of untouched code — touch a function, leave
it better; that is how the surface gets covered without a doc-rot-prone mass
edit.

---

## Frontend

Vanilla CSS in `assets/app.css`. Vanilla JS in `assets/app.js`. No bundlers, no transpilers, no source maps, no `node_modules`. Carve-out: vendored frontend assets under `assets/vendor/` (currently `qrcode.min.js`); see `runtime-dependency-policy.md` for the policy.

CSS uses custom properties for theming (`--bg`, `--fg`, `--card`, etc.) controlled by `html[data-theme]`. Don't hardcode colours; reach for the variable.

UI conventions (nav, icons, sidebar, command palette, button hierarchy, card layout) live in `design-guide.md`.

---

## Static analysis tools (must pass before push)

| Tool | Config | Command |
|---|---|---|
| PHP lint | — | `php -l <file>` |
| PHPStan | `phpstan.neon` (level 9) | `vendor/bin/phpstan analyse` |
| PHP_CodeSniffer | `.phpcs.xml` (PSR-12 with project exclusions) | `vendor/bin/phpcs` |
| PHPUnit | `phpunit.xml` | `vendor/bin/phpunit` |
| Semgrep | `.semgrep/rules.yml` | `semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/` |

PHPStan baseline (`phpstan-baseline.neon`) suppresses known false-positives. Do not append to the baseline to silence real bugs.

**Baseline hygiene when code moves between files.** PHPStan's `--report-on-unmatched-ignored-errors` flag (default in this project's `phpstan.neon`) fails CI when a baseline entry no longer matches anything in the source — i.e. an "ignore" for a code path that has been deleted, refactored, or moved into a differently-typed parameter. After any change that extracts code into a new file or removes a path, grep `phpstan-baseline.neon` for the source file you touched and prune entries that no longer apply, BEFORE pushing. v3.28.1 #1177 hit this: `restore.php`'s 5 `Offset 'db_host' on IpamConfig` ignores became unmatched once those reads moved into `lib/restore_dsn.php` (parameter typed `array<string,mixed>`), and the local gate was clean but CI failed on the unmatched-ignore identifier `(ignore.unmatched (non-ignorable))`. Re-running `vendor/bin/phpstan analyse` locally after a refactor will surface the same error if any baseline entry is now dead.

PHPCS exclusions are deliberate — K&R braces, inline control structures, column-aligned `=>` arrays. Don't fight them; they match the codebase style. Run the `phpcs-style-fixer` subagent (in `.claude/agents/`) on diffs before commit.

Full testing procedure (containerized + dev-direct fallback + recurring footguns) lives in `testing-guide.md`.

---

## Settings registry (ADR-001)

The setting type system is a **PHP registry — there is no DB table for it**
(ADR-001 was amended to Option D; the originally-designed `setting_definitions`
table was withdrawn). The single source of truth is
`ipam_setting_definitions_registry()` in `lib/settings.php` — a literal array,
one entry per setting, and the place a new setting is added.

- **A new setting is added by appending an entry to
  `ipam_setting_definitions_registry()`.** No migration registers a setting;
  the registry is plain PHP. See `adding-a-setting.md` for the recipe.
- **Each registry entry carries a raw `type` key holding the 4-value STORAGE
  type** (`string|int|bool|json`) plus optional `options`/`min`/`max`/
  `multiline` and the two boolean flags below.
- **Registry-entry visibility flags are plain PHP keys, not DB columns.**
  `sensitive => true` masks the value in the UI and in audit details;
  `deprecated => true` keeps a registry-only setting out of the admin UI (this
  is the actual hide-from-UI gate). A literal `hidden` key exists on a few
  legacy entries but nothing reads it — do not rely on it. Neither flag is
  stored in the database.
- **`ipam_setting_definitions()` enriches each entry with `storage_type`
  (4-value) and `logical_type` (11-value) and strips the bare `type` key.**
  The 11 logical types are `string, int, bool, json, enum, secret, url, email,
  timezone, cidr, datetime`, derived by `ipam_setting_definitions_logical_type()`.
  A contract test asserts the absence of a bare `type` key on the returned
  arrays.
- **`ipam_setting_storage_type()` maps a logical type to its 4-value storage
  type; `ipam_setting_validate()` dispatches per-logical-type validation**,
  returning `true` or a human-readable error string.
- **The `settings.type` DB column was dropped in v3.30.0** (migration
  `3.30.0-drop-settings-type`). The storage type is computed from the registry,
  never read from the row.
- **`sensitive` settings are encrypted at rest (v3.31.0+, ADR-001 part 2).**
  A `sensitive => true` registry entry has its `settings.value` stored as an
  `IPAMSEC1` envelope (`lib/secret.php`); `ipam_setting()` / `ipam_setting_set()`
  decrypt and encrypt it transparently. Prefer the explicit aliases
  `ipam_secret_get()` / `ipam_secret_set()` at call sites that handle secrets —
  same behaviour, clearer intent. **Never log a decrypted secret value**, and
  let `IpamSecretDecryptException` propagate (fail loud) rather than catching it
  to fall back to a default. Crypto detail: `security-model.md` →
  "Encrypt-at-rest: settings secrets".

---

## Settings vs preferences

Two distinct stores — pick by *who owns the value*.

| | `settings` table | `user_preferences` table |
|---|---|---|
| Scope | tenant / global | per-user |
| Managed by | admins, on `settings.php` | the user, anywhere in the UI |
| Type system | PHP registry (above) | key allowlist (ADR-002) |
| Save semantics | explicit form submit | instant-save on change |
| Auth surface | admin-gated page | `user_preference.php` (session + CSRF) |

Use `settings` for anything an administrator configures for the whole
deployment (branding, SMTP, OIDC, security policy). Use `user_preferences` for
per-user, non-privileged choices that should persist across sessions and take
effect immediately (currently theme). A `user_preferences` write is **not**
admin-gated; it is constrained by a server-side **key allowlist** so a user can
only set keys the app recognises (ADR-002). Per-user theme moved out of the old
`users.theme` column into this table in v3.30.0.

---

## Reading config in extracted modules

Extracted `lib/*.php` modules **must not** use `global $config;`. They read
config through the accessors in `lib/config.php`:

```php
$timeout = ipam_config('scan_timeout', 5);          // flat key, with default
$issuer  = ipam_config_nested(['oidc', 'issuer']);  // nested path
```

`global $db` is still permitted — it is the runtime PDO handle, not config.
This is ADR-003. The full sweep of remaining `global $config` sites elsewhere
in the codebase is tracked as #1207 for a later release; new code in `lib/`
goes straight to the accessors.

---

## Module-membership cheat sheet

When adding a function, place it in the `lib/*.php` module that owns its
concern. The v3.30.0 ADR-004 wave-1 extraction split `lib.php` into these
modules:

| Module | Owns |
|---|---|
| `lib/utils.php` | generic string/array/format helpers |
| `lib/ip.php` | IP parsing, subnet math, binary IP conversion |
| `lib/config.php` | `ipam_config()` / `ipam_config_nested()` accessors |
| `lib/db.php` | PDO bootstrap, `ipam_dialect()`, `ipam_bind_binary()` |
| `lib/audit.php` | `audit()` and audit-log helpers |
| `lib/presentation.php` | HTML/render helpers, `e()`-adjacent output |
| `lib/settings.php` | settings registry + type dispatch |
| `lib/user_preferences.php` | per-user preference read/write + allowlist |
| `lib/auth.php` | login/session/role checks |
| `lib/auth_password.php` | password hashing + reset tokens |
| `lib/auth_rate_limit.php` | login / IP rate limiting + lockout |
| `lib/auth_recaptcha.php` | reCAPTCHA + login protection |

Pre-existing modules (NOT part of the v3.30.0 extraction) still own their
areas: `lib/backup*.php`, `lib/restore*.php`, `lib/vault.php`,
`lib/app_secret.php`, `lib/auth_step_up.php`, `lib/S3Client.php`,
`lib/SftpClient.php`, `lib/LocalBackupClient.php`,
`lib/BackupClientInterface.php`. Anything not yet extracted still lives in
`lib.php`. The module linter (`testing/scripts/lib-module-linter.php`) enforces
the module header, the cross-module-`require` ban, and function uniqueness.

---

## PR-time gates (non-negotiable)

A PR that violates any of these gets bounced.

1. **Schema change → update `data-dictionary.md`.** Run `php tools/generate-data-dictionary.php`. `DataDictionaryDriftTest` fails CI on stale doc.
2. **Schema change → update all three `schema.*.sql` in the same PR.** `SchemaParityTest` fails CI on drift. Run the `multi-engine-schema-parity` subagent on the diff.
3. **Config key added or removed → update `config-reference.md`.** Match the registry definition in `lib.php` if the key is admin-tunable (see `adding-a-setting.md`).
4. **API endpoint added or changed → update `api-contract.md` and `docs/api-spec.yaml`.** Procedure in `adding-an-api-endpoint.md`.
5. **New `audit()` action verb → update `audit-actions.md`.**
6. **New runtime dependency → six-criteria justification PR per `runtime-dependency-policy.md`; update the whitelist in this doc.** Vendored frontend asset additions also update the whitelist.
7. **Migration added → run `migration-reviewer` subagent.** Confirm the FK-safe pattern from `adding-a-migration.md`. CI runs `MigrationTest` to assert no data loss.
8. **IP/binary code touched → run `ip-binary-auditor` subagent.** Covers `ip_bin`, `network_bin`, IP parsing, subnet math, ping/scan, DB binds for binary columns.
9. **Local three-driver gate before push.** `bash testing/playwright/bootstrap-app.sh sqlite|mysql|pgsql` then Playwright must all pass. GH Action minutes are paid; do not use CI as your test runner.
10. **Public / cross-module function added or changed → it carries a current docblock.** See "Docblocks are not inline comments". A missing or stale contract docblock on changed surface code is a review finding.
11. **No `git push` or PR merge without explicit per-conversation authorisation.** A previous yes does not carry forward.

---

## Runtime dependency whitelist

Adding a runtime dependency requires a justification PR meeting six acceptance criteria (`runtime-dependency-policy.md`). The current whitelist is the single source of truth:

| Package | Version | Purpose | Justified in |
|---|---|---|---|
| `phpmailer/phpmailer` | `^6.9` | Direct SMTP delivery (replaces native `mail()` when `smtp.enabled=true`) | #415, v3.1.0 |
| `robthree/twofactorauth` | `^2.1` | TOTP (RFC 6238) generation + verification | #418, v3.6.0 |
| `lbuchs/webauthn` | `^2.1` | WebAuthn attestation + assertion verification | #687, v3.15.0 |
| `phpseclib/phpseclib` | `^3.0` | SFTP transport for backup destinations | #693, v3.17.0 |

Vendored frontend assets (under `Simple-PHP-IPAM/assets/vendor/`, ≤50KB, vanilla JS/CSS only):

| File | Size | Source | Purpose | Justified in |
|---|---|---|---|---|
| `qrcode.min.js` | ~20KB | cdnjs (qrcodejs 1.0.0, MIT) | QR rendering for TOTP enrollment | #418, v3.6.0 |

---

## Cross-references

- `design-document.md` — invariants, architecture, trust boundaries.
- `testing-guide.md` — test layers and how to run them.
- `security-model.md` — threat model.
- `auth-model.md` — auth implementation reference (incl. step-up).
- `api-contract.md` — API versioning and response shape.
- `config-reference.md` — every config key and resolution order.
- `runtime-dependency-policy.md` — full policy text behind the whitelist.
- `adding-a-migration.md`, `adding-a-page.md`, `adding-a-setting.md`, `adding-an-api-endpoint.md`, `adding-a-runtime-dependency.md` — procedural recipes.
- `cleanup.md` — pre-ticket backlog for low-risk code-health items.

---

## Update protocol

- New PR-time gate or convention discovered → add it under "PR-time gates" with the rationale.
- Runtime whitelist changes → update the table here in the same PR as the Composer change.
- Conventions that turn out to have exceptions → either document the exception explicitly here, or rationalise the convention. Don't leave it implicit.
