<picture>
  <source media="(prefers-color-scheme: dark)" srcset="logo/banner-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset="logo/banner.svg">
  <img src="logo/banner.svg" alt="Simple PHP IPAM" width="320">
</picture>

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite — or **MySQL 8.0.29+**, **MariaDB 10.11+**, or **PostgreSQL 14+**. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No npm, no build step — just PHP and a web server. Runtime Composer dependencies are pre-bundled into the release tarball, so end users still deploy by extracting and running.

---

## What's new in v3.28.0

DR + security stabilization release. One schema migration (runs automatically) and one behavior change worth knowing about — the legacy `app_secret`-based backup-encryption *write* path was removed.

- **Concurrency hardening.** The per-IP rate-limit audit dampener (#1143) and the backup-notification cooldown state (#1159) were racy — a TOCTOU on the dampener and a lost-update on JSON cooldown blobs. Both now use small dedicated tables with atomic single-row writes (`rate_limit_dampener`, `backup_state`). The Health page's per-destination connectivity readout, which had been silently broken, now reports real status.
- **Step-up coverage sweep.** Webhook create/edit/delete (#1156), SMTP / backup-notification-recipient settings (#1157), and custom field definition changes (#1158) now require a fresh step-up proof under your `auth.step_up.*` policy.
- **Surgical security fixes** carried over from the v3.27.x verification passes: restore upload now caps decompressed size against gzip bombs (#1149), the session-fallback API path re-checks `users.is_active` (#1151), webhook dispatch failures are logged instead of swallowed (#1150), and `ipam_db_init()` won't skip migrations on a half-bootstrapped DB (#1175).
- **`app_secret` backup encryption is being retired** (#1164/#1165). Encrypted scheduled backups now require a backup vault key (Stored mode) or a passphrase (Transitory mode); an `app_secret`-only install fails preflight with an actionable message. The in-app reader for legacy `app_secret`-encrypted archives is retained through the whole v3.x line — **v4.0.0 removes it (cold break)**, with `tools/decrypt-backup.php` as the long-term escape hatch. See `docs/upgrading.md §v3.28.0` for the migration path. The Backups → Destinations and Restore tabs carry a retirement banner.
- **CSP regression guard in CI** (#906) — fails the build if any page introduces an inline `<script>`/`<style>` block that the app's Content-Security-Policy would silently break.

[Full changelog →](CHANGELOG.md)

---

## Features

### Core IPAM
- Manage **IPv4 and IPv6 subnets** (CIDR notation) with validation and normalization
- Manage **address records** with hostname, owner, status (`used` / `reserved` / `free`), MAC address, expiry date, and notes
- Subnet **hierarchy view** — parent/child nesting with expand/collapse for both IPv4 and IPv6
- **Subnet overlap detection** — warns when a new subnet nests inside or contains existing ones
- **Unassigned IP tracking** — lists assignable IPs (IPv4 and IPv6, capped at 256) with a quick-add form
- Correct IP sorting using packed binary storage (`ip_bin`, `network_bin`)
- **Auto-reserve** network/broadcast/gateway IPs on subnet create (configurable)
- **Address expiry** — per-address lease expiry date; expired rows highlighted; bulk extend or clear

### Search & Productivity
- **Dashboard** — utilization bars for top subnets with inline sparklines, per-site breakdown, expiring-address widget, recent audit activity
- **Global search** across IP / hostname / owner / note with filters for status, site, IP version, and tag
- **Bulk update** — update hostname / owner / status / note / MAC / expiry across multiple addresses at once, with bulk delete and bulk expiry extension
- **CSV import wizard** (admin-only) — upload, map columns, dry-run preview, then apply; supports auto-create missing subnets
- **CSV exports** — addresses per subnet, all addresses cross-subnet, subnet utilization summary, utilization trend history, address change history, search results, unassigned IPs, audit log, DNS zone

### Organisation
- **Sites** — group subnets by location or network segment; supports **site hierarchy** (region → site)
- **VLANs** — first-class VLAN objects linked to subnets; admin CRUD at `/vlans.php`; VLAN ranges
- **VRFs** — Virtual Routing and Forwarding groups with Route Distinguisher, BGP ASN, and RT import/export; subnets are scoped per-VRF
- **Aggregates** — RIR-assigned supernet blocks (IPv4 and IPv6) for top-level capacity planning
- **IPv6 Prefix Delegation pools** — per-pool delegation list with subscriber linking and expiry (RFC 3633)
- **Contacts** — first-class contact records (name, email, phone, org) linked to addresses and subnets with role labels; multi-contact assignments per site/subnet
- **Tags** — colour-coded labels attachable to subnets and addresses; filter by tag in search and API
- Child subnets automatically **inherit the site** of their enclosing parent

### Network Scanning
- **ICMP and TCP scanning** — per-subnet scan schedules with configurable interval; results stored with latency
- **Stale address detection** — marks addresses as stale after configurable consecutive missed pings
- **ARP import** — paste or upload ARP table output; auto-matches and updates MAC/last-seen on existing addresses
- **Scan history** — timeline view of scan results per subnet

### Alerting
- **Utilization alerts** — configurable warn/critical thresholds with 24-hour per-subnet cooldown
- **Direct SMTP delivery** — PHPMailer with TLS/STARTTLS; falls back to `mail()` when not configured
- **Per-subnet alert toggle** — disable alerts for individual subnets; bell-slash badge in subnet list
- **Bulk enable/disable** — enable or disable alerts for all subnets in one click (admin-only)

### Utilization History
- **Automatic daily snapshots** — captured during housekeeping; configurable retention period
- **Inline sparklines** — SVG trend lines on the dashboard top-subnets table (no JS library)
- **Reports page** — full history table with date range filter and per-subnet trend charts (Admin → Reports)
- **CSV export** — `export_utilization_history.php` with optional subnet and date range filters

### Security & Access Control
- HTTPS enforced at the application layer; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- **Login rate limiting** — IP-based lockout after repeated failed attempts
- **Session idle timeout** — automatic logout after configurable inactivity period
- CSRF protection on all POST requests; PDO prepared statements throughout
- **RBAC roles:** `admin` (full access), `readonly`
- Append-only audit log enforced with SQLite triggers
- **OIDC SSO** — Authorization Code + PKCE in pure PHP; auto-provision and auto-link; optional `disable_local_login`
- **User management** — name/email fields, per-user timezone, per-user enable/disable, delete, manual SSO linking

### Administration
- **Settings UI** — all configuration in Admin → Settings; `config.php` reduced to a bootstrap stub
- **Database Tools** — one-click SQL export, SQL import with pre-import backup, backup status panel
- **Database migration tool** (`migrate_db.php`) — CLI utility for migrating between engines (all 6 direction pairs: SQLite ↔ MySQL ↔ PostgreSQL)
- **Automatic backups** — configurable daily/weekly snapshots with retention pruning
- **Responsive GUI** — sticky headers, slide-in form drawer, mobile hamburger nav, sortable columns, breadcrumbs
- **Per-user timezone preference** — timestamps displayed in each user's local timezone
- **Health check endpoint** — unauthenticated `GET /status.php` returns JSON status and version
- **Audit log retention** — configurable pruning of old audit entries during scheduled housekeeping
- **DHCP pool reservation** — interactive tool for allocating DHCP ranges within a subnet

### REST API
- JSON REST API (`api.php`) authenticated with `Authorization: Bearer <key>` header
- **Read-only API keys** — admin-toggleable flag restricts a key to GET-only access
- Resources: subnets, addresses, sites, VLANs, VRFs, contacts, address history, search, audit log, unassigned IPs, utilization snapshots
- Filters: `?tag=`, `?vlan_id=`, `?parent_id=`, `?expired=1`, `?expiring_days=N`, `?site_id=`, `?ip_version=`, `?subnet_id=`, `?days=`
- Write: create / update / delete subnets, addresses, sites, and VLANs (POST / PUT / DELETE)
- **Bulk write** — `POST ?resource=addresses&bulk=1` / `POST ?resource=subnets&bulk=1` with JSON array; partial success returns HTTP 207
- Timestamps in API responses are always UTC

---

## Demo Site

- https://demo.simplephpipam.com/
- Username: demo
- Password: demo

---

## Requirements

| Requirement | Details |
|---|---|
| **PHP** | 8.2 or later (8.3 recommended) |
| **PHP extensions** | `pdo`, `openssl`, plus one of `pdo_sqlite` (default), `pdo_mysql`, or `pdo_pgsql` |
| **Web server** | Apache, OpenLiteSpeed, nginx, or Caddy (see [install.md](docs/install.md)) |
| **Database** | SQLite 3.x (default), **or** MySQL 8.0.29+ / MariaDB 10.11+ (opt-in via `db_driver = 'mysql'`), **or** PostgreSQL 14+ (opt-in via `db_driver = 'pgsql'`) — see [install.md](docs/install.md) |
| **HTTPS** | Required — HTTP is redirected to HTTPS |
| **Writable `data/` dir** | Web server user needs read and write access to `data/` (SQLite only) |

---

## Quick start

1. Download and extract a [release](../../releases), or clone this repo
2. Point your web server document root at the `Simple-PHP-IPAM/` directory
3. Open the site in a browser; the bootstrap admin account is created on first load
4. Log in and configure settings at Admin → Settings

See the [Installation guide](docs/install.md) for full web server configuration examples (Apache, nginx, Caddy), file permission setup, and first-login steps.

---

## Documentation

| Guide | Description |
|---|---|
| [Installation](docs/install.md) | Requirements, web server setup, file permissions, first login |
| [Configuration](docs/configuration.md) | Settings UI and all configuration keys explained |
| [Upgrading](docs/upgrading.md) | Using `upgrade.sh`, CLI migration utilities, v3.0 breaking changes |
| [Security](docs/security.md) | HTTPS, rate limiting, session hardening, audit log, API keys |
| [REST API](docs/api.md) | API authentication, endpoints, pagination, examples |
| [OIDC Authentication](docs/oidc.md) | SSO setup, IdP examples, user provisioning |
| [SMTP Configuration](docs/smtp.md) | SMTP setup, Gmail, Office 365, AWS SES examples |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.
