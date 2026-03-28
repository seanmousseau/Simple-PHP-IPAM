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
- **RBAC roles:** `admin` (full access + user management) and `readonly`
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

### What's new in 1.3

**Site collapse/expand** — Site groups on the Subnets page are now collapsible. Click a site group header to toggle it open or closed. State persists across page loads via `localStorage`.

**Search site column** — The Search results table now shows the site each result belongs to, alongside the subnet CIDR.

**API subnets pagination** — `GET api.php?resource=subnets` is now paginated (`&page=N&limit=N`, max 1000, default 200) with a standard `total`/`page`/`limit` envelope.

**Security & hardening** — XSS fix for inherited site name in subnet warnings; upgrade detection normalises version strings to x.x.x format; rate limiting no longer falls back to an empty IP; search queries capped at 500 chars server-side; subnet deletion now records the address count in the audit log.

### What's new in 1.1

**Bug fixes** — update-check cache is now invalidated immediately after an upgrade; `ipam_update_check()` is memoised so only one HTTP request is made per page load; fresh installs stamp all migration versions on first run; `find_containing_subnet()` now correctly returns the tightest (most-specific) parent subnet.

**Utilization accuracy** — free-status addresses are excluded from utilization calculations. Only `used` and `reserved` addresses count toward subnet fill rates and the dashboard Top Subnets table.

**Bulk edit — unconfigured IPs** — for IPv4 subnets with ≤ 4094 assignable IPs, the bulk edit tool now shows every unrecorded host IP as a `free (unconfigured)` row. Selecting them and applying Update inserts new address records in one step.

**Default password warning** — a red banner is shown to all logged-in users until the bootstrap admin password is changed from its default value.

**Address history API** — new REST resource `GET api.php?resource=history&address_id=N` returns the paginated change history for any address record.

**Subnet count badges** — each subnet node header now displays coloured `N used · N reserved · N free` address count badges, with a muted subtree aggregate when child subnets exist.

**Audit log filter** — a category filter dropdown on the audit log page lets administrators narrow events to `auth`, `subnet`, `address`, `user`, `site`, `apikey`, `dhcp_pool`, `export`, or `import`.

### What's new in 1.0

**Production release** — security fixes, schema completeness, and operational polish.

**Security fixes** — HTML output escaping made consistent in Database Tools; OIDC claim values clamped to 255 characters before storage; name and email fields enforce `maxlength` in both the UI and server-side.

**Complete `schema.sql`** — fresh installs now receive the full 1.0 schema (all tables, columns, and indexes) from the first request, including the OIDC partial unique index.

**Audit log retention** — new `audit_log_retention_days` config option prunes old entries during scheduled housekeeping. Defaults to `0` (keep forever).

**Health check endpoint** — `GET /status.php` returns `{"status":"ok","version":"1.0","db":"ok"}` (HTTP 200) or `{"status":"error","db":"error"}` (HTTP 503). No authentication required.

**Cleanup** — removed deprecated `ipamToggleTheme`/`ipamClearTheme` JS aliases.

### What's new in 0.15

**Config auto-population** — Any config keys added in an upgrade are automatically appended to `config.php` with their defaults. Admins see a one-time notice identifying the added keys.

**Automatic backups** — Built-in backup scheduler writes timestamped SQLite snapshots on page load at a configurable interval (`daily`/`weekly`). Older backups beyond the retention count are pruned automatically. Opt in via `'backup' => ['enabled' => true]` in `config.php`.

**Database Tools** — New admin page (⚙ Admin → Database Tools) for one-click SQL export, SQL import with pre-import backup and rollback on error, manual backup trigger, and backup status summary.

**Mobile GUI** — Responsive CSS breakpoints at 768 px and 480 px. Nav, tables, cards, forms, and toolbars all adapt gracefully to phones and tablets.

**Update check enhancements** — Dismissible update banner shown at the top of every admin page. New `notify_prerelease` option surfaces alpha/beta/RC releases. Cache TTL extended to 24 hours.

### What's new in 0.14

**DHCP pool tool** — Bulk-reserve a contiguous IPv4 range as DHCP pool addresses in one action. Used IPs are never overwritten. Accessible from each subnet's action bar and the Admin menu.

**Update check** — Footer shows an "Update available" badge when a newer GitHub release is published. Result is cached; opt-out in `config.php`.

**User hardening** — Admins can no longer accidentally disable themselves, change their own role, or unlink their own SSO from the users admin page. Last login time now visible per user.

**Utilization badges** — IPv4 subnets show a colour-coded mini progress bar (green/yellow/red) with configurable warn and critical thresholds.

**Emergency access controls** — New `hide_emergency_link` and `disable_emergency_bypass` config options for stricter SSO-only enforcement.

**App rename** — Application renamed to Simple PHP IPAM throughout.

### What's new in 0.13

**User management** — Name and email fields on every account. Delete users (with guard against removing the last admin). Inline OIDC linking from the admin UI.

**OIDC improvements** — Username derived from `preferred_username` claim; name and email synced from IdP claims. Auto-link tries `preferred_username` then `email` before provisioning. New `disable_local_login` option hides the password form; `?local=1` emergency bypass always available.

**Site inheritance** — Child subnets automatically inherit the site of their tightest enclosing parent. The site field is locked to a read-only badge for child subnets.

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
