# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Fresh Install](#fresh-install)
- [Configuration](#configuration)
- [First Login](#first-login)
- [Upgrading](#upgrading)
- [CLI Utilities](#cli-utilities)
- [File Permissions Reference](#file-permissions-reference)
- [Security Notes](#security-notes)
- [Changelog](#changelog)

---

## Features

### Core IPAM
- Manage **IPv4 and IPv6 subnets** (CIDR notation) with validation and normalization
- Manage **address records** with hostname, owner, status (`used` / `reserved` / `free`), and notes
- Subnet **hierarchy view** — parent/child nesting with expand/collapse for both IPv4 and IPv6
- **Subnet overlap detection** — warns when a new subnet nests inside or contains existing ones
- IPv4 **unassigned host tracking** — lists assignable IPs that have no address record, with a quick-add form
- Correct IP sorting using packed binary storage (`ip_bin`, `network_bin`)

### Search & Productivity
- **Dashboard** — utilization bar chart for top subnets, per-site address breakdown, recent audit activity
- **Global search** across IP / hostname / owner / note with filters for status, site, and IP version
- **Bulk update** — update hostname / owner / status / note across multiple addresses at once, with bulk delete
- **CSV import wizard** (admin-only) — upload, map columns, dry-run preview, then apply; supports skip / overwrite / fill-empty duplicate modes and can auto-create missing subnets

### Organisation
- **Sites** — group subnets by location or network segment; subnets page groups hierarchies by site

### Security & Access Control
- HTTPS enforced at the application layer; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- CSRF protection on all POST requests
- Prepared statements everywhere (PDO)
- Password hashing via `password_hash()` / `password_verify()`
- **RBAC roles:** `admin` (full access + user management) and `readonly`
- Hardened `.htaccess` — blocks direct access to SQLite DB files, build artefacts, and internal PHP files
- **Append-only audit log** enforced with SQLite triggers (rows cannot be updated or deleted)

### Auditing & History
- Audit log viewer with CSV export (admin)
- Per-address change history — captures who changed what, when, and from/to values for create / update / delete / bulk / import events

---

## Requirements

| Requirement | Details |
|---|---|
| **PHP** | 8.2 or later (8.3 recommended) |
| **PHP extensions** | `pdo`, `pdo_sqlite`, `openssl` |
| **Web server** | Apache or LiteSpeed (`.htaccess` support required); Nginx requires manual translation of `.htaccess` rules |
| **SQLite** | 3.x via PDO SQLite |
| **HTTPS** | Required — the app redirects all HTTP traffic to HTTPS |
| **Writable `data/` dir** | The web server user needs read/write access to `data/` (and `data/tmp/` for CSV import) |

### `upgrade.sh` dependencies (optional)
`bash`, `rsync`, `tar`, `stat`, `find`, `chmod`, `sort`, `sed`, `head`, `rm`
Optional: `php` CLI (for automatic DB migrations), `chown` (for ownership alignment).

---

## Fresh Install

### 1. Download a release

Download the latest release archive from the [Releases](../../releases) page and extract it, or clone this repository:

```bash
# Option A — download a release bundle
tar -xzf ipam-0.10.tar.gz -C /var/www/

# Option B — clone the repository
git clone https://github.com/seanmousseau/Simple-PHP-IPAM.git /var/www/ipam
```

The application files live inside the `Simple-PHP-IPAM/` subdirectory of the repo. Point your web server document root at that directory.

### 2. Set file permissions

```bash
# Replace www-data with your web server user (e.g. apache, nginx, _www on macOS)
chown -R www-data:www-data /var/www/ipam
find /var/www/ipam -type f -name '*.php' -exec chmod 0644 {} \;
find /var/www/ipam -type d -exec chmod 0755 {} \;

# Restrict the data directory
chmod 0700 /var/www/ipam/data
```

The `data/` directory and the SQLite database file are created automatically on first request. If they already exist:

```bash
chmod 0700 /var/www/ipam/data
chmod 0600 /var/www/ipam/data/ipam.sqlite
```

### 3. Configure the application

Copy or edit `config.php` — see [Configuration](#configuration) below. At minimum, **change the default admin password** before the site receives any traffic.

### 4. Configure your web server

#### Apache (virtual host example)

```apache
<VirtualHost *:443>
    ServerName ipam.example.com
    DocumentRoot /var/www/ipam

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/ipam.crt
    SSLCertificateKeyFile /etc/ssl/private/ipam.key

    <Directory /var/www/ipam>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# Redirect HTTP → HTTPS
<VirtualHost *:80>
    ServerName ipam.example.com
    Redirect permanent / https://ipam.example.com/
</VirtualHost>
```

Ensure `mod_rewrite` and `mod_headers` are enabled:

```bash
a2enmod rewrite headers
systemctl reload apache2
```

#### Nginx

Nginx does not process `.htaccess` files. You must replicate the rules manually. Key rules to translate from `.htaccess`:
- Deny access to `data/` and `*.sqlite` / `*.db` files
- Deny access to `*.sh`, `*.sql`, `*.json`, `*.tar.gz`, `*.zip`, `*.bundle.txt`, `SHA256SUMS`
- Pass all `.php` requests through PHP-FPM

### 5. Verify the install

Open `https://ipam.example.com/` in a browser. You should be redirected to the login page. Log in with the bootstrap admin credentials from `config.php` and immediately change the password under **Password** in the navigation.

---

## Configuration

All settings are in `config.php`. This file is preserved automatically during upgrades.

```php
return [
    // Path to the SQLite database file.
    // The directory must be writable by the web server user.
    'db_path' => __DIR__ . '/data/ipam.sqlite',

    // Session cookie name.
    'session_name' => 'IPAMSESSID',

    // Set to true only if the app sits behind a trusted reverse proxy
    // that sets X-Forwarded-Proto. Leave false if accessed directly.
    'proxy_trust' => false,

    // Bootstrap admin account — created on first run if no users exist.
    // CHANGE THIS PASSWORD before exposing the site.
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // Maximum CSV upload size for the import wizard (MB). Range: 5–50.
    'import_csv_max_mb' => 5,

    // How long (seconds) to keep uploaded CSV temp files before cleanup.
    'tmp_cleanup_ttl_seconds' => 86400,

    // Lazy housekeeping: runs on normal site access at most once per interval.
    'housekeeping' => [
        'enabled' => true,
        'interval_seconds' => 86400, // once per day
    ],
];
```

### Behind a reverse proxy

If HTTPS is terminated at a load balancer or reverse proxy that forwards `X-Forwarded-Proto: https`, set `'proxy_trust' => true`. Only do this if you control the proxy and it reliably sets this header — trusting it on a public-facing server without a proxy is a security risk.

---

## First Login

1. Navigate to your install URL. You will be redirected to the login page.
2. Log in with the credentials set in `config.php` under `bootstrap_admin` (default: `admin` / `ChangeMeNow!12345`).
3. **Immediately change the password** — go to **Password** in the top navigation.
4. Optionally create additional users under **Users** (admin-only).

> The bootstrap admin account is only created if no users exist in the database. Once any user account exists, changes to `bootstrap_admin` in `config.php` have no effect.

---

## Upgrading

Upgrades are handled by `upgrade.sh`, included in each release bundle. The script:

- Creates a timestamped backup of your current install (including the SQLite DB and WAL files)
- Syncs new application files into the target directory
- Preserves `config.php` and the entire `data/` directory
- Fixes file permissions
- Runs database migrations automatically (if the `php` CLI is available)
- Removes upgrade artefacts from the webroot

### Steps

```bash
# 1. Extract the new release bundle alongside your current install
tar -xzf ipam-0.10.tar.gz -C /tmp/

# 2. Run upgrade.sh from the extracted bundle, pointing it at your current install
bash /tmp/Simple-PHP-IPAM/upgrade.sh /var/www/ipam
```

The script will confirm the version transition before proceeding. To skip the confirmation prompt (e.g. in a deployment script):

```bash
bash /tmp/Simple-PHP-IPAM/upgrade.sh --yes /var/www/ipam
```

### Options

| Flag | Description |
|---|---|
| `--yes` | Non-interactive — skip confirmation prompts |
| `--force` | Allow reinstalling the same version |
| `--force-downgrade` | Allow downgrading (not recommended — may break the DB) |

### Environment variables

| Variable | Default | Description |
|---|---|---|
| `CLEANUP_ARTIFACTS` | `1` | Remove build/upgrade artefacts from the target webroot after success |
| `REMOVE_UPGRADE_SH_FROM_TARGET` | `1` | Also remove `upgrade.sh` from the target webroot |

### What the backup looks like

```
/var/www/ipam.backup.20260326-143000/   ← timestamped copy
    data/
        ipam.sqlite
        ipam.sqlite-wal   ← if present
        ipam.sqlite-shm   ← if present
    ... (all other app files)
```

If the migration step fails, `upgrade.sh` automatically restores from the backup and exits with code `10`.

---

## CLI Utilities

These scripts are run from the application directory using the PHP CLI.

### Run database migrations manually

```bash
cd /var/www/ipam
php migrate.php
```

Applies any pending schema migrations. Migrations are also run automatically on each web request (via `ipam_db_init()`) and during upgrades, so this is only needed for manual or scripted deployments.

### Clean up stale CSV temp files

```bash
cd /var/www/ipam
php tmp_cleanup.php
```

Deletes uploaded CSV files and import plan files in `data/tmp/` that are older than `tmp_cleanup_ttl_seconds` (default: 24 hours). This also runs automatically via lazy housekeeping on normal site access.

---

## File Permissions Reference

| Path | Recommended permissions | Notes |
|---|---|---|
| Application files (`*.php`, `*.sql`, etc.) | `0644` | Web server reads; world-readable is fine |
| Directories (except `data/`) | `0755` | Standard web directory permissions |
| `data/` | `0700` | Web server user only — keeps DB out of reach of other users |
| `data/ipam.sqlite` | `0600` | Web server user only |
| `data/ipam.sqlite-wal` / `-shm` | `0600` | Created automatically by SQLite WAL mode |
| `data/tmp/` | `0700` | Created automatically; holds CSV uploads |
| `config.php` | `0640` | Web server readable, not world-readable |
| `upgrade.sh` | `0755` | Executable; removed from webroot by default after upgrade |

---

## Security Notes

- **HTTPS is required.** The application redirects all HTTP traffic to HTTPS and sets `Secure` on session cookies. Do not run this on plain HTTP in production.
- **Change the default password** before the site receives any traffic.
- The `data/` directory and SQLite database are protected by `.htaccess` rules (Apache/LiteSpeed). On Nginx you must replicate these rules manually.
- The audit log is **append-only** — SQLite triggers prevent any UPDATE or DELETE on `audit_log` rows.
- All POST endpoints are protected by **CSRF tokens**.
- All database queries use **PDO prepared statements**.
- User passwords are stored using `password_hash()` with `PASSWORD_DEFAULT` (bcrypt).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

### What's new in 0.10

**Milestone 1 — Export Foundation**
- New CSV exports for addresses, search results, audit log, unassigned IPs, and import reports
- All export actions are recorded in the audit log
- Shared export helpers added to `lib.php` (`safe_export_filename`, `csv_download_headers`, `csv_out`, etc.)

**Milestone 2 — Import Safety and Dry Run**
- Import wizard now produces a frozen dry-run plan before applying any changes — row-level and summary reports show exactly what will happen
- Apply step reads the saved plan rather than re-parsing the CSV, with conflict detection if the DB changes between dry-run and apply
- Hardening: duplicate row detection within a CSV, CIDR/IP cross-validation, field length checks, clarified `fill_empty` semantics

**Milestone 3 — Subnet Overlap Detection**
- `detect_subnet_overlaps()` in `lib.php` classifies existing subnets as parents or children of a proposed CIDR using binary network comparison
- Informational warnings on subnet create and update (never blocks — hierarchy is valid); same check annotates the import dry-run report for subnets being auto-created

**Milestone 4 — Dashboard and Search**
- Dashboard rebuilt with a six-metric summary strip, top IPv4 subnet utilization bars (colour-coded at 70% / 90%), per-site address breakdown table, and a recent activity panel
- Search gains a site filter, IP version filter (IPv4 / IPv6), client-side subnet dropdown narrowing, and a "Clear filters" link

---

## License

[MIT](LICENSE)
