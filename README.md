<img width="200" draggable="false" oncontextmenu="return false;" src="https://media.pupness.ca/file/seanmousseau/assets/logos/ipam/logo-readme.webp" alt="logo_readme" />

# Simple-PHP-IPAM

A lightweight **IP Address Management (IPAM)** web application built with PHP 8.2+ and SQLite. Designed for small to mid-sized environments that need straightforward subnet and address tracking without the complexity of a full enterprise IPAM platform.

No Composer, no npm, no external dependencies — just PHP and a web server.

---

## What's new in v2.5.0

- **Scanner broadcast exclusion** (#363) — network and IPv4 broadcast addresses are now skipped during scan runs. Some hosts respond to broadcast ICMP, which previously produced misleading up/down results. `/31` (RFC 3021) and `/32` have no reserved addresses; IPv6 only skips the network address.
- **cron.php demo reset** (#364) — demo mode database reset is now an integrated task in the unified cron runner (throttled to once every 24 hours). `demo_reset.php` remains available as an unthrottled manual runner.
- **docs/install.md** — new *Step 6: Register the cron runner* post-install section with the recommended crontab entry.
- **Testing hardening** — new Playwright specs for CSP compliance, console cleanliness, and CSS regression (theme switching, sticky headers, status badge tokens, utilisation bar width). Extended tooltip and JS behaviour specs (fleet-wide `[data-tooltip]`/`[title]` non-empty sweep, CSRF-token-on-every-POST-form check, theme persistence). 329 Playwright tests, 96 PHPUnit tests.
- Full QA gate (lint, PHPStan level 9, PHPCS, PHPUnit, Semgrep, Playwright) green.

See [CHANGELOG.md](CHANGELOG.md) for full details.

## What's new in v2.4.0

- **Bug fixes** — ICMP scan false negatives fixed (stdout read before pipe close; RTT parsing for Linux and macOS; exit code 2 handled); sticky table headers unified stacking context via thead-level sticky
- **Timezone support** — `timezone` config key; `display_datetime()` helper converts UTC timestamps; `init.php` calls `date_default_timezone_set()`
- **Unified cron.php** — single cron entry for housekeeping (temp cleanup, audit pruning, history pruning, utilisation alerts, DB backup); JSONL output per task
- **Live ping button** — per-address "Ping" button on addresses page; fires `ping_host.php` via `fetch()`; available to all authenticated users including read-only
- **Scan schedule UI** — moved from subnets.php inline form to dedicated section on scan_history.php
- **VRF BGP attributes** — ASN, RT Import, RT Export, enforce-unique-prefix fields on VRF add/edit forms
- **VLAN ranges** — named 802.1Q VLAN ID allocation blocks (e.g. Management: 1–99) with optional site scoping
- **Tooltip system** — `[data-tooltip]` CSS pseudo-element tooltips across all admin forms; edge-clamping via JS
- **Aggregates** (`aggregates.php`) — supernet/RIR block CRUD for admin users; subnet coverage count; IPv4 and IPv6
- **IPv6 PD pools** (`pd_pools.php`) — RFC 3633 prefix delegation pool management; subscriber linking; expiry tracking
- **DNS zone export** (`export_dns.php`) — BIND-format A/AAAA and PTR zone file download from any subnet
- **Docs** — `docs/advanced-networking.md` (VRF BGP, VLAN ranges, aggregates, PD pools, DNS export); GitHub Pages site (`docs/_config.yml`, `docs/index.md`)

See [CHANGELOG.md](CHANGELOG.md) for full details.

## What's new in v2.3.0

- **Network scanning** — ping sweep scanner (ICMP + TCP) implemented in pure PHP with no external dependencies; all IPs validated before use in `proc_open()` / `fsockopen()`
- **Scan schedules** — per-subnet scan configuration (method, interval, active flag) managed via subnets page or REST API; `scan_run.php` CLI script for cron integration
- **Auto-stale detection** — addresses that miss N consecutive scan results are automatically flagged as stale with a visible badge; clear automatically when host responds
- **Scan history** — new `scan_history.php` page shows per-subnet scan run timeline with up/down counts, per-address last-seen timestamps, and stale badges
- **ARP table import** — new `import_arp.php` wizard: paste ARP output in any format (space/tab/CSV, Linux `arp -a` style), preview parsed entries, apply to update MAC addresses
- **Scan REST API** — six new endpoints: `scan_results`, `scan_history`, `scan_schedules` (GET/POST/DELETE), and `scan_run` (synchronous trigger, /28 cap)
- **Sticky header final fix** — `syncTopbarHeight()` measures the topbar at runtime, eliminating the hard-coded 79px offset; `thead th` z-index raised to 51

See [CHANGELOG.md](CHANGELOG.md) for full details.

## What's new in v2.2.0

- **Sticky table headers fixed** — two CSS stacking-context bugs corrected; headers now reliably pin below the navbar on all table pages (`overflow:hidden` → `clip-path`, `overflow-y:clip` on wrapper, corrected topbar offset)
- **Contacts, VRFs, and DHCP Pool test suites** — three new Playwright specs bring full CRUD + integration coverage to the Contacts admin page, VRFs admin page (including delete-guard validation), and the DHCP Pool reservation tool
- **Large-DB sample refreshed** — test sample database regenerated with current v2.1.x schema including VRFs, contacts, tags, VLANs, MAC addresses, and expiry dates (500 subnets, 43 000+ addresses)
- **Visual audit selector fix** — subnet map check in `visual-audit.spec.ts` corrected to use actual DOM classes

See [CHANGELOG.md](CHANGELOG.md) for full details.

## What's new in v2.1.5

- **Rendering fixes** — VLAN badge no longer shows literal `\u{2014}`; search overlay placeholder no longer shows literal `\u2026`; sticky table header z-index corrected so headers always appear above scrolled rows
- **Upgrade-path tests** — automated Playwright spec imports a pre-v2.0.0 schema snapshot, runs all migrations, and asserts every v2.x page loads cleanly
- **Large-DB tests** — automated import/export round-trip test with 100 subnets and 5 000 addresses via Database Tools
- **Visual audit** — full-coverage screenshot spec across every page in desktop, mobile, and dark-mode viewports
- **Richer demo & sample data** — demo database and large-DB generators now include VRFs, contacts, tags, VLANs, MAC addresses, and expiry dates

See [CHANGELOG.md](CHANGELOG.md) for full details.

## What's new in v2.1.0

- **VRF support** — virtual routing tables with VRF-scoped overlap detection; same CIDR allowed in different VRFs; admin CRUD page + API
- **Contacts** — first-class contact records (name, email, phone, org, note) linked to address owner fields via typeahead; API CRUD + fuzzy search
- **⌘K / Ctrl+K search overlay** — instant global search from any page with keyboard navigation
- **Inline cell editing** — click hostname, owner, note, or group on the address list to edit in-place; Enter saves, Escape reverts
- **Subnet map view** — visual indented hierarchy map on subnets page with List / Map toggle (persisted to localStorage)
- **Column visibility** — gear dropdown to show/hide address table columns; preference persisted per browser
- **Dashboard widgets** — hide/show stat cards; site filter on "Addresses by Site" panel; one-click reset

See [CHANGELOG.md](CHANGELOG.md) for full details.

## What's new in v2.0.0

- **VLANs** — first-class VLAN objects with admin CRUD, subnet assignment picker, and list badge
- **Site hierarchy** — parent site / region support; two-level tree (region → site) with indented display
- **Tags** — colour-coded labels on subnets and addresses; filter by tag in search and API
- **Auto-reserve IPs** — network, broadcast, and gateway automatically reserved on subnet create
- **Email utilization alerts** — configurable threshold alerts with 24-hour per-subnet cooldown
- **Slide-in form drawer** — add/edit forms open in a side panel instead of inline sections
- **Mobile hamburger nav** — full-screen drawer overlay at ≤600 px
- **Breadcrumbs** — consistent trail on every page
- **Sticky table headers** — column headers stay visible while scrolling
- **Sortable columns** — click column headers on addresses, search, users, and audit tables
- **Inline status toggle** — click a status badge to cycle `free → used → reserved → free`

See [CHANGELOG.md](CHANGELOG.md) for full details.

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
