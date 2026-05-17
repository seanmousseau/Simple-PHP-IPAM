# Design document

> **Audience:** developer or agent picking up a session in this codebase. This doc answers "why does the system look this way, and what would break if I changed it?" Procedural recipes (how to add a migration, how to cut a release) live in their own docs and are linked from `README.md`. The invariants table below is the load-bearing core.

---

## Architecture in one paragraph

Simple PHP IPAM is a single PHP web application served from `Simple-PHP-IPAM/` (a subdirectory of the repo root, not the repo root itself). Every browser-facing page boots through `init.php`, which loads `config.php` and `lib.php`, configures the session, opens a PDO handle via `ipam_db()`, runs `ipam_db_init()` (apply pending migrations, bootstrap-admin if no users), and emits HTML through `page_header()` / `page_footer()`. The stateless surfaces (`api.php`, `status.php`) load `config.php` + `lib.php` directly and skip the session layer entirely. All shared behaviour — DB helpers, auth helpers, output sanitiser, IP math, dialect abstraction — lives in `lib.php` as plain functions; the one deliberate class hierarchy is `Dialect` for per-engine SQL differences. There is no router, no templating engine, no framework, no build step. Frontend is vanilla CSS + JS in `assets/`; runtime PHP dependencies are a small curated set installed at release-bundle time via Composer and shipped inside the tarball.

---

## Load-bearing invariants

The table below lists the rules that protect specific code locations. **Every entry was bought with a real incident or a deliberate design decision.** Changing any of these without understanding the history will reintroduce a known bug.

| # | Invariant | Protects | Source |
|---|---|---|---|
| 1 | Binary IPs are stored at native length (4B IPv4, 16B IPv6). Never left-pad IPv4 to 16B. All three engines do byte-wise memcmp on native-length values; padding breaks sort order across engines. | `Simple-PHP-IPAM/lib.php` `parse_cidr` / `normalize_ip`, `schema.*.sql` `ip_bin` / `network_bin` columns | v2.8.0 planning (#410, #379); revisit those threads before changing |
| 2 | All binary binding goes through `ipam_bind_binary()` with `PDO::PARAM_LOB`. `PARAM_STR` produces TEXT affinity on SQLite (which sorts greater than every BLOB), null-truncates on MySQL VARBINARY, and corrupts BYTEA on PostgreSQL. | `Simple-PHP-IPAM/lib.php` `ipam_bind_binary`; every IP-binding query | v2.9.0 (#379) + v2.9.0 normalisation migration (#410) |
| 3 | `apply_migrations()` disables FK enforcement before each migration's `BEGIN`, restores in every exit path. `DROP TABLE` with `PRAGMA foreign_keys=ON` cascades child rows — SQLite executes a row-by-row DELETE before the drop. | `Simple-PHP-IPAM/lib.php` `apply_migrations` | v2.2.1 data-loss bug (every upgrade from v1.x wiped IPs) |
| 4 | `PRAGMA foreign_keys` cannot be changed inside a transaction. Setting it after `BEGIN EXCLUSIVE` silently no-ops. Set it before `BEGIN`. | `Simple-PHP-IPAM/lib.php` `apply_migrations` | Discovered during v2.2.1 fix |
| 5 | `audit_log` is append-only. SQLite triggers abort UPDATE and DELETE. | `Simple-PHP-IPAM/schema.sql` `audit_log` + triggers | Tamper-evidence requirement; assumed by every consumer of the audit feed |
| 6 | Three schema files (`schema.sql`, `schema.mysql.sql`, `schema.pgsql.sql`) stay structurally in lockstep from v2.9.0 onward. `SchemaParityTest` + `DataDictionaryDriftTest` fail the build on drift. After any schema edit, run `php tools/generate-data-dictionary.php`. | All three `schema.*.sql`, `tests/SchemaParityTest.php`, `tests/DataDictionaryDriftTest.php`, `docs/internal/data-dictionary.md` | v2.9.0 multi-engine policy |
| 7 | `addresses` has no `ip_version` column — that lives only on `subnets`. | `Simple-PHP-IPAM/schema.sql`, every address INSERT | Original schema decision; documented because new code keeps trying to add it |
| 8 | `addresses.grp` is a SQL reserved-word workaround. Stored as `grp` in DB; exposed as `group` in UI, API responses, and CSV headers. | `Simple-PHP-IPAM/lib.php`, `Simple-PHP-IPAM/api.php`, CSV import/export | Reserved-word collision; touching either name without the other breaks API contract |
| 9 | CSRF required on every browser POST via `csrf_require()` at handler top + hidden `csrf` field in form. `api.php` is exempt (stateless Bearer auth). | Every POST handler under `Simple-PHP-IPAM/` except `api.php` | Browser session protection |
| 10 | All HTML output goes through `e()`. The function is registered as the Semgrep XSS sanitizer in `.semgrep/rules.yml` — bypassing it triggers `ipam-xss-unsanitized-echo`. | Every `echo` / `<?=` in `Simple-PHP-IPAM/` | Project-wide XSS policy |
| 11 | Self-protection guards in `users.php` block `toggle_active`, `set_role`, `unlink_oidc`, `delete` on the logged-in user's own account. Last-active-admin guard counts active admins **excluding target** (`AND id != :id`) and only fires when target is active AND admin. | `Simple-PHP-IPAM/users.php` | Prevents accidental lockout of the only admin |
| 12 | `app_secret` lives in `config.php`, never in the DB. Per-tenant keys (v4.0.0 forward) are HKDF-derived at runtime from `app_secret`, not stored. Storing the key that protects DB data inside that same DB defeats the security model. | `Simple-PHP-IPAM/config.php`; `docs/internal/v4-tenancy-design.md` | Security model invariant; survived multiple "let's just put it in settings" suggestions |
| 13 | UNIQUE involving a nullable column does **not** block duplicate-NULL rows. `UNIQUE(cidr, vrf_id)` allows two NULL-`vrf_id` rows with the same CIDR by SQL standard. Test UNIQUE-on-nullable with non-NULL values; apply business logic for "no duplicates in the default VRF" at the app layer. | `Simple-PHP-IPAM/schema.sql` `subnets`; `tests/MigrationTest.php` | Discovered in v2.1.0 testing |
| 14 | Migrations execute in `ksort(SORT_NATURAL)` order, not array order. File order in `migrations.php` is for human readability only. | `Simple-PHP-IPAM/lib.php` `apply_migrations`; `Simple-PHP-IPAM/migrations.php` | Permits adding migrations anywhere in the file without ordering concern |
| 15 | `api.php` and `status.php` never load `init.php` — they have no session, no CSRF, and no `$config` injection. They `require 'config.php'` and `require 'lib.php'` directly. | `Simple-PHP-IPAM/api.php`, `Simple-PHP-IPAM/status.php` | Stateless surface contract |
| 16 | Asset cache-buster is automatic. `page_header()` + `demo_gate.php` derive `?v=` from `IPAM_VERSION` plus the asset's `filemtime()` for `app.css`/`app.js`. Never reintroduce hardcoded `?v=X.Y.Z` literals — they drifted release-to-release before this was made dynamic. | `Simple-PHP-IPAM/lib.php` `page_header`, `Simple-PHP-IPAM/demo_gate.php` | Eliminates a recurring "I forgot to bump the asset version" footgun |
| 17 | `page_footer()` is **not** `exit`. It emits closing HTML but returns. Pages must not output anything after calling it. | `Simple-PHP-IPAM/lib.php` `page_footer` | Allows tests to inspect output; trips up callers expecting `exit` semantics |
| 18 | Scanner `scan_run.php` is CLI-only: emits HTTP 403 + `exit(1)` when SAPI is not `cli`. | `Simple-PHP-IPAM/scan_run.php` | The scanner can shell out; preventing web exposure is non-negotiable |
| 19 | Raw `$_GET` / `$_POST` IPs must pass through `normalize_ip()` before reaching `proc_open()` or `fsockopen()`. Semgrep rule `ipam-proc-open-safe` enforces. | `Simple-PHP-IPAM/lib.php` scanner helpers, `.semgrep/rules.yml` | IP-injection class of bugs (shell-quote escape into ping/scan argv) |
| 20 | `addresses.mac` is free-form, `NOT NULL DEFAULT ''`. Never validate format server-side — users enter any notation (cisco, eui-48, vendor-specific). | `Simple-PHP-IPAM/schema.sql`; address-edit handlers | User-facing policy; format validators were tried twice and reverted both times |
| 21 | No `global $config;` in extracted `lib/*.php` modules — they read config via the `ipam_config()` / `ipam_config_nested()` accessors. `global $db` (the runtime PDO handle) is still permitted. The full sweep of remaining `global $config` sites elsewhere is tracked as #1207. | `Simple-PHP-IPAM/lib/*.php`, `Simple-PHP-IPAM/lib/config.php` | ADR-003 (v3.30.0 ADR-004 wave-1 extraction) |

Cross-release rollup of lessons that did not become invariants (because they're advice, not enforceable rules at a code location) lives in `lessons-learned.md`.

---

## Trust boundaries

| Boundary | What crosses it | Who validates |
|---|---|---|
| Browser → page handler | Form fields, query params, cookies | `csrf_require()` for POSTs; `require_login()` / `require_role()` for auth; `e()` on every echo back |
| External API client → `api.php` | Bearer token, JSON body | `ipam_api_authenticate()`; readonly key check on write endpoints |
| OIDC IdP → `oidc_callback.php` | ID token (JWT), claims | `oidc_verify_id_token()` against cached JWKS |
| Operator → scanner argv | IP, hostname, MAC | `normalize_ip()` before `proc_open` / `fsockopen` |
| Operator → backup destination | File contents, credentials | Vault-key encryption on IPAMBKP3 payload; destination credentials stored encrypted with `app_secret` |
| Migration code → schema | Table/column DDL | `apply_migrations()` transaction + FK-off bracket; `SchemaParityTest` for fresh-install schemas |

Threat model detail (with mermaid diagram + explicit non-threats) lives in `security-model.md`.

---

## Bootstrap sequence (browser path)

Every browser-facing page begins with `require __DIR__ . '/init.php'`. The bootstrap performs, in order:

1. Load `config.php` into `$config`.
2. Enforce HTTPS (301 redirect if not).
3. Configure session cookie (`Secure`, `HttpOnly`, `SameSite=Strict`, strict mode).
4. Start session.
5. `require __DIR__ . '/lib.php'`.
6. Open DB via `ipam_db()`; result available as `$db`.
7. Call `ipam_db_init()` — applies pending migrations, creates bootstrap admin if `users` is empty.
8. Run lazy housekeeping if due (temp file cleanup, stale `login_attempts` purge).
9. Initialise CSRF token.

`api.php` and `status.php` skip steps 2–4 and 7–9.

---

## Repo layout

```
Simple-PHP-IPAM/          web root (deployed)
  init.php                bootstrap entry (browser paths)
  lib.php                 all shared functions
  migrations.php          schema migrations (closures by version)
  schema.sql              fresh-install schema — SQLite (authoritative)
  schema.mysql.sql        fresh-install schema — MySQL 8.0+
  schema.pgsql.sql        fresh-install schema — PostgreSQL 14+
  version.php             IPAM_VERSION constant
  config.php              user-editable config
  api.php                 stateless REST API
  status.php              stateless health endpoint
  assets/                 vanilla CSS + JS + SVG sprite (no build step)
  views/                  partials included by page handlers
  lib/                    extracted shared-function modules + subsystem extensions
  data/                   runtime (gitignored): ipam.sqlite + tmp/

releases/                 release-bundle output (gitignored bundles)
docs/                     public-facing docs (operator + API consumer)
docs/internal/            internal docs (developer + agent)
tests/                    PHPUnit suite
testing/                  containerized test harness + API smoke + Playwright
tools/                    dev-time scripts (data-dictionary generator, etc.)
.claude/                  in-repo Claude Code automations (agents, skills, hooks)
.semgrep/                 custom security rules
```

Dev tooling at repo root (not shipped): `composer.json`, `phpstan.neon`, `.phpcs.xml`, `phpunit.xml`, `phpstan-baseline.neon`.

---

## Code organisation

Historically every shared function lived in a single `lib.php`. The v3.30.0
ADR-004 wave-1 extraction began splitting it into focused, concern-scoped
modules under `lib/`. As of v3.30.0, `lib.php` is ~7,900 lines (down from
~12,500 at v3.29.0); the rest moved into 12 new modules:

`lib/utils.php`, `lib/ip.php`, `lib/config.php`, `lib/db.php`, `lib/audit.php`,
`lib/presentation.php`, `lib/settings.php`, `lib/user_preferences.php`,
`lib/auth.php`, `lib/auth_password.php`, `lib/auth_rate_limit.php`,
`lib/auth_recaptcha.php`.

These join the pre-existing subsystem files already in `lib/` (`backup*.php`,
`restore*.php`, `vault.php`, `app_secret.php`, `auth_step_up.php`,
`S3Client.php`, `SftpClient.php`, `LocalBackupClient.php`,
`BackupClientInterface.php`). Functions not yet extracted still live in
`lib.php`; extraction continues in later releases. The per-module
function-membership cheat sheet is in `coding-guide.md`.

`testing/scripts/lib-module-linter.php` enforces the module shape: every
`lib/*.php` carries a module header, no module `require`s another module
(`lib.php` is the dual-require shim), and no function is defined twice across
modules. `LibModuleLinterTest` runs it in CI.

---

## Procedural code is the default

No namespaces, no DI container, no ORM, no templating engine. Shared functions
live in `lib.php` and the concern-scoped `lib/*.php` modules (see "Code
organisation" above). The one deliberate class hierarchy is `Dialect` /
`SqliteDialect` / `MysqlDialect` / `PgsqlDialect` for per-engine SQL
differences — see `runtime-dependency-policy.md` → "When to use classes vs
functions".

Runtime dependencies are curated: each package must pass six acceptance criteria. The full policy is in `runtime-dependency-policy.md`; the procedure for proposing one is in `adding-a-runtime-dependency.md`; the current whitelist is in `coding-guide.md` (single source of truth).

---

## Release engineering

End users deploy by extracting a tarball — no Composer, no build step on the target. The release bundle:

- `releases/make_releases.sh` runs `composer install --no-dev` against `Simple-PHP-IPAM/` and produces `releases/ipam-X.Y.Z.tar.gz` containing the web root plus `vendor/`.
- Tarball is uploaded as a GitHub Release asset and pulled by `upgrade.sh` on the target.
- `vendor/` is gitignored in the repo but **ships in the tarball**.

Release flow (Phase 1 land content → Phase 4 deploy across 7 targets) is in `release-workflow.md`. Marketing-site updates are in `marketing-site.md`. Hotfix flow (off `main`, sync `dev ← main` after) is in `hotfix-release.md`.

---

## Forward-looking design (not in force in v3.x)

Two sets of design notes describe planned v4.x behaviour. They are intentionally separated from this doc because none of them apply to current code:

- `v4-tenancy-design.md` — opt-in multi-tenancy with `/t/{slug}/` URLs and HKDF per-tenant keys. v4.0.0 schema migration runs on every install but the feature is off by default.
- `v4-release-stream.md` — enterprise-auth + global-reach sequencing across the v4.x stream.
- `i18n-design.md` — Gettext-based i18n / l10n; candidate for v4.0.0 + v4.2.0.

Do not apply any of these to v3.x code. If a session uncovers a v3.x concern that interacts with v4 design, raise it explicitly rather than implementing speculative compatibility.

---

## Cross-references

- `README.md` (this directory) — entry point and routing.
- `coding-guide.md` — language and project conventions.
- `testing-guide.md` — test layers and anti-patterns.
- `security-model.md` — threat model and trust boundaries in detail.
- `data-dictionary.md` (generated) — every table, column, index across all three engines.
- `lessons-learned.md` — chronological rollup of cross-release lessons.
- Root `CLAUDE.md` — the hot-cache pointer set agents load every session.

---

## Update protocol

Update this doc when:

- A new invariant is discovered (something that broke once and now has a guard / test / convention enforcing it). Add a row to the invariants table with the protected code location and the source incident.
- An existing invariant is invalidated (the underlying constraint genuinely no longer applies). Remove the row; do not leave a stale entry.
- The bootstrap sequence, trust boundaries, or repo layout changes structurally.
- A forward-looking design moves from speculative to shipped — promote it into the active body and remove from "Forward-looking design".

Procedural changes (how releases ship, how migrations are added) belong in their respective procedure docs, not here.
