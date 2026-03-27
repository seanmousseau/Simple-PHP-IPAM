# CLAUDE.md — Simple PHP IPAM

Developer guide for AI assistants working on this repository.

---

## Project overview

Simple PHP IPAM is a lightweight IPv4/IPv6 address management web application built with **PHP 8.2+ and SQLite**. It has no Composer dependencies and no npm build step — everything is vanilla PHP, CSS, and JavaScript. The web root is `Simple-PHP-IPAM/` (the subdirectory, not the repo root).

---

## Repository layout

```
Simple-PHP-IPAM/          ← web root (document root for web server)
  init.php                ← bootstraps every page: config, HTTPS redirect, session, DB, CSRF
  lib.php                 ← all shared functions (DB, auth, CSRF, IP helpers, UI, OIDC, etc.)
  migrations.php          ← versioned schema migrations (one closure per version key)
  schema.sql              ← initial DB schema for fresh installs
  version.php             ← defines IPAM_VERSION constant
  config.php              ← user-editable runtime configuration (preserved on upgrade)
  *.php                   ← individual page/endpoint files (see page inventory below)
  assets/
    app.css               ← all CSS, CSS custom properties for theming
    app.js                ← theme cycling, nav dropdown toggle
  data/                   ← runtime data (gitignored); created on first request
    ipam.sqlite           ← SQLite database
    tmp/                  ← OIDC cache, update-check cache, import temp files
  upgrade.sh              ← upgrade script (rsync + backup + migrate)
docs/
  api.md                  ← REST API reference
  configuration.md        ← config.php reference
  install.md              ← installation guide
  oidc.md                 ← OIDC SSO setup guide
  security.md             ← security notes
  upgrading.md            ← upgrade guide
CHANGELOG.md
README.md
```

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
| `unassigned.php` | yes | any | IPv4 unassigned host tracker |
| `bulk_update.php` | yes | write | Bulk address update/delete |
| `dhcp_pool.php` | yes | write | DHCP pool reservation tool |
| `import_csv.php` | yes | admin | CSV import wizard |
| `sites.php` | yes | admin | Site management |
| `users.php` | yes | admin | User management |
| `api_keys.php` | yes | admin | REST API key management |
| `change_password.php` | yes | any | Self-service password change |
| `address_history.php` | yes | any | Per-address change history |
| `oidc_login.php` | — | — | Initiates OIDC auth flow (PKCE) |
| `oidc_callback.php` | — | — | Handles OIDC redirect callback |
| `api.php` | — | — | Stateless read-only REST API |
| `migrate.php` | CLI | — | Applies pending DB migrations |
| `tmp_cleanup.php` | CLI | — | Deletes stale temp files |
| export_*.php | yes | any | CSV export endpoints |

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

`api.php` does **not** use `init.php` (no session); it loads `config.php` and `lib.php` directly.

---

## Database

**Engine:** SQLite 3 via PDO with WAL mode and `PRAGMA foreign_keys = ON`.

**PDO settings:** `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`.

### Core tables (defined in `schema.sql`)

| Table | Key columns |
|-------|------------|
| `users` | `id`, `username`, `password_hash`, `role` (admin\|readonly), `is_active`, `oidc_sub`, `name`, `email`, `last_login_at` |
| `subnets` | `id`, `cidr`, `ip_version`, `network`, `network_bin` (BLOB), `prefix`, `description`, `site_id` |
| `addresses` | `id`, `subnet_id`, `ip`, `ip_bin` (BLOB), `hostname`, `owner`, `note`, `status` (used\|reserved\|free) |
| `audit_log` | `id`, `action`, `entity_type`, `entity_id`, `user_id`, `username`, `ip`, `details` |
| `sites` | `id`, `name`, `description` |
| `api_keys` | `id`, `name`, `key_hash` (SHA-256), `is_active`, `created_by` |
| `login_attempts` | `id`, `ip`, `attempted_at` |
| `address_history` | `id`, `address_id`, `action`, `before_json`, `after_json` |
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

---

## Schema migrations

Migrations live in `migrations.php` as an associative array keyed by version string:

```php
function ipam_migrations(): array {
    return [
        '0.11' => function(PDO $db) { /* ... */ },
        '0.12' => function(PDO $db) { /* ... */ },
        // ...
    ];
}
```

`apply_migrations()` in `lib.php` runs `ksort($migs, SORT_NATURAL)` before iterating, so **array order does not matter** — migrations always execute in natural version order. Each migration runs in a transaction and is recorded in `schema_migrations`. Always guard `ALTER TABLE` with `PRAGMA table_info()` checks to make new migrations idempotent.

**When adding a new version:** add the migration closure, bump `version.php`, update `CHANGELOG.md`.

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
- `login_user(int $uid, string $username, string $role): void` — sets session, regenerates ID

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

Asset cache-buster: update `?v=X.Y` in the `<link>` and `<script>` tags in `page_header()` when changing CSS/JS.

### Nav structure
- Left: nav-links (Dashboard, Subnets, Addresses, Search, Audit, ⚙ Admin dropdown)
- Right: user dropdown (username + role badge → Theme, Password, Logout)
- Admin dropdown items: Sites, Users, DHCP Pools, API Keys, Import CSV

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
apikey.create       apikey.deactivate       apikey.activate      apikey.delete
dhcp_pool.reserve   dhcp_pool.clear
export.*            import.*
```

---

## Update check

`ipam_update_check(array $config): ?array` in `lib.php`:
- Fetches `https://api.github.com/repos/seanmousseau/Simple-PHP-IPAM/releases/latest`
- Caches result in `data/tmp/update-check.json` for 6 hours (configurable)
- Returns `['version' => '0.15', 'url' => '...']` if newer, otherwise `null`
- Silently skips on network failure; ignores drafts and pre-releases
- Called by `page_footer()` and uses `global $config`

---

## Development workflow

### Branching convention
Feature branches follow: `claude/v{VERSION}-dev` (e.g. `claude/v0.14-dev`).

### Version bump checklist
When implementing a new version:
1. Add migration closure to `migrations.php` (guard `ALTER TABLE` with `PRAGMA table_info()` check)
2. Bump `IPAM_VERSION` in `version.php`
3. Update `CHANGELOG.md` with a `## X.Y — Title` section
4. Update `README.md` "What's new" section
5. Update relevant `docs/` files
6. Bump asset cache-buster `?v=X.Y` in `page_header()` if CSS/JS changed

### Linting
Always run `php -l` on every changed PHP file before committing:
```bash
php -l Simple-PHP-IPAM/lib.php
php -l Simple-PHP-IPAM/users.php
# etc.
```

### Commit style
```
feat(scope): short description
fix(scope): short description
docs(scope): short description
```
Include `https://claude.ai/code/session_...` in commit body.

---

## Key constraints and gotchas

- **No Composer, no npm** — zero external dependencies. Everything must be implemented in vanilla PHP using only standard extensions (`pdo`, `pdo_sqlite`, `openssl`).
- **`addresses` has no `ip_version`** — that column exists only on `subnets`. Do not add it to address INSERTs.
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
