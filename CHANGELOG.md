# CHANGELOG

All notable changes to this project will be documented in this file.

### 0.7

#### New: Global Search (paged)
- Added `search.php` to search addresses across the system by:
  - IP, hostname, owner, note
- Optional filters:
  - subnet
  - status (`used`, `reserved`, `free`)
- Results are paginated (default page size 100).

#### New: Unassigned IPv4 Listing + Quick Add (paged)
- Added `unassigned.php` to list IPv4 **unassigned** (no row in `addresses`) assignable hosts.
- Safety limit: unassigned listing is only enabled for subnets with **≤ 4096 assignable hosts**.
- Paged view (default page size 100).
- Quick add inline form per IP:
  - hostname, owner, note
  - status dropdown (default `used`)
- Created rows are logged in the audit log.

#### New: Address Change History
- Added `address_history` table to capture who changed what and when.
- Added `address_history.php` view per address.
- History is recorded for:
  - create/update/delete in `addresses.php`
  - bulk updates (`bulk_update.php`)
  - CSV import creates/updates (`import_csv.php`)
  - unassigned quick-add (`unassigned.php`)

#### DB / Performance
- Added indexes to support searching and history queries:
  - `addresses(hostname)`, `addresses(owner)`, `addresses(status)`
  - history indexes for `address_history(address_id)` and `address_history(subnet_id)`

---

## 0.6
### Added
- **Bulk Update tool** (`bulk_update.php`) to bulk update address records within a selected subnet:
  - Bulk update supports updating any combination of **Hostname**, **Owner**, **Status** (`used|reserved|free`), and **Note**
  - Subnet filter + optional text search + multi-select with “select all/none”
  - Changes applied in a transaction and recorded in the audit log (`address.bulk_update`)
- **Navigation link** to Bulk Update for **non-readonly** users.

### Fixed
- CSV import column mapping validation when mapping IP to the **first column** (index `0`):
  - Replaced `empty()` checks with numeric-index validation to avoid `empty("0")` pitfalls.
- `upgrade.sh` message for same-version reinstall:
  - Now correctly instructs using `--force` (not `--force-reinstall`).

---

## 0.5
### Added
- **CSV Import Wizard** (admin-only) (`import_csv.php`):
  - Upload CSV
  - Confirm delimiter + whether CSV has headers
  - Map detected columns to IPAM fields (IP required; others optional/ignorable)
  - Select duplicate handling mode per import run:
    - skip existing
    - update/overwrite
    - update only empty fields
  - Supports importing **IPv4 and IPv6**
- Subnet resolution/creation during import:
  - If CSV provides CIDR values: can create missing subnets from CIDR.
  - If IP does not match existing subnets and CSV does not provide CIDR:
    - can create subnets based on IP + prefix length
    - prefix can come from CSV prefix column, IPv4 netmask column, or defaults (IPv4 `/24`, IPv6 `/64`).
- **Configurable CSV upload size limit** `import_csv_max_mb` (allowed range **5–50MB**, default **5MB**).
- Upload temp storage under `data/tmp/` with restrictive permissions.
- **Temp cleanup CLI utility** (`tmp_cleanup.php`) to remove old uploaded CSV files.
- **Daily housekeeping (lazy cleanup)**:
  - Controlled by `housekeeping.enabled` and `housekeeping.interval_seconds`
  - Runs on normal site access at most once per interval
  - Removes stale CSV temp files based on `tmp_cleanup_ttl_seconds`
  - Uses a lock file to prevent concurrent runs.

### Security / Hardening
- `.htaccess` blocks downloading common upgrade/build artifacts (`*.sh`, `SHA256SUMS`, `*.tar.gz`, `*.tgz`, `*.zip`).
- Import is admin-only and protected by CSRF.
- Import runs logged to audit log (`import.csv`) with summary counts.

---

## 0.4
### Added
- Initial public bundle release (consolidated features up to this point).

### Security / Hardening
- HTTPS enforced at application layer; secure session cookies (`Secure`, `HttpOnly`, `SameSite=Strict`).
- Optional reverse-proxy HTTPS detection via `proxy_trust`.
- CSRF tokens on all POSTs.
- Prepared statements throughout (PDO).
- Password hashing via `password_hash()` / `password_verify()` (`PASSWORD_DEFAULT`) + rehash support.
- Security headers via `.htaccess` (CSP, frame deny, nosniff, etc.) and optional HSTS.
- Denied web access to internal files and SQLite database files.
- **Append-only audit log** enforced with SQLite triggers (no UPDATE/DELETE).

### Authentication / Authorization
- Local authentication with sessions.
- RBAC roles: `admin` and `readonly`.
- User management UI (admin-only): create users, enable/disable, set role, reset passwords.
- Self-service password change page.

### IPAM Features
- Subnet management (IPv4 + IPv6): create/update/delete with CIDR validation and normalization.
- Address management: create/update/delete with status (`used|reserved|free`).
- IP normalization and “IP belongs to subnet” enforcement.
- Correct IP sorting using packed binary IP (`ip_bin`) index.

### Subnet Hierarchy UI
- Added `subnets.network_bin` with migration/backfill support.
- Nested subnet hierarchy view (IPv4 + IPv6) with expand/collapse.
- Utilization counts per subnet:
  - direct counts (subnet only)
  - aggregated counts (includes children)

### IPv4 Unassigned (Assignable Hosts)
- Added IPv4-only “Unassigned” calculation:
  - Unassigned = assignable IPv4 hosts within subnet that do **not** have a row in `addresses`.
  - Counts only assignable hosts (excludes network/broadcast where applicable; RFC 3021 `/31` supported).
  - Not shown for IPv6.

### Upgrade / Release Tooling
- Added `version.php` and version-aware upgrade behavior.
- Included `upgrade.sh`:
  - prevents downgrades by default
  - creates backups (including SQLite + WAL/SHM)
  - preserves `config.php` and `data/`
  - fixes permissions and attempts ownership alignment based on existing install
  - runs DB migrations via `migrate.php` (if PHP CLI available)
  - cleans up common artifacts in webroot
  - defaults to removing `upgrade.sh` from target webroot after upgrade
- Migration framework:
  - `schema_migrations` table
  - `migrations.php`
  - `migrate.php`
- Improved DB initialization so existing DBs are upgraded via migrations rather than re-applying `schema.sql`.
