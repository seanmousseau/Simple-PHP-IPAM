# CHANGELOG

All notable changes to this project will be documented in this file.

---

## 1.3 — Hardening, Search & API Improvements

### Security fixes
- **Fixed #53:** XSS — unescaped site name in `$warn` output on the Subnets page when a site is auto-inherited from a parent subnet. Site name is now passed through `e()` before being embedded in the warning message.

### Bug fixes
- **Fixed #52:** Upgrade detection did not recognise patch releases when the installed version used a two-part number (e.g. `1.2` vs `1.2.1`). Both the installed version and the GitHub release tag are now normalised to three segments before comparison (`1.2` → `1.2.0`).
- **Fixed #48:** Rate limiting fell back to an empty string when `REMOTE_ADDR` was absent (unusual SAPI environments). Changed fallback to `127.0.0.1` in both `api.php` and `client_ip()` in `lib.php`.
- **Fixed #54:** Deleting a subnet did not record how many addresses were cascade-deleted. The `subnet.delete` audit entry now includes `addresses_deleted=N`.

### Hardening
- **Fixed #47:** Search query `$q` in `search.php`, `bulk_update.php`, and `export_search.php` is now capped at 500 characters server-side before being passed to the SQLite LIKE expression.

### Enhancements
- **Feature #37:** Site groups on the Subnets → Grouped Hierarchy section are now collapsible/expandable. Click a site group header to toggle. State persists across page loads via `localStorage`. Implemented using `aria-expanded` as the CSS hook so all groups are always fully rendered on initial page load — collapse state is applied after JS runs.
- **Feature #55:** REST API `?resource=subnets` endpoint now supports pagination (`&page=N&limit=N`, max 1000, default 200). Response includes `total`, `page`, `limit`, and `subnets` fields.
- **Feature #56:** Search results table now includes a **Site** column, showing which site each result's subnet belongs to (or a dash for ungrouped subnets).

---

## 1.2.3 — Patch

### Bug fixes
- **Fixed:** Site group collapse feature (issue #37) completely removed from v1.2.x due to persistent rendering issues across multiple approaches (`<details>/<summary>`, div+JS toggle). Site groups are restored to plain static `<div>` sections (matching v1.1 behaviour) to unblock the release. Collapse/expand will be re-implemented as a standalone issue against v1.3.

---

## 1.2.2 — Patch

### Bug fixes
- **Fixed:** Site groups on the Subnets page still not displaying after v1.2.1. The `<details>`/`<summary>` approach had browser-compatibility issues that caused inconsistent rendering. Replaced entirely with a `<div>`-based JS toggle (click handler on header div, CSS `collapsed` class, `display:none` on body). The localStorage key prefix changed to `ipam_sg_*` to clear any stale state written by v1.2/v1.2.1.

---

## 1.2.1 — Patch

### Bug fixes
- **Fixed:** Site groups on the Subnets page were blank — no subnets or group labels visible. The `<summary>` element contained an `<h2>` (a block-level element), which caused browsers to implicitly close the `<summary>` early and break `<details>` rendering. Replaced with a styled `<span class="site-group-label">` to produce valid, conformant HTML.

---

## 1.2 — Security Hardening & UX Improvements

### Bug fixes
- **Fixed #43:** LIKE wildcard characters (`%`, `_`, `\`) in search and filter inputs were passed unescaped to SQLite, causing incorrect or overly broad results. All LIKE queries now use `ESCAPE '\\'` with a new `like_escape()` helper in `lib.php`.
- **Fixed #45:** OIDC HTTP functions (`oidc_http_get` / `oidc_http_post`) did not explicitly request SSL peer verification in their stream contexts. Added `'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]` to both.
- **Fixed #46:** Hostname, owner, and note fields in `addresses.php` had no server-side length enforcement. Added `substr()` clamping (253 / 255 / 1000 chars) on both create and update, plus matching `maxlength` attributes on the HTML inputs.
- **Fixed #42:** SSO-only accounts (those with `oidc_sub` set) shown an empty password change form they could not use. The page now detects SSO-only accounts and displays an informational message instead of the form.

### Security enhancements
- **#40:** Config upgrade logic (`ipam_config_sync`) now deep-merges missing sub-keys into existing nested config blocks, ensuring new options within existing blocks (e.g. `oidc`) are correctly populated on upgrade.
- **#44:** The REST API now applies the same IP-based rate limiting used by the web login form. After `api_max_attempts` failures from an IP the endpoint returns HTTP 429 for `api_lockout_seconds`. New config keys `api_max_attempts` (default 20) and `api_lockout_seconds` (default 300) are auto-populated on upgrade.

### Enhancements
- **#35:** Parent subnet utilization bars in the subnet list now roll up assignable/assigned counts from all descendant subnets. When a parent has children, the bar and IP counts reflect the entire subtree with an *(incl. subnets)* annotation.
- **#37:** Site groups on the Subnets page are now collapsible. Each group renders as a `<details>` element with a caret indicator; collapse/expand state is persisted per-site-key in `localStorage`.

---

## 1.1 — Bug Fixes & Enhancements

### Bug fixes
- **Fixed #21:** Update-check cache was not invalidated after an upgrade. The cached version is now compared against `IPAM_VERSION` on load; if the cache is stale (cached version ≤ running version), it is deleted and re-fetched immediately.
- **Fixed #26:** Fresh installs did not stamp existing migrations as applied. `ipam_db_init()` now inserts all migration version keys into `schema_migrations` when initialising a brand-new database, preventing spurious re-runs of already-incorporated migrations on any future upgrade.
- **Fixed #27:** `find_containing_subnet()` returned the broadest matching parent instead of the tightest. `ORDER BY prefix ASC` was changed to `ORDER BY prefix DESC` so the most-specific parent is selected.

### Enhancements
- **#22:** Free-status addresses no longer count towards subnet utilisation. Progress bars and the dashboard Top Subnets table now only include `used` and `reserved` addresses in the numerator.
- **#23:** Bulk edit tool shows unconfigured IPs. For IPv4 subnets with ≤ 4094 assignable IPs and no active search filter, all IPs not yet in the addresses table appear as dimmed `free (unconfigured)` rows. Selecting them and applying **Update** inserts them with the chosen field values and logs the creation. A "Select unconfigured" button makes batch-configuring new IPs easy.
- **#25:** `ipam_update_check()` is now memoised; calling it from both `page_header()` and `page_footer()` no longer makes two HTTP requests per page.
- **#28:** A dismissible danger banner is shown to all logged-in users when the default bootstrap admin password (`ChangeMeNow!12345`) is still in use.
- **#29:** New REST API resource `GET api.php?resource=history&address_id=<id>` returns the paginated change history for any address record, with decoded `before`/`after` JSON objects.
- **#30:** Subnet node headers in the subnet list now display coloured address count badges (`N used · N reserved · N free`) with an optional subtree aggregate when child subnets exist.
- **#31:** Audit log now has an **action category filter** dropdown (`auth`, `subnet`, `address`, `user`, `site`, `apikey`, `dhcp_pool`, `export`, `import`). The filter is preserved across pagination.

---

## 1.0 — Production Release

### Security hardening
- **Fixed:** `db_tools.php` error and message output was not HTML-escaped at the output point; inner `e()` calls were removed and `e()` is now applied consistently at echo time, matching every other page.
- **Hardened:** OIDC claim values (`name`, `email`, `preferred_username`) are now clamped to 255/255/64 characters respectively before storage, preventing unbounded writes from a misconfigured or malicious IdP.
- **Hardened:** `users.php` now enforces `maxlength=255` on name and email fields in both the create and profile-edit forms, plus server-side `substr()` clamping, matching the OIDC claim limit.

### Schema improvements
- **Updated:** `schema.sql` is now the complete canonical schema for v1.0, including all tables, columns, and indexes that were previously only created by migrations. Fresh installs now have a fully functional database from the first request.
- The partial unique index on `users.oidc_sub WHERE oidc_sub IS NOT NULL` is now present in `schema.sql` (was previously only in migration 0.12).

### Audit log retention
- New `audit_log_retention_days` config option (default `0` = keep forever). When set, entries older than the specified number of days are pruned during scheduled housekeeping.
- Pruning is performed safely via a staging table swap that preserves the append-only integrity triggers.
- The new config key is auto-populated on upgrade via the existing config-sync mechanism.

### Health check endpoint (`status.php`)
- New unauthenticated `GET /status.php` endpoint for uptime monitors, load balancers, and container health checks.
- Returns `HTTP 200` with `{"status":"ok","version":"1.0","db":"ok"}` when healthy.
- Returns `HTTP 503` with `{"status":"error","db":"error"}` when the database is unreachable.

### Code cleanup
- Removed deprecated `window.ipamToggleTheme` and `window.ipamClearTheme` JavaScript aliases from `assets/app.js`. Only `window.ipamCycleTheme` remains.
- Asset cache busters updated to `?v=1.0` in `page_header()`.

---

## 0.15 — Config Auto-Population, Backups, Database Tools, Mobile GUI, Update Check Enhancements

### Config auto-population
- Missing top-level keys are automatically appended to `config.php` with their defaults on application boot
- A one-time notice is shown in the admin panel identifying which keys were added
- Applies to: `update_check.notify_prerelease`, the new `backup` block, and any future keys added by upgrades

### Automatic database backups (`lib.php`, `config.php`)
- New `backup` config block: `enabled`, `frequency` (`daily`|`weekly`), `retention`, `dir`
- Backup runs on page load when the configured interval has elapsed (file-locked, non-blocking)
- Backup format: WAL-checkpointed SQLite copy with timestamp filename (`ipam-YYYY-MM-DD-HHmmss.sqlite`)
- Older backups beyond the retention count are pruned automatically
- Disabled by default — opt in via `'backup' => ['enabled' => true]` in `config.php`

### Database Tools admin page (`db_tools.php`)
- New **Database Tools** entry in the Admin nav dropdown
- **Export**: one-click download of a full SQL dump (schema + data + indexes), compatible with SQLite
- **Import**: upload a `.sql` dump to replace all data; pre-import backup is created automatically; executes in a transaction with rollback on error
- **Manual backup**: trigger an out-of-schedule backup from the UI
- Backup status panel: last backup time, last file name, current backup count
- All export, import, and backup actions are recorded in the audit log

### Mobile-optimized GUI (`assets/app.css`)
- Responsive CSS breakpoints at **768 px** and **480 px**
- Tables, cards, forms, toolbars, and metric blocks stack gracefully on narrow viewports
- Navigation wraps and pills shrink on mobile; all primary CRUD actions remain accessible
- New `.table-scroll` utility class for horizontally scrollable tables on small screens
- Admin notice styles for config and update banners

### GitHub update checker enhancements (`lib.php`, `config.php`)
- New `update_check.notify_prerelease` option (default `false`): opt in to alerts for alpha/beta/RC releases
- Update check now fetches the `/releases` list (not just `/releases/latest`) to support pre-release detection
- Cache TTL extended to 24 hours (was 6 hours); configurable via `update_check.ttl_seconds`
- **Dismissible update banner** shown to admins at the top of every page (separate from the footer badge)
- Dismiss action stores dismissed version in `localStorage`; banner re-appears for the next release

---

## 0.14 — DHCP Pools, Update Check, User Hardening, Utilization Badges

### Application branding
- Application renamed from **PHP SQLite IPAM** to **Simple PHP IPAM** throughout
- Footer app name is now a link to the GitHub repository (opens in new tab)

### Update check
- Footer shows an **Update available vX.Y** badge when a newer GitHub release exists
- Fetches the GitHub releases API with a User-Agent header; result cached for 6 hours (configurable)
- Skips drafts and pre-releases; silently no-ops on network failure
- Opt-out via `'update_check' => ['enabled' => false]` in `config.php`

### DHCP pool reservation tool (`dhcp_pool.php`)
- New admin page to bulk-reserve a contiguous IPv4 range within any subnet
- IPs already marked `used` are always skipped; `reserved`/`free` records are updated
- Separate **Clear** form removes `reserved` records from a range (leaves `used` untouched)
- Max 1,024 IPs per operation; linked from each subnet's action bar and the Admin nav dropdown
- All operations recorded in the audit log

### User management hardening (`users.php`)
- **Disable**, **Set role**, and **Unlink SSO** are hidden in the UI for the logged-in admin's own row
- Server-side guards: `toggle_active`, `set_role`, and `unlink_oidc` all reject self-targeting
- **Last login** column added to the users table; populated on every login (local and OIDC)

### Subnet utilization badges (`subnets.php`, `assets/app.css`)
- IPv4 subnets show a colour-coded mini progress bar and percentage next to assignable counts
- Green → yellow (≥ `utilization_warn`, default 80%) → red (≥ `utilization_critical`, default 95%)
- Thresholds configurable in `config.php`

### OIDC emergency access controls (`login.php`, `config.php`)
- New `hide_emergency_link` option: hides the emergency link text without disabling the URL
- New `disable_emergency_bypass` option: makes `login.php?local=1` completely ineffective

### Database migration
- Migration `0.14`: adds `last_login_at TEXT` column to the `users` table

---

## 0.13 — User Management, Site Inheritance, OIDC Improvements

### User management (`users.php`, `migrations.php`)
- Added **Name** and **Email** fields to every user account; stored in new `name` and `email` DB columns (migration `0.13`)
- Users table now shows Name and Email columns; inline edit form per-user to update them
- Added ability to **delete users** with guard: you cannot delete your own account or the last active admin
- Added ability to **disable/enable** user accounts (previously existed but now surfaced more prominently)
- Added **manual OIDC linking**: admin can paste an IdP subject ID (`sub` claim) to link any existing account to an SSO identity
- Actions per user are collapsed in a `<details>` element to keep the table compact

### OIDC improvements (`oidc_callback.php`, `config.php`)
- Username for auto-provisioned users is now derived from the `preferred_username` claim (falls back to the local-part of `email`, then `sub`)
- `name` claim is stored as the user's display name; `email` claim is stored as the email address on create or first link
- Auto-link now tries `preferred_username` first, then `email`/username match against existing local accounts before creating a new account
- Name and email are silently synced from IdP claims on every login when the fields are blank
- Username collision during auto-provision is handled by appending a short random suffix
- New `disable_local_login` config option: when OIDC is enabled and this is `true`, the password form is hidden; emergency local access always available at `login.php?local=1`

### Site inheritance for child subnets (`subnets.php`, `lib.php`)
- Child subnets automatically inherit the site of their tightest enclosing parent that has a site assigned
- Site field is replaced with a read-only locked badge (showing the inherited site name) for child subnets in the edit form
- `find_parent_site_id()` resolves the inherited site by querying the containment tree built by `detect_subnet_overlaps()`

### Login page (`login.php`)
- First-run hint ("use bootstrap admin…") is now hidden once any successful login has been recorded in the audit log — no migration or extra state needed

---

## 0.12 — User Menu Redesign and OIDC Authentication

### Milestone 1 — User Menu & Nav Polish

#### User dropdown (`lib.php`, `assets/app.css`, `assets/app.js`)
- Username and role badge moved from the far-left of the nav bar to the far-right
- Password, Logout, and Theme toggle consolidated into a single user dropdown menu
- Theme button remains open in the dropdown while cycling so the user can step through modes without re-opening
- Right-anchored dropdown variant (`.nav-dropdown-menu--right`) prevents off-screen overflow
- `.nav-dropdown-item` unified to style both `<a>` and `<button>` elements identically; divider between Theme and account links

---

### Milestone 2 — OIDC Authentication

#### Authorization Code + PKCE flow (`oidc_login.php`, `oidc_callback.php`, `lib.php`)
- Full OIDC Authorization Code + PKCE flow implemented in pure PHP — no Composer packages or external dependencies
- `oidc_login.php`: generates PKCE verifier/challenge pair and state/nonce, stores them in session, redirects to IdP
- `oidc_callback.php`: validates state (CSRF guard), exchanges authorization code for tokens, verifies ID token, resolves local user
- ID token verification supports RS256/RS384/RS512; JWK→PEM conversion is done in-process using PHP's `openssl` extension
- JWKS are cached in `data/tmp/` for 1 hour; one automatic cache-bust retry handles in-flight key rotation
- Discovery document (`/.well-known/openid-configuration`) is also cached for 1 hour

#### ID token verification (`lib.php`)
- `oidc_verify_id_token()`: validates signature, `exp`, `iat`, `iss`, `aud`, and `nonce`
- `jwk_rsa_to_pem()`: constructs DER SubjectPublicKeyInfo from JWK `n`/`e` fields without `ext-gmp`
- `oidc_pkce_pair()`: generates RFC 7636 S256 code challenge from a 32-byte random verifier

#### User provisioning and linking
- On successful OIDC login the `sub` claim is matched against `users.oidc_sub`
- With `auto_provision = true`: if no linked user is found, an existing local user with a matching username/email is linked; otherwise a new account is created with an unusable password and the configured `default_role`
- OIDC lifecycle events recorded in audit log: `auth.oidc_login`, `auth.oidc_provision`, `auth.oidc_link`, `auth.oidc_failed`

#### Database migration (`migrations.php`)
- Migration `0.12` adds `oidc_sub TEXT` column to `users` table
- Partial unique index enforces that each IdP subject maps to at most one local user

#### Admin UI (`users.php`)
- SSO column shows "linked" (green, full `sub` on hover) or "—" for each user
- "Unlink SSO" button lets admins remove the `oidc_sub` association without deleting the account

#### Login page (`login.php`)
- When OIDC is enabled, a prominent "Sign in with \<display_name\>" button appears above the local login form
- OIDC error flash messages (set by the callback on failure) are displayed on the login page

#### Configuration (`config.php`)
- New `oidc` section: `enabled`, `display_name`, `client_id`, `client_secret`, `discovery_url`, `redirect_uri`, `scopes`, `auto_provision`, `default_role`
- Defaults to disabled; no behaviour change for existing installs

#### Bug fix
- `api.php`: addresses resource used non-existent `description` column; corrected to `note` to match schema

---

## 0.11 — Security Hardening, Nav Polish, and REST API

### Milestone 1 — Security Hardening

#### Login rate limiting (`login.php`, `lib.php`, `migrations.php`)
- New `login_attempts` table (migration `0.11`) tracks failed login attempts per IP with a timestamp
- Logins from an IP are blocked for a configurable window after too many consecutive failures
- New `lib.php` functions: `login_rate_limited()`, `record_login_failure()`, `clear_login_failures()`, `purge_old_login_attempts()`
- Successful login clears the failure counter for that IP
- Stale attempt records are purged opportunistically on each login page load
- Blocked attempts are recorded in the audit log as `auth.login_blocked`
- Configurable via `config.php`: `login_max_attempts` (default 5), `login_lockout_seconds` (default 900)

#### Session idle timeout (`lib.php`)
- `require_login()` now checks `$_SESSION['last_active']` and logs users out after a configurable inactivity period
- `login_user()` seeds `last_active` at login time; `require_login()` refreshes it on every authenticated page load
- Expired sessions redirect to `login.php?timeout=1` with an informational message
- Configurable via `config.php`: `session_idle_seconds` (default 1800 / 30 min)

---

### Milestone 2 — Nav & UX Polish

#### Admin dropdown menu (`lib.php`, `assets/app.css`, `assets/app.js`)
- Admin-only nav links (Sites, Users, API Keys, Import CSV) are grouped under a single **⚙ Admin ▾** dropdown
- Dropdown opens on click and closes when clicking outside, reducing visual clutter for non-admin users

#### Single theme toggle (`lib.php`, `assets/app.js`)
- Replaced the two separate Theme/System buttons with a single **cycle button**: System → Light → Dark → System
- Button label updates dynamically to show the current mode (🖥 System / ☀ Light / 🌙 Dark)
- Legacy `ipamToggleTheme` and `ipamClearTheme` JS functions retained for backward compatibility

---

### Milestone 3 — REST API

#### Read-only JSON API (`api.php`)
- New stateless endpoint with no session dependency; authenticated via API key
- API key passed as `Authorization: Bearer <key>` header or `?api_key=` query parameter (header preferred)
- `last_used_at` timestamp updated on each successful API request
- Resources:
  - `GET api.php?resource=subnets` — list all subnets (with site name)
  - `GET api.php?resource=subnets&id=N` — single subnet by ID
  - `GET api.php?resource=addresses` — paginated address list; filterable by `subnet_id`, `status`; `page`/`limit` params (max 500 per page)
  - `GET api.php?resource=sites` — list all sites

#### API key management (`api_keys.php`, `migrations.php`)
- New `api_keys` table (migration `0.11`): `name`, `key_hash` (SHA-256), `created_at`, `last_used_at`, `is_active`, `created_by`
- Admin-only `api_keys.php` UI: generate keys (raw key shown once), deactivate, re-activate, delete
- Key generation uses `random_bytes(32)` encoded as 64-character hex; only the SHA-256 hash is persisted
- All key lifecycle events (create, deactivate, activate, delete) are written to the audit log

---

## 0.10 — Exports, Import Safety, Overlap Detection, and Dashboard

### Milestone 1 — Export Foundation

#### New: CSV exports
- `export_addresses.php` — export all addresses for a selected subnet
- `export_search.php` — export current filtered search results
- `export_audit.php` — admin-only export of audit events
- `export_unassigned.php` — export unassigned IPv4 addresses for supported subnets
- `export_import_report.php` — export dry-run plan or final import result report

#### UI updates
- Added **Export CSV** links/buttons to `addresses.php`, `search.php`, `audit.php`, and `unassigned.php`

#### Shared export helpers (`lib.php`)
- `safe_export_filename()` — sanitised timestamped filename generation
- `csv_download_headers()` — sets `Content-Type` and `Content-Disposition` headers
- `csv_output_handle()` — returns a `php://output` file handle
- `csv_out()` — writes a single row to the CSV stream
- `audit_export()` — records export actions in the audit log

#### Audit
- All export actions are written to `audit_log`: `export.addresses`, `export.search`, `export.audit`, `export.unassigned`

---

### Milestone 2 — Import Safety, Dry Run, and Reporting

#### New: Dry-run import analysis
- Import wizard now analyzes the CSV before applying any changes
- Dry-run analysis is saved as a frozen JSON plan in `data/tmp/`; the apply step reads the plan rather than re-parsing the CSV
- Row-level report shows: row number, IP/raw value, planned action, resolved subnet/CIDR, and reason
- Summary counts: parsed rows, invalid rows, creates, updates, skips, subnets to create
- Final apply report: created subnets, created addresses, updated addresses, skipped rows, conflicts
- Reports persist after import for post-import review and CSV export

#### Import plan helpers (`lib.php`)
- `save_import_plan()`, `load_import_plan()`, `delete_import_plan()`, `cleanup_tmp_import_plans()`
- `tmp_cleanup.php` updated to clean stale import plan and result files in addition to uploaded CSV files

#### Hardening and correctness (Milestones 2.1 / 2.2)
- Fixed subnet creation counter inflation — newly created CIDRs are counted only once per import run
- Added **duplicate row detection within the same CSV** — later duplicate rows (same resolved CIDR + IP) are marked skipped
- Added **CIDR/IP cross-validation** — a row is invalid if the provided CIDR does not contain the IP
- Added **field length validation** — hostname (255), owner (255), note (4000) are checked before analysis
- Dry-run decisions are **frozen in the plan**: final action, resolved CIDR, resolved subnet ID, and whether the address existed at analysis time
- Added **apply-time conflict detection** — if the DB changes between dry-run and apply, affected rows are flagged as conflicts instead of silently applying unexpected changes
- Clarified `fill_empty` semantics: fills only empty text fields; never overwrites status

---

### Milestone 3 — Subnet Overlap Detection

#### New: Overlap detection helper (`lib.php`)
- `detect_subnet_overlaps($db, $cidr, $excludeId)` — compares a proposed CIDR against all existing subnets of the same IP version using binary network comparison
- Returns `parents` (existing subnets that contain the proposed CIDR) and `children` (existing subnets that would fall inside it)
- In valid CIDR math, two subnets of different prefix lengths are either in a strict parent/child relationship or completely disjoint — partial overlap is impossible; exact duplicates are blocked by the DB `UNIQUE` constraint

#### Subnet create/update warnings (`subnets.php`)
- On **create**: overlap check runs before the `INSERT`; if a relationship is detected, a flash warning is stored in the session and displayed after the redirect
- On **update**: overlap check runs against all other subnets (self excluded); warning displayed inline alongside the success message
- Operations are never blocked — hierarchical nesting is the intended use-case; the warning prompts the user to confirm intent

#### Import dry-run annotation (`import_csv.php`)
- Overlap check runs for each unique CIDR flagged for auto-creation during dry-run analysis
- Results cached per CIDR to avoid redundant queries on bulk imports
- Hierarchy notice (parents / children) displayed in the Reason column of the dry-run row report

#### Styles (`assets/app.css`)
- Added `.warning` utility class using the existing `--warn` CSS token (amber, dark-mode aware)

---

### Milestone 4 — Dashboard and Search Enhancement

#### Dashboard (`dashboard.php`) — full rebuild
- Six-metric summary strip: total subnets, total address rows, used / reserved / free counts, IPv4 vs IPv6 subnet split
- **Top IPv4 subnets by usage** table (prefix /8–/30) with colour-coded fill bars: green below 70%, amber 70–90%, red above 90%
- **Addresses by site** — table showing used / reserved / free / total broken down per site
- **Recent activity panel** — last 10 audit log events with a link to the full audit log

#### Search (`search.php`) — new filters and UX
- Added **Site filter** dropdown; changing site narrows the subnet dropdown client-side via a small vanilla JS snippet (no page reload)
- Added **IP version filter** (any / IPv4 / IPv6) — applied via `subnets.ip_version` in the WHERE clause
- COUNT query now uses the same `JOIN subnets` as the results query, so totals and pagination remain accurate under all filter combinations
- **Clear filters** link appears whenever any filter (query, status, site, subnet, version) is active

---

### 0.9 — Site Grouping, UX Refresh, and Performance Tuning

#### New: Site Grouping
- Added a proper **Sites** data model:
  - new `sites` table
  - `subnets.site_id`
- Added **Sites** management page (`sites.php`) for admins
- Subnets can now be assigned to a site during create/update
- Subnets page now groups subnet hierarchies by **site**
- Unassigned subnets are shown under an **Ungrouped** section

#### UX / Navigation Improvements
- Cleaner top navigation with improved organization
- Added **Sites** to the nav for admins
- Moved task-specific workflows closer to where they are used
- Added contextual links on subnet cards:
  - **View Addresses**
  - **Unassigned** (IPv4 only)
  - **Bulk Update**
- Addresses and Unassigned pages now include contextual links to related workflows

#### UI Refresh / Dark Mode Polish
- Refreshed styling with improved layout, spacing, cards, metrics, and tables
- Improved form readability and table legibility
- Enhanced dark mode styling while keeping:
  - system preference default
  - manual toggle
  - reset to system mode

#### Performance / Memory Improvements
- Default page size increased to **254** to match a typical `/24`
- Applied `254` default page size to:
  - addresses
  - search
  - unassigned IPv4 listing
- Reduced unnecessary work on several pages by:
  - keeping paginated queries consistent
  - avoiding larger-than-needed result loading in common views
  - keeping housekeeping checks lightweight
- Added/used query patterns that reduce extra lookups in common UI flows

#### Notes
- This release lays the groundwork for future site-based filtering and permission scoping.
- Existing subnets remain valid after upgrade; newly added `site_id` is optional and can be assigned later.

### 0.8 — Bulk Delete + UI Refresh + Dark Mode

#### New: Bulk Delete in Bulk Update
- Added **bulk delete** support to `bulk_update.php`
- Requires users to type **`DELETE`** to confirm before deleting selected IP address rows
- Deletes are:
  - transaction-safe
  - recorded in the audit log (`address.bulk_delete`)
  - written to per-address history as `bulk_delete`

#### UI / UX Improvements
- Added a shared stylesheet: `assets/app.css`
- Improved general usability and readability:
  - cleaner table styling
  - improved spacing and form controls
  - more consistent layout across pages
- Added support for a dedicated JS file: `assets/app.js`

#### Dark Mode
- Added **dark mode** support using CSS variables
- Default behavior follows the user’s **system preference**
- Added manual theme controls in the navigation bar:
  - **Toggle theme**
  - **System** (clear manual override and return to system preference)
- Theme preference is stored in `localStorage`

#### Navigation / Integration
- Updated `page_header()` in `lib.php` to load:
  - `assets/app.css`
  - `assets/app.js`
- Added theme controls directly into the nav bar so they are available across the app

---

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
