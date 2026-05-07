<picture>
  <source media="(prefers-color-scheme: dark)" srcset="logo/banner-dark.svg">
  <source media="(prefers-color-scheme: light)" srcset="logo/banner.svg">
  <img src="logo/banner.svg" alt="Simple PHP IPAM" width="320">
</picture>

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite — or **MySQL 8.0.29+**, **MariaDB 10.11+**, or **PostgreSQL 14+**. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No npm, no build step — just PHP and a web server. Runtime Composer dependencies are pre-bundled into the release tarball, so end users still deploy by extracting and running.

---

## What's new in v3.26.0

Backup-overhaul closeout + code-quality sweep. Two breaking-change footnotes (legacy backup runner retired, vault-key relocated to DB) plus a sweep of P0/P1 security and correctness fixes that landed earlier in the cycle.

- **Legacy v3.7 backup runner retired (#1059).** `run_db_backup_if_due()`, the four legacy `backup.*` settings keys, and the standalone `backup.php` CLI entry point are removed. Backups are driven entirely by the unified `backup_destinations` + `backup_schedules` surface; `cron.php` is the sole scheduler entry point. **Operators upgrading from a pre-v3.23 install must pass through v3.23.0–v3.25.x first** so the conversion helper can materialise their legacy schedule before the migration aborts.
- **`backup_vault_key` relocated to DB (#1098).** The 32-byte vault key for IPAMBKP3-encrypted archives moved out of `config.php` into a wrapped envelope in `settings`. New `bootstrap_key` config field wraps via libsodium `crypto_secretbox`; the raw key never lives in the database. New admin panel on the Destinations tab shows fingerprint + source label, gates Reveal behind a sudo-mode password re-prompt with per-IP rate limit, and gates Replace on encrypted-runs absence so a key swap cannot orphan existing archives.
- **Destination-disabled mid-backup (#859).** Disabling a destination while a backup is in flight now aborts the run with a distinct audit detail (`reason=destination_disabled`), the same "mark canceled, audit, cleanup tmpfile" choreography as an explicit operator-cancel.
- **Security & correctness sweep (B/C tracks).** CSRF on `set_theme.php` (#879), per-IP rate-limit on forgot/reset/email-OTP-verify (#882), expanded SSRF block-list on webhook URL validation (#872), email-OTP attempt-count race fix (#874), tags `colour` CSS injection defence (#869), `client_ip()` walks XFF right-to-left with a trusted-proxy CIDR list (#876). Plus 10 correctness fixes across audit-log filtering, webhook delivery, JSON containment, MySQL `GET_LOCK` hashing, and transaction wrapping.
- **VR coverage restored (#1091).** The 200–470 px PostgreSQL drift on `/subnets` and `/search` no longer reproduces; HTML and `subnet_stats` JSON are byte-identical across all three drivers.
- **Streaming memory property test (#860).** Asserts logical-dump peak memory delta stays under 64 MB regardless of row count; nightly CI workflows can crank `IPAM_LARGE_DB_TEST_BYTES=1073741824` for a 1 GB synthetic round-trip.

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
