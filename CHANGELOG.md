# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
as of v1.15.0. Versions prior to 1.15.0 used two-part numbering.

## [2.1.5] - 2026-04-12

### Fixed
- **#300** — VLAN badge em dash rendered as literal `\u{2014}` on the subnet list. PHP does not interpret `\u{...}` Unicode escapes in single-quoted strings; the em dash separator is now embedded as a literal UTF-8 `—` character in `subnets.php`.
- **#301** — Search overlay placeholder showed literal `\u2026` instead of an ellipsis (`…`). The escape sequence in `assets/app.js` has been replaced with the actual UTF-8 ellipsis character.
- **#302** — Sticky table header appeared below the first data row during scroll due to insufficient z-index. `thead th` z-index raised from `2` to `10` in `assets/app.css`; `--topbar-h` corrected to match the actual rendered topbar height.

### Added (testing)
- **#303** — Sample data generators (`gen_large_db.php` and CSV generators) updated to include VRFs, contacts, tags, VLANs, `expires_at`, and MAC addresses so generated datasets exercise all v2.x features.
- **#304** — Demo database (`demo_seed.php`) now seeds VRFs, contacts, tags, VLANs (with `vlan_fk` linkage), addresses with `expires_at` and MAC populated, and a parent+child site pair so the live demo showcases all available features.
- **#305** — Upgrade-path test suite: `testing/playwright/tests/upgrade.spec.ts` imports a pre-v2.0.0 SQL snapshot, triggers `apply_migrations()` via a page load, and asserts all v2.x pages (VLANs, VRFs, contacts, tags, addresses, audit) load correctly with migrated data intact. Supporting shell script at `testing/scripts/test_upgrade.sh`.
- **#306** — Large-DB import/export test suite: `testing/playwright/tests/large-db.spec.ts` imports 100 subnets and 5 000 addresses via `db_tools.php`, verifies counts and export integrity, then performs a full round-trip (export → re-import). Original database is always restored in `afterAll`.
- **#307** — Full visual audit spec: `testing/playwright/tests/visual-audit.spec.ts` covers every page in the page inventory, checks for literal Unicode escapes in rendered text (both `\uXXXX` and `\u{XXXX}` forms), verifies sticky header z-index (≥ 10), and captures screenshots for every page in desktop, mobile, and dark-mode viewports.

## [2.1.4] - 2026-04-12

### Fixed
- Migration locking (root cause): `ipam_db_init()` now calls `closeCursor()` on the `sqlite_master` check statement before running migrations. An unfinalised PDOStatement holds a WAL read mark at the **database level** — not just on the table it queried — which prevents `DROP TABLE` from entering WAL exclusive mode and causes `SQLITE_LOCKED` even in a single-process CLI context. This is the real reason v2.1.2/v2.1.3 retries all failed: the lock was intra-process, so sleeping never helped. Confirmed fix on OpenLiteSpeed (PHP 8.4).

## [2.1.3] - 2026-04-12

### Fixed
- Migration locking (improved): `apply_migrations()` now retries up to 60 times (1s sleep between attempts) when a migration fails with `SQLITE_LOCKED` or `SQLITE_BUSY`. This handles the case where `busy_timeout` does not retry DDL inside an existing transaction (e.g. `DROP TABLE` needing WAL exclusive mode while PHP-FPM connections are open). SQLite DDL is fully transactional — `ROLLBACK` cleanly undoes partial work so each retry starts from a clean state. Confirmed to fix v2.0.0→v2.1.x upgrades on live servers.

## [2.1.2] - 2026-04-12

### Fixed
- Migration locking: `apply_migrations()` now uses `BEGIN EXCLUSIVE` instead of `BEGIN DEFERRED` so SQLite's `busy_timeout` retry fires at transaction start rather than mid-transaction on the first DDL statement. This prevents `SQLITE_LOCKED` failures when the web server has connections open during an upgrade that rebuilds the subnets table (introduced in v2.1.0).

## [2.1.1] - 2026-04-12

### Fixed
- Nav bar ⌘K search shortcut was rendered as a duplicate nav item showing literal `\u2318K` text (PHP does not interpret `\u` escapes without curly braces). The standalone button and Search link are now merged into a single `🔎 Search ⌘K` nav link; clicking it opens the quick-search overlay (keyboard shortcut unchanged).

## [2.1.0] - 2026-04-12

### Added
- **#271** — VRF (Virtual Routing and Forwarding) support: new `vrfs` admin page (CRUD with name, description, route-distinguisher fields), `vrfs` DB table, `subnets.vrf_id` FK. Overlap detection is now VRF-scoped (same CIDR allowed in different VRFs). Subnet create/edit form includes a VRF picker; subnet list shows VRF badge. API: `?resource=vrfs` endpoint (GET/POST/PUT/DELETE); subnet response includes `vrf_id`/`vrf_name`; `?vrf_id=` filter on subnets.
- **#272** — Contacts as first-class objects: new `contacts` admin page (CRUD with name, email, phone, org, note fields), `contacts` DB table, `addresses.owner_contact_id` FK. Address create/edit form includes a contact typeahead (debounced, session-auth). Address list shows linked contact name with mailto link. Free-text `owner` field retained for legacy rows. API: `?resource=contacts` endpoint (GET/POST/PUT/DELETE, `?q=` fuzzy search usable from browser session); address response includes `owner_contact_id`/`owner_contact_name`; `?contact_id=` filter on addresses.
- **#253** — Global ⌘K / Ctrl+K search overlay: keydown listener opens a modal overlay; debounced 300ms fetch to `search.php?format=json`; ↑↓ keyboard navigation, Enter navigates, Escape/backdrop closes. New `format=json` branch on `search.php` returns up to 20 address results as JSON. ⌘K shortcut button added to nav bar.
- **#254** — Inline cell editing on address rows: clicking hostname, owner, note, or group cells on the address list opens an in-place `<input>`; Enter saves, Escape reverts, Tab moves to next editable cell. New `action=update_cell` JSON handler on `addresses.php` (write role only; CSRF protected; whitelisted fields; history logged).
- **#255** — Subnet visual hierarchy map view: "List / Map" toggle buttons on `subnets.php`. Map view renders a server-side indented tree with CIDR, description, and utilization bar per node. Toggle state persisted to `localStorage`. Graceful cap at 200 nodes.
- **#256** — Per-user column visibility preferences: gear dropdown on the addresses table lets users show/hide columns; state persisted to `localStorage` per table. At least one column always remains visible.
- **#257** — Dashboard pinnable widgets and site filter: ✕ button on each dashboard card hides the widget (persisted to `localStorage`). "Addresses by Site" panel has a live site-filter dropdown (saved to `localStorage`). "↺ Reset widgets" link in page-actions clears all `ipam_*` localStorage keys.

---

## [2.0.0] - 2026-04-11

### Added
- **#268** — VLANs as first-class managed objects: new `vlans` admin page (CRUD), `vlans` DB table, `subnets.vlan_fk` FK column. Subnet create/edit form includes a VLAN picker; subnet list shows VLAN name badge. API: `?resource=vlans` endpoint (GET list, GET by id, POST, PUT, DELETE); subnet response includes `vlan_name` field; `?tag=` filter on subnets/addresses.
- **#269** — Site hierarchy / region support: `sites.parent_id` self-referential FK (max depth 2). Sites admin page shows parent picker and indented tree. Subnet site picker shows hierarchy. API: sites response includes `parent_id` / `parent_name`; `?parent_id=` filter on sites endpoint.
- **#266** — Tags on subnets and addresses: new `tags` admin page (CRUD with colour picker), `tags`, `subnet_tags`, `address_tags` tables. Coloured tag badges on subnet and address lists. `?tag=` filter in API and search. CSV export includes `tags` column.
- **#267** — Auto-reserve network/broadcast/gateway IPs on subnet create: checkbox (pre-checked per `auto_reserve_network_broadcast` config key) and optional gateway field on subnet create form. Inserts network, broadcast, and gateway as `status=reserved` addresses automatically.
- **#270** — Email utilization threshold alerts: `check_utilization_alerts()` in `lib.php` sends email via `mail()` when subnet utilization crosses `alert_util_warn_pct` (default 80%) or `alert_util_crit_pct` (default 95%). 24-hour cooldown per subnet per level tracked in new `alert_state` table. Configurable via `alert_email`, `alert_util_warn_pct`, `alert_util_crit_pct`, `alert_interval_seconds` config keys.
- **#251** — Sortable columns on key tables: `addresses.php`, `search.php`, `users.php`, `audit.php` support `?sort=` and `?dir=` query parameters with ▲/▼/⇅ sort indicators; sort state preserved in pagination links.
- **#249** — Consistent breadcrumb trail on all pages via new `page_breadcrumb()` helper in `lib.php`; `.breadcrumbs` nav element renders on every page with correct hierarchy.
- **#250** — Mobile hamburger navigation: `#nav-toggle` button (☰) and `#nav-drawer` slide-in overlay with all nav links; toggled by `body.nav-open` class; closes on overlay click, nav link click, or ESC key. Desktop nav unchanged.
- **#248** — Sticky table headers: `thead th` elements use `position: sticky; top: var(--topbar-h)` so column headers stay visible while scrolling long tables.
- **#252** — Inline status toggle on address rows: clicking a `.status-badge` cycles `free→used→reserved→free` via a JSON POST to `addresses.php?action=update_status`; optimistic UI update with revert on error. Readonly users see a static badge.
- **#247** — Slide-in form drawer: add/edit forms on `subnets.php`, `addresses.php`, `users.php`, `sites.php`, `api_keys.php` open in a fixed right-panel drawer (`#form-drawer`) instead of inline page sections. Falls back gracefully when JS is unavailable.
- **#289** — reCAPTCHA v3 configurable action name: new `recaptcha_action` top-level config key (default `'login'`) controls the action string passed to `grecaptcha[.enterprise].execute()`; previously hardcoded.

### Security
- **#288** — GitHub Actions workflows now pin `actions/checkout` and all third-party actions to full commit SHAs instead of mutable version tags, preventing supply-chain attacks via tag mutation.

---

## [1.19.1] - 2026-04-12

### Fixed
- **reCAPTCHA Enterprise** — `enterprise.js` is now loaded instead of the standard `api.js` when `recaptcha_enterprise.enabled = true`, enabling proper Enterprise token generation.
- **reCAPTCHA Enterprise** — `grecaptcha.enterprise.execute()` / `grecaptcha.enterprise.ready()` are now called instead of the standard `grecaptcha.*` equivalents when Enterprise mode is active.
- **reCAPTCHA v3** — Removed premature `typeof grecaptcha !== "undefined"` guard in `DOMContentLoaded`; the guard prevented the submit-event listener from being attached when the async `api.js`/`enterprise.js` script had not yet executed, causing the form to submit with an empty token.
- **reCAPTCHA Enterprise** — Default `expected_action` changed from `'LOGIN'` to `'login'`; the action name passed to `grecaptcha.enterprise.execute()` is lowercase and the comparison is case-sensitive, so the mismatch caused every verification to fail.

## [1.19.0] - 2026-04-11

### Added
- **#264** — Address `mac` field: free-form MAC address (max 64 chars) stored per address; shown and editable in addresses, search, bulk update, and CSV import/export.
- **#262** — Address `expires_at` field: optional lease expiry date (`YYYY-MM-DD`); rows with a past expiry date are highlighted in the addresses table; filterable via `?expired=1` in the API.
- **#235** — Session activity log on user profile (`change_password.php`): last 10 auth events (login, logout, OIDC login, failed login) with IP and user agent; admins can view any user's activity via `?user_id=N`.
- **#236** — Config validation on boot: `ipam_validate_config()` checks key settings on every page load; admins see a dismissible banner for each invalid value.
- **#237** — Configurable utilization warn/critical thresholds: `utilization_warn` and `utilization_critical` config keys replace the previous hardcoded values (80/95); applied on dashboard and subnets list.
- **#265** — API bulk write endpoints: `POST ?resource=addresses&bulk=1` and `POST ?resource=subnets&bulk=1` accept a JSON array of up to 500 items; partial success returns HTTP 207; each item reports `{success, id?, error?}`.
- **#263** — IPv6 unassigned IP tracking: `unassigned.php` and `export_unassigned.php` now support IPv6 subnets (capped at 256 addresses); API `resource=unassigned` also supports IPv6.
- **#284** — reCAPTCHA Enterprise API support: new `recaptcha_enterprise` config block enables the GCP Enterprise Assessment API for backend token verification when `login_protection.method = 'recaptcha'`.
- **#246** — Subnet list deep-link: CIDR in the subnets table now links directly to the addresses view for that subnet.
- **#245** — Empty state CTA buttons: all empty-state divs across subnets, addresses, sites, audit, and search pages now include contextual action buttons.

### Changed
- **#238** / **#230** — PHPStan static analysis raised to level 9 (strictest); zero errors; empty baseline.
- **#243** — Audit log default page size changed from 20 to 50 rows.
- **#244** — Audit log Details column truncated with ellipsis (`max-width: 300px`); full text shown on hover via `title` attribute.

## [1.18.0] - 2026-04-09

### Added
- **#232** — API subnets: `site_id` integer field now included in GET subnet response objects alongside the existing `site` name field.
- **#258** — API subnets: `?counts=1` opt-in adds an `address_counts` object (`used`, `reserved`, `free`, `total`, `utilization_pct`) to each subnet in the GET response.
- **#231** — API addresses: `?site_id=` filter on GET addresses; returns addresses in subnets belonging to the specified site.
- **#260** — API: new `resource=unassigned` endpoint returns paginated unassigned IPv4 host IPs for a subnet; requires `?subnet_id=`; limited to /24 or smaller (≤256 assignable IPs).
- **#227** — Address history CSV export (`export_address_history.php`): per-address change history download linked from the Address History page.
- **#259** — Subnet utilization summary CSV export (`export_subnet_utilization.php`): one row per subnet with used/reserved/free/total/utilization% columns.
- **#261** — Cross-subnet all-addresses CSV export: `export_addresses.php` now supports omitting `subnet_id` (write-role users), producing a full export across all subnets with site column prepended.
- **#241** — Dismissible security warning banners on `db_tools.php`, `import_csv.php`, and `demo_gate.php`; dismissed per-session via `?dismiss_warning=<context>`.
- **#234** — `status.php` health-check response now includes `schema_version` (latest applied migration version); hidden when `status_hide_version` is set in config.

### Changed
- **#233** — Audit log UI (`audit.php`): Client IP column now displays `—` for null values instead of an empty cell.
- **#242** — DHCP Pools moved from the main navigation bar into the Admin dropdown.
- **#229** — PHPStan static analysis level raised from 6 to 7; all new type errors resolved (proper handling of `string|false` from `inet_pton`, `json_encode`, `unpack`, `PDO::query`; proper list typing for `applied_migrations` and `oidc_jwks`; `strtotime` casts).

### Fixed
- **#276** — `demo_gate.php` asset cache-buster URLs updated to match the running version (was stuck at `v=1.15.1`).
- **#240** — `addresses.php`: empty state when no subnet is selected now shows a navigation link to Subnets instead of a bare "No subnet selected." message.
- **#239** — `address_history.php`: missing or invalid `address_id` now renders a styled error page via `page_header()`/`page_footer()` instead of a bare HTTP response.

## [1.17.0] - 2026-04-08

### Added
- **#225** — Read-only API keys: new `is_readonly` flag on API keys blocks write operations (`POST`/`PUT`/`DELETE`) with a 403 response; toggle button in the admin UI lets admins flip any key between read-only and read-write without deactivating it.
- **#225** — API key description field: optional free-text description stored alongside the key name; shown in the keys table for documentation purposes.
- **#226** — Subnets API filter params: `GET /api.php?resource=subnets` now accepts `ip_version=4|6` and `vlan_id=1–4094` query parameters; both params validate on input (400 for invalid values) and can be combined with each other and the existing `site_id` filter.

### Changed
- **#224** — PHPStan static analysis level raised from 5 to 6; all 99 `missingType.iterableValue` errors resolved with `@param`/`@return` PHPDoc annotations across `lib.php`, `api.php`, `subnets.php`, `import_csv.php`, `migrations.php`, `init.php`, `address_history.php`, `search.php`, and `unassigned.php`.

## [1.16.0] - 2026-04-08

### Added
- **#216** — `apple-touch-icon.webp` added to `assets/`; `page_header()` now emits a WebP `<link rel="apple-touch-icon">` before the existing PNG fallback, matching the favicon WebP+PNG pattern.

### Fixed
- **#217** — `import_csv.php`: dead `if ($msg)` success block at the top of the rendered page removed; `$msg` is only assigned inside the Step 4 processing block that follows, so this check could never be true.
- **#218** — `import_csv.php`: redundant `if ($step === 4)` wrapper around the Step 4 block removed; Steps 1–3 each `exit` before reaching that point, so the condition is always true.
- **#219** — `import_csv.php`: dead `if ($entry['resolved_cidr'] === null)` guard removed; every non-`continue` code path assigns a `string` to `resolved_cidr` before line 266, making the null check unreachable.

## [1.15.0] - 2026-04-08

### Fixed
- **#204** — `demo_reset.php` and `demo_seed.php` passed an extra `$config` argument to `ipam_db_init()`, which takes only one parameter; removed the stray arg.
- **#205** — `import_csv.php` had an unreachable `page_footer()` call after the step dispatcher; all code paths exit before reaching it; removed the dead call.
- **#206** — `bulk_update.php` had a dead `if ($msg)` template block; `$msg` is initialised to `''` and never reassigned on that path; block removed.
- **#207** — `unassigned.php` same dead `if ($msg)` block removed.
- **#208** — `lib.php` and `api.php`: removed redundant `?? ''` guards on `explode()[0]` (offset 0 always exists) and removed redundant `!== null` after `isset()`.
- **#209** — `lib.php`: removed redundant `?? ''` on `$role` in `page_header()` (assigned unconditionally at function entry); added `@phpstan-impure` annotations to `housekeeping_should_run()` and `backup_is_due()`.
- **#182** — Card `margin-top` inside CSS Grid containers zeroed out; the `.card + .card` adjacency margin was reducing card height by 16 px in the Database Tools grid despite `gap` already providing the spacing.

## [1.14] - 2026-04-06

### Fixed
- **#193** — `oidc_fail()` in OIDC callback was missing required `$db` parameter, causing a TypeError crash on username collision after 5 retries.
- **#194** — Bulk update pagination referenced non-existent `total_pages` key (correct key: `pages`), so pagination controls never rendered.
- **#197** — `upgrade.sh` utility functions moved above first call site; `/home/*` path guard relaxed to allow subdirectories.
- **#198** — API subnet update now calls `find_parent_site_id()` to enforce site inheritance (matching web UI).
- **#199** — API sites update checks for duplicate name (409); sites delete wrapped in transaction; `api_audit()` now records `user_agent`.
- **#200** — `ipam_db_init()` sentinel path now calls `ensure_audit_log_table()` for self-healing; highlight-row CSS animation preserves zebra stripes.

### Security
- **#195** — SQL import `CREATE TRIGGER` now restricted to `RAISE(ABORT)` patterns only, preventing arbitrary SQL injection via crafted trigger bodies.
- **#196** — `prune_audit_log()` rewritten to drop triggers + DELETE + recreate (instead of `ALTER TABLE RENAME` + `SELECT *` which is unsafe in SQLite transactions).

## [1.13] - 2026-04-05

### Added
- **#174** — REST API `resource=search` endpoint (text search across addresses), `resource=audit` endpoint (paginated audit log with action/date filters), sites write API (POST/PUT/DELETE), `updated_at` field in address responses.
- **#176** — Dashboard quick action links, weekly/monthly growth trend metrics, responsive grid layout.
- **#168** — "Next available IP" shown on the addresses page for IPv4 subnets with a one-click "Use" link.
- **#162** — Search page now also searches subnets by CIDR and description.
- **#164** — Subnet CSV export (`export_subnets.php`) with optional site and IP version filters.
- **#167** — Address history renders before/after changes as a field-by-field diff table instead of raw JSON.
- **#175** — Client-side CIDR and IP validation via `data-validate` attributes with JavaScript validation on blur.
- **#181** — Database import shows a CSS spinner overlay during processing and reports statement count and elapsed time on completion.

### Changed
- **#182** — Export/Import cards on Database Tools page now stretch to equal height via CSS Grid `align-items: stretch`.
- **#166** — All data tables wrapped in `.table-wrap` for horizontal scroll on mobile. Subnet tree indentation collapses on small screens.
- **#165** — Dashboard "Top IPv4 Subnets" now sorted by utilization percentage instead of absolute address count.
- **#163** — Search results include "Edit" link that navigates to the address with the row highlighted.
- **#183** — SQL import file size limit now configurable via `import_sql_max_mb` in config.php (default 200 MB).
- **#171** — `prune_audit_log()` rewritten with subquery approach. Audit log DDL extracted into `ensure_audit_log_table()` helper (deduplicated from 4 locations).

### Fixed
- **#186** — Host header validation regex too strict (v1.12 regression). Widened to accept underscores in hostnames and IPv6 bracket notation.
- **#179** — Six code quality cleanups: removed redundant config.php load, duplicate `global $config`, uninitialised `$formError`, dead `$auditQs` variable, exposed PDO exceptions, added sentinel file for bootstrap queries.
- **#178** — OIDC username collision retry now loops up to 5 attempts with incrementing suffix.
- **#177** — DHCP pool page accessible to readonly users (view-only); write-access check moved inside POST handlers.
- **#173** — Inline theme-priming script replaced with a `<meta>` tag + app.js reader, fixing CSP `script-src` violation.
- **#169** — Added breadcrumb navigation to bulk_update.php and sites.php.

### Security
- **#170** — Status endpoint version field now conditional on `status_hide_version` config. API key via query parameter emits `Deprecation` response header.

## [1.12] - 2026-04-05

### Added
- **#137** — Subnet overlap confirmation: creating or updating a subnet that overlaps existing subnets shows a confirmation page. API responses include a non-blocking `warnings` array.
- **#154** — Flash messages survive POST-redirect-GET via `flash_set()`/`flash_get()`. Applied to sites, addresses, API keys, and subnet overlap warnings.
- **#157** — CSV export of search results now respects site and IP version filters.
- **#151** — Indexes on `audit_log(action)` and `audit_log(created_at)` for faster filtering and date-range queries.

### Changed
- **#149** — Subnet tree builder rewritten from O(N²) to O(N log N) using a sorted-stack algorithm.
- **#150** — IPv4 utilization replaced with single `GROUP BY` aggregate query.
- **#152** — Database export refactored into streaming `ipam_db_dump_stream()`.
- **#160** — Bulk update page paginated to prevent memory exhaustion on large subnets.
- **#147** — API audit logging now uses `client_ip()` (respects proxy trust config).
- **#148** — API address create/update/delete now record entries in `address_history`.
- **#153** — API subnet creation inherits `site_id` from the tightest enclosing parent subnet.

### Fixed
- **#158** — Dashboard empty-state text corrected from "/8–/30" to "/8–/32".
- **#159** — Audit action names normalised: `api_key.*` → `apikey.*`, `user.password_change` → `user.change_password`. Migration updates existing rows.
- **#161** — POST action fall-through in `addresses.php` and `sites.php` fixed with `if`→`elseif` chains.

### Security
- **#146** — Disabling or demoting the last active admin account is now blocked.
- **#155** — SQL import whitelist: only `INSERT`, `CREATE`, `DROP`, `DELETE` allowed. `ATTACH`, `DETACH`, `LOAD_EXTENSION` blocked.
- **#156** — Host header validated before HTTPS redirect when `base_url` is not configured.

## [1.11] - 2026-03-15

### Added
- **#133** — Group field on addresses: optional free-text `group` field shown as badge, searchable, in CSV exports/imports, and REST API.
- **#134** — Browser tab title now reads `{AppName} — {Page}`.
- **#135** — Configurable `app_name` in config.php.
- **#136** — App name in nav bar brand link and login page.
- **#138** — VLAN ID (1–4094) on subnets with badge in subnet list and REST API.
- **#139** — REST API write support: POST/PUT/DELETE for addresses and subnets.
- **#140** — Per-user theme persistence in `users.theme`.
- **#141** — Session idle timeout notice on login page.
- **#142** — DHCP pool view/edit: hostname, owner, note columns; inline Edit/Delete; range headers.
- **#143** — Application logo and favicons.

## [1.10] - 2026-02-20

### Fixed
- **#130** — CSV import PHP 8.4 deprecation: `str_getcsv`/`fgetcsv` now pass `''` as escape argument.
- **#127** — "Add Subnet" card duplicate `class` attribute merged.
- **#128** — Audit log filter labels now inline with controls.
- **`.htaccess`** — Removed global CSP header that conflicted with PHP per-page CSP.

## [1.9] - 2026-02-01

### Added
- **#124** — Optional login form bot protection (`honeypot`, `time_check`, `turnstile`, `hcaptcha`, `recaptcha`, `friendly_captcha`).
- **#125** — Optional demo mode pre-login challenge gate.
- **#114** — API history endpoint returns 200 (not 404) for deleted addresses.
- **#115** — Demo mode login rate limiting.
- **#119** — Admin notice when config keys could not be auto-saved.

### Changed
- **#116** — `find_containing_subnet()` uses per-request static cache for large CSV imports.

### Fixed
- **#107** — OIDC auto-link email fallback was matching usernames.
- **#109** — Login timing side-channel for username enumeration.
- **#111** — OIDC username claims not sanitised.
- **#108** — `validate_password_complexity()` used `strlen()` instead of `mb_strlen()`.
- **#118** — Dashboard top subnets excluded /31 and /32.
- **#120** — `prune_audit_log()` used `$db->quote()` instead of prepared statement.
- **#122** — `demo_reset_db()` blocked by append-only trigger.

### Security
- **#112** — CSP `style-src 'unsafe-inline'` removed; all 122 inline styles replaced with CSS classes.
- **#113** — `backup_dir()` path traversal via relative paths.
- **#117** — Failed login audit no longer logs submitted username.

## [1.8] - 2026-01-15

### Fixed
- **#104** — CSRF mismatch now redirects to login instead of plain-text error.
- **#106** — HTTP→HTTPS redirect used `HTTP_HOST` directly; new `base_url` config option.
- **#98** — `IPAM_VERSION` double-include fatal error.
- **#103** — DHCP pool missing demo mode guard.
- **#99** — `data/.htaccess` OLS compatibility (RewriteRule instead of FilesMatch).
- **#100** — Root `.htaccess` OLS compatibility.
- **#101** — `upgrade.sh` now always overwrites `data/.htaccess`.

## [1.7] - 2025-12-20

### Added
- **#86** — Server-side sortable columns on all data tables.
- **#87** — `data/.htaccess` hardened with extension blocking.
- **#88** — nginx and Caddy configuration docs.
- **#90** — Opt-in demo mode with nightly reset and realistic seed data.

### Fixed
- **#85** — User creation form field alignment with password managers.
- **#89** — Audit page Export/Apply button alignment.

## [1.6] - 2025-12-01

### Added
- **#82** — `address_history_retention_days` config option.
- **#81** — CSV exports include UTF-8 BOM for Excel.
- **#75** — Dark mode calendar picker icon visibility.

### Fixed
- **#77** — Users admin page error messages swallowed after v1.5 refactor.
- **#73** — SSO-only toggle state on page load.
- **#78** — Subnet overlap warnings XSS.
- **#79** — Unassigned quick-add raw PDO exception leak.

### Security
- **#74** — All inline JS event handlers removed; CSP `script-src 'self'` compliance.
- **#80** — OIDC HTTP response reads capped at 1 MB.

## [1.5] - 2025-11-15

### Added
- **#59** — Password complexity returns all failing rules at once.
- **#60** — User creation form re-populates on validation failure.
- **#61** — OIDC `auto_link` separated from `auto_provision`.
- **#68** — API 429 includes `Retry-After` and `X-RateLimit-Limit` headers.
- **#70** — Audit log date filters (From / To).
- **#67** — Security headers on all responses (CSP, X-Frame-Options, etc.).

### Fixed
- **#69** — Duplicate subnet/address shows specific "already exists" message.

### Security
- **#64** — `X-Forwarded-For` validated when `proxy_trust` enabled.
- **#65** — OIDC callback error parameters sanitised.
- **#66** — OIDC auto-provision validates `netops` role.

## [1.4] - 2025-11-01

### Added
- **#41** — `netops` role with write access but no user/key management.
- **#38** — SSO-only accounts with unusable password hash.
- **#39** — Password complexity and rotation policy.
- Audit log access opened to all roles.

## [1.3] - 2025-10-15

### Added
- **#37** — Collapsible site groups on Subnets page with localStorage persistence.
- **#55** — API subnets pagination.
- **#56** — Search results Site column.

### Fixed
- **#53** — XSS in inherited site name warning.
- **#52** — Version comparison for patch releases.
- **#48** — Rate limiting empty IP fallback.
- **#54** — Subnet delete now records cascade-deleted address count.
- **#47** — Search query capped at 500 characters.

## [1.2.3] - 2025-10-05

### Fixed
- Site group collapse removed from v1.2.x due to rendering issues; restored to static divs.

## [1.2.2] - 2025-10-04

### Fixed
- Site groups replaced with div-based JS toggle after `<details>` browser issues.

## [1.2.1] - 2025-10-03

### Fixed
- Site groups blank due to `<h2>` inside `<summary>` breaking `<details>` rendering.

## [1.2] - 2025-10-01

### Added
- **#35** — Parent subnet utilization bars roll up descendant counts.
- **#37** — Collapsible site groups with `<details>` elements.
- **#40** — Config deep-merge for nested blocks on upgrade.
- **#44** — API rate limiting.

### Fixed
- **#43** — LIKE wildcard escaping in search/filter queries.
- **#45** — OIDC SSL peer verification.
- **#46** — Hostname/owner/note length enforcement.
- **#42** — SSO-only accounts no longer shown password change form.

## [1.1] - 2025-09-15

### Added
- **#22** — Free-status addresses excluded from utilization.
- **#23** — Bulk edit shows unconfigured IPs with quick-add.
- **#25** — `ipam_update_check()` memoised.
- **#28** — Default password warning banner.
- **#29** — API address history endpoint.
- **#30** — Subnet node address count badges.
- **#31** — Audit log category filter.

### Fixed
- **#21** — Update-check cache not invalidated after upgrade.
- **#26** — Fresh installs now stamp all migrations.
- **#27** — `find_containing_subnet()` returned broadest instead of tightest parent.

## [1.0] - 2025-09-01

### Added
- Audit log retention (`audit_log_retention_days` config option).
- Health check endpoint (`status.php`).

### Fixed
- HTML escaping in Database Tools.
- OIDC claim values capped at 255 characters.

### Security
- Name/email `maxlength` enforced in UI and server-side.

## [0.15] - 2025-08-15

### Added
- Config auto-population of missing keys on boot.
- Automatic database backups (daily/weekly, configurable retention).
- Database Tools admin page (export, import, manual backup).
- Mobile-responsive CSS (768px and 480px breakpoints).
- Dismissible update banner; `notify_prerelease` option; 24-hour cache TTL.

## [0.14] - 2025-08-01

### Added
- DHCP pool reservation tool (bulk reserve/clear IPv4 ranges).
- Update check badge in footer.
- User management: disable self-targeting guards, last login column.
- Subnet utilization colour-coded progress bars.
- OIDC emergency access controls (`hide_emergency_link`, `disable_emergency_bypass`).
- Application renamed to Simple PHP IPAM.

## [0.13] - 2025-07-15

### Added
- User management: name/email fields, delete users, disable/enable, manual OIDC linking.
- OIDC: `preferred_username` claim, name/email sync, auto-link by username then email.
- `disable_local_login` config option.
- Child subnets inherit site from tightest parent.

## [0.12] - 2025-07-01

### Added
- User dropdown menu (username + role badge on far-right of nav).
- OIDC Authorization Code + PKCE authentication (pure PHP, no Composer).
- Auto-provisioning and auto-linking of OIDC users.

### Fixed
- `api.php` addresses resource used `description` instead of `note`.

## [0.11] - 2025-06-15

### Added
- Login rate limiting (IP-based lockout).
- Session idle timeout.
- Admin dropdown menu.
- Single theme cycle button.
- Read-only JSON REST API with Bearer token auth.
- API key management admin page.

## [0.10] - 2025-06-01

### Added
- CSV exports for addresses, search results, audit log, unassigned IPs, and import reports.
- CSV import safety: dry-run plan, row-level report, duplicate/conflict detection.

[2.1.5]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.4...v2.1.5
[2.1.4]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.3...v2.1.4
[2.1.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.2...v2.1.3
[2.1.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.1...v2.1.2
[2.1.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.19.1...v2.0.0
[1.19.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.19.0...v1.19.1
[1.19.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.18.0...v1.19.0
[1.18.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.17.0...v1.18.0
[1.17.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.16.0...v1.17.0
[1.16.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.15.0...v1.16.0
[1.15.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.14...v1.15.0
[1.14]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.13...v1.14
[1.13]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.12...v1.13
[1.12]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.11...v1.12
[1.11]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.10...v1.11
[1.10]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.9...v1.10
[1.9]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.8...v1.9
[1.8]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.7...v1.8
[1.7]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.6...v1.7
[1.6]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.5...v1.6
[1.5]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.4...v1.5
[1.4]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.3...v1.4
[1.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2.3...v1.3
[1.2.3]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.2...v1.2.1
[1.2]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.1...v1.2
[1.1]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v1.0...v1.1
[1.0]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.15...v1.0
[0.15]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.14...v0.15
[0.14]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.13...v0.14
[0.13]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.12...v0.13
[0.12]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.11...v0.12
[0.11]: https://github.com/seanmousseau/Simple-PHP-IPAM/compare/v0.10...v0.11
[0.10]: https://github.com/seanmousseau/Simple-PHP-IPAM/releases/tag/v0.10
