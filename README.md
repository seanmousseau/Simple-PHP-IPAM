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

### What's new in 1.11

**Branding & UX** — Configurable application name (`app_name` in `config.php`) shown in the browser tab, nav bar brand link, login page, and demo gate. Logo and favicons (`assets/logo.svg`, `favicon-32.png`, `apple-touch-icon.png`) displayed on every page. Browser tab title now reads `{AppName} — {Page}`.

**Group field on addresses** — All address records now carry an optional `group` text field. Shown as a badge in the address list and address tables; included in all CSV exports and the import wizard column mapper; searchable; exposed in the REST API as `group`.

**VLAN ID on subnets** — Subnets now have an optional VLAN ID (1–4094), displayed as a badge in the subnet hierarchy and returned in the REST API.

**REST API write support** — `POST`, `PUT`, and `DELETE` are now supported for `subnets` and `addresses`. All writes require a valid API key and are recorded in the audit log as `api:{key-name}`. See [REST API reference](docs/api.md).

**Per-user theme persistence** — The selected theme (light / dark / auto) is stored in the database and restored on next login, preventing the theme-flash on first page load.

**Session timeout notice** — Login page now shows a human-readable inactivity timeout notice derived from `session_idle_seconds`.

**DHCP pool improvements** — The reserved address table now shows hostname, owner, and note columns. Each row has inline Edit and Delete actions. Contiguous IP blocks are grouped under range headers.

### What's new in 1.10

**PHP 8.4 compatibility** — `str_getcsv()` and `fgetcsv()` now explicitly pass `''` as the escape argument, silencing the PHP 8.4 deprecation warning that broke the CSV import wizard. This also adopts RFC 4180-compliant quote-doubling behaviour throughout.

**Layout fixes** — The "Add Subnet" card on the Subnets page now has correct top spacing (duplicate `class` attribute bug); the Audit Log filter bar now shows Category / From / To labels inline with their controls instead of stacked above them.

**CSP / CAPTCHA fix** — The global `Content-Security-Policy` header in `.htaccess` has been removed. PHP sets a per-page CSP that correctly extends `script-src` and `frame-src` for active CAPTCHA providers; the `.htaccess` header was enforced simultaneously by browsers as a separate, more restrictive policy, silently blocking widget scripts and iframes.

**Sample dataset** — A 4,522-row CSV (`docs/samples/sample_dataset.csv`) is included for comprehensive import testing, covering six sites, all IPv4 prefix sizes from /23 to /32, IPv6 /64 and /128 subnets, P2P links, loopbacks, and all three address statuses.

### What's new in 1.9

**CSP `unsafe-inline` removed** — All 122 inline `style="..."` attributes across 15 PHP files have been replaced with utility CSS classes or `data-attribute` + JavaScript setters. `Content-Security-Policy: style-src` no longer includes `'unsafe-inline'`.

**Login bot protection** — Optional bot mitigation on the login form: `honeypot`, `time_check`, Cloudflare Turnstile, hCaptcha, Google reCAPTCHA (v2 and v3), or Friendly Captcha. Configure via `login_protection` in `config.php`. Widget-based methods extend `script-src` on the login page only and do not affect other pages.

**Demo gate** — When running in demo mode, an optional pre-login challenge page (`demo_gate.php`) can be configured to block bots before they reach the login form. Supports the same widget methods as login protection.

**Security hardening** — OIDC auto-link email fallback is now email-only (fixes cross-account link via username=email coincidence); `preferred_username` from ID tokens is sanitised before use as a local username; login timing is normalised to prevent username enumeration; failed login audit entries no longer record the submitted username.

**Bug fixes** — Password minimum-length check now uses `mb_strlen()` for multi-byte characters; dashboard top-subnets includes `/31` and `/32`; API history endpoint returns 200 (not 404) for deleted addresses; `prune_audit_log()` uses a prepared statement; `backup_dir()` canonicalises relative paths.

**Performance** — `find_containing_subnet()` uses a per-request static cache, eliminating redundant full table scans during large CSV imports.

### What's new in 1.8

**CSRF error recovery** — Stale-tab CSRF mismatches now redirect to the login page instead of showing a plain-text error with no navigation. HTTP 403 is returned so browser history works correctly.

**Open redirect hardening** — A new optional `base_url` config key (`'base_url' => 'https://ipam.example.com'`) pins the HTTP→HTTPS redirect to your canonical URL, preventing a spoofed `Host:` header from redirecting users to an attacker-controlled domain.

**PHP 9 compatibility** — `IPAM_VERSION` is now defined with a `defined()` guard to prevent fatal constant-redefinition errors when `version.php` is included more than once.

**Demo mode completeness** — `dhcp_pool.php` now correctly blocks reserve and clear operations in demo mode, matching the protection already on other admin pages.

**OpenLiteSpeed .htaccess compatibility** — Both `data/.htaccess` and the root `.htaccess` now use `mod_rewrite RewriteRule` directives for file blocking instead of `<FilesMatch>` / `Require all denied`, which OpenLiteSpeed ignores entirely.

**Upgrade .htaccess propagation** — `upgrade.sh` now always overwrites `data/.htaccess` from the new bundle, ensuring security improvements reach existing installs.

### What's new in 1.7

**Sortable columns** — Click any column header on Addresses, Audit Log, Search, Users, Sites, API Keys, Address History, or Bulk Update to sort by that column. Click again to reverse direction. Sort state is preserved across pagination.

**UI alignment fixes** — Input fields on the user creation form no longer shift when a browser password manager injects an icon. Export CSV and Apply buttons on the Audit page are now vertically aligned with filter inputs.

**.htaccess hardening** — `data/.htaccess` now includes Apache 2.2 fallback directives and explicitly blocks common backup and database file extensions.

**nginx and Caddy config docs** — `docs/install.md` now includes complete nginx and Caddy configuration blocks covering PHP-FPM, data directory protection, security headers, and HTTPS redirect.

**Demo mode** — Opt-in `demo_mode` in `config.php`. When enabled: only `demo`/`demo` can log in, destructive actions are blocked, a banner is shown, and the database resets nightly to pre-seeded realistic data.

### What's new in 1.6

**CSP-compliant JavaScript** — All inline `onclick`, `onchange`, `onsubmit` handlers and inline `<script>` blocks have been removed. Behaviour is now handled via event delegation in `app.js` using `data-*` attributes, fully compliant with `Content-Security-Policy: script-src 'self'`.

**Bug fixes** — Users admin page error messages no longer silently swallowed after the v1.5 refactor; SSO-only toggle applies on page load for form re-renders; subnet overlap warnings properly escaped; unassigned quick-add no longer leaks raw exception messages.

**OIDC response cap** — HTTP functions for OIDC discovery, JWKS, and token exchange now limit response reads to 1 MB.

**CSV UTF-8 BOM** — CSV exports include a UTF-8 BOM for automatic Excel encoding recognition.

**Address history retention** — New `address_history_retention_days` config option prunes old entries during housekeeping, matching the existing audit log retention pattern.

**Dark mode calendar icon** — Date input calendar picker icons are now visible in dark mode.

### What's new in 1.5

**Security headers** — All page responses now include `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy` headers. The API JSON responses include the same headers (except CSP).

**X-Forwarded-For hardening** — When `proxy_trust` is enabled, the `X-Forwarded-For` header is now validated as a proper IP address before use in rate limiting. Comma-separated proxy chains are handled correctly.

**OIDC auto-link** — New `auto_link` config option lets pre-created accounts self-link on first OIDC login without allowing open account creation (`auto_provision = false`). Fully backwards-compatible.

**Password errors all at once** — All unmet password requirements are now shown together in a single response, across self-service change, admin user creation, and admin password reset.

**User creation form preservation** — When user creation fails validation, username, name, email, and role are re-populated in the form.

**Audit log date filter** — Filter audit entries by date range (From / To) alongside the existing category filter.

**Better duplicate errors** — Creating a subnet or address that already exists now shows a specific _"already exists"_ message.

**API rate limit headers** — `429` responses include `Retry-After` and `X-RateLimit-Limit` headers.

### What's new in 1.4

**Netops role** — A new `netops` role sits between `admin` and `readonly`. Netops users can create and edit subnets, addresses, DHCP pools, and sites, but cannot manage users or API keys. DHCP Pools link moves to the main nav for write-access users.

**Password policy** — Configure complexity requirements (`min_length`, uppercase/lowercase/number/symbol) and a rotation interval (`max_password_age_days`) in `config.php`. Users with expired passwords are redirected to the change-password page. The change-password form shows the active policy requirements.

**SSO-only accounts** — Admins can now create accounts that have no usable password and must authenticate via OIDC. An optional OIDC `sub` claim can be pre-linked at creation time.

**Audit log open to all roles** — Previously restricted to admins; now all logged-in users can view the audit log.

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
