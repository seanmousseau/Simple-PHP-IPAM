# Simple-PHP-IPAM
A simple IP Address Management Tool written in PHP

## Feature Summary

This project is a lightweight **PHP 8.2+ + SQLite** IPAM (IP Address Management) web app with local authentication and a focus on safe defaults.

### Core IPAM
- Manage **IPv4 and IPv6 subnets** (CIDR) with validation and normalization
- Manage **addresses** (IPv4/IPv6) with fields:
  - hostname, owner, status (`used` / `reserved` / `free`), note
- Enforces “**IP must belong to selected subnet**”
- Correct IP sorting using packed binary IP storage (`ip_bin`)
- Subnet hierarchy view (parent/child nesting) for both IPv4 and IPv6 with expand/collapse
- IPv4 “**unassigned**” tracking:
  - Unassigned = assignable host IPs in subnet **without a row** in the address table
  - Safe listing + quick-add for small subnets (≤ 4096 assignable hosts)

### Productivity Tools
- **Global Search** (paged) across IP / hostname / owner / note, with optional subnet + status filters
- **Bulk Update** tool to update hostname/owner/status/note for multiple address records at once
- Flexible **CSV Import Wizard** (admin-only):
  - Detect delimiter + header handling
  - Map columns to fields (ignore unused columns)
  - Duplicate handling modes: skip / overwrite / fill-empty
  - Can create missing subnets from CIDR or infer from IP + prefix

### Security & Access Control
- Enforced HTTPS; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`)
- CSRF protection on all POST requests
- Prepared statements everywhere (PDO)
- Password hashing via `password_hash()` / `password_verify()` (`PASSWORD_DEFAULT`)
- RBAC roles:
  - `admin` (full access + user management)
  - `readonly` (read-only)
- Hardened `.htaccess`:
  - blocks access to internal files + SQLite DB files
  - blocks downloading build/upgrade artifacts
- Append-only audit log (DB triggers prevent UPDATE/DELETE)

### Auditing & History
- Audit log viewer (admin)
- Per-address change history capturing who changed what and when:
  - create/update/delete/bulk update/import events

### Ops / Upgrades
- Versioned releases (`version.php`)
- `upgrade.sh` for safe in-place upgrades:
  - backup current install + DB (including WAL/SHM)
  - preserve `config.php` and `data/`
  - run migrations
  - clean up leftover artifacts in webroot
- Migration framework (`migrations.php`, `migrate.php`, `schema_migrations`)
- Daily “lazy housekeeping” to clean stale CSV uploads from `data/tmp/`

## Requirements

### Server / Runtime
- **PHP 8.2+** (PHP 8.3 recommended)
- PHP extensions:
  - **PDO**
  - **PDO_SQLITE**
  - **OpenSSL** (used for secure randomness for sessions/CSRF; typically enabled by default)
- A web server capable of running PHP:
  - **Apache** or **LiteSpeed/OpenLiteSpeed** (supports `.htaccess`)
  - (Nginx can be used, but you must translate the `.htaccess` rules to Nginx config)

### Database
- **SQLite 3** (via PDO SQLite)
- The web server user must have **read/write** access to the configured DB path (default: `data/ipam.sqlite`)

### File Permissions
- `data/` must be writable by the web server user (recommended):
  - directory permissions: `0700`
  - SQLite DB permissions: `0600`
- Optional but recommended for CSV import:
  - `data/tmp/` writable by the web server user (created automatically)

### HTTPS / TLS (Required)
- HTTPS must be configured (the app enforces HTTPS in `init.php`)
- If running behind a reverse proxy/load balancer:
  - set `proxy_trust` appropriately in `config.php` **only** if proxy headers are trustworthy

### CLI Utilities (Optional but Recommended)
These are not required to *run* the web app, but are recommended for operations and maintenance:

- `php` CLI (recommended) for:
  - manual migrations: `php migrate.php`
  - manual temp cleanup: `php tmp_cleanup.php`

### `upgrade.sh` Requirements (Optional)
If you use the included `upgrade.sh` helper for in-place upgrades, the server must have:

- **bash**
- **rsync** (required)
- **tar**
- **stat**
- **find**
- **chmod**
- `sort`, `sed`, `head`, `rm` (typically present on Linux)
- Optional: **chown** (only needed if you want the script to attempt ownership fixes; may require sudo/root)

> Note: `upgrade.sh` runs on the server shell, not via the web UI. It also expects permission to write to the target install directory and to create a backup directory alongside it.


