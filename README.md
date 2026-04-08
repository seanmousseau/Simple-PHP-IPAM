[logo]: https://github.com/seanmousseau/Simple-PHP-IPAM/blob/main/logo/logo.webp

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No Composer, no npm, no external dependencies — just PHP and a web server.

---

## Features

### Core IPAM
- Manage **IPv4 and IPv6 subnets** (CIDR notation) with validation and normalization
- Manage **address records** with hostname, owner, status (`used` / `reserved` / `free`), and notes
- Subnet **hierarchy view** — parent/child nesting with expand/collapse for both IPv4 and IPv6
- **Subnet overlap detection** — warns when a new subnet nests inside or contains existing ones
- IPv4 **unassigned host tracking** — lists assignable IPs with no address record and a quick-add form
- Correct IP sorting using packed binary storage (`ip_bin`, `network_bin`)

### Search & Productivity
- **Dashboard** — utilization bars for top subnets, per-site address breakdown, recent audit activity
- **Global search** across IP / hostname / owner / note with filters for status, site, and IP version
- **Bulk update** — update hostname / owner / status / note across multiple addresses at once, with bulk delete
- **CSV import wizard** (admin-only) — upload, map columns, dry-run preview, then apply; supports auto-create missing subnets

### Organisation
- **Sites** — group subnets by location or network segment
- Child subnets automatically **inherit the site** of their enclosing parent

### Security & Access Control
- HTTPS enforced at the application layer; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- **Login rate limiting** — IP-based lockout after repeated failed attempts
- **Session idle timeout** — automatic logout after configurable inactivity period
- CSRF protection on all POST requests; PDO prepared statements throughout
- **RBAC roles:** `admin` (full access), `netops` (write access, no user/key management), `readonly`
- Append-only audit log enforced with SQLite triggers
- **OIDC SSO** — Authorization Code + PKCE in pure PHP; auto-provision and auto-link; optional `disable_local_login`
- **User management** — name/email fields, per-user enable/disable, delete, manual SSO linking

### Administration
- **Database Tools** — one-click SQL export, SQL import with pre-import backup, manual backup trigger, backup status panel
- **Automatic backups** — configurable daily/weekly SQLite snapshots with retention pruning
- **Config auto-population** — missing config keys appended with defaults on boot; admin notice on first load
- **Mobile-optimized GUI** — responsive layout works on phones and tablets at 375 px and 768 px
- **Health check endpoint** — unauthenticated `GET /status.php` returns JSON status and version for uptime monitors and container health checks
- **Audit log retention** — configurable pruning of old audit entries during scheduled housekeeping

### REST API
- JSON REST API (`api.php`) authenticated with API keys
- Read: subnets, addresses (paginated + filterable), sites, address history
- Write: create / update / delete subnets and addresses (POST / PUT / DELETE)

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
