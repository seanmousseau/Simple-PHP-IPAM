<img width="200" draggable="false" oncontextmenu="return false;" src="https://media.pupness.ca/file/seanmousseau/assets/logos/ipam/logo-readme.webp" alt="logo_readme" />

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No Composer, no npm, no external dependencies — just PHP and a web server.

---

## What's new in v2.10.0

**Experimental MySQL 8.0.22+ driver.** Opt-in via `db_driver = 'mysql'` in `config.php`; SQLite remains the default and is unchanged. The MySQL path is **beta** — a dismissible yellow banner shows on every admin page while it is active, the install docs carry a "MySQL (experimental)" section with a known-issues list, and the v2.10.0 release exists so real users can validate the driver against their data before v3.0.0 commits to the contract. SQLite users can upgrade with zero impact.

- **`MysqlDialect` on top of the v2.9.0 `Dialect` interface.** Every interface method implemented for MySQL — `NOW()`, `ON DUPLICATE KEY UPDATE`, `BIGINT AUTO_INCREMENT`, `VARBINARY(16)` for binary IPs (native length, never padded, same byte-wise sort order as SQLite), `utf8mb4_bin` for case-sensitive columns, `SIGNAL SQLSTATE '45000'` append-only triggers. Rejects MySQL < 8.0.22 at connect time with a clear error.
- **`schema.mysql.sql`** — authoritative 19-table MySQL schema mirroring the fully-migrated SQLite state. Ends with a pre-seed of all 28 historical migration rows so `apply_migrations()` is a no-op on fresh MySQL installs and the SQLite-only historical closures never execute. Installer picks the schema file by `db_driver`.
- **Cross-engine SQL sweep.** The last SQLite-specific idioms in `lib.php`, `dashboard.php`, and the search endpoints were routed through the `Dialect` abstraction or rewritten in portable SQL: `datetime('now')`, `BEGIN EXCLUSIVE`, `sqlite_sequence`, `PRAGMA foreign_keys`, `PRAGMA table_info`, `IS :param`, `INSERT OR IGNORE`, `strftime()`, `LIKE ... ESCAPE '\\'`, and reused `:q` placeholders across LIKE-OR chains (MySQL native prepares reject reused named placeholders). A new suite of 10 proactive Semgrep rules catches any regression.
- **CI coverage.** PHP QA and nightly Playwright both gain a MySQL 8.0 matrix slot (`fail-fast: false`) running the full suite — PHPStan, PHPCS, PHPUnit, Semgrep, `test_api.sh`, and the 329-test Playwright suite — against a containerized `mysql:8.0` service. A failing MySQL slot never masks a SQLite slot or vice-versa.
- **Dev ergonomics.** New top-level `Makefile` with a `gate` target that runs the full local PHP gate (lint, PHPStan, PHPCS, PHPUnit, Semgrep) in one command.

**All v2.9.x features are still present** — Dialect abstraction, BLOB affinity normalization, Composer runtime-dep infrastructure, the global `E_WARNING` handler, graceful 413 on oversized DB imports, and everything from v2.8.0 and earlier. See [CHANGELOG.md](CHANGELOG.md) for the full list.

See [CHANGELOG.md](CHANGELOG.md) for full release history, [docs/install.md](docs/install.md#mysql-experimental) for the MySQL setup section, and [docs/upgrading.md](docs/upgrading.md) for upgrade notes.

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
