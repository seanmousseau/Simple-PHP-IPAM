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

## Project overview

> **Current shipped version: v3.4.1** (see `Simple-PHP-IPAM/version.php`). This CLAUDE.md intentionally documents forward-looking policy for unreleased versions (v3.5.0 → v4.0.0+) so design intent survives across sessions. Any section or sentence that cites a version ≥ v3.5.0 describes future work — **do not apply it to current v3.4.x code**. Current-state rules are the ones that do not cite a future version.

Simple PHP IPAM is a lightweight IPv4/IPv6 address management web application built with **PHP 8.2+ and SQLite**. It has **no npm build step** — all CSS and JavaScript are vanilla. Starting in v2.9.0, the application will ship a small, carefully curated set of Composer-managed runtime dependencies bundled into the release tarball, so end users still deploy by extracting the tarball with no build step. The web root is `Simple-PHP-IPAM/` (the subdirectory, not the repo root).

---

## Repository layout

The web root is `Simple-PHP-IPAM/` (subdirectory, not the repo root). Key files in it: `init.php` (bootstrap), `lib.php` (all shared functions), `migrations.php` (schema migrations), `schema.sql` (fresh-install schema), `version.php` (`IPAM_VERSION` constant), `config.php` (user-editable config), `assets/app.css` + `assets/app.js` (all CSS/JS), `upgrade.sh` (upgrade script). Runtime data lives in `data/` (gitignored): `data/ipam.sqlite` and `data/tmp/` (caches, temp uploads).

Other top-level directories: `testing/` (API test suite and sample datasets), `releases/` (release bundle builder), `docs/` (api.md, advanced-networking.md, configuration.md, install.md, oidc.md, scanning.md, security.md, upgrading.md; plus `_config.yml` and `index.md` for GitHub Pages), `tests/` (PHPUnit unit tests).

Dev tooling at the repo root (not deployed): `composer.json`, `composer.lock`, `phpstan.neon`, `phpstan-baseline.neon`, `.phpcs.xml`, `phpunit.xml`. Run `composer install` once to install tools into `vendor/` (gitignored).

---

## Page inventory

| File | Auth required | Role | Description |
|------|--------------|------|-------------|
| `login.php` | — | — | Local login form + OIDC button |
| `logout.php` | yes | any | Destroys session |
| `dashboard.php` | yes | any | Utilization summary, recent audit |
| `subnets.php` | yes | any/write | Subnet CRUD, hierarchy view |
| `addresses.php` | yes | any/write | Address CRUD per subnet |
| `search.php` | yes | any | Global IP/hostname/owner search |
| `audit.php` | yes | any | Audit log viewer |
| `unassigned.php` | yes | any | IPv4/IPv6 unassigned host tracker |
| `bulk_update.php` | yes | write | Bulk address update/delete |
| `dhcp_pool.php` | yes | write | DHCP pool reservation tool |
| `import_csv.php` | yes | admin | CSV import wizard |
| `sites.php` | yes | admin | Site management (supports parent site / region hierarchy) |
| `vlans.php` | yes | admin | VLAN management (first-class VLAN objects, linked to subnets via `vlan_fk`); includes VLAN ranges section (v2.4.0) |
| `vrfs.php` | yes | admin | VRF management (Virtual Routing and Forwarding; admin CRUD); includes BGP attributes (ASN, RT import/export, enforce_unique) (v2.4.0) |
| `aggregates.php` | yes | admin | Aggregate/supernet CRUD — RIR-assigned blocks, IPv4 and IPv6 (v2.4.0) |
| `pd_pools.php` | yes | admin | IPv6 Prefix Delegation pool management (RFC 3633); per-pool delegation list with subscriber linking and expiry (v2.4.0) |
| `contacts.php` | yes | admin | Contact management (first-class contact records linked to addresses via `owner_contact_id`) |
| `tags.php` | yes | admin | Tag management (colour-coded tags attached to subnets and addresses) |
| `users.php` | yes | admin | User management |
| `api_keys.php` | yes | admin | REST API key management |
| `webhooks.php` | yes | admin | Outbound webhook management: create, edit, toggle, delete, test-fire, delivery log, retry (v3.3.0) |
| `change_password.php` | yes | any | Account page: self-service password change, timezone preference, email change with verification (nav label: "Account") |
| `forgot_password.php` | — | — | Email-based password recovery: submit username/email, sends reset link |
| `reset_password.php` | — | — | Consumes password reset token, shows new-password form |
| `devices.php` | yes | admin | Device and interface management (CRUD, filter by type/site, interface sub-section) |
| `address_history.php` | yes | any | Per-address change history |
| `oidc_login.php` | — | — | Initiates OIDC auth flow (PKCE) |
| `oidc_callback.php` | — | — | Handles OIDC redirect callback |
| `api.php` | — | — | Stateless read-only REST API |
| `migrate.php` | CLI | — | Applies pending DB migrations |
| `tmp_cleanup.php` | CLI | — | Deletes stale temp files |
| `demo_reset.php` | CLI | — | Resets demo database to seed data (nightly cron) |
| `demo_seed.php` | CLI | — | Seeds demo data into database |
| `export_addresses.php` | yes | any/write | CSV export: single subnet (any role) or all subnets cross-subnet (write role) |
| `export_dns.php` | yes | any | BIND-format DNS zone file export (A/AAAA + PTR) for a single subnet (v2.4.0) |
| `export_dhcp.php` | yes | write | DHCP config export: ISC `dhcpd.conf` or Kea 2.x JSON; supports `?format=dhcpd|kea`, `?subnets=N,N,N`, `?preview=1`; audited as `export.dhcp`/`export.kea` (v3.4.0) |
| `ping_host.php` | yes | any | AJAX POST: ICMP probe a single address by ID; returns latency or down status (v2.4.0) |
| `cron.php` | CLI | — | Unified housekeeping + scanning cron runner: temp cleanup, audit pruning, history pruning, utilisation alerts, DB backup, network scanning of all due scan_schedules (v2.3.0) |
| `export_address_history.php` | yes | any | CSV export: per-address change history |
| `export_subnet_utilization.php` | yes | any | CSV export: subnet utilization summary across all subnets |
| export_audit.php, export_search.php, export_subnets.php, export_unassigned.php, export_import_report.php | yes | any | Other CSV export endpoints |
| `smtp_test.php` | yes | admin | AJAX POST (CSRF-required): sends a test email via configured SMTP/mail() to the logged-in admin's email; returns JSON `{ok, message, transport}` |
| `index.php` | — | — | Redirects to dashboard (if logged in) or login |
| `status.php` | — | — | Health check JSON endpoint (`{"status":"ok"}`) for load balancers/uptime monitors |
| `set_theme.php` | yes | any | AJAX POST: persists theme preference to `users.theme` |
| `db_tools.php` | yes | admin | Database SQL export and import |
| `demo_gate.php` | — | — | Demo mode bot challenge gate (pre-login) |

---

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

> **Current shipped version: v3.4.1.** The three subsections below (`Creating new data tables in post-v4.0.0 releases`, `Modifying the schema (multi-engine, from v2.9.0 onward)`, `Runtime dependencies`) describe **forward-looking policy** for unreleased versions. Do not apply them to work targeting v3.4.x or earlier. The rules become active on the version indicated in each heading; until then, treat them as design intent to preserve when new work approaches that version.

### Creating new data tables in post-v4.0.0 releases *(applies from v4.1.0+)*

Once v4.0.0 has shipped and the tenancy migration has run, every data table in the IPAM schema has a `tenant_id` column pointing at the `tenants` table. **Any migration in a release numbered greater than v4.0.0 that creates a new data table must include `tenant_id` in the `CREATE TABLE` statement from day one**, with `NOT NULL REFERENCES tenants(id) ON DELETE RESTRICT` and an index on `tenant_id`.

This rule exists because the v4.0.0 tenancy migration only backfills tables that existed at the time it ran. A table created in v4.1.0, v5.0.0, or any later release is outside that migration's reach and must carry its own tenant scoping from creation.

Pre-v4.0.0 migrations do not need to worry about this — they predate tenancy and are handled automatically by v4.0.0's runtime table enumeration (see #406 for implementation). The rule applies strictly to migrations whose version key sorts greater than v4.0.0 under natural-sort ordering.

**Exception:** tables that are explicitly global and not tenant-scoped (e.g. `users`, `tenants` itself, future `system_health` or similar) do not take `tenant_id`. When adding such a table, document in the migration closure why it is global, and update `docs/tenancy.md` to list it as an exception.

### Modifying the schema (multi-engine, from v2.9.0 onward) *(applies from v2.9.0+)*

Once v2.9.0 lands, the project ships three schema files that must stay in sync:

- `schema.sql` — SQLite (authoritative for fresh SQLite installs)
- `schema.mysql.sql` — MySQL 8.0+ (lands in v2.10.0)
- `schema.pgsql.sql` — PostgreSQL 14+ (lands in v2.11.0)

**When adding or modifying a table, column, index, or constraint:** update all three files in the same PR. No exceptions. The `SchemaParityTest` in CI will fail the build if the three files diverge on any structural element (table set, column set, type class, nullability, default kind, FK target, FK on-delete action). Type classes are normalised — `BLOB` / `VARBINARY(16)` / `BYTEA` all map to `"binary"`, so you do not need to match exact type names, only semantic equivalents.

Migrations remain the source of truth for schema evolution. The three schema files are a fast path for fresh installs; the migration chain is what existing installs run through. `MigrationTest` asserts that applying every migration from v1.0 on an empty database converges to the same structural state as the matching `schema.X.sql` file, so drift between "what migrations produce" and "what the schema file says" is also caught.

**Dialect helpers** (the `Dialect` abstraction from v2.9.0) are used inside migration closures for portable SQL — `$dialect->now()`, `$dialect->upsert()`, `$dialect->autoincrement()`, `$dialect->binary_type()`, etc. The schema files themselves are hand-written per engine and do not go through the dialect layer, because they are engine-specific by definition.

**Do not introduce a schema templating system.** This was evaluated during v2.9.0 planning and rejected: three plain SQL files are easier to review, easier to debug against a live database, and aligned with the project's vanilla-PHP ethos. The parity test plus migration-replay test is sufficient drift protection.

### Runtime dependencies *(applies from v2.9.0+)*

Adopted in v2.9.0. The project uses Composer-managed runtime dependencies under a narrow, curated policy. The goals are (1) to avoid hand-rolling security-sensitive network protocols and cryptography and (2) to preserve the "rsync and run" deployment story for end users.

**Deployment model:**

- `vendor/` is gitignored and never committed
- `releases/make_releases.sh` runs `composer install --no-dev --optimize-autoloader` against the working tree before building the tarball
- The tarball includes `vendor/` with all production deps pre-built
- End users extract the tarball and run — no `composer install` on the server, no network access to packagist required at install time
- `.htaccess` inside `vendor/` denies all web access to bundled library source
- Security advisories are tracked via `composer audit` in CI (scheduled nightly and on every PR)

**When a runtime dependency is acceptable:**

A new dep must meet **all** of the following criteria:

1. **Narrow purpose.** It solves a security-sensitive protocol or standards compliance problem where hand-rolling is error-prone. Canonical examples: SMTP, SAML, OAuth/OIDC/JWT verification, LDAP, TOTP.
2. **Mature.** >5 years of active maintenance, with visible security advisories and a track record of prompt response.
3. **Widely used.** Big enough user base that bugs get found by someone else first. "Thousands of GitHub stars" is not a metric but "used by WordPress, Drupal, Joomla" is.
4. **Minimal dependency tree.** Prefer libraries with zero or few transitive deps. A 300KB lib with 20 transitive deps is worse than a 500KB lib with none.
5. **Liberal license.** MIT, BSD, Apache 2.0. No GPL-family licenses (viral), no LGPL (complicated for bundled distribution).
6. **Maintainer justification.** Adding a new dep requires a PR that updates this section with the dep name, version constraint, purpose, and a one-paragraph justification explaining why vanilla PHP is a poor fit.

**Data-visualization primitives — the one carve-out.** Sparklines, time-series charts, gauges, heatmaps, and similar chart primitives MAY use a curated, vendored library if hand-rolling would require reinventing axis scaling, tick generation, DPI-aware canvas rendering, or responsive sizing. The bar is the same as the SMTP/SAML rule above: narrow purpose, mature, wide adoption, minimal deps, liberal license. Additional constraints specific to viz libraries: (a) **vanilla JS only** — no framework deps, no build step, (b) **self-hosted** under `Simple-PHP-IPAM/assets/vendor/` rather than Composer, (c) **single file** or small handful of files that can be copied in with no transitive deps, (d) **under ~50KB minified** — "lean" is a hard criterion here. Adding a viz library still requires a CLAUDE.md PR with the same justification as a Composer dep. The v3.8.0 UI rework is expected to vendor uPlot (~40KB, zero deps, MIT) for the dashboard time-series work; see #513.

**Design token libraries — the second carve-out.** Curated collections of CSS custom properties (spacing, sizing, typography, colour, shadows, borders, easings) MAY be vendored under the same rules as the viz carve-out above. This exists because maintaining a homegrown token scale across ~40 pages is error-prone and because proven token libraries encode years of accessibility and responsive-design work that would otherwise have to be reinvented. Constraints identical to viz libraries: **vanilla CSS only** (no Sass, no PostCSS, no build step), **self-hosted** under `assets/vendor/`, **single file or small handful**, **under ~50KB minified**, liberal license, mature, wide adoption. A vendored token library is a *source* of tokens — the project-specific overrides still live in `assets/app.css` and take precedence. v2.13.0 #506 vendors [Open Props](https://open-props.style) (~30KB, MIT, curated and maintained by Adam Argyle of Google Chrome DevRel) as the token source instead of hand-rolling `--space-*`, `--size-*`, `--radius-*`, `--font-size-*`, etc. The project's own brand tokens (`--link`, `--bg`, `--fg`, `--card`, IPAM-specific semantic classes) remain hand-authored in `assets/app.css` and consume Open Props primitives where useful.

**Important: neither carve-out re-opens the frontend-framework door.** Tailwind, Bootstrap, React, Vue, Svelte, Sass, PostCSS, and every similar tool remain explicitly forbidden. The carve-outs are narrow exceptions for **primitive data** (chart points, design tokens) — not for component libraries, utility-class vocabularies, or rendering engines. When in doubt: vanilla CSS and vanilla JS are the default, and a new dep needs a PR with a written justification.

**When a runtime dependency is NOT acceptable:**

- **IPAM business logic.** Subnet math, CIDR parsing, address allocation, audit logging, permission checks, etc. All of this is bespoke to the project and has no library equivalent worth pulling in.
- **UI and rendering.** All HTML is hand-authored PHP. No templating engines (no Twig/Blade/Latte/Smarty — no new syntax, no compile cache, no reserved directives), no frontend frameworks (no React/Vue/Svelte), no CSS preprocessors (no Sass/Less). **Exception:** shared `ipam_render($view, $props)` helpers that `require` a PHP file under `Simple-PHP-IPAM/views/*.php` with `extract($props)` ARE allowed and encouraged — they are ordinary PHP functions, not a DSL. The anti-pattern is a compiled syntax layer, not code reuse. Data-viz primitives covered by the carve-out above.
- **Simple utilities.** If it can be done in 20 lines of vanilla PHP, it should be done in 20 lines of vanilla PHP. Do not pull in a library for one function.
- **"Nice to have" conveniences.** Libraries that make code 5% cleaner are not worth the dep. The bar is "hand-rolling is meaningfully dangerous or expensive," not "this API is nicer."

**Current runtime dependency whitelist:**

| Package | Version | Purpose | Justified in |
|---|---|---|---|
| phpmailer/phpmailer | ^6.9 | Direct SMTP delivery (replaces native `mail()` when smtp.enabled=true). Hand-rolling SMTP+TLS+AUTH is error-prone and a security risk; PHPMailer has >5 years of active maintenance, is used by WordPress/Joomla/Drupal, has zero transitive runtime deps, and is licensed LGPL-2.1-or-later with an explicit bundling exception (PHPMailer FAQ). | #415, v3.1.0 |

Future candidates to be evaluated on a case-by-case basis as feature work surfaces them.

**Explicitly not adopted (deliberate choices):**

- No HTTP client library yet. `ext-curl` + careful wrapping is the current path for webhook dispatch (#399, v3.3.0). May revisit if curl wrapping proves painful at implementation time — Guzzle or symfony/http-client would be the likely candidates.
- No JWT / JWK library yet. The hand-rolled OIDC in `lib.php` works and is not being retrofitted on speculation. May revisit if a security-sensitive bug surfaces or if the RFC tracking burden becomes obviously not worth it.
- No JSON Schema validator. Custom fields (#313, v3.5.0) use a bespoke lightweight type system, not JSON Schema.
- No templating engine, no DI container, no service locator, no ORM. These are architectural departures that do not fit this project's philosophy.

### When to use classes vs functions

The project's application code is predominantly procedural — `lib.php` is a bag of top-level functions, and most pages are procedural PHP. The one deliberate exception is the `Dialect` family of classes under `dialects/` (introduced in v2.9.0), which encapsulates per-engine SQL differences.

**Classes are appropriate for polymorphic contracts with a small, closed set of implementations.** The `Dialect` interface with `SqliteDialect` / `MysqlDialect` / `PgsqlDialect` is the canonical example: the contract says "every DB engine must implement these methods with these signatures," and PHPStan level 9 enforces that at compile time. Without an interface, we would have to reinvent the same guarantee with array shape annotations or runtime dispatch, both of which are worse.

**Classes are not appropriate for utility functions, request handlers, or anything that would otherwise be a plain function.** Do not OO-ify `lib.php`. Do not wrap handlers in controller classes. Do not introduce a service locator or DI container. When in doubt, write a function.

**Namespaces are not used.** The project has zero namespaces today and a hand-rolled autoloader is not worth the complexity for the small number of classes we expect to introduce. Keep class names unambiguous (`Dialect`, `SqliteDialect`, etc.) and `require_once` explicitly in `init.php` or `lib.php`.

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

## OIDC authentication

Authorization Code + PKCE flow, pure PHP, no Composer packages, requires `openssl` extension.

Key functions in `lib.php`:
- `oidc_enabled(array $config): bool`
- `oidc_discovery(array $config): array` — fetches/caches `/.well-known/openid-configuration`
- `oidc_jwks(string $uri, bool $forceRefresh): array` — fetches/caches JWKS
- `oidc_verify_id_token(string $idToken, array $jwks, array $expect): array` — verifies RS256/384/512
- `jwk_rsa_to_pem(array $jwk): string` — DER SubjectPublicKeyInfo from JWK `n`/`e`, no ext-gmp

Cache files live in `data/tmp/` with 1-hour TTL. A single automatic cache-bust retry handles in-flight key rotation.

**Claim mapping on login:**
- `preferred_username` → username (fallback: email local-part, then `sub`)
- `name` → display name
- `email` → email field
- `sub` → `users.oidc_sub` (unique, partial index where NOT NULL)

**Auto-link order:** first tries `preferred_username` match, then `email`/username match, then provisions a new account.

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

### Nav structure
- Left: nav-links (Dashboard, Subnets, Addresses, Search, Audit, ⚙ Admin dropdown)
- Right: user dropdown (username + role badge → Theme, Password, Logout)
- Admin dropdown items: Sites, VLANs, VRFs, Tags, Contacts, Users, DHCP Pools, API Keys, Webhooks, Import CSV, Database Tools

---

## Audit logging

Call `audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details)` for every significant action. Convention for `$action`:

```
auth.login          auth.login_failed       auth.login_blocked
auth.oidc_login     auth.oidc_provision     auth.oidc_link       auth.oidc_failed
subnet.create       subnet.update           subnet.delete
address.create      address.update          address.delete
user.create         user.delete             user.toggle_active
user.set_role       user.reset_password     user.update_profile
user.oidc_link      user.oidc_unlink
site.create         site.update             site.delete
vlan.create         vlan.update             vlan.delete
vrf.create          vrf.update              vrf.delete
contact.create      contact.update          contact.delete
tag.create          tag.update              tag.delete
apikey.create       apikey.deactivate       apikey.activate      apikey.delete
dhcp_pool.reserve   dhcp_pool.clear
db.export           db.import               db.import_failed
export.*            import.*
scan.run            scan.schedule_create    scan.schedule_update   scan.schedule_delete
address.arp_import
```

---

## Scanner (v2.3.0)

### New tables
- **`scan_schedules`** — per-subnet scan configuration: `subnet_id` (UNIQUE FK), `method` (icmp|tcp|both), `tcp_port`, `interval_minutes`, `is_active`, `last_run_at`
- **`scan_results`** — one row per IP per scan run: `subnet_id`, `address_id` (nullable FK), `ip`, `method`, `is_up`, `latency_ms`, `scanned_at`

### New columns on `addresses`
- `last_seen_at TEXT` — datetime of last successful ping/TCP response
- `is_stale INTEGER NOT NULL DEFAULT 0` — set by `ipam_mark_stale_addresses()`

### New pages
- `scan_history.php` — read-only scan timeline; requires `require_login()` (no admin gate)
- `import_arp.php` — ARP import wizard; requires `require_write_access()` and CSRF
- `scan_run.php` — CLI-only scan runner; must guard `PHP_SAPI !== 'cli'` at top

### Scanner functions in lib.php
- `ipam_probe_icmp(string $ip, int $timeoutMs): ?int` — IP **must** be pre-validated via `normalize_ip()` before this call; uses `proc_open()` with system `ping`; OS-aware flags (`-W ms` macOS, `-W s` Linux)
- `ipam_probe_tcp(string $ip, int $port, int $timeoutMs): ?int` — IP and port **must** be validated; uses `fsockopen()`
- `ipam_scan_subnet(PDO $db, int $subnetId, string $method, ?int $tcpPort): array{scanned:int,up:int,down:int,skipped:int,stale_marked:int}` — enforces /28 cap for synchronous calls. Skips reserved IPs (network + IPv4 broadcast) via `ipam_subnet_reserved_bins()`; counted in `skipped`. (v2.5.0 / #363)
- `ipam_mark_stale_addresses(PDO $db, int $subnetId, int $missThreshold = 3): int` — runs in a transaction; audit logged if rows changed. Also skips reserved IPs so they never accrue a stale flag.
- `ipam_subnet_reserved_bins(PDO $db, int $subnetId): array{network:?string, broadcast:?string}` — binary IPs excluded from scan/stale passes. Nulls for IPv6, /31, /32.
- `ipam_compute_broadcast_bin(string $netBin, int $prefix): ?string` — pure IPv4 broadcast calculation; unit-tested in `tests/UtilTest.php`. Returns null for IPv6, /31, /32.
- `ipam_parse_arp_table(string $raw): array` — uses `filter_var(FILTER_VALIDATE_IP)` + MAC regex; never trusts raw input
- `ipam_apply_arp_import(PDO $db, array $entries, int $subnetId): array` — returns `['matched', 'updated']` stats

### Security patterns
- **IP injection guard**: raw `$_GET`/`$_POST` IPs must go through `normalize_ip()` before `proc_open()`/`fsockopen()`. Semgrep rule `ipam-proc-open-safe` enforces this.
- **CLI-only guard**: `scan_run.php` must `header('HTTP/1.1 403 Forbidden'); exit(1)` on non-CLI SAPI.
- **Sync cap**: `api_scan_run()` checks prefix ≤ 28 and returns HTTP 400 for larger subnets.

### Nav
Admin dropdown includes **ARP Import** (`import_arp.php`). Subnet rows include a **Scan History** action pill.

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

### Running the test suites

Two paths exist as of v2.5.2:

1. **Containerized** — a fully self-contained Dockerized Apache+PHP instance on `https://127.0.0.1:8443` seeded from `demo_seed.php`. No SSH, no dev-direct dependencies, no shared state. Use this for Playwright unless you need something that only a real deployment can test (real-IdP OIDC, the `timezone.spec.ts` remote-config flow). See `testing/playwright/README.md` for full instructions. In short:
   ```bash
   (cd testing/playwright && npm ci && npx playwright install chromium)
   bash testing/playwright/bootstrap-app.sh sqlite
   (cd testing/playwright && \
     IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
     npx playwright test)
   bash testing/playwright/teardown-app.sh
   ```
   The `.github/workflows/playwright.yml` CI job runs the same commands against the same image; a failing CI run can almost always be reproduced locally verbatim.

2. **Manual against dev-direct** — the shared test server at `https://dev-direct.seanmousseau.com:8343/claude/ipam`. Needed for `test_api.sh`, real-IdP OIDC scenarios, and any quick verification against a "real" install. The dev environment is shared, stateful, and has multiple footguns that have caused repeated false failures — read this whole section before invoking the suites. All commands assume cwd is the repo root.

See the dedicated subsections below: **Local PHP tools**, **Deploy**, **Pre-flight cleanup**, **Verify admin login**, **test_api.sh**, **Playwright (dev-direct)**.

#### Local PHP tools

These have no dev-server dependency. Must be green before deploying.

```bash
php -l Simple-PHP-IPAM/<changed-file>.php   # one per changed file
vendor/bin/phpstan analyse --memory-limit=1G   # default 128M crashes parallel workers
vendor/bin/phpcs
vendor/bin/phpunit
semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
```

#### Deploy

The API and Playwright suites run against the live `/claude/ipam/` deploy, not your local copy.

```bash
rsync -az --delete \
  --exclude='data/' --exclude='config.php' --exclude='.htaccess' \
  Simple-PHP-IPAM/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/claude/ipam/
ssh root@192.168.80.15 \
  "chown -R www-data:www-data /opt/container_data/dev.seanmousseau.com/html/claude/ipam/ && \
   docker exec dev_seanmousseau_com-apache-php-1 php /var/www/html/claude/ipam/migrate.php"
```

Always `chown -R www-data` after rsync — Apache cannot write the SQLite WAL otherwise.

#### Pre-flight cleanup

The suites are sensitive to leftover state from prior runs. Run this before every test invocation:

```bash
ssh root@192.168.80.15 \
  'docker exec dev_seanmousseau_com-apache-php-1 bash -c "pkill -f cron.php; pkill -f ping; true"'
ssh root@192.168.80.15 \
  "docker exec dev_seanmousseau_com-apache-php-1 sqlite3 /var/www/html/claude/ipam/data/ipam.sqlite \
   'DELETE FROM login_attempts;'"
```

- **Stale cron processes** can hold the SQLite write lock for hours (50+ active scan_schedules × 254 IPs each). Symptom: `database is locked` errors mid-suite, or `cron.php` hanging silently after Task 5.
- **login_attempts** is the rate limiter. Any prior failed login (wrong creds, hung CSP test) leaves rows that cause subsequent good logins to return *"Too many failed login attempts. Please try again later"*.

#### Verify admin login before running the suites

`~/.claude/dev-secrets.env` can drift from what's stored in the dev DB. Verify before assuming creds are wrong:

```bash
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  rm -f /tmp/c.txt; \
  curl -k -sS -u "$IPAM_BASIC_USER:$IPAM_BASIC_PASS" -c /tmp/c.txt -b /tmp/c.txt \
    https://dev-direct.seanmousseau.com:8343/claude/ipam/login.php > /tmp/login.html; \
  CSRF=$(grep -oE "name=\"csrf\" value=\"[^\"]*\"" /tmp/login.html | head -1 | sed "s/.*value=\"//;s/\"//"); \
  curl -k -sS -u "$IPAM_BASIC_USER:$IPAM_BASIC_PASS" -c /tmp/c.txt -b /tmp/c.txt -L \
    --data-urlencode "username=$IPAM_ADMIN_USER" \
    --data-urlencode "password=$IPAM_ADMIN_PASS" \
    --data-urlencode "csrf=$CSRF" \
    -w "%{url_effective}\n" -o /dev/null \
    https://dev-direct.seanmousseau.com:8343/claude/ipam/login.php'
```

Success: URL ends with `/dashboard.php`. Failure: still `/login.php`.

**Always use `--data-urlencode` for the password**, not `-d`. The default bootstrap password contains `!` which curl's `-d` does not encode, breaking the form post.

If creds are wrong, reset the DB password to match secrets via a small PHP tempfile (avoids shell-escaping the bcrypt `$` chars):

```bash
cat > /tmp/setpw.php <<'PHP'
<?php
$p = getenv('NEW_PASS');
$h = password_hash($p, PASSWORD_DEFAULT);
$db = new PDO('sqlite:/var/www/html/claude/ipam/data/ipam.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->prepare("UPDATE users SET password_hash=:h, is_active=1 WHERE username='admin'")
   ->execute([':h' => $h]);
$db->exec("DELETE FROM login_attempts");
echo password_verify($p, $h) ? "ok\n" : "FAIL\n";
PHP
scp /tmp/setpw.php root@192.168.80.15:/tmp/setpw.php
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  ssh root@192.168.80.15 "docker cp /tmp/setpw.php dev_seanmousseau_com-apache-php-1:/tmp/setpw.php && \
    docker exec -e NEW_PASS='\''$IPAM_ADMIN_PASS'\'' dev_seanmousseau_com-apache-php-1 php /tmp/setpw.php"'
```

#### test_api.sh

**Containerized (preferred since v2.9.0 / #451):**

```bash
bash testing/playwright/bootstrap-app.sh sqlite
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
bash testing/playwright/teardown-app.sh
```

No SSH, no shared state, no `BASIC_AUTH`. The same flow runs in CI's `api-tests` job under `playwright.yml`.

**Against dev-direct (use only when you need the shared deployment):**

```bash
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  BASIC_AUTH="$IPAM_BASIC_USER:$IPAM_BASIC_PASS" \
  SSH_HOST=root@192.168.80.15 \
  SSH_DB_PATH=/opt/container_data/dev.seanmousseau.com/html/claude/ipam/data/ipam.sqlite \
  bash testing/scripts/test_api.sh https://dev-direct.seanmousseau.com:8343/claude/ipam'
```

- **`BASIC_AUTH=user:pass`** is required for dev-direct. Exporting `IPAM_BASIC_USER`/`PASS` alone is not enough — the script reads the combined `BASIC_AUTH` env var. Forgetting this returns 401 on every POST/PUT.
- **`SSH_HOST` + `SSH_DB_PATH`** let the script auto-create an API key on the dev server. Without them you must pass `API_KEY=...`.
- Expect ~150 PASS, 0 FAIL, possibly 1 SKIP. A `database is locked` error means a stray cron is holding the lock — re-run pre-flight cleanup.

#### Playwright (dev-direct)

Prefer the containerized local path described at the top of this section. Use dev-direct only when you need something that only a real deployment can test (real-IdP OIDC, `timezone.spec.ts` which SSHes to patch remote config, etc.). Most timezone and OIDC specs self-skip against non-dev-direct targets via an `isDevServer()` guard.

```bash
bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
  IPAM_BASE_URL=https://dev-direct.seanmousseau.com:8343/claude/ipam \
  npx --prefix testing/playwright playwright test \
    --config=testing/playwright/playwright.config.ts > /tmp/pw.log 2>&1; \
  echo "exit=$?"; tail -40 /tmp/pw.log'
```

- The suite has 329 tests and takes 25–30 minutes. Always run in the background with `run_in_background: true` and a Monitor watching for `exit=` in the output file.
- **Do not pipe through `tail`** during the run — Playwright's reporter buffers and you lose all output until the end. Redirect to a file instead.
- Failures cluster: if the auth fixture fails, every dependent test fails. Always look at the **first failure**, not the count. The most common first-failure causes:
  1. Bad admin password (see *Verify admin login* above).
  2. `database is locked` from a stale cron — see pre-flight cleanup.
  3. Rate-limited from prior failed runs (`login_attempts` not cleared).
  4. The dev container is down or unreachable.
- Single-test debug: append `pages.spec.ts:42 --reporter=line` (or any `file.spec.ts:line`).

### Local gate — required before opening a PR

Starting in v2.5.2, the containerized Playwright harness runs automatically on every PR targeting `dev` or `main` via `.github/workflows/playwright.yml` (full suite + `.htaccess` subset, both against a fresh Dockerized Apache+PHP instance). That changes what has to be run locally.

**Required every push — must ALL be green before `git push`:**

**Step 1: Static analysis (fast, ~5s):**
```bash
php -l Simple-PHP-IPAM/<file>.php   # each changed file
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs
vendor/bin/phpunit
semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
```

**Step 2: Containerized test suite (required, ~2–10 min per driver):**

GitHub Action minutes are a finite paid resource. **Never push to `dev` or open a PR without a full local dockerized pass.** Run against every DB driver your changes could affect:

```bash
# SQLite (always required)
bash testing/playwright/bootstrap-app.sh sqlite
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --project=chromium)
bash testing/playwright/teardown-app.sh

# MySQL (required if changes touch migrations, schema, dialects, or multi-engine code)
bash testing/playwright/bootstrap-app.sh mysql
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --project=chromium)
bash testing/playwright/teardown-app.sh

# PostgreSQL (required if changes touch migrations, schema, dialects, or multi-engine code)
bash testing/playwright/bootstrap-app.sh pgsql
DOCKER_CONTAINER=ipam-pw-test bash testing/scripts/test_api.sh https://127.0.0.1:8443
(cd testing/playwright && \
  IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
  npx playwright test --project=chromium)
bash testing/playwright/teardown-app.sh
```

Do not push until **all drivers are green**. A failing CI run wastes Action minutes and creates PR noise.

**Manual dev-direct testing is only needed when:**
- You need `testing/scripts/test_api.sh` against a real deployment specifically (the containerized `DOCKER_CONTAINER=ipam-pw-test` path in CI covers regression on every PR via #451 — see the **test_api.sh** subsection above)
- You're verifying `timezone.spec.ts` (SSH-based remote config patching — skipped against containerized targets)
- You're testing real-IdP OIDC against a live provider
- You suspect a bug that only reproduces behind the reverse-proxy / shared Chrome stack

For those scenarios, follow the full dev-direct pipeline in the *Running the test suites* section above (Deploy → Pre-flight cleanup → Verify admin login → test_api.sh / Playwright dev-direct). For everything else, trust the CI run.

### Release workflow

The release bundle **ships with the release PR**, not in a follow-up PR. Feature code and the prebuilt tarball land in a single merge to `main`, and CodeRabbit reviews both in one cycle. The previous workflow (merge → build bundle → second archive PR → second CR review) was abandoned because the second CR pass on a binary tarball added nothing but latency.

#### Phase 1 — land the release content on `dev`

Normal feature PRs into `dev` through the session. Every feature/fix/docs commit goes through its own review cycle; nothing in this phase is release-specific.

#### Phase 2 — prepare the release on `dev`

Once everything that belongs in `vX.Y.Z` is on `dev`, do the following **in order** on `dev`:

1. **Documentation audit — required, not optional.** Review every file in `docs/` and update for any changed features, new config keys, new pages, renamed UI elements, or deprecated behaviour. Specific checks:
   - `docs/index.md` — features list and documentation table (add new guides, update feature bullets)
   - `docs/api.md` — new resources, changed parameters, updated examples
   - `docs/upgrading.md` — add a `### vX.Y.Z` section at the top of "Version-specific upgrade notes" listing new pages, features, breaking changes (if any), and nav/URL changes
   - `docs/configuration.md` — new settings, changed defaults
   - `docs/scanning.md`, `docs/smtp.md`, `docs/security.md`, `docs/oidc.md` — any area the release touched
   - Any new `docs/*.md` file for a new major feature (e.g. `docs/devices.md`)
   - **Stale version strings** — grep for the old version number: `grep -r "vX.(Y-1)" docs/`
2. **Update `CLAUDE.md`** — update the "Current shipped version" line near the top, the page inventory table if new pages were added, and any policy section affected by the release. This file is the agent's source of truth; keeping it current prevents bad decisions in future sessions.
3. **Update Memory MCP** — create or update the release entity `project:simple-php-ipam:roadmap:vX.Y.Z` with an observation recording what was built. Close out the previous roadmap entity with a "RELEASED" observation including the merge commit hash and bundle SHA256. Update the bare `project:simple-php-ipam` entity with the new current version if it has a version observation.
4. Update `testing/samples/large-db-sample/gen_large_db.php` and sample datasets if schema or data model changed.
5. Update `testing/scripts/test_api.sh` if API endpoints were added or changed.
6. Bump `Simple-PHP-IPAM/version.php` to `X.Y.Z`.
7. Bump the asset cache-buster `?v=X.Y.Z` in `page_header()` (`lib.php`) **and** `demo_gate.php:74-75` if any CSS/JS changed.
8. Update `CHANGELOG.md` with the full `## [X.Y.Z] - YYYY-MM-DD` entry and add the comparison link at the bottom.
9. Update `README.md` — replace the existing `## What's new in vA.B.C` block **in place** with a vX.Y.Z summary (README only ever carries the single latest release).
10. Run the full local gate until clean:
    ```bash
    for f in Simple-PHP-IPAM/<changed>.php; do php -l "$f"; done
    vendor/bin/phpstan analyse --memory-limit=1G
    vendor/bin/phpcs
    vendor/bin/phpunit
    semgrep --config=.semgrep/rules.yml --error Simple-PHP-IPAM/
    ```
11. Run the containerized Playwright harness end-to-end — not just the new spec. The full suite is the gate on release-readiness, not feature-completeness:
    ```bash
    bash testing/playwright/bootstrap-app.sh sqlite
    (cd testing/playwright && \
      IPAM_BASE_URL=https://127.0.0.1:8443 IPAM_ADMIN_USER=demo IPAM_ADMIN_PASS=demo \
      npx playwright test)
    bash testing/playwright/teardown-app.sh
    ```
    Only proceed if the full suite is green (pre-existing flaky tests excepted — log them in the PR description).
12. Run the dev-direct pipeline only if the release touches something the containerized harness can't cover (real-IdP OIDC, `test_api.sh` regressions, `timezone.spec.ts`).
13. **Clean `Simple-PHP-IPAM/data/` of any local test debris** before building — the release script excludes `*.sqlite` but **not** `*.sqlite.*.bak`, `demo_last_reset.txt`, or any other runtime artifacts. Stray files get baked into the tarball otherwise:
    ```bash
    rm -f Simple-PHP-IPAM/data/*.bak Simple-PHP-IPAM/data/demo_last_reset.txt
    ls Simple-PHP-IPAM/data/   # expect only ipam.sqlite, tmp/, possibly .htaccess
    ```
14. **Build the bundle:**
    ```bash
    ./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
    ```
    If `rsync` is not available, replicate the same logic with `cp -a` (copy dotfiles with `cp -a src/. dest/`), permission sanitisation, and `tar --numeric-owner --owner=0 --group=0`. Verify the tarball contains:
    - `upgrade.sh` with execute permission (`-rwxr-xr-x` in `tar tvf ...`)
    - Both `.htaccess` files (root and `data/`)
    - `settings.php`, `lib.php`, and any other newly-added PHP files
    - **No** `data/ipam.sqlite`, no `data/.db_initialized`, no `data/*.bak`
15. Move the bundle into its permanent home and commit:
    ```bash
    mkdir -p releases/ipam-X.Y.Z
    mv ipam-X.Y.Z/ipam-X.Y.Z.tar.gz ipam-X.Y.Z/SHA256SUMS releases/ipam-X.Y.Z/
    rmdir ipam-X.Y.Z 2>/dev/null || true
    git add releases/ipam-X.Y.Z/
    git commit -m "chore(release): add vX.Y.Z release bundle and SHA256SUMS"
    ```
16. Push `dev` and open the release PR `dev → main`. CodeRabbit + CI review the full changeset — code, docs, tests, and bundle — in one cycle.

#### Phase 3 — responding to review comments

If CodeRabbit or a human reviewer asks for a change on the open PR:

1. Make the code/doc/test fix on `dev` and re-run the relevant local gate checks.
2. **Rebuild the bundle so it reflects the fix.** This is non-negotiable: the merged `releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz` must match the final code state. Delete the old artifact, rerun the cleanup + build, commit as a new `chore(release): rebuild vX.Y.Z bundle after review fixes` commit (or amend, if the review round happens on a single unreviewed change):
   ```bash
   rm -rf releases/ipam-X.Y.Z ipam-X.Y.Z
   rm -f Simple-PHP-IPAM/data/*.bak Simple-PHP-IPAM/data/demo_last_reset.txt
   ./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
   mkdir -p releases/ipam-X.Y.Z
   mv ipam-X.Y.Z/ipam-X.Y.Z.tar.gz ipam-X.Y.Z/SHA256SUMS releases/ipam-X.Y.Z/
   rmdir ipam-X.Y.Z 2>/dev/null || true
   git add releases/ipam-X.Y.Z/
   git commit -m "chore(release): rebuild vX.Y.Z bundle after review fixes"
   ```
3. Push the same branch. CI re-runs; CodeRabbit re-reviews. Repeat until the code is clean.
4. **Never merge a PR where `releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz` predates the final code commit.** If you forget step 2 on the last round, catch it before merge: the `git log --name-only` should show the rebuild commit after every code-fix round.

#### Phase 4 — merge and release

1. Merge the PR once all checks are green.
2. Pull `main` locally.
3. Tag and push:
   ```bash
   git tag -a vX.Y.Z -m "vX.Y.Z - <one-line summary>"
   git push origin vX.Y.Z
   ```
4. Create the GitHub release, attaching the bundle from the in-tree path (or the local copy — both SHA match because the tree is now authoritative):
   ```bash
   gh release create vX.Y.Z \
     releases/ipam-X.Y.Z/ipam-X.Y.Z.tar.gz \
     releases/ipam-X.Y.Z/SHA256SUMS \
     --title "vX.Y.Z - <summary>" \
     --notes "<markdown body>"
   ```
5. Verify the release is live: `gh release view vX.Y.Z --json url,assets`.
6. **Deploy to demo site** (`demo.simplephpipam.com`, OpenLiteSpeed on `root@192.168.80.23`):
   ```bash
   rsync -az --delete \
     --exclude='data/' --exclude='config.php' \
     Simple-PHP-IPAM/ root@192.168.80.23:/usr/local/lsws/vhosts/demo.simplephpipam.com/html/
   ssh root@192.168.80.23 "chown -R nobody:nogroup /usr/local/lsws/vhosts/demo.simplephpipam.com/html/ && php /usr/local/lsws/vhosts/demo.simplephpipam.com/html/migrate.php"
   ```
   Verify: browse `https://demo.simplephpipam.com/` and confirm the version shown in the footer.
7. **Deploy to 4 testing instances** (`root@192.168.80.15`):
   ```bash
   for dir in ipam ipam-maria ipam-mysql ipam-postgres; do
     rsync -az --delete --exclude='data/' --exclude='config.php' \
       Simple-PHP-IPAM/ root@192.168.80.15:/opt/container_data/dev.seanmousseau.com/html/testing/$dir/
     ssh root@192.168.80.15 "chown -R www-data:www-data /opt/container_data/dev.seanmousseau.com/html/testing/$dir/ && docker exec dev_seanmousseau_com-apache-php-1 php /var/www/html/testing/$dir/migrate.php"
   done
   ```
8. **Update the marketing website** (`simplephpipam.com`) — required on every release, not optional. The version number appears in multiple places in `website/front-page.php`:
   - Hero badge: `vX.Y.Z — <tagline>`
   - Download button label (appears twice: hero CTA and quickstart section)
   - Quickstart `tar` command: `ipam-X.Y.Z.tar.gz`
   - Update or add feature cards for significant new features
   Then push and deploy:
   ```bash
   cd website/
   git add -A && git commit -m "chore(release): bump to vX.Y.Z, update feature cards"
   git push origin main
   cd ..
   rsync -az --delete \
     website/ root@192.168.80.23:/usr/local/lsws/vhosts/simplephpipam.com/html/wp-content/themes/simplephpipam/
   ssh root@192.168.80.23 "chown -R nobody:nogroup /usr/local/lsws/vhosts/simplephpipam.com/html/wp-content/themes/simplephpipam/"
   ```
9. **Close milestone issues** — close every GitHub issue in the vX.Y.Z milestone:
   ```bash
   gh issue close <N> --comment "Released in vX.Y.Z. See https://github.com/seanmousseau/Simple-PHP-IPAM/releases/tag/vX.Y.Z"
   ```
10. **Update Memory MCP** — write a final "RELEASED" observation to the `project:simple-php-ipam:roadmap:vX.Y.Z` entity with: merge commit hash, bundle SHA256, tag, deploy confirmation, and all issues closed. Also update the bare `project:simple-php-ipam` entity if it has a "current version" observation.

### Commit style
```
feat(scope): short description
fix(scope): short description
docs(scope): short description
```
Include `https://claude.ai/code/session_...` in commit body.

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
