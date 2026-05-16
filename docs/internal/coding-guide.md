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

Since v3.30.0 the setting registry lives in the `setting_definitions` DB
table, seeded once at install time from the frozen v3.29.0 PHP registry
(`ipam_setting_definitions_seed()`).

- **A new setting added after v3.30.0 is registered by its OWN migration.**
  The migration `INSERT`s a row into `setting_definitions` with the setting's
  11-value logical `type` stated **explicitly** (e.g. `'secret'`, `'enum'`,
  `'url'`). Do **not** add the setting to `ipam_setting_definitions_seed()` —
  that array is a frozen v3.29.0 snapshot and is intentionally never extended.
- **The `3.30.0-setting-definitions` migration's `$logicalType` classifier
  closure is frozen history.** It infers logical types for the v3.29.0
  snapshot only; it is NOT updated when a new setting is added. New settings
  carry their logical type in their own migration's INSERT, not via the
  classifier.
- **`ipam_setting_definitions()` returns `storage_type` (4-value) and
  `logical_type` (11-value) — never a bare `type` key.** `type` is the DB
  column name and means the *logical* type; the in-memory storage-type key is
  `storage_type` to avoid the same-name/opposite-meaning collision.
- **Invariant for the v3.31.0 encrypt-at-rest pipeline:** `is_sensitive = 1`
  IFF `type = 'secret'` in `setting_definitions`. `SettingDefinitionsMigrationTest`
  asserts it across the seed; keep new INSERTs consistent.

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
9. **Local three-driver gate before push.** `bash testing/bootstrap-app.sh sqlite|mysql|pgsql` then Playwright must all pass. GH Action minutes are paid; do not use CI as your test runner.
10. **No `git push` or PR merge without explicit per-conversation authorisation.** A previous yes does not carry forward.

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
