<img width="200" draggable="false" oncontextmenu="return false;" src="https://media.pupness.ca/file/seanmousseau/assets/logos/ipam/logo-readme.webp" alt="logo_readme" />

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No Composer, no npm, no external dependencies — just PHP and a web server.

---

## What's new in v2.9.0

Driver-abstraction foundation release. Runtime behaviour is unchanged for end users — same SQLite, same vanilla deployment, same `upgrade.sh` flow. The codebase is now ready for multi-engine SQL (v2.10.0 MySQL, v2.11.0 Postgres), curated Composer runtime deps, and tiered CI.

- **`Dialect` interface + `SqliteDialect`** — new `dialects/` subdirectory with a stateless 8-method contract (`now`, `upsert`, `autoincrement`, `binary_type`, `case_sensitive_collation`, `append_only_trigger`, `pragma_foreign_keys`, `driver_name`). Every SQL call site in `lib.php` and the page handlers now routes through this layer, so v2.10.0 / v2.11.0 drop in their implementations without touching application code. 16 new unit tests including an end-to-end DDL check against an in-memory SQLite database.
- **BLOB affinity normalization for binary IP columns** — the `2.9.0-blob-affinity` migration rewrites every `subnets.network_bin` and `addresses.ip_bin` row on existing SQLite installs from TEXT to BLOB affinity. Pre-v2.9.0 the project used `PDO::PARAM_STR` for binary binds, which SQLite's loose typing stored with TEXT affinity — bytes were correct but `ORDER BY ip_bin` would break the moment new rows arrived bound as BLOB. The migration is idempotent and runs automatically during `upgrade.sh`. New `ipam_bind_binary()` helper uses `PDO::PARAM_LOB` unconditionally on every driver.
- **Composer runtime-dependency infrastructure** — `composer.json` gains a (currently empty) `require` block, `releases/make_releases.sh` installs production deps into a scratch dir and bundles the autoloader into the release tarball, `init.php` conditionally loads `vendor/autoload.php`, CI gains `composer audit` as a build gate. End-user deployment story is unchanged — still tarball + extract, no Composer needed on the target server. The first actual runtime dep lands in a future release; this PR is scaffolding.
- **CI tier restructure** — `php-qa.yml` is now the Tier 1 fast-per-commit workflow with a `fail-fast: false` engine matrix (SQLite slot now, MySQL/Postgres placeholders). Composer cache via `actions/cache@v4`, concurrency cancel-in-progress, and a **fix for a real gap**: PHP QA now runs on PRs targeting `dev`, not just `main`. New `docs/ci.md` documents all four tiers.
- **`test_api.sh` containerized backend** — the 160-assertion REST regression suite now runs in CI on every PR via the containerized Playwright harness. New `DOCKER_CONTAINER` env var routes auto-API-key creation through `docker exec` instead of ssh-to-dev-direct.
- **`oidc.default_role` is now a dropdown** on the Settings page — picks from {Read-only, Administrator} instead of free-form string. A typo previously broke OIDC auto-provisioning silently.

See [CHANGELOG.md](CHANGELOG.md) for full release history, [docs/upgrading.md](docs/upgrading.md) for upgrade notes, and [docs/configuration.md](docs/configuration.md) for the settings transition plan.

---

## Features

### Core IPAM
- Manage **IPv4 and IPv6 subnets** (CIDR notation) with validation and normalization
- Manage **address records** with hostname, owner, status (`used` / `reserved` / `free`), and notes
- Subnet **hierarchy view** — parent/child nesting with expand/collapse for both IPv4 and IPv6
- **Subnet overlap detection** — warns when a new subnet nests inside or contains existing ones
- **Unassigned IP tracking** — lists assignable IPs (IPv4 and IPv6, capped at 256) with no address record and a quick-add form
- Correct IP sorting using packed binary storage (`ip_bin`, `network_bin`)
- **Auto-reserve** network/broadcast/gateway IPs on subnet create (configurable)

### Search & Productivity
- **Dashboard** — utilization bars for top subnets, per-site address breakdown, recent audit activity
- **Global search** across IP / hostname / owner / note with filters for status, site, IP version, and tag
- **Bulk update** — update hostname / owner / status / note / MAC / expiry across multiple addresses at once, with bulk delete
- **CSV import wizard** (admin-only) — upload, map columns, dry-run preview, then apply; supports auto-create missing subnets
- **CSV exports** — addresses per subnet, all addresses cross-subnet, subnet utilization summary, address change history, search results, unassigned IPs, audit log

### Organisation
- **Sites** — group subnets by location or network segment; supports **site hierarchy** (region → site)
- **VLANs** — first-class VLAN objects linked to subnets; admin CRUD at `/vlans.php`
- **Tags** — colour-coded labels attachable to subnets and addresses; filter by tag in search and API
- Child subnets automatically **inherit the site** of their enclosing parent

### Security & Access Control
- HTTPS enforced at the application layer; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- **Login rate limiting** — IP-based lockout after repeated failed attempts
- **Session idle timeout** — automatic logout after configurable inactivity period
- CSRF protection on all POST requests; PDO prepared statements throughout
- **RBAC roles:** `admin` (full access), `readonly`
- Append-only audit log enforced with SQLite triggers
- **OIDC SSO** — Authorization Code + PKCE in pure PHP; auto-provision and auto-link; optional `disable_local_login`
- **User management** — name/email fields, per-user enable/disable, delete, manual SSO linking

### Administration
- **Database Tools** — one-click SQL export, SQL import with pre-import backup, manual backup trigger, backup status panel
- **Automatic backups** — configurable daily/weekly SQLite snapshots with retention pruning
- **Config auto-population** — missing config keys appended with defaults on boot; admin notice on first load
- **Responsive GUI** — sticky headers, slide-in form drawer, mobile hamburger nav, sortable columns, breadcrumbs
- **Email utilization alerts** — configurable warn/critical thresholds with 24-hour per-subnet cooldown
- **Health check endpoint** — unauthenticated `GET /status.php` returns JSON status and version for uptime monitors and container health checks
- **Audit log retention** — configurable pruning of old audit entries during scheduled housekeeping

### REST API
- JSON REST API (`api.php`) authenticated with API keys
- **Read-only API keys** — admin-toggleable flag restricts a key to GET-only access; write attempts return 403
- **API key descriptions** — optional free-text field to document what each key is for
- Read: subnets (with `vlan_name`, `tags[]`), addresses (with `tags[]`), sites (with `parent_id`), VLANs, address history, search, audit log, unassigned IPs
- Filters: `?tag=`, `?vlan_id=`, `?parent_id=`, `?expired=1`, `?site_id=`, `?ip_version=`
- Write: create / update / delete subnets, addresses, sites, and VLANs (POST / PUT / DELETE)
- **Bulk write** — `POST ?resource=addresses&bulk=1` / `POST ?resource=subnets&bulk=1` with JSON array; partial success returns HTTP 207

---

## Demo Site

- https://dev.seanmousseau.com/ipam-demo/
- Username: demo
- Password: demo

---

## Requirements

| Requirement | Details |
|---|---|
| **PHP** | 8.2 or later (8.3 recommended) |
| **PHP extensions** | `pdo`, `pdo_sqlite`, `openssl` |
| **Web server** | Apache, LiteSpeed, nginx, or Caddy (see [install.md](docs/install.md)) |
| **SQLite** | 3.x via PDO SQLite |
| **HTTPS** | Required — HTTP is redirected to HTTPS |
| **Writable `data/` dir** | Web server user needs read/write access to `data/` |

---

## Quick start

1. Download and extract a [release](../../releases), or clone this repo
2. Point your web server document root at the `Simple-PHP-IPAM/` directory
3. Edit `config.php` — **change the default admin password**
4. Open the site in a browser; you will be redirected to the login page

See the [Installation guide](docs/install.md) for full web server configuration examples (Apache, nginx, Caddy), file permission setup, and first-login steps.

---

## Documentation

| Guide | Description |
|---|---|
| [Installation](docs/install.md) | Requirements, web server setup, file permissions, first login |
| [Configuration](docs/configuration.md) | All `config.php` settings explained |
| [Upgrading](docs/upgrading.md) | Using `upgrade.sh`, CLI migration utilities |
| [Security](docs/security.md) | HTTPS, rate limiting, session hardening, audit log, API keys |
| [REST API](docs/api.md) | API authentication, endpoints, pagination, examples |
| [OIDC Authentication](docs/oidc.md) | SSO setup, IdP examples, user provisioning |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.
