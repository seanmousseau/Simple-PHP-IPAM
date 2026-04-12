# CLAUDE.md — Simple PHP IPAM

Developer guide for AI assistants working on this repository.

---

## Project overview

Simple PHP IPAM is a lightweight IPv4/IPv6 address management web application built with **PHP 8.2+ and SQLite**. It has no Composer dependencies and no npm build step — everything is vanilla PHP, CSS, and JavaScript. The web root is `Simple-PHP-IPAM/` (the subdirectory, not the repo root).

---

## Repository layout

The web root is `Simple-PHP-IPAM/` (subdirectory, not the repo root). Key files in it: `init.php` (bootstrap), `lib.php` (all shared functions), `migrations.php` (schema migrations), `schema.sql` (fresh-install schema), `version.php` (`IPAM_VERSION` constant), `config.php` (user-editable config), `assets/app.css` + `assets/app.js` (all CSS/JS), `upgrade.sh` (upgrade script). Runtime data lives in `data/` (gitignored): `data/ipam.sqlite` and `data/tmp/` (caches, temp uploads).

Other top-level directories: `testing/` (API test suite and sample datasets), `releases/` (release bundle builder), `docs/` (api.md, configuration.md, install.md, oidc.md, security.md, upgrading.md), `tests/` (PHPUnit unit tests).

Dev tooling at the repo root (not deployed): `composer.json`, `composer.lock`, `phpstan.neon`, `phpstan-baseline.neon`, `.phpcs.xml`, `phpunit.xml`. Run `composer install` once to install tools into `vendor/` (gitignored).

---

## Page inventory

| File | Auth required | Role | Description |
|------|--------------|------|-------------|
| `login.php` | — | — | Local login form + OIDC button |
| `logout.php` | yes | any | Destroys session |
| `dashboard.php` | yes | any | Utilization summary, recent audit |
| `subnets.php` | yes | any/write | Subnet CRUD, hierarchy view |
| `addresses.php` | yes | any/write | Address CRUD per subnet |
| `search.php` | yes | any | Global IP/hostname/owner search |
| `audit.php` | yes | any | Audit log viewer |
| `unassigned.php` | yes | any | IPv4/IPv6 unassigned host tracker |
| `bulk_update.php` | yes | write | Bulk address update/delete |
| `dhcp_pool.php` | yes | write | DHCP pool reservation tool |
| `import_csv.php` | yes | admin | CSV import wizard |
| `sites.php` | yes | admin | Site management (supports parent site / region hierarchy) |
| `vlans.php` | yes | admin | VLAN management (first-class VLAN objects, linked to subnets via `vlan_fk`) |
| `vrfs.php` | yes | admin | VRF management (Virtual Routing and Forwarding; admin CRUD) |
| `contacts.php` | yes | admin | Contact management (first-class contact records linked to addresses via `owner_contact_id`) |
| `tags.php` | yes | admin | Tag management (colour-coded tags attached to subnets and addresses) |
| `users.php` | yes | admin | User management |
| `api_keys.php` | yes | admin | REST API key management |
| `change_password.php` | yes | any | Self-service password change |
| `address_history.php` | yes | any | Per-address change history |
| `oidc_login.php` | — | — | Initiates OIDC auth flow (PKCE) |
| `oidc_callback.php` | — | — | Handles OIDC redirect callback |
| `api.php` | — | — | Stateless read-only REST API |
| `migrate.php` | CLI | — | Applies pending DB migrations |
| `tmp_cleanup.php` | CLI | — | Deletes stale temp files |
| `demo_reset.php` | CLI | — | Resets demo database to seed data (nightly cron) |
| `demo_seed.php` | CLI | — | Seeds demo data into database |
| `export_addresses.php` | yes | any/write | CSV export: single subnet (any role) or all subnets cross-subnet (write role) |
| `export_address_history.php` | yes | any | CSV export: per-address change history |
| `export_subnet_utilization.php` | yes | any | CSV export: subnet utilization summary across all subnets |
| export_audit.php, export_search.php, export_subnets.php, export_unassigned.php, export_import_report.php | yes | any | Other CSV export endpoints |
| `index.php` | — | — | Redirects to dashboard (if logged in) or login |
| `status.php` | — | — | Health check JSON endpoint (`{"status":"ok"}`) for load balancers/uptime monitors |
| `set_theme.php` | yes | any | AJAX POST: persists theme preference to `users.theme` |
| `db_tools.php` | yes | admin | Database SQL export and import |
| `demo_gate.php` | — | — | Demo mode bot challenge gate (pre-login) |

---

## Bootstrap sequence

Every web page starts with `require __DIR__ . '/init.php'`, which:

1. Loads `config.php` into `$config`
2. Enforces HTTPS (301 redirect if not)
3. Configures session (`Secure`, `HttpOnly`, `SameSite=Strict`, strict mode)
4. Starts the session
5. Requires `lib.php`
6. Opens the SQLite DB with `ipam_db()` → `$db`
7. Runs `ipam_db_init()` — applies pending migrations, creates bootstrap admin if no users exist
8. Runs lazy housekeeping if due (temp file cleanup, stale login attempt purge)
9. Initialises CSRF token

`api.php` and `status.php` do **not** use `init.php` (no session); they load `config.php` and `lib.php` directly.

---

## Database

**Engine:** SQLite 3 via PDO with WAL mode and `PRAGMA foreign_keys = ON`.

**PDO settings:** `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`.

### Core tables (defined in `schema.sql`)

| Table | Key columns |
|-------|------------|
| `users` | `id`, `username`, `password_hash`, `role` (admin\|readonly), `is_active`, `oidc_sub`, `name`, `email`, `last_login_at`, `password_changed_at`, `theme` (auto\|light\|dark), `created_at`, `updated_at` |
| `vrfs` | `id`, `name` (UNIQUE), `description`, `rd` (Route Distinguisher, free-form), `created_at`, `updated_at` |
| `subnets` | `id`, `cidr`, `ip_version`, `network`, `network_bin` (BLOB), `prefix`, `description`, `site_id`, `vlan_id` (legacy int 1–4094, nullable), `vlan_fk` (FK → `vlans.id`, nullable), `vrf_id` (FK → `vrfs.id`, nullable), `created_at`, `updated_at` — UNIQUE(cidr, vrf_id) |
| `contacts` | `id`, `name`, `email`, `phone`, `org`, `note`, `created_at`, `updated_at` |
| `addresses` | `id`, `subnet_id`, `ip`, `ip_bin` (BLOB), `hostname`, `owner`, `owner_contact_id` (FK → `contacts.id`, nullable), `note`, `grp`, `status` (used\|reserved\|free), `mac` (free-form, default ''), `expires_at` (YYYY-MM-DD, nullable), `created_at`, `updated_at` |
| `audit_log` | `id`, `action`, `entity_type`, `entity_id`, `user_id`, `username`, `ip`, `user_agent`, `details`, `created_at` |
| `sites` | `id`, `name`, `description`, `parent_id` (FK → `sites.id`, nullable, added v2.0.0), `created_at` |
| `vlans` | `id`, `vlan_id` (1–4094), `name`, `description`, `site_id` (FK → `sites.id`, nullable), `created_at`, `updated_at` — UNIQUE(vlan_id, site_id) |
| `tags` | `id`, `name` (UNIQUE, max 50 chars), `colour` (hex, default `#6c757d`), `created_at` |
| `subnet_tags` | `subnet_id` (FK → `subnets.id` CASCADE), `tag_id` (FK → `tags.id` CASCADE) — join table |
| `address_tags` | `address_id` (FK → `addresses.id` CASCADE), `tag_id` (FK → `tags.id` CASCADE) — join table |
| `alert_state` | `subnet_id` (FK → `subnets.id` CASCADE), `level` (warn\|crit), `last_alerted_at` — tracks sent utilization alerts |
| `api_keys` | `id`, `name`, `key_hash` (SHA-256), `is_active`, `is_readonly`, `description`, `created_by`, `created_at`, `last_used_at` |
| `login_attempts` | `id`, `ip`, `attempted_at` |
| `address_history` | `id`, `address_id`, `subnet_id`, `ip`, `action`, `user_id`, `username`, `client_ip`, `user_agent`, `before_json`, `after_json`, `created_at` |
| `schema_migrations` | `id`, `version`, `applied_at` |

**Important:** The `addresses` table does **not** have an `ip_version` column — that lives only on `subnets`.

The `audit_log` table is **append-only** — SQLite triggers raise an abort on any UPDATE or DELETE.

### Binary IP storage

IPs are stored as raw binary blobs (`inet_pton()` output) for correct sort order and fast range queries. Never store text IPs in `ip_bin`/`network_bin`. Use `inet_pton()` to encode and `inet_ntop()` to decode.

Key helpers in `lib.php`:
- `parse_cidr(string $cidr): ?array` — validates and normalises a CIDR, returns `['version', 'network', 'prefix', 'net_bin']`
- `normalize_ip(string $ip): ?array` — returns `['ip', 'bin', 'version']`
- `ip_in_cidr(string $ip, string $network, int $prefix): bool`
- `apply_prefix_mask(string $ipBin, int $prefix): string`
- `ipv4_bin_to_int(string $bin): int` / `ipv4_int_to_bin(int $n): string`
- `ipv6_bin_increment(string $bin): string` — pure-PHP 16-byte carry increment (no GMP)
- `ipv6_enumerate_first_n(PDO $db, int $subnetId, string $networkBin, int $prefix, int $n): array` — returns first N unassigned IPv6 addresses in a subnet (scans from network+1, skips assigned)

---

## Schema migrations

Migrations live in `migrations.php` as an associative array of version string → closure returned by `ipam_migrations()`. `apply_migrations()` in `lib.php` calls `ksort($migs, SORT_NATURAL)` before iterating, so **array order does not matter** — migrations always execute in natural version order. Each migration runs in a transaction and is recorded in `schema_migrations`. Always guard `ALTER TABLE` with `PRAGMA table_info()` checks to make new migrations idempotent.

**When adding a new version:** add the migration closure, bump `version.php`, update `CHANGELOG.md` (keepachangelog format).

---

## Authentication & authorisation

### Roles
- `admin` — full access including all admin pages
- `readonly` — read-only access; all write operations return 403

### Helpers
- `is_logged_in(): bool`
- `require_login(): void` — also enforces session idle timeout
- `require_role('admin'): void` — 403 if not admin
- `require_write_access(): void` — 403 if readonly
- `current_user(): array` — returns `['id', 'username', 'role']` from session
- `login_user(int $uid, string $username, string $role, ?PDO $db = null): void` — sets session, regenerates ID, loads persisted theme if `$db` provided

After calling `login_user()`, always update `last_login_at`:
```php
$db->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=:id")
   ->execute([':id' => $uid]);
```

### CSRF
Every POST form must include `<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">` and the handler must call `csrf_require()` at the top.

### Self-protection guards in users.php
These actions are blocked both server-side and hidden in the UI for the logged-in user's own account:
- `toggle_active` (disable/enable)
- `set_role`
- `unlink_oidc`
- `delete`

The last-active-admin guard uses: count active admins **excluding the target user** (`AND id != :id`). Only applies when the target is active AND admin — never block deletion of an inactive admin.

---

## OIDC authentication

Authorization Code + PKCE flow, pure PHP, no Composer packages, requires `openssl` extension.

Key functions in `lib.php`:
- `oidc_enabled(array $config): bool`
- `oidc_discovery(array $config): array` — fetches/caches `/.well-known/openid-configuration`
- `oidc_jwks(string $uri, bool $forceRefresh): array` — fetches/caches JWKS
- `oidc_verify_id_token(string $idToken, array $jwks, array $expect): array` — verifies RS256/384/512
- `jwk_rsa_to_pem(array $jwk): string` — DER SubjectPublicKeyInfo from JWK `n`/`e`, no ext-gmp

Cache files live in `data/tmp/` with 1-hour TTL. A single automatic cache-bust retry handles in-flight key rotation.

**Claim mapping on login:**
- `preferred_username` → username (fallback: email local-part, then `sub`)
- `name` → display name
- `email` → email field
- `sub` → `users.oidc_sub` (unique, partial index where NOT NULL)

**Auto-link order:** first tries `preferred_username` match, then `email`/username match, then provisions a new account.

---

## UI conventions

### HTML output
- All PHP output goes through `e(string $s): string` (wraps `htmlspecialchars`)
- Every page calls `page_header(string $title)` and `page_footer()` from `lib.php`
- `page_header()` renders the full `<html>...<body>` opening with nav bar
- `page_footer()` closes `</div></body></html>` and renders the footer with version + update check

### CSS
Located in `assets/app.css`. Uses CSS custom properties for theming (light/dark/auto via `html[data-theme]`). Key variables: `--bg`, `--fg`, `--muted`, `--border`, `--card`, `--danger`, `--success`, `--warn`, `--link`, `--btn`, `--btnfg`, `--badge-bg`.

Key utility classes: `.muted`, `.danger`, `.success`, `.warning`, `.badge`, `.badge-update`, `.status-used`, `.status-reserved`, `.status-free`, `.util-bar`, `.util-bar-fill`, `.util-bar-fill--warn`, `.util-bar-fill--crit`, `.row`, `.card`, `.action-pill`, `.button-danger`, `.button-secondary`.

Asset cache-buster: update `?v=X.Y.Z` in the `<link>` and `<script>` tags in `page_header()` **and** in `demo_gate.php` (lines 74–75) when changing CSS/JS. `demo_gate.php` has its own `<head>` block and does not call `page_header()`, so it must be updated separately.

### Nav structure
- Left: nav-links (Dashboard, Subnets, Addresses, Search, Audit, ⚙ Admin dropdown)
- Right: user dropdown (username + role badge → Theme, Password, Logout)
- Admin dropdown items: Sites, VLANs, VRFs, Tags, Contacts, Users, DHCP Pools, API Keys, Import CSV, Database Tools

---

## Audit logging

Call `audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details)` for every significant action. Convention for `$action`:

```
auth.login          auth.login_failed       auth.login_blocked
auth.oidc_login     auth.oidc_provision     auth.oidc_link       auth.oidc_failed
subnet.create       subnet.update           subnet.delete
address.create      address.update          address.delete
user.create         user.delete             user.toggle_active
user.set_role       user.reset_password     user.update_profile
user.oidc_link      user.oidc_unlink
site.create         site.update             site.delete
vlan.create         vlan.update             vlan.delete
vrf.create          vrf.update              vrf.delete
contact.create      contact.update          contact.delete
tag.create          tag.update              tag.delete
apikey.create       apikey.deactivate       apikey.activate      apikey.delete
dhcp_pool.reserve   dhcp_pool.clear
db.export           db.import               db.import_failed
export.*            import.*
```

---

## Development workflow

### Branching & PRs
- **All development happens on the `dev` branch** (or feature branches off `dev`).
- **Never commit directly to `main`.**
- **Pull requests go `dev` → `main`** only. Do not create PRs targeting any other branch unless explicitly instructed.

### Versioning
This project follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`):
- **MAJOR** — breaking or incompatible changes (e.g. config format change, removed feature)
- **MINOR** — backwards-compatible new features or enhancements
- **PATCH** — backwards-compatible bug fixes and security patches

Examples: `1.15.0` (new features), `1.15.1` (bug fix), `2.0.0` (breaking change).

Note: versions prior to 1.15.0 used two-part numbering (e.g. `1.14`). The `ipam_normalise_version()` function in `lib.php` pads these to three parts for comparison, so the update checker and upgrade script handle both formats correctly.

### Version bump checklist
When implementing a new version:
1. Add migration closure to `migrations.php` (guard `ALTER TABLE` with `PRAGMA table_info()` check)
2. Bump `IPAM_VERSION` in `version.php` (use `MAJOR.MINOR.PATCH` format)
3. Update `CHANGELOG.md` following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format:
   - Header: `## [X.Y.Z] - YYYY-MM-DD`
   - Categories: **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**, **Security** (only include categories with entries)
   - Add a version comparison link at the bottom of the file
4. Update `README.md` "What's new" section
5. Update relevant `docs/` files
6. Bump asset cache-buster `?v=X.Y.Z` in `page_header()` **and** `demo_gate.php:74–75` if CSS/JS changed

### Static analysis & testing

The project uses four dev tools (three Composer-managed, one standalone):

| Tool | Config | Purpose |
|------|--------|---------|
| **PHPStan** | `phpstan.neon` | Static analysis — level 9, analyses `Simple-PHP-IPAM/` |
| **PHP_CodeSniffer** | `.phpcs.xml` | Style checking — PSR-12 with exclusions for K&R brace and inline control structure style |
| **PHPUnit** | `phpunit.xml` | Unit tests for pure utility functions in `lib.php` |
| **Semgrep** | `.semgrep/rules.yml` | Security taint rules — XSS, path traversal, SQLi, open redirect. Recognises `e()` as an HTML sanitizer. |

**Running the tools:**
```bash
vendor/bin/phpstan analyse          # static analysis
vendor/bin/phpcs                    # style check
vendor/bin/phpunit                  # unit tests
php -l Simple-PHP-IPAM/changed.php  # syntax check individual files
semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/   # security rules (standalone, not via Composer)
```

**PHPStan baseline (`phpstan-baseline.neon`):** Pre-existing errors are acknowledged in the baseline so CI only fails on *new* errors. The baseline is almost entirely `Variable $db/$config might not be defined` false-positives caused by PHPStan not being able to see variables injected by `require 'init.php'`. Fix baseline errors incrementally; do not add new entries to suppress real bugs.

**PHPUnit tests (`tests/UtilTest.php`):** Tests covering the pure utility functions that have no DB or session dependencies: `e()`, `parse_cidr()`, `apply_prefix_mask()`, `ip_in_cidr()`, `normalize_ip()`, `ipv4_bin_to_int()`, `ipv4_int_to_bin()`, `ipam_normalise_version()`, `normalize_status()`, `ipv6_bin_increment()`. Bootstrap is `tests/bootstrap.php` which requires `lib.php` directly.

**PHPCS style exclusions** (see `.phpcs.xml` comments for rationale):
- Inline control structures without braces — established codebase style
- K&R function brace placement (`function foo() {`) — established codebase style
- Column-aligned `=>` arrays — intentional for readability
- `<?php\ndeclare(strict_types=1);` without blank line — established codebase style
- Line length — SQL and HTML strings are legitimately long

**CI:** `.github/workflows/php-qa.yml` runs lint → PHPStan → PHPCS → PHPUnit on every push to `dev`/`main` and every PR targeting `main`.

**Semgrep:** Custom security rules live in `.semgrep/rules.yml`. Run locally with:
```bash
semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/
```
Rules cover XSS (`ipam-xss-unsanitized-echo`), path traversal (`ipam-unlink-user-path`), SQL injection (`ipam-sqli-raw-concat`), and open redirect (`ipam-open-redirect`). The `e()` function is registered as an XSS sanitizer so it is never flagged as a false positive. Path exclusions are in `.semgrepignore`.

### Linting
Always run `php -l` on every changed PHP file before committing:
```bash
php -l Simple-PHP-IPAM/lib.php
php -l Simple-PHP-IPAM/users.php
# etc.
```

### Pre-release checklist
Before building a release bundle, **always** complete these steps in order:
1. Update `docs/` (api.md, configuration.md, etc.) for any changed features or config keys
2. Update `testing/samples/large-db-sample/gen_large_db.php` and sample datasets if schema or data model changed
3. Update `testing/scripts/test_api.sh` if API endpoints were added or changed
4. Update `testing/scripts/cdp_test.py` if UI features were added or changed
5. Run `php -l` on every changed PHP file
6. Run the full QA suite and confirm **all checks pass**:
   ```bash
   vendor/bin/phpstan analyse
   vendor/bin/phpcs
   vendor/bin/phpunit
   semgrep --config=.semgrep/rules.yml Simple-PHP-IPAM/
   bash testing/scripts/test_api.sh https://dev-direct.seanmousseau.com:8343/claude/ipam
   bash -c 'set -a; source ~/.claude/dev-secrets.env; set +a; \
     IPAM_BASE_URL=https://dev-direct.seanmousseau.com:8343/claude/ipam \
     npx --prefix testing/playwright playwright test --config=testing/playwright/playwright.config.ts'
   ```
7. Run CodeRabbit review and address any Critical findings:
   ```bash
   coderabbit review --plain -t all
   ```
8. Only then build the release bundle

### Building a release bundle
Use `releases/make_releases.sh` when `rsync` is available:
```bash
./releases/make_releases.sh Simple-PHP-IPAM X.Y.Z
```
If `rsync` is not available, replicate the same logic with `cp -a` (copy dotfiles with `cp -a src/. dest/`), permission sanitisation, and `tar --numeric-owner --owner=0 --group=0`.

Verify the bundle contains:
- `upgrade.sh` with execute permission (`-rwxr-xr-x` in `tar tvf`)
- Both `.htaccess` files (root and `data/`)
- No `data/ipam.sqlite` or `data/.db_initialized`

### Commit style
```
feat(scope): short description
fix(scope): short description
docs(scope): short description
```
Include `https://claude.ai/code/session_...` in commit body.

---

## Key constraints and gotchas

- **No Composer, no npm in production** — the application itself has zero runtime dependencies. Everything must be implemented in vanilla PHP using only standard extensions (`pdo`, `pdo_sqlite`, `openssl`). Composer is used for *dev tooling only* (`vendor/` is gitignored and never deployed).
- **`addresses` has no `ip_version`** — that column exists only on `subnets`. Do not add it to address INSERTs.
- **`addresses.grp` is a SQL reserved word** — stored as `grp` in the DB, exposed as `group` in the UI, API responses, and CSV headers.
- **`addresses.mac`** — free-form MAC address string, `NOT NULL DEFAULT ''`. Never validate the format server-side; users may enter any notation.
- **`addresses.expires_at`** — nullable YYYY-MM-DD lease expiry date. Rows where `expires_at < date('now')` are highlighted in the UI. The API supports `?expired=1` to filter these rows.
- **Migration order is by `ksort(SORT_NATURAL)`** — array order in `migrations.php` does not matter.
- **`fetch()` returns `false` on no rows**, never `null` — always check with `if ($row)` not `if ($row !== null)`.
- **CSRF on every POST** — `csrf_require()` at top of every POST handler; hidden `csrf` field in every form.
- **`e()` on every output** — never echo user-controlled data without `e()`.
- **`$GLOBALS['config']`** — when a local-scope function needs config (e.g. `render_subnet_node_local()`), access via `$GLOBALS['config']`. This is the established pattern (see `require_login()`).
- **Binary blobs in PDO** — `ip_bin` and `network_bin` are raw binary; do not treat as UTF-8 strings. Use `hash_equals()` for comparisons.
- **SQLite `rowCount()`** — works correctly for DELETE/UPDATE. Use it after DML statements to count affected rows.
- **`$config` is a global** — set by `init.php`. Available as `$config` in page scope; access via `global $config` inside functions.
- **Child subnets inherit site** — `find_parent_site_id()` is called on every subnet create/update; the server overrides the submitted `site_id` if a parent with a site is found. The UI shows a locked badge for `$depth > 0`.
- **Self-delete guard** — cannot delete the last *active* admin. Count uses `AND id != :id` (exclude target) and only applies when deleting an *active* admin.
- **`page_footer()` is not `exit`** — it outputs the closing HTML but does not call `exit`. The calling page must not output anything after it.
- **`subnets.vlan_fk` vs `subnets.vlan_id`** — `vlan_fk` (added v2.0.0) is the FK to `vlans.id`. The legacy integer column `vlan_id` (1–4094) is retained for backwards compatibility. New code should use `vlan_fk`; do not confuse the two.
- **Tags via join tables** — tags are attached to subnets/addresses through `subnet_tags` and `address_tags`. Both have `ON DELETE CASCADE` so tags are automatically removed when the parent entity is deleted. Use `get_tags_for_entity()` / `save_tags_for_entity()` in `lib.php`.
- **Site hierarchy depth limit** — enforced at the application layer (max depth 2: region → site). Prevent circular references in the parent picker by filtering out the site itself and its descendants.
- **`alert_email` must be set** — `check_utilization_alerts()` no-ops silently when `alert_email` is empty. The email alert feature relies on the server's MTA (`mail()` function).
- **`recaptcha_action` config key** — controls the reCAPTCHA v3 `action` parameter passed during login. Defaults to `'login'`. The value is emitted as `data-recaptcha-action` on the hidden input and read by `app.js` at runtime.
