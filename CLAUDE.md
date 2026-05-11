# CLAUDE.md — Simple PHP IPAM

Developer guide for AI assistants working on this repository.

---

## Agent session state — Memory MCP

This project uses the **Memory MCP server** (Docker MCP Toolkit, `claude-memory` Docker volume, persistent) as the sole agent session-state store. Session rules, entity naming convention, backup procedure, and the full Memory MCP workflow live in the user-scope global `~/.claude/CLAUDE.md` — read that first, then come back for the project-specific notes below. Memory MCP replaced TrucoPilot on 2026-04-14 (see `migration:trucopilot-to-memory-mcp` in the graph and the archived snapshot at `~/.claude/projects/<project>/memory/archive_trucopilot_snapshot_20260414.json`).

### Project slug for entity naming

This project's slug is **`simple-php-ipam`**. Per the global convention, all project-scoped entities must use it as a prefix:

- `project:simple-php-ipam` — the project entity
- `project:simple-php-ipam:release:v2.7.0` — current release
- `project:simple-php-ipam:roadmap:v2.8.0` through `...:roadmap:v4.0.0` — planned work
- `project:simple-php-ipam:bug:v2.8.0-showpw-visibility` — active bug
- `project:simple-php-ipam:hotspot:settings.php` — repeat-offender file

Cross-project / global entities that also apply to this project but are named without a prefix: `user:sean`, `mcp:memory`, `migration:trucopilot-to-memory-mcp`.

### Session-start query for this project

```text
open_nodes(["user:sean"])
search_nodes("project:simple-php-ipam")
```

Two cheap calls. The first loads your profile + preferences. The second returns every entity prefixed with `project:simple-php-ipam:` plus the bare `project:simple-php-ipam` node itself — the full project view — without dragging in other projects' state.

---

## Quick start (new session)

```bash
composer install                              # one-time, installs dev tools to vendor/
bash testing/bootstrap-app.sh sqlite          # spin up dockerized app + seed for local testing
vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/phpcs
```

Web root is `Simple-PHP-IPAM/`. Bootstrap entry is `Simple-PHP-IPAM/init.php`. See `docs/internal/test-suites.md` before pushing.

---

## Project overview

> **Current shipped version: v3.27.8** (see `Simple-PHP-IPAM/version.php`). This CLAUDE.md intentionally documents forward-looking policy for unreleased versions (v4.0.0+) so design intent survives across sessions. Any section or sentence that cites a version ≥ v4.0.0 describes future work — **do not apply it to current v3.x code**. Current-state rules are the ones that do not cite a future version.

Simple PHP IPAM is a lightweight IPv4/IPv6 address management web application built with **PHP 8.2+ and SQLite**. It has **no npm build step** — all CSS and JavaScript are vanilla. Starting in v2.9.0, the application will ship a small, carefully curated set of Composer-managed runtime dependencies bundled into the release tarball, so end users still deploy by extracting the tarball with no build step. The web root is `Simple-PHP-IPAM/` (the subdirectory, not the repo root).

---

## Repository layout

The web root is `Simple-PHP-IPAM/` (subdirectory, not the repo root). Key files in it: `init.php` (bootstrap), `lib.php` (all shared functions), `migrations.php` (schema migrations), `schema.sql` (fresh-install schema), `version.php` (`IPAM_VERSION` constant), `config.php` (user-editable config), `assets/app.css` + `assets/app.js` (all CSS/JS), `upgrade.sh` (upgrade script). Runtime data lives in `data/` (gitignored): `data/ipam.sqlite` and `data/tmp/` (caches, temp uploads).

Other top-level directories: `testing/` (API test suite and sample datasets), `releases/` (release bundle builder), `docs/` (api.md, advanced-networking.md, configuration.md, install.md, oidc.md, scanning.md, security.md, upgrading.md; plus `_config.yml` and `index.md` for GitHub Pages), `tests/` (PHPUnit unit tests).

Dev tooling at the repo root (not deployed): `composer.json`, `composer.lock`, `phpstan.neon`, `phpstan-baseline.neon`, `.phpcs.xml`, `phpunit.xml`. Run `composer install` once to install tools into `vendor/` (gitignored).

### Procedure docs (`docs/internal/`)

Operational procedures live alongside the code, one Read away. CLAUDE.md is policy and architecture; these are the "how do I do X" recipes.

| Doc | Use when |
|---|---|
| `docs/internal/page-inventory.md` | Looking up existing pages or adding a new one |
| `docs/internal/test-suites.md` | Running tests locally or fixing CI failures (Local gate before push) |
| `docs/internal/release-workflow.md` | Cutting a regular release (Phase 1–4) |
| `docs/internal/hotfix-release.md` | Cutting a hotfix off `main` |
| `docs/internal/marketing-site.md` | Anything touching `simplephpipam.com` (version bump, new docs, cache purge) |
| `docs/internal/adding-a-migration.md` | Writing a `migrations.php` closure |
| `docs/internal/adding-a-page.md` | Creating a new PHP page |
| `docs/internal/investigating-ci-failure.md` | A check went red on a PR |
| `docs/internal/release-kickoff-prompt.md` | Starting a new release session (paste-and-go template) |
| `docs/internal/coderabbit-config.md` | Debugging `.coderabbit.yaml` ↔ org-config inheritance (early-access opt-in for pre-merge checks) |
| `docs/internal/data-dictionary.md` | **Generated** — looking up a column type, FK, or unique constraint across SQLite/MySQL/PostgreSQL. Refresh with `php tools/generate-data-dictionary.php` after any schema change |
| `docs/internal/lessons-learned.md` | Curated rollup of cross-release lessons (migration footguns, binary IP rules, deploy/release gotchas, testing discipline, auth pitfalls). Read at session start; update only when a lesson generalises across more than one release |
| `docs/internal/deploy-targets.md` | Deploying a release bundle to any of the 7 targets (demo, prod, 4 testing instances, marketing site). Host vs container `upgrade.sh`, deploy ordering, rollback |
| `docs/internal/adding-a-runtime-dependency.md` | Proposing a new Composer package or vendored frontend asset. Six acceptance criteria, PR shape, whitelist update |
| `docs/internal/adding-a-setting.md` | Adding an admin-tunable setting via `ipam_setting_definitions()`. Resolution order, registry fields, sensitive-value handling |
| `docs/internal/incident-response.md` | Production regression playbook. Detect → triage → hotfix → post-mortem; when to hotfix vs defer; promotion to lessons-learned |
| `docs/internal/adding-an-api-endpoint.md` | Extending `api.php`. Handler shape, dispatch wiring, OpenAPI spec update, readonly-key enforcement, integration test |
| `docs/internal/auth-model.md` | Auth helpers, OIDC implementation details, MFA (TOTP/Email OTP/WebAuthn), claim mapping, session config. Read when touching login/MFA code |
| `docs/internal/step-up-auth.md` | Step-up auth subsystem (v3.27.0) — `ipam_sudo_verify()` contract, policy keys, session keys/TTL, invalidation triggers, prompt UX, "how to add a new sensitive action" recipe. Read when adding or migrating a sudo-class admin handler |
| `docs/internal/audit-actions.md` | Full vocabulary of `audit()` action strings. Update when adding a new action |
| `docs/internal/scanner.md` | Scanner subsystem reference — tables, helpers, security patterns. Read when touching scan/probe/ARP code |
| `docs/internal/runtime-dependency-policy.md` | Full policy text behind the `vendor/` whitelist. The whitelist + summary stays in CLAUDE.md; rationale lives here |
| `docs/internal/v4-tenancy-design.md` | Forward-looking v4.0.0 multi-tenancy design (opt-in wizard, `/t/slug/` URLs, settings cascade, HKDF per-tenant keys, post-v4 table rules). Not in force in v3.x |
| `docs/internal/i18n-design.md` | Forward-looking i18n/l10n design (Gettext, per-user locale cascade, 4-phase rollout). Candidate for v4.0.0 + v4.2.0 per `v4-release-stream.md` |
| `docs/internal/v4-release-stream.md` | v4.x stream strategy — enterprise auth + global reach. Sequencing for i18n + RBAC + SAML + LDAP + OAuth + SCIM across ~6 releases. Multi-tenancy explicitly NOT in v4.x (deferred — see `v4-tenancy-design.md` header) |
| `docs/internal/cleanup.md` | Pre-ticket backlog for low-risk code-health items. Add a row when spotting cleanup-worthy code during other work; batch into a GH issue once items accumulate |
| `docs/internal/ipambkl1-format.md` | `IPAMBKL1` Logical-backup format spec — magic, header/body/footer JSON shape, abstract-type encoding, re-emit-IDs replay strategy, schema_version compat. Source-of-truth for #824 (writer + reader, v3.23.0), #849/#1076 (picker UI, v3.25.0), and #1042's conformance tests. Read when touching `ipam_backup_logical_*` or `ipam_restore_logical_*`. |

---

## Page inventory

Full table of every PHP page (auth required, role, description) lives in **`docs/internal/page-inventory.md`**. Read it when you need to look up an existing page; update it when you add or remove pages.

## Bootstrap sequence

Every web page starts with `require __DIR__ . '/init.php'`, which:

1. Loads `config.php` into `$config`
2. Enforces HTTPS (301 redirect if not)
3. Configures session (`Secure`, `HttpOnly`, `SameSite=Strict`, strict mode)
4. Starts the session
5. Requires `lib.php`
6. Opens the SQLite DB with `ipam_db()` → `$db`
7. Runs `ipam_db_init()` — applies pending migrations, creates bootstrap admin if no users exist
8. Runs lazy housekeeping if due (temp file cleanup, stale login attempt purge)
9. Initialises CSRF token

`api.php` and `status.php` do **not** use `init.php` (no session); they load `config.php` and `lib.php` directly.

---

## Database

**Engine:** SQLite 3 via PDO with WAL mode and `PRAGMA foreign_keys = ON`.

**PDO settings:** `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`.

### Core tables (defined in `schema.sql`)

| Table | Key columns |
|-------|------------|
| `users` | `id`, `username`, `password_hash`, `role` (admin\|readonly), `is_active`, `oidc_sub`, `name`, `email`, `last_login_at`, `password_changed_at`, `theme` (auto\|light\|dark), `created_at`, `updated_at` |
| `vrfs` | `id`, `name` (UNIQUE), `description`, `rd` (Route Distinguisher, free-form), `created_at`, `updated_at` |
| `subnets` | `id`, `cidr`, `ip_version`, `network`, `network_bin` (BLOB), `prefix`, `description`, `site_id`, `vlan_id` (legacy int 1–4094, nullable), `vlan_fk` (FK → `vlans.id`, nullable), `vrf_id` (FK → `vrfs.id`, nullable), `created_at`, `updated_at` — UNIQUE(cidr, vrf_id) |
| `contacts` | `id`, `name`, `email`, `phone`, `org`, `note`, `created_at`, `updated_at` |
| `addresses` | `id`, `subnet_id`, `ip`, `ip_bin` (BLOB), `hostname`, `owner`, `owner_contact_id` (FK → `contacts.id`, nullable), `note`, `grp`, `status` (used\|reserved\|free), `mac` (free-form, default ''), `expires_at` (YYYY-MM-DD, nullable), `created_at`, `updated_at` |
| `audit_log` | `id`, `action`, `entity_type`, `entity_id`, `user_id`, `username`, `ip`, `user_agent`, `details`, `created_at` |
| `sites` | `id`, `name`, `description`, `parent_id` (FK → `sites.id`, nullable, added v2.0.0), `created_at` |
| `vlans` | `id`, `vlan_id` (1–4094), `name`, `description`, `site_id` (FK → `sites.id`, nullable), `created_at`, `updated_at` — UNIQUE(vlan_id, site_id) |
| `tags` | `id`, `name` (UNIQUE, max 50 chars), `colour` (hex, default `#6c757d`), `created_at` |
| `subnet_tags` | `subnet_id` (FK → `subnets.id` CASCADE), `tag_id` (FK → `tags.id` CASCADE) — join table |
| `address_tags` | `address_id` (FK → `addresses.id` CASCADE), `tag_id` (FK → `tags.id` CASCADE) — join table |
| `alert_state` | `subnet_id` (FK → `subnets.id` CASCADE), `level` (warn\|crit), `last_alerted_at` — tracks sent utilization alerts |
| `api_keys` | `id`, `name`, `key_hash` (SHA-256), `is_active`, `is_readonly`, `description`, `created_by`, `created_at`, `last_used_at` |
| `login_attempts` | `id`, `ip`, `attempted_at` |
| `address_history` | `id`, `address_id`, `subnet_id`, `ip`, `action`, `user_id`, `username`, `client_ip`, `user_agent`, `before_json`, `after_json`, `created_at` |
| `schema_migrations` | `id`, `version`, `applied_at` |

**Important:** The `addresses` table does **not** have an `ip_version` column — that lives only on `subnets`.

The `audit_log` table is **append-only** — SQLite triggers raise an abort on any UPDATE or DELETE.

### Binary IP storage

IPs are stored as raw binary blobs (`inet_pton()` output) for correct sort order and fast range queries. Never store text IPs in `ip_bin`/`network_bin`. Use `inet_pton()` to encode and `inet_ntop()` to decode.

**Storage format: native length, never padded.** IPv4 is stored as 4 bytes and IPv6 as 16 bytes, matching the output of `inet_pton()` directly. Do not left-pad IPv4 to 16 bytes. All three supported engines (SQLite, MySQL `VARBINARY(16)`, Postgres `BYTEA`) do byte-wise memcmp comparison on native-length values, so sort order is equivalent across engines without padding. This was evaluated during v2.8.0 planning and locked in as the storage convention — if you are tempted to change it, read the discussion on #410 and #379 first.

**All binding goes through `ipam_bind_binary()`** (introduced in v2.9.0, #379), which uses `PDO::PARAM_LOB` on every driver. This is non-negotiable:

- On **SQLite**, `PARAM_LOB` is required so the column stores with BLOB affinity rather than TEXT affinity. SQLite's comparison rules state that any BLOB sorts greater than any TEXT regardless of byte content, so mixing affinities in the same column breaks `ORDER BY ip_bin` and every range query. Pre-v2.9.0 data was inadvertently stored with TEXT affinity because the default `PARAM_STR` binding was used. The migration in #410 normalizes existing rows to BLOB on upgrade.
- On **MySQL**, `PARAM_LOB` is required for `VARBINARY(16)` columns. `PARAM_STR` would string-escape high bytes and truncate at null bytes, corrupting every stored IP.
- On **Postgres**, `PARAM_LOB` is required for `BYTEA` columns. The pgsql driver may also need an explicit bytea type hint on prepare — verify during the v2.11.0 implementation.

**Round-trip test vectors** that any new driver binding must handle correctly:

- `inet_pton('10.0.0.0')` — `\x0A\x00\x00\x00`, null bytes after the first byte
- `inet_pton('2001:db8::')` — `\x20\x01\x0D\xB8\x00...\x00`, mostly null bytes
- `inet_pton('255.255.255.255')` — `\xFF\xFF\xFF\xFF`, all high bytes

These three values catch the most common string-escape bugs immediately. If a binding round-trips any of them incorrectly, the driver is broken and must not ship.

Key helpers in `lib.php`:
- `parse_cidr(string $cidr): ?array` — validates and normalises a CIDR, returns `['version', 'network', 'prefix', 'net_bin']`
- `normalize_ip(string $ip): ?array` — returns `['ip', 'bin', 'version']`
- `ip_in_cidr(string $ip, string $network, int $prefix): bool`
- `apply_prefix_mask(string $ipBin, int $prefix): string`
- `ipv4_bin_to_int(string $bin): int` / `ipv4_int_to_bin(int $n): string`
- `ipv6_bin_increment(string $bin): string` — pure-PHP 16-byte carry increment (no GMP)
- `ipv6_enumerate_first_n(PDO $db, int $subnetId, string $networkBin, int $prefix, int $n): array` — returns first N unassigned IPv6 addresses in a subnet (scans from network+1, skips assigned)

---

## Schema migrations

Migrations live in `migrations.php` as an associative array of version string → closure returned by `ipam_migrations()`. `apply_migrations()` in `lib.php` calls `ksort($migs, SORT_NATURAL)` before iterating, so **array order does not matter** — migrations always execute in natural version order. Each migration runs in a transaction and is recorded in `schema_migrations`. Always guard `ALTER TABLE` with `PRAGMA table_info()` checks to make new migrations idempotent.

**When adding a new version:** add the migration closure, bump `version.php`, update `CHANGELOG.md` (keepachangelog format).

> **Current shipped version: v3.27.8.** `Creating new data tables in post-v4.0.0 releases` is **forward-looking** (applies to any migration whose version sorts after v4.0.0, including v4.0.x patches) — ignore it for all current v3.x work. `Modifying the schema (multi-engine, from v2.9.0 onward)` and `Runtime dependencies` are **currently active** rules (apply to v2.9.0+ including the current v3.27.8).

### Multi-tenancy (v4.0.0 — forward-looking)

Full design lives in **`docs/internal/v4-tenancy-design.md`**. Summary:

- **Opt-in.** Schema migration runs on every v4.0.0 install but feature is off by default. App behaves identically to v3.x until an admin runs the conversion wizard. SQLite is single-tenant only.
- **URL model.** `/t/{slug}/` path prefix (decided 2026-04-24). Single-tenant installs have zero URL change.
- **Settings cascade.** `ipam_setting()` resolves tenant-row → global-row → `$GLOBALS['config']` → registry default. All v3.x rows use `tenant_id = NULL`; the cascade contract is in force today.
- **`app_secret` stays in `config.php`, never in DB.** Per-tenant keys are HKDF-derived at runtime (`HKDF-SHA256(app_secret, "ipam-v4:" || tenant_id || ":" || purpose)`). This rule is unconditional.
- **Post-v4.0.0 migrations creating data tables MUST include `tenant_id NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT`** plus an index. Applies to any migration version sorting greater than v4.0.0 under natural-sort. Read `v4-tenancy-design.md` before adding such a table.

### Modifying the schema (multi-engine, from v2.9.0 onward) *(applies from v2.9.0+)*

Once v2.9.0 lands, the project ships three schema files that must stay in sync:

- `schema.sql` — SQLite (authoritative for fresh SQLite installs)
- `schema.mysql.sql` — MySQL 8.0+ (shipped v2.10.0)
- `schema.pgsql.sql` — PostgreSQL 14+ (shipped v2.11.0)

**When adding or modifying a table, column, index, or constraint:** update all three files in the same PR. No exceptions. The `SchemaParityTest` in CI will fail the build if the three files diverge on any structural element (table set, column set, type class, nullability, default kind, FK target, FK on-delete action). Type classes are normalised — `BLOB` / `VARBINARY(16)` / `BYTEA` all map to `"binary"`, so you do not need to match exact type names, only semantic equivalents.

**Then regenerate the data dictionary** (`docs/internal/data-dictionary.md`) by running `php tools/generate-data-dictionary.php` and committing the refreshed file. `DataDictionaryDriftTest` (PHPUnit) re-runs the generator in-process and fails the build if the committed copy is stale.

Migrations remain the source of truth for schema evolution. The three schema files are a fast path for fresh installs; the migration chain is what existing installs run through. `MigrationTest` asserts that applying every migration from v1.0 on an empty database converges to the same structural state as the matching `schema.X.sql` file, so drift between "what migrations produce" and "what the schema file says" is also caught.

**Dialect helpers** (the `Dialect` abstraction from v2.9.0) are used inside migration closures for portable SQL — `$dialect->now()`, `$dialect->upsert()`, `$dialect->autoincrement()`, `$dialect->binary_type()`, etc. The schema files themselves are hand-written per engine and do not go through the dialect layer, because they are engine-specific by definition.

**Do not introduce a schema templating system.** This was evaluated during v2.9.0 planning and rejected: three plain SQL files are easier to review, easier to debug against a live database, and aligned with the project's vanilla-PHP ethos. The parity test plus migration-replay test is sufficient drift protection.

### Runtime dependencies *(in force since v2.9.0)*

Full policy + rationale lives in **`docs/internal/runtime-dependency-policy.md`**. Procedure for adding a dep is in **`docs/internal/adding-a-runtime-dependency.md`**. Load-bearing summary:

- **Goal:** avoid hand-rolling security-sensitive protocols/crypto while preserving the "rsync and run" deployment story.
- **Deployment:** `vendor/` is gitignored; `releases/make_releases.sh` runs `composer install --no-dev` and ships `vendor/` in the tarball. End users do not run Composer.
- **Six acceptance criteria** for any new dep (narrow purpose, mature, widely used, minimal tree, liberal license, maintainer justification PR). See policy doc.
- **Carve-outs** for vendored frontend assets: data-viz primitives (e.g. uPlot) and design tokens (e.g. Open Props), under `Simple-PHP-IPAM/assets/vendor/`, vanilla JS/CSS only, ≤50KB. Carve-outs do **not** re-open the frontend-framework door — Tailwind/React/Vue/Sass remain forbidden.

**Current runtime dependency whitelist:**

| Package | Version | Purpose | Justified in |
|---|---|---|---|
| phpmailer/phpmailer | ^6.9 | Direct SMTP delivery (replaces native `mail()` when smtp.enabled=true). LGPL-2.1-or-later with PHPMailer's bundling exception. | #415, v3.1.0 |
| robthree/twofactorauth | ^2.1 | TOTP (RFC 6238) secret generation, code verification, otpauth:// URI for QR enrollment. MIT, zero deps. | #418, v3.6.0 |
| lbuchs/webauthn | ^2.1 | WebAuthn server-side challenge/response — attestation verification, assertion verification, COSE key parsing. MIT, zero deps. | #687, v3.15.0 |
| phpseclib/phpseclib | ^3.0 | SFTP transport for backup destinations. MIT, pure PHP (no ext-ssh2 required). | #693, v3.17.0 |

**Vendored frontend assets (assets/vendor/):**

| File | Size | Source | Purpose | Justified in |
|---|---|---|---|---|
| `qrcode.min.js` | ~20KB | cdnjs (qrcodejs 1.0.0, MIT) | QR code canvas rendering for TOTP enrollment. | #418, v3.6.0 |

**Procedural code is the default.** No namespaces, no DI container, no ORM, no templating engine. The one deliberate class hierarchy is `Dialect` / `SqliteDialect` / `MysqlDialect` / `PgsqlDialect` for per-engine SQL differences — see `runtime-dependency-policy.md` → "When to use classes vs functions".

---

## Authentication & authorisation

### Roles
- `admin` — full access including all admin pages
- `readonly` — read-only access; all write operations return 403

### Helpers
- `is_logged_in(): bool`
- `require_login(): void` — also enforces session idle timeout
- `require_role('admin'): void` — 403 if not admin
- `require_write_access(): void` — 403 if readonly
- `current_user(): array` — returns `['id', 'username', 'role']` from session
- `login_user(int $uid, string $username, string $role, ?PDO $db = null): void` — sets session, regenerates ID, loads persisted theme if `$db` provided

After calling `login_user()`, always update `last_login_at`:
```php
$db->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=:id")
   ->execute([':id' => $uid]);
```

### CSRF
Every POST form must include `<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">` and the handler must call `csrf_require()` at the top.

### Self-protection guards in users.php
These actions are blocked both server-side and hidden in the UI for the logged-in user's own account:
- `toggle_active` (disable/enable)
- `set_role`
- `unlink_oidc`
- `delete`

The last-active-admin guard uses: count active admins **excluding the target user** (`AND id != :id`). Only applies when the target is active AND admin — never block deletion of an inactive admin.

---

## OIDC, MFA, and full auth reference

Helper functions, claim mapping, JWKS caching, MFA methods (TOTP / Email OTP / WebAuthn passkeys), and session configuration live in **`docs/internal/auth-model.md`**. Authorization Code + PKCE flow; hand-rolled (no JWT library — see `runtime-dependency-policy.md` "Explicitly not adopted").

---

## UI conventions

### HTML output
- All PHP output goes through `e(string $s): string` (wraps `htmlspecialchars`)
- Every page calls `page_header(string $title)` and `page_footer()` from `lib.php`
- `page_header()` renders the full `<html>...<body>` opening with nav bar
- `page_footer()` closes `</div></body></html>` and renders the footer with version + update check

### CSS
Located in `assets/app.css`. Uses CSS custom properties for theming (light/dark/auto via `html[data-theme]`). Key variables: `--bg`, `--fg`, `--muted`, `--border`, `--card`, `--danger`, `--success`, `--warn`, `--link`, `--btn`, `--btnfg`, `--badge-bg`.

Key utility classes: `.muted`, `.danger`, `.success`, `.warning`, `.badge`, `.badge-update`, `.status-used`, `.status-reserved`, `.status-free`, `.util-bar`, `.util-bar-fill`, `.util-bar-fill--warn`, `.util-bar-fill--crit`, `.row`, `.card`, `.action-pill`, `.button-danger`, `.button-secondary`.

Asset cache-buster: update `?v=X.Y.Z` in the `<link>` and `<script>` tags in `page_header()` **and** in `demo_gate.php` (lines 74–75) when changing CSS/JS. `demo_gate.php` has its own `<head>` block and does not call `page_header()`, so it must be updated separately.

### Nav structure (v3.8.0 sidebar)
- **Desktop (≥1024px):** always-visible sidebar with SVG Heroicon nav links (Dashboard, Subnets, Addresses, Search, Audit, Admin section) and user block at the bottom (username + role badge → Theme, Account, Logout)
- **Mobile (<1024px):** hamburger button opens the sidebar as a full-height overlay; Escape or backdrop click closes it
- **Command palette ⌘K / Ctrl+K:** keyboard-driven navigation, record creation, and theme toggle; accessible from any page
- **Admin section nav items:** Sites, VLANs, VRFs, Tags, Contacts, Custom Fields, Users, DHCP Pools, API Keys, Webhooks, Import CSV, Database Tools (hidden for non-SQLite engines), Backups, Health
- All emoji icons replaced with consistent SVG Heroicons sprite (`assets/icons.svg`)

---

## Audit logging

Call `audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details = '')` for every significant action. Convention for `$action`: `<entity>.<verb>` — lowercase, dot-separated, verb is one of `create`/`update`/`delete`/`toggle_active`/`set_role`/etc. Reuse the existing vocabulary so log queries (`WHERE action LIKE 'subnet.%'`) stay consistent.

**Full action vocabulary (auth, entities, users, scanner, etc.) lives in `docs/internal/audit-actions.md`.** Update that table when adding a new action.

---

## Scanner

Tables (`scan_schedules`, `scan_results`), columns (`addresses.last_seen_at`, `is_stale`), pages (`scan_history.php`, `import_arp.php`, `scan_run.php`), and the full helper-function reference live in **`docs/internal/scanner.md`**. Load-bearing security rules:

- **IP injection guard:** raw `$_GET`/`$_POST` IPs **must** go through `normalize_ip()` before `proc_open()` / `fsockopen()`. Semgrep rule `ipam-proc-open-safe` enforces this.
- **CLI-only guard:** `scan_run.php` must `header('HTTP/1.1 403 Forbidden'); exit(1)` on non-CLI SAPI.
- **Sync cap:** `api_scan_run()` rejects prefix > 28 with HTTP 400.

---

## Development workflow

### Branching & PRs
- **All development happens on the `dev` branch** (or feature branches off `dev`).
- **Never commit directly to `main`.**
- **Pull requests go `dev` → `main`** only. Do not create PRs targeting any other branch unless explicitly instructed.

### Versioning
This project follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`):
- **MAJOR** — breaking or incompatible changes (e.g. config format change, removed feature)
- **MINOR** — backwards-compatible new features or enhancements
- **PATCH** — backwards-compatible bug fixes and security patches

Examples: `1.15.0` (new features), `1.15.1` (bug fix), `2.0.0` (breaking change).

Note: versions prior to 1.15.0 used two-part numbering (e.g. `1.14`). The `ipam_normalise_version()` function in `lib.php` pads these to three parts for comparison, so the update checker and upgrade script handle both formats correctly.

### Version bump checklist
When implementing a new version:
1. Add migration closure to `migrations.php` (guard `ALTER TABLE` with `PRAGMA table_info()` check)
2. Bump `IPAM_VERSION` in `version.php` (use `MAJOR.MINOR.PATCH` format)
3. Update `CHANGELOG.md` following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format:
   - Header: `## [X.Y.Z] - YYYY-MM-DD`
   - Categories: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**, **Security** (only include categories with entries)
   - Add a version comparison link at the bottom of the file
4. Update `README.md` "What's new" section. **README only carries the single most recent release** — replace the existing `## What's new in vX.Y.Z` section in-place rather than appending. Older versions live in `CHANGELOG.md`. Do not let the README accumulate historical sections.
5. Update relevant `docs/` files
6. Bump asset cache-buster `?v=X.Y.Z` in `page_header()` **and** `demo_gate.php:74–75` if CSS/JS changed

### Static analysis & testing

The project uses four dev tools (three Composer-managed, one standalone):

| Tool | Config | Purpose |
|------|--------|---------|
| **PHPStan** | `phpstan.neon` | Static analysis — level 9, analyses `Simple-PHP-IPAM/` |
| **PHP_CodeSniffer** | `.phpcs.xml` | Style checking — PSR-12 with exclusions for K&R brace and inline control structure style |
| **PHPUnit** | `phpunit.xml` | Unit tests for pure utility functions in `lib.php` |
| **Semgrep** | `.semgrep/rules.yml` | Security taint rules — XSS, path traversal, SQLi, open redirect. Recognises `e()` as an HTML sanitizer. |

**Running the tools:**
```bash
vendor/bin/phpstan analyse          # static analysis
vendor/bin/phpcs                    # style check
vendor/bin/phpunit                  # unit tests
php -l Simple-PHP-IPAM/changed.php  # syntax check individual files
semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/   # security rules (standalone, not via Composer)
```

**PHPStan baseline (`phpstan-baseline.neon`):** Pre-existing errors are acknowledged in the baseline so CI only fails on *new* errors. The baseline is almost entirely `Variable $db/$config might not be defined` false-positives caused by PHPStan not being able to see variables injected by `require 'init.php'`. Fix baseline errors incrementally; do not add new entries to suppress real bugs.

**PHPUnit tests:**
- `tests/UtilTest.php` — unit tests for pure utility functions that have no DB or session dependencies: `e()`, `parse_cidr()`, `apply_prefix_mask()`, `ip_in_cidr()`, `normalize_ip()`, `ipv4_bin_to_int()`, `ipv4_int_to_bin()`, `ipam_normalise_version()`, `normalize_status()`, `ipv6_bin_increment()`.
- `tests/MigrationTest.php` — integration tests for `apply_migrations()`. Builds an in-memory SQLite database in the exact pre-`2.1.0-vrfs` schema state (subnets without `vrf_id`, 5 addresses, 18 prior migrations recorded), then runs `apply_migrations()` and asserts: addresses are preserved (the exact production data-loss scenario), subnet IDs are unchanged, the `vrf_id` column is added, addresses still JOIN to subnets, FK enforcement is re-enabled, second call is idempotent, and UNIQUE(cidr, vrf_id) is enforced for non-NULL vrf_id pairs.

Bootstrap is `tests/bootstrap.php` which requires `lib.php` directly.

**Migration testing pitfalls — read this before writing any migration that rebuilds a table:**

1. **`DROP TABLE` with `PRAGMA foreign_keys = ON` cascades child rows.** SQLite executes an implicit row-by-row DELETE before physically dropping the table. Any child table with `ON DELETE CASCADE` (e.g. `addresses`, `subnet_tags`, `alert_state`) will have all its rows deleted. This is the root cause of the v2.2.1 data-loss bug where every upgrade from v1.x wiped all IP addresses. Fix: `apply_migrations()` disables FK enforcement (`PRAGMA foreign_keys = OFF`) before each migration's `BEGIN EXCLUSIVE` and restores it unconditionally in all exit paths.

2. **`PRAGMA foreign_keys` cannot be changed inside a transaction.** The PRAGMA must be set *outside* `BEGIN`/`COMMIT`. Setting it after `BEGIN EXCLUSIVE` has no effect — the transaction runs with whatever FK state was active before `BEGIN`. Always set the PRAGMA, then begin the transaction.

3. **`ALTER TABLE t RENAME TO t_old` in SQLite ≥3.26 rewrites child FK references.** After renaming `subnets` to `subnets_old`, the `addresses` table's FK is automatically rewritten to reference `subnets_old`. Dropping `subnets_old` still cascades. The `PRAGMA legacy_alter_table = ON` workaround was tested and does not reliably prevent this. The only safe approach is to disable FK enforcement before the transaction (point 1 above).

4. **SQLite UNIQUE treats NULLs as distinct.** `UNIQUE(cidr, vrf_id)` does NOT prevent two rows with the same `cidr` and both `vrf_id = NULL` — SQLite considers each NULL distinct from every other value (SQL standard). The meaningful constraint is that two subnets cannot share the same CIDR within the same named (non-NULL) VRF. When testing UNIQUE constraints involving nullable FK columns, always use a non-NULL value.

**PHPCS style exclusions** (see `.phpcs.xml` comments for rationale):
- Inline control structures without braces — established codebase style
- K&R function brace placement (`function foo() {`) — established codebase style
- Column-aligned `=>` arrays — intentional for readability
- `<?php\ndeclare(strict_types=1);` without blank line — established codebase style
- Line length — SQL and HTML strings are legitimately long

**CI:** `.github/workflows/php-qa.yml` runs lint → PHPStan → PHPCS → PHPUnit on every push to `dev`/`main` and every PR targeting `main`.

**Semgrep:** Custom security rules live in `.semgrep/rules.yml`. Run locally with:
```bash
semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/
```
Rules cover XSS (`ipam-xss-unsanitized-echo`), path traversal (`ipam-unlink-user-path`), SQL injection (`ipam-sqli-raw-concat`), and open redirect (`ipam-open-redirect`). The `e()` function is registered as an XSS sanitizer so it is never flagged as a false positive. Path exclusions are in `.semgrepignore`.

### Linting
Always run `php -l` on every changed PHP file before committing:
```bash
php -l Simple-PHP-IPAM/lib.php
php -l Simple-PHP-IPAM/users.php
# etc.
```

### Running the test suites + local gate before push

Full procedure lives in **`docs/internal/test-suites.md`** — required reading before pushing any branch.

Covers: containerized Playwright (`bootstrap-app.sh sqlite|mysql|pgsql`), local PHP tools (php -l, phpstan, phpcs, phpunit, semgrep), the dev-direct fallback path with all seven recurring footguns documented (stale cron, login_attempts, password drift, etc.), `test_api.sh`, single-test debugging, and the **Local gate** checklist that must be all-green before `git push` — including the 3-driver dockerized test pass policy ("never push without a full local dockerized pass — GH Action minutes are a finite paid resource").

### Release workflow

Full procedure lives in **`docs/internal/release-workflow.md`** — required reading before cutting a release. Covers Phase 1 (land content on `dev`), Phase 2 (prep checklist + bundle build), Phase 3 (review-comment handling + bundle rebuild), and Phase 4 (merge, tag, GitHub release, deploy to demo + prod + 4 testing instances, marketing site update, milestone close, Memory MCP update).

Marketing-site updates (every release, plus when shipping new docs) — see **`docs/internal/marketing-site.md`**.

### Commit style
```
feat(scope): short description
fix(scope): short description
docs(scope): short description
```
Include `https://claude.ai/code/session_...` in commit body.

---

## In-repo Claude Code automations

Project-local agents, skills, and hooks live under `.claude/`. Use them — they encode procedure-doc rules and the documented footguns.

**Subagents** (`.claude/agents/`, invoke via Agent tool with `subagent_type`):
- `migration-reviewer` — run after editing `migrations.php`. Checks the four SQLite/FK footguns.
- `multi-engine-schema-parity` — run after editing any of `schema.sql` / `schema.mysql.sql` / `schema.pgsql.sql`, or after a migration adds a table. Flags structural drift before `SchemaParityTest` does. **Also rerun `php tools/generate-data-dictionary.php` after these edits** — `DataDictionaryDriftTest` will fail the build if `docs/internal/data-dictionary.md` is stale.
- `ip-binary-auditor` — run on any diff that touches `ip_bin` / `network_bin`, IP parsing, subnet math, ping/scan, or DB binds for binary columns.
- `phpcs-style-fixer` — run before commit if you edited `.php`. Surfaces real PHPCS violations and respects the documented PSR-12 exclusions.

**Skills** (`.claude/skills/`, invoke via Skill tool or `/<name>`):
- `/add-migration` — scaffold a new migration with FK-safe patterns and idempotency guards.
- `/release-kickoff` — plan and execute a release end-to-end.
- `/release-gate` — pre-existing local gate runner.

**Hooks** (`.claude/hooks/`, fire automatically on Edit/Write):
- `block-sensitive-paths.sh` (PreToolUse) — refuses edits to release tarballs, `Simple-PHP-IPAM/data/`, `config.php`, `vendor/`, `node_modules/`.
- `php-lint.sh` (PostToolUse) — `php -l` on every edited `.php` file.
- `phpstan-changed.sh` (PostToolUse) — PHPStan L9 single-file analysis on edited files under `Simple-PHP-IPAM/`. Skips silently if `vendor/bin/phpstan` is missing.

**MCP servers** (`.mcp.json` + user-scope `MCP_DOCKER`): SQLite (project-scoped to `data/ipam.sqlite`), Memory MCP, Semgrep, Playwright, Chrome DevTools, context7. Memory MCP is the agent session-state store — see top of this doc.

---

## Key constraints and gotchas

- **Runtime dependencies are allowed but curated** — see the "Runtime dependencies" section below for the policy and current whitelist. Historical note: this project shipped with zero runtime deps through v2.7.0 and adopted a curated dep policy in v2.8.0 to avoid hand-rolling security-sensitive protocols. The `vendor/` directory is gitignored in the repo but **is shipped in the release tarball** — end users still deploy by extracting the tarball with no build step. `composer install --no-dev` runs at release bundle time, not at install time on the target.
- **No npm in production** — all frontend CSS and JavaScript is vanilla. No bundlers, no build step, no node_modules. This rule is separate from the Composer rule and is not being relaxed.
- **`addresses` has no `ip_version`** — that column exists only on `subnets`. Do not add it to address INSERTs.
- **`addresses.grp` is a SQL reserved word** — stored as `grp` in the DB, exposed as `group` in the UI, API responses, and CSV headers.
- **`addresses.mac`** — free-form MAC address string, `NOT NULL DEFAULT ''`. Never validate the format server-side; users may enter any notation.
- **`addresses.expires_at`** — nullable YYYY-MM-DD lease expiry date. Rows where `expires_at < date('now')` are highlighted in the UI. The API supports `?expired=1` to filter these rows.
- **Migration order is by `ksort(SORT_NATURAL)`** — array order in `migrations.php` does not matter.
- **`fetch()` returns `false` on no rows**, never `null` — always check with `if ($row)` not `if ($row !== null)`.
- **CSRF on every POST** — `csrf_require()` at top of every POST handler; hidden `csrf` field in every form.
- **`e()` on every output** — never echo user-controlled data without `e()`.
- **`$GLOBALS['config']`** — when a local-scope function needs config (e.g. `render_subnet_node_local()`), access via `$GLOBALS['config']`. This is the established pattern (see `require_login()`).
- **Binary blobs in PDO** — `ip_bin` and `network_bin` are raw binary; do not treat as UTF-8 strings. Use `hash_equals()` for comparisons.
- **SQLite `rowCount()`** — works correctly for DELETE/UPDATE. Use it after DML statements to count affected rows.
- **`$config` is a global** — set by `init.php`. Available as `$config` in page scope; access via `global $config` inside functions.
- **Child subnets inherit site** — `find_parent_site_id()` is called on every subnet create/update; the server overrides the submitted `site_id` if a parent with a site is found. The UI shows a locked badge for `$depth > 0`.
- **Self-delete guard** — cannot delete the last *active* admin. Count uses `AND id != :id` (exclude target) and only applies when deleting an *active* admin.
- **`page_footer()` is not `exit`** — it outputs the closing HTML but does not call `exit`. The calling page must not output anything after it.
- **`subnets.vlan_fk` vs `subnets.vlan_id`** — `vlan_fk` (added v2.0.0) is the FK to `vlans.id`. The legacy integer column `vlan_id` (1–4094) is retained for backwards compatibility. New code should use `vlan_fk`; do not confuse the two.
- **Tags via join tables** — tags are attached to subnets/addresses through `subnet_tags` and `address_tags`. Both have `ON DELETE CASCADE` so tags are automatically removed when the parent entity is deleted. Use `get_tags_for_entity()` / `save_tags_for_entity()` in `lib.php`.
- **Site hierarchy depth limit** — enforced at the application layer (max depth 2: region → site). Prevent circular references in the parent picker by filtering out the site itself and its descendants.
- **`alert_email` must be set** — `check_utilization_alerts()` no-ops silently when `alert_email` is empty. The email alert feature relies on the server's MTA (`mail()` function).
- **`recaptcha_action` config key** — controls the reCAPTCHA v3 `action` parameter passed during login. Defaults to `'login'`. The value is emitted as `data-recaptcha-action` on the hidden input and read by `app.js` at runtime.
