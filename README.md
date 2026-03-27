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

### Security & Access Control
- HTTPS enforced at the application layer; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- **Login rate limiting** — IP-based lockout after repeated failed attempts
- **Session idle timeout** — automatic logout after configurable inactivity period
- CSRF protection on all POST requests; PDO prepared statements throughout
- **RBAC roles:** `admin` (full access + user management) and `readonly`
- Append-only audit log enforced with SQLite triggers

### REST API
- Read-only JSON API (`api.php`) authenticated with API keys
- Resources: subnets, addresses (paginated + filterable), sites

---

## Requirements

| Requirement | Details |
|---|---|
| **PHP** | 8.2 or later (8.3 recommended) |
| **PHP extensions** | `pdo`, `pdo_sqlite`, `openssl` |
| **Web server** | Apache or LiteSpeed (`.htaccess` required); Nginx needs manual rule translation |
| **SQLite** | 3.x via PDO SQLite |
| **HTTPS** | Required — HTTP is redirected to HTTPS |
| **Writable `data/` dir** | Web server user needs read/write access to `data/` |

---

## Quick start

1. Download and extract a [release](../../releases), or clone this repo
2. Point your web server document root at the `Simple-PHP-IPAM/` directory
3. Edit `config.php` — **change the default admin password**
4. Open the site in a browser; you will be redirected to the login page

See the [Installation guide](docs/install.md) for full web server configuration examples (Apache, Nginx), file permission setup, and first-login steps.

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

### What's new in 0.12

**User menu** — Username and role moved to the far-right of the nav bar; Password, Logout, and Theme toggle consolidated into a user dropdown.

**OIDC Authentication** — Authorization Code + PKCE single sign-on in pure PHP. Supports any compliant IdP (Google, Azure AD, Okta, Keycloak, etc.). Auto-provisioning optional. Configured entirely in `config.php`.

### What's new in 0.11

**Security Hardening** — Login rate limiting (IP-based lockout, configurable attempts/window), session idle timeout with redirect on expiry, new `login_max_attempts` / `login_lockout_seconds` / `session_idle_seconds` config keys.

**Nav & UX Polish** — Admin links (Sites, Users, API Keys, Import CSV) collapsed into a single ⚙ Admin dropdown. Two theme buttons replaced with a single cycle button (System → Light → Dark).

**REST API** — New read-only `api.php` with Bearer token auth. Resources: subnets, addresses (paginated), sites. Admin UI to generate, deactivate, and delete API keys.

### What's new in 0.10

**Exports** — CSV export for addresses, search results, audit log, unassigned IPs, and import reports.

**Import safety** — Dry-run plan saved before applying; row-level report; duplicate detection; CIDR validation; conflict detection between dry-run and apply.

**Subnet overlap detection** — Informational parent/child warnings on create, update, and import dry-run.

**Dashboard & Search** — Rebuilt dashboard with utilization bars and per-site breakdown. Search gains site, IP version, and subnet filters.
