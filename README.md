<img width="200" draggable="false" oncontextmenu="return false;" src="https://media.seanmousseau.com/file/seanmousseau/assets/logos/ipam/logo-readme.webp" alt="logo_readme" />

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite — or **experimental MySQL 8.0.29+** (v2.10.0) / **experimental MariaDB 10.11+** (v2.12.0) / **experimental PostgreSQL 14+** (v2.11.0). Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No npm, no build step — just PHP and a web server. Runtime Composer dependencies are pre-bundled into the release tarball, so end users still deploy by extracting and running.

---

## What's new in v3.0.0

**Breaking release — multi-engine database support is now stable.**

- **MySQL & PostgreSQL promoted to stable (#392).** MySQL 8.0+ and PostgreSQL 14+ are fully supported first-class engines. Experimental banners removed.
- **`migrate_db.php` (#390).** New CLI tool for migrating between database engines — all 6 direction pairs (SQLite ↔ MySQL ↔ PostgreSQL), batched copies, binary blob round-trip, row count verification.
- **`config.php` reduced to bootstrap stub (#341).** All settings now live in the database, managed through Admin → Settings. Upgrade migration auto-imports your values.
- **Multi-contact assignments (#563).** Assign multiple contacts with role labels to sites and subnets.
- **`?api_key=` auth removed (#340).** Use `Authorization: Bearer` header instead.
- **`upgrade.sh` driver migration (#393).** Interactive prompt to migrate to MySQL/PostgreSQL during upgrade.

See [CHANGELOG.md](CHANGELOG.md) for full release history and [docs/upgrading.md](docs/upgrading.md#v300) for the upgrade guide.

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
| **PHP extensions** | `pdo`, `openssl`, plus one of `pdo_sqlite` (default), `pdo_mysql` (experimental, v2.10.0+), or `pdo_pgsql` (experimental, v2.11.0+) |
| **Web server** | Apache, OpenLiteSpeed, nginx, or Caddy (see [install.md](docs/install.md)) |
| **Database** | SQLite 3.x (default), **or** MySQL 8.0.29+ (experimental, opt-in via `db_driver = 'mysql'`; see [install.md#mysql-experimental](docs/install.md#mysql-experimental)), **or** PostgreSQL 14+ (experimental, opt-in via `db_driver = 'pgsql'`; see [install.md#postgresql-experimental](docs/install.md#postgresql-experimental)) |
| **HTTPS** | Required — HTTP is redirected to HTTPS |
| **Writable `data/` dir** | Web server user requires read/write access to `data/` (SQLite only — MySQL/Postgres store their own data files) |

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
