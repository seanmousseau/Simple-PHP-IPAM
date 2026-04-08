# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
as of v1.15.0. Versions prior to 1.15.0 used two-part numbering.

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
