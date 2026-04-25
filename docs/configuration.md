# Configuration Reference

## Where configuration lives

Configuration lives in two places:

1. **`config.php`** — bootstrap keys plus a small set of security-sensitive keys that must be available before the database is opened. Bootstrap keys: `db_driver`, `db_dsn`, `db_user`, `db_pass`, `session_name`, `force_https`. Security-sensitive keys added in v3.6.0: `app_secret` (TOTP encryption key), `session.absolute_lifetime_minutes`, `auth.lockout_after_failures`, `auth.lockout_duration_minutes`. See `config.php.example` for the full template. Edit by hand on the server.
2. **Database (`settings` table)** — everything else. Edited through the admin UI under **⚙ Admin → Settings**. Reads are cached per-request.

When the app reads a setting it checks: `settings` table row → default from `ipam_setting_definitions()`. The admin page shows a source badge next to every setting — 🟢 Database or ⚪ Default.

### Transition from `config.php` (v2.6 → v2.7 → v3.0)

- **v2.6.0** — groundwork release. Introduces the `settings` table, the `ipam_setting()` helper, the registry, and the `settings.php` admin UI. The migration seeds every registered key into the table on first boot using the current `config.php` value (or the registry default). Wherever `ipam_setting()` is called, the database row takes precedence over `config.php`. The runtime subsystems (OIDC, alerting, branding, login protection, update checker) are not yet rewired to call `ipam_setting()`, though, so they keep reading straight from `$config` at request time — saving a value in the admin UI lands it in the `settings` table correctly, but most subsystems still behave as if you had edited `config.php`.
- **v2.7.0** — every subsystem routes through `ipam_setting()`, so edits in the admin UI take effect on the next request. `config.php` continued to work as a fallback through v2.x.
- **v3.0.0** — the `config.php` fallback is removed. Only the six bootstrap keys (`db_driver`, `db_dsn`, `db_user`, `db_pass`, `session_name`, `force_https`) were read from the file at runtime (until v3.6.0 added more — see below). An upgrade-time migration copies any customised values into the database and rewrites `config.php` to stub format. See [docs/upgrading.md](upgrading.md#v300) for the full upgrade guide.
- **v3.6.0** — adds a small set of security-sensitive pre-DB keys to `config.php`: `app_secret` (TOTP encryption), `session.absolute_lifetime_minutes`, and `auth.lockout_after_failures` / `auth.lockout_duration_minutes`. These cannot live in the `settings` table because they are needed before or during the DB open / session start sequence. The bootstrap six remain unchanged.

#### Settings cascade (v3.13.0)

In v3.13.0, `ipam_setting()` accepts an optional `?int $tenantId` parameter. In v3.x all settings are global (`tenant_id IS NULL`) — passing `$tenantId` has no visible effect until v4.0.0 multi-tenancy is activated. The cascade resolution order is: (1) tenant-specific row, (2) global row (`tenant_id IS NULL`), (3) code default.

### Settings reference (database-backed)

The keys below are seeded into the `settings` table by the v2.6.0 migration and can be edited at **⚙ Admin → Settings**. Generated from `ipam_setting_definitions()` in `lib.php`; if it drifts from the registry, the registry is authoritative.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `branding.site_name` | string | `Simple PHP IPAM` | App display name in browser tab, nav bar, and login page. |
| `branding.timezone` | string | `UTC` | PHP timezone identifier used to render timestamps. |
| `security.session_idle_seconds` | int | `1800` | Session idle timeout before auto-logout. |
| `security.login_max_attempts` | int | `5` | Failed logins per window before IP lockout. |
| `security.login_lockout_seconds` | int | `900` | Lockout window length. |
| `security.account_lockout_max_attempts` | int | `10` | Failed logins per username before account lockout. |
| `security.account_lockout_seconds` | int | `900` | Account lockout window length. |
| `api.rate_limit_window_seconds` | int | `60` | Sliding window size (seconds) for per-API-key rate limiting. Seeded from `config.php` `api.rate_limit_window_seconds` on first install. *(v3.6.0)* |
| `api.rate_limit_requests` | int | `300` | Max requests per window per API key before HTTP 429. Seeded from `config.php` `api.rate_limit_requests` on first install. *(v3.6.0)* |
| `backup.enabled` | bool | `false` | Enable scheduled database backups via `cron.php`. *(v3.7.0)* |
| `backup.dir` | string | `''` | Directory where backup files are written. Empty = `data/backups/` relative to app root. Created automatically on first backup. *(v3.7.0)* |
| `backup.retention` | int | `7` | Number of backup files to retain; oldest files beyond this count are deleted after each backup. *(v3.7.0)* |
| `backup.frequency` | string | `daily` | How often to run automatic backups. Accepted values: `daily`, `weekly`. *(v3.7.0)* |
| `housekeeping.audit_log_retention_days` | int | `0` | Days to keep audit log entries. Entries older than this are pruned during scheduled housekeeping. Set to `0` to never prune. *(v3.7.0)* |
| *(config.php only)* `recovery_mode` | bool | `false` | Emergency login recovery mode (see below). |
| `alert.recipient_user_ids` | json | `[]` | (v2.8.0+) Active user IDs that receive utilization alerts. Picked from a multi-select on **Settings → Alerting**; only users with a non-empty email are eligible. Inactive users / cleared emails drop out automatically at send time. |
| `alert.email` | string | *(empty)* | **Deprecated in v2.8.0** — replaced by `alert.recipient_user_ids`. The 2.8.0 migration auto-maps a matching active user; unmappable values produce a `settings.alert_email_unmigrated` audit row. Hidden from the UI. Removal in v3.0.0. |
| `alert.util_warn_pct` | int | `80` | Subnet utilization warn threshold. |
| `alert.util_crit_pct` | int | `95` | Subnet utilization critical threshold. |
| `alert.interval_seconds` | int | `3600` | Minimum seconds between utilization alert checks. |
| `update_check.enabled` | bool | `true` | Fetch GitHub release info and show the update banner. |
| `update_check.ttl_seconds` | int | `86400` | Update check cache TTL. |
| `update_check.notify_prerelease` | bool | `false` | Also alert for alpha / beta / RC builds. |
| `oidc.enabled` | bool | `false` | Master switch for Authorization Code + PKCE SSO. |
| `oidc.display_name` | string | `SSO` | Label on the login page SSO button. |
| `oidc.client_id` | string | *(empty)* | OIDC client identifier. |
| `oidc.client_secret` | **sensitive** string | *(empty)* | OIDC client secret. Masked in the UI and in audit details. |
| `oidc.discovery_url` | string | *(empty)* | Base URL of the IdP. |
| `oidc.redirect_uri` | string | *(empty)* | Must match the URI registered with the IdP exactly. |
| `oidc.scopes` | string | `openid email profile` | Space-separated scopes. |
| `oidc.auto_link` | bool | `false` | Link incoming OIDC identities to matching local accounts on first login. |
| `oidc.auto_provision` | bool | `false` | Create a new local account on first OIDC login. Implies auto-link. |
| `oidc.default_role` | string | `readonly` | Role assigned to auto-provisioned users. |
| `oidc.disable_local_login` | bool | `false` | Hide the local username/password form entirely when OIDC is active. **Added v2.7.0.** |
| `oidc.disable_emergency_bypass` | bool | `false` | Disable the `?local=1` emergency access path. **Added v2.7.0.** |
| `oidc.hide_emergency_link` | bool | `false` | Hide the "(emergency local access)" link. **Added v2.7.0.** |
| `login_protection.method` | string | *(empty)* | Bot/abuse mitigation on the login form. One of: off, `honeypot`, `time_check`, `turnstile`, `hcaptcha`, `recaptcha`, `friendly_captcha`. Dropdown-validated. |
| `login_protection.site_key` | string | *(empty)* | Widget site key for the selected provider. |
| `login_protection.secret_key` | **sensitive** string | *(empty)* | Widget secret key used for backend verification. |
| `login_protection.min_seconds` | int | `3` | Minimum seconds between page load and submit for the `time_check` method. |
| `login_protection.version` | int | `2` | reCAPTCHA widget version: 2 (checkbox) or 3 (invisible). |
| `recaptcha_enterprise.enabled` | bool | `false` | Use the Google reCAPTCHA Enterprise API for backend verification. |
| `recaptcha_enterprise.project_id` | string | *(empty)* | GCP project ID for Enterprise assessments. |
| `recaptcha_enterprise.api_key` | **sensitive** string | *(empty)* | GCP API key for the reCAPTCHA Enterprise API. |
| `recaptcha_enterprise.expected_action` | string | `login` | Action name passed in reCAPTCHA v3 assessments. |
| `recaptcha_enterprise.score_threshold` | string | `0.5` | Minimum risk score for a request to be accepted. |

---

## `config.php` reference

The keys below are the only ones read from `config.php` at runtime in v3.x. **All other application settings live in the `settings` table** and are edited at **⚙ Admin → Settings**. Do not add v2.x-era keys (`session_idle_seconds`, `login_max_attempts`, `oidc`, `update_check`, `housekeeping`, etc.) to `config.php` — they are silently ignored in v3.x.

## Contents

- [Full example](#full-example)
- [Settings reference](#settings-reference)
- [`app_secret`](#app_secret) *(v3.6.0)*
- [`session`](#session) *(v3.6.0)*
- [`auth`](#auth) *(v3.6.0)*
- [`api`](#api) *(v3.6.0)*
- [`login_protection`](#login_protection)
- [`demo_mode`](#demo_mode)
- [`password_policy`](#password_policy)
- [OIDC settings](#oidc-settings)
- [`update_check`](#update_check)
- [`backup`](#backup)
- [`audit_log_retention_days`](#audit_log_retention_days)
- [`address_history_retention_days`](#address_history_retention_days)
- [`status_hide_version`](#status_hide_version)
- [Behind a reverse proxy](#behind-a-reverse-proxy)

---

## Full example

This is the v3.x `config.php` stub — only these keys belong in the file. Everything else (OIDC, alerting, update checker, login protection, branding, etc.) is configured at **⚙ Admin → Settings** and stored in the database.

```php
<?php
declare(strict_types=1);

return [
    // ── Database ────────────────────────────────────────────────────────────
    // SQLite (default): set db_path to the absolute path of your database file.
    'db_driver' => 'sqlite',
    'db_path'   => __DIR__ . '/data/ipam.sqlite',

    // MySQL / MariaDB: comment out db_path above and uncomment:
    // 'db_driver' => 'mysql',
    // 'db_dsn'    => 'mysql:host=127.0.0.1;dbname=ipam;charset=utf8mb4',
    // 'db_user'   => 'ipam',
    // 'db_pass'   => 'changeme',

    // PostgreSQL:
    // 'db_driver' => 'pgsql',
    // 'db_dsn'    => 'pgsql:host=127.0.0.1;dbname=ipam',
    // 'db_user'   => 'ipam',
    // 'db_pass'   => 'changeme',

    // ── Session ─────────────────────────────────────────────────────────────
    'session_name' => 'IPAMSESSID',
    'force_https'  => true,

    // ── TOTP 2FA (v3.6.0) ───────────────────────────────────────────────────
    // Required before any user can enroll in two-factor authentication.
    // Generate with: php -r "echo bin2hex(random_bytes(32));"
    // WARNING: changing this after enrollment invalidates all existing TOTP secrets.
    'app_secret' => '',

    // ── Session lifetime (v3.6.0) ────────────────────────────────────────────
    'session' => [
        'absolute_lifetime_minutes' => 480,  // 8 hours; 0 = disabled
    ],

    // ── 2FA lockout (v3.6.0) ─────────────────────────────────────────────────
    'auth' => [
        'lockout_after_failures'   => 10,   // persistent lockout after N consecutive 2FA failures
        'lockout_duration_minutes' => 30,   // how long the lockout lasts
    ],

    // ── API rate limit seed defaults (v3.6.0) ────────────────────────────────
    // Runtime values are read from the settings table (api.rate_limit_*);
    // these seed the DB rows on first install.
    'api' => [
        'rate_limit_window_seconds' => 60,   // sliding window size
        'rate_limit_requests'       => 300,  // max requests per window per key
    ],

    // ── Bootstrap admin ──────────────────────────────────────────────────────
    // Created on first run when no users exist. Change the password immediately.
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // ── Optional keys ────────────────────────────────────────────────────────
    // 'proxy_trust'   => false,  // true if behind a trusted reverse proxy (X-Forwarded-Proto)
    // 'recovery_mode' => false,  // emergency break-glass; disable immediately after use

    // Demo mode (opt-in):
    'demo_mode' => [
        'enabled'    => false,
        'gate'       => null,
        'site_key'   => '',
        'secret_key' => '',
    ],
];
```

---

## Settings reference

### `db_path`

**Default:** `__DIR__ . '/data/ipam.sqlite'`

Absolute path to the SQLite database file. The directory must exist and be writable by the web server user. The file is created automatically on first request.

---

### `session_name`

**Default:** `'IPAMSESSID'`

Name of the session cookie. Change this if you run multiple PHP applications on the same domain to avoid session collisions.

---

### `app_name`

**Default:** `'Simple PHP IPAM'`

Sets the application display name shown in the browser tab title, navigation bar brand link, login page heading, and demo gate page.

```php
'app_name' => 'Acme IPAM',
```

---

### `base_url`

**Default:** `null`

Set to your application's canonical HTTPS URL (without trailing slash) to harden the HTTP→HTTPS redirect against `Host:` header spoofing:

```php
'base_url' => 'https://ipam.example.com',
```

When set, the redirect in `init.php` uses this value instead of `$_SERVER['HTTP_HOST']`. If `null` (default), the redirect falls back to `HTTP_HOST`, which is safe when behind a trusted reverse proxy that enforces the correct hostname.

---

### `proxy_trust`

**Default:** `false`

Set to `true` if the application is behind a reverse proxy that sets `X-Forwarded-Proto: https`. See [Behind a reverse proxy](#behind-a-reverse-proxy).

---

### `bootstrap_admin`

**Default:** `username: admin`, `password: ChangeMeNow!12345`

Credentials for the initial admin account. This account is created automatically when the database is first initialised (i.e. when no users exist). **Change the password before the site receives any traffic.**

Once any user exists in the database, changes to this setting have no effect.

A security warning banner is displayed to all logged-in admins until the password is changed away from the default value.

---

### `app_secret`

*(Added in v3.6.0)*

**Default:** `''` (empty — 2FA disabled)

Encryption key used to encrypt TOTP secrets stored in the database. This key is **required before any user can enable two-factor authentication**. If left empty, the 2FA enrollment option is hidden and cannot be activated.

Generate a suitable value:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

```php
'app_secret' => 'your-64-character-hex-string-here',
```

**Important:** Changing `app_secret` after users have enrolled in 2FA will invalidate all existing TOTP secrets. Users will not be able to complete the 2FA challenge and will need an admin to reset their 2FA before they can log in. Set this value once and do not change it.

---

### `session`

*(Added in v3.6.0)*

Controls session lifetime settings beyond the idle timeout.

```php
'session' => [
    'absolute_lifetime_minutes' => 480,
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `absolute_lifetime_minutes` | `480` | Maximum session duration in minutes, regardless of activity. Users are logged out and redirected to the login page when this limit is reached. Set to `0` to disable the absolute lifetime limit (sessions expire only via idle timeout). |

The default of 480 minutes (8 hours) ensures that sessions do not persist indefinitely when users leave their browsers open overnight.

---

### `auth`

*(Added in v3.6.0)*

Controls authentication hardening behaviour, specifically the persistent 2FA lockout.

```php
'auth' => [
    'lockout_after_failures'   => 10,
    'lockout_duration_minutes' => 30,
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `lockout_after_failures` | `10` | Number of consecutive 2FA failures before the account is persistently locked. |
| `lockout_duration_minutes` | `30` | How long the persistent lockout lasts in minutes. Admins can unlock early from the Users admin page. |

The persistent 2FA lockout is separate from the IP-based and per-account login lockouts. It tracks failures at the 2FA challenge step and persists across server restarts via `users.locked_until` and `users.lock_reason`. The Users admin page shows a "Locked (2FA)" badge for affected accounts.

---

### `api`

*(Added in v3.6.0)*

Seed defaults for per-API-key rate limiting. These values initialise the corresponding rows in the `settings` table on first install. **At runtime the values are read from the `settings` table** (editable at **⚙ Admin → Settings → API**); the `config.php` values serve only as the initial seed and as a fallback when the DB row is absent.

```php
'api' => [
    'rate_limit_window_seconds' => 60,   // sliding window size in seconds
    'rate_limit_requests'       => 300,  // max requests per window per key
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `rate_limit_window_seconds` | `60` | Length of the sliding-window rate-limit period. |
| `rate_limit_requests` | `300` | Maximum API requests per window per key before the server returns HTTP 429 with a `Retry-After` header. |

When a key exceeds the limit, the response is `HTTP 429 Too Many Requests` with a `Retry-After` header indicating how many seconds remain in the current window. The bucket resets automatically when the window elapses.

---

### `session_idle_seconds`

**Default:** `1800` (30 minutes)

How long a session can be idle before the user is automatically logged out. On the next page load after the timeout the user is redirected to the login page with an informational message.

---

### `login_max_attempts`

**Default:** `5`

Maximum number of consecutive failed login attempts from a single IP address before that IP is locked out. Works together with `login_lockout_seconds`.

---

### `login_lockout_seconds`

**Default:** `900` (15 minutes)

How long an IP address is locked out after exceeding `login_max_attempts`. Stale attempt records are purged automatically.

Locked-out login attempts are recorded in the audit log as `auth.login_blocked`.

---

### `account_lockout_max_attempts`

**Default:** `10`

Maximum number of failed login attempts for a specific username (across all source IPs) before the account is locked out. Independent of the per-IP `login_max_attempts` rate limiter — both must pass for login to succeed. Set in `config.php` only (not in the Settings UI).

---

### `account_lockout_seconds`

**Default:** `900` (15 minutes)

How long a username is locked out after exceeding `account_lockout_max_attempts`. Admins can manually unlock a locked account from `users.php`. The unlock action is audit-logged as `user.unlock`.

---

### `recovery_mode`

**Default:** `false` (not present in config.php by default)

Emergency break-glass escape hatch. When set to `true` in `config.php`:

- The local login form is always shown (overrides `oidc.disable_local_login`).
- CAPTCHA (`login_protection`) is disabled.
- IP rate limiting and per-account lockout are bypassed.
- Submitting the `bootstrap_admin` credentials from `config.php` resets the matching admin user's password hash and logs in. If the admin user was deleted, it is recreated.
- A red sticky banner warns on every page: **RECOVERY MODE ACTIVE**.
- All recovery actions are audit-logged (`auth.recovery_login`, `auth.recovery_reset`, `auth.recovery_provision`).

**Security:** This intentionally weakens security. Disable it in `config.php` immediately after use. Never leave `recovery_mode => true` in production.

---

### `import_csv_max_mb`

**Default:** `5`

Maximum allowed CSV file size for the import wizard, in megabytes. Accepted range: `5`–`50`.

---

### `import_sql_max_mb`

**Default:** `200`

Maximum file size in megabytes for SQL database imports via the Database Tools page. The PHP `upload_max_filesize` and `post_max_size` directives in `.htaccess` must also be set to at least this value.

```php
'import_sql_max_mb' => 200,
```

---

### `tmp_cleanup_ttl_seconds`

**Default:** `86400` (24 hours)

How long uploaded CSV files and import plan files in `data/tmp/` are kept before being eligible for deletion. Cleanup runs automatically via lazy housekeeping.

---

### `audit_log_retention_days`

**Default:** `0` (keep forever)

When set to a positive integer, audit log entries older than this many days are pruned during the next scheduled housekeeping run. Pruning is performed safely via an internal staging table swap that preserves the append-only integrity triggers.

```php
'audit_log_retention_days' => 90,
```

---

### `address_history_retention_days`

**Default:** `0` (keep forever)

Number of days to retain address change history. Entries older than this are pruned during scheduled housekeeping.

```php
'address_history_retention_days' => 180,
```

---

### `housekeeping`

Controls lazy background housekeeping (temp file cleanup, stale login attempt purge).

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Whether housekeeping runs automatically |
| `interval_seconds` | `86400` | Minimum seconds between housekeeping runs (min: 3600) |

---

### `utilization_warn` / `utilization_critical`

**Defaults:** `80` / `95`

Percentage thresholds for the subnet utilization progress bars on the dashboard and subnets list. The bar turns yellow at `utilization_warn` and red at `utilization_critical`.

---

### `api_bulk_limit`

**Default:** `500`

Maximum number of records accepted per bulk API write request (`POST ?resource=addresses&bulk=1` or `POST ?resource=subnets&bulk=1`). Requests exceeding this limit receive HTTP `400`. Range: 1–500.

---

### `auto_reserve_network_broadcast`

*(Added in v2.0.0)*

**Default:** `true`

When `true`, the **Auto-reserve network, broadcast & gateway IPs** checkbox on the subnet create form is pre-checked. When a subnet is created with this option enabled, the network address, broadcast address, and (if provided) the gateway are automatically inserted as `status=reserved` addresses. Users can uncheck the box per-subnet at creation time regardless of this default.

---

### `alert_email`

*(Added in v2.0.0; **deprecated in v2.8.0**, removal in v3.0.0)*

**Default:** `''` (disabled)

Replaced in v2.8.0 by **`alert.recipient_user_ids`**, a multi-select picker on **⚙ Admin → Settings → Alerting** that ties recipients to active user records with a non-empty email. The v2.8.0 migration auto-maps a single matching active user from any existing `alert_email` value; unmappable values produce a `settings.alert_email_unmigrated` audit row so the admin can re-pick recipients on the settings page. The legacy key is hidden from the settings UI as of v2.8.0 and will be removed in v3.0.0.

If you are still on v2.7.x or earlier, this key is the single string address that receives every utilization alert. The feature depends on a working server MTA (`mail()` function).

```php
'alert_email' => 'netops@example.com',
```

---

### `alert_util_warn_pct` / `alert_util_crit_pct`

*(Added in v2.0.0)*

**Defaults:** `80` / `95`

Percentage thresholds for email utilization alerts (distinct from the `utilization_warn` / `utilization_critical` keys which control UI colour only). An email is sent once per 24-hour window per subnet per level. The application tracks sent alerts in the `alert_state` table and auto-clears them when utilization drops back below the threshold.

---

### `alert_interval_seconds`

*(Added in v2.0.0)*

**Default:** `3600`

Minimum number of seconds between utilization alert checks. Each page load may trigger a check if this interval has elapsed since the last run. The check itself is fast (a single SQL query); set lower if you need sub-hourly polling.

---

## `login_protection`

*(Added in v1.9)*

Optional bot/abuse mitigation on the login form. Disabled by default (`method: null`).

```php
'login_protection' => [
    'method'      => null,   // see methods below
    'site_key'    => '',     // required for widget-based methods
    'secret_key'  => '',     // required for widget-based methods
    'min_seconds' => 3,      // time_check only: min seconds between page load and submit
    'version'     => 2,      // recaptcha only: 2 (checkbox) or 3 (invisible)
],
```

### Methods

| `method` | Description |
|----------|-------------|
| `null` | Disabled (default) |
| `'honeypot'` | Hidden field that bots fill in — filled submissions are silently discarded |
| `'time_check'` | Rejects submissions faster than `min_seconds` after page load — catches naive bots |
| `'turnstile'` | [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) (privacy-preserving) |
| `'hcaptcha'` | [hCaptcha](https://www.hcaptcha.com/) |
| `'recaptcha'` | [Google reCAPTCHA](https://www.google.com/recaptcha/) v2 (checkbox) or v3 (invisible) |
| `'friendly_captcha'` | [Friendly Captcha](https://friendlycaptcha.com/) (privacy-preserving, GDPR-friendly) |

### Widget-based setup

For `turnstile`, `hcaptcha`, `recaptcha`, and `friendly_captcha`:

1. Register your site with the provider and obtain a `site_key` and `secret_key`.
2. Set `method` to the provider name, and fill in both keys.
3. The widget is rendered inside the login form automatically.

The `Content-Security-Policy` `script-src` directive is extended on the login page only to include the provider's domain. Other pages are unaffected.

### Fail-open behaviour

If the provider's verification endpoint is unreachable (network error), the login attempt is **allowed through**. Bot protection never locks out legitimate users due to a third-party outage.

---

## `demo_mode`

*(Available since v1.7; `gate` added in v1.9)*

Enables an opt-in public demo mode suitable for showcasing the application without risking real data.

```php
'demo_mode' => [
    'enabled'    => false,
    'gate'       => null,   // optional pre-login bot challenge — see below
    'site_key'   => '',
    'secret_key' => '',
],
```

When `enabled` is `true`:

- **Only the `demo` / `demo` account can log in.** All other credentials are rejected.
- **Destructive admin actions are disabled:** user create/delete/toggle/role-change, API key create/deactivate/delete, and CSV import apply are all blocked server-side.
- **A banner** is displayed on every page informing visitors they are in demo mode.
- **The database is reset nightly at midnight** to pre-populated seed data (realistic sites, subnets, addresses, users, audit log, API keys). This happens automatically on the next page load after midnight.
- **OIDC login is hidden** — only local form login is available.
- **Rate limiting applies** to the demo login form (same `login_max_attempts` / `login_lockout_seconds` settings as normal mode).

### Seed data

The seed database includes:
- 4 sites (London HQ, New York DC, Sydney Office, AWS eu-west-1)
- 10 IPv4 subnets + 3 IPv6 subnets across the sites
- ~55 address records with realistic hostnames, owners, and statuses
- 3 users: `demo` (admin), `readonly-user` (readonly), `netops-user` (netops)
- 2 API keys (one active, one inactive)
- ~30 audit log entries and ~8 address history entries (backdated)

### Demo gate

*(Added in v1.9)*

The optional `gate` key adds a mandatory bot challenge at `demo_gate.php` that visitors must pass **before reaching the login page**. This is useful for public demo instances to reduce automated scraping and abuse.

```php
'demo_mode' => [
    'enabled'    => true,
    'gate'       => 'turnstile',          // challenge method
    'site_key'   => 'your-site-key',
    'secret_key' => 'your-secret-key',
],
```

Supported gate methods: `honeypot`, `turnstile`, `hcaptcha`, `recaptcha`, `friendly_captcha`.

Once a visitor passes the gate their session is marked and they are not challenged again until they log out. The gate session flag is cleared on logout.

---

## `recaptcha_enterprise`

*(Added in v1.19.0)*

Optional upgrade to Google reCAPTCHA Enterprise for server-side token verification. When enabled, the standard reCAPTCHA v2/v3 widget is used on the front-end (no HTML changes required), but backend verification uses the Enterprise Assessment API instead of `siteverify`.

```php
'recaptcha_enterprise' => [
    'enabled'          => false,
    'project_id'       => '',    // GCP project ID
    'api_key'          => '',    // Server-side API key (Restricted API key from GCP Console)
    'expected_action'  => 'login',
    'score_threshold'  => 0.5,
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Set to `true` to use the Enterprise API |
| `project_id` | `''` | GCP project ID where reCAPTCHA Enterprise is enabled |
| `api_key` | `''` | Server-side restricted API key (not the site key) |
| `expected_action` | `'login'` | Action name passed to `grecaptcha.enterprise.execute()` — must match exactly (case-sensitive) |
| `score_threshold` | `0.5` | Minimum score to pass (0.0–1.0; higher = stricter) |

### Setup

1. Enable the **reCAPTCHA Enterprise API** in your GCP project.
2. Create a reCAPTCHA Enterprise key (type: **website**, integration type: **score** for v3).
3. Create a **restricted API key** in GCP Credentials scoped to the reCAPTCHA Enterprise API.
4. Set `login_protection.method = 'recaptcha'` and `login_protection.site_key` to your Enterprise site key.
5. Set `recaptcha_enterprise.enabled = true`, `project_id`, `api_key`, and `expected_action`.

When `enabled` is `false` (default), the standard `https://www.google.com/recaptcha/api/siteverify` endpoint is used instead.

---

### `recaptcha_action`

*(Added in v2.0.0)*

**Default:** `'login'`

Top-level config key (not inside `recaptcha_enterprise`) that controls the reCAPTCHA v3 `action` parameter used by the login form. The value is emitted as a `data-recaptcha-action` HTML attribute on the hidden reCAPTCHA input and read by `app.js` at runtime, replacing the previous hardcoded `'login'` string.

Change this if your reCAPTCHA policy requires a different action name:

```php
'recaptcha_action' => 'ipam_login',
```

This key applies to both the standard reCAPTCHA v3 integration and the reCAPTCHA Enterprise flow (which reads `recaptcha_enterprise.expected_action` separately for server-side verification).

---

### Nightly reset cron (optional)

For a guaranteed reset at midnight (independent of web traffic), add a cron entry:

```cron
0 0 * * * php /path/to/Simple-PHP-IPAM/demo_reset.php
```

---

## Cron runner (`cron.php`)

*(Added in v2.3.0)*

`cron.php` is the unified CLI housekeeping and network scanning runner. A single cron entry replaces the need for multiple scheduled scripts:

```cron
# Run every 15 minutes.
# Housekeeping tasks throttle themselves internally (temp cleanup, log pruning, backups).
# Scanning honours the per-subnet interval configured in the Scan Schedule UI.
*/15 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /var/log/ipam-cron.log 2>&1
```

**What it runs (in order):**

| Task | Config key(s) | Throttled? |
|------|--------------|-----------|
| Temp file cleanup | `tmp_cleanup_ttl_seconds` | No — always runs |
| Audit log pruning | `housekeeping.audit_log_retention_days` | No — skipped when value is `0` |
| Address history pruning | `address_history_retention_days` | No — skipped when `retention_days=0` |
| Subnet utilisation alerts | `alert.recipient_user_ids` (v2.8.0+; legacy `alert_email`) | No — skipped when no eligible recipients resolve |
| Database backup | `backup.enabled`, `backup.frequency` | Yes — honours `backup.frequency` setting |
| Network scanning | Per-subnet `scan_schedules.interval_minutes` | Yes — each subnet's own interval |
| Demo mode reset | `demo_mode.enabled` | Yes — at most once every 24 hours |

Each task emits one JSON object to stdout (JSONL format). Errors go to stderr. Exit code is `0` on success or `1` if any task throws an exception (remaining tasks still run).

**Notes:**
- `cron.php` rejects web requests with HTTP 403 — it is CLI-only.
- For one-off or per-subnet scans from the CLI, use `scan_run.php` instead.
- Scanning requires `scan_schedules` rows (configured via the Scan Schedule UI or REST API). Subnets with no schedule are never scanned by `cron.php`.
- The demo reset task is a no-op unless `demo_mode.enabled=true` in `config.php`. When enabled, it resets the database to seed data at most once every 24 hours (tracked via `data/demo_last_reset.txt`). For an immediate unthrottled reset, run `php demo_reset.php` directly.

### Security note

Demo mode does not restrict read access. Any visitor can browse all IPAM data. For public-facing demo instances, strongly consider adding an IP allowlist or HTTP Basic Auth at the web-server level to limit exposure. The demo gate provides bot protection but not access control.

---

## `password_policy`

Controls password complexity requirements and optional rotation. **As of v3.14.0, these settings are stored in the database and enforced in all password-change flows.** Admin changes via Admin → Settings take effect immediately without a server restart.

| Key | Default | Description |
|-----|---------|-------------|
| `password_policy.min_length` | `12` | Minimum number of characters (multi-byte safe) |
| `password_policy.require_uppercase` | `false` | Require at least one uppercase letter |
| `password_policy.require_lowercase` | `false` | Require at least one lowercase letter |
| `password_policy.require_number` | `false` | Require at least one digit |
| `password_policy.require_symbol` | `false` | Require at least one non-alphanumeric character |
| `password_policy.max_password_age_days` | `0` | Force change after N days; `0` = never expires |

All failing rules are reported at once rather than one at a time.

---

## `mfa`

*(Added in v3.14.0)*

Controls multi-factor authentication options. Managed via Admin → Settings → MFA.

| Key | Default | Description |
|-----|---------|-------------|
| `mfa.email_otp_enabled` | `false` | Allow users to enroll Email OTP as a second authentication factor. Requires a working SMTP configuration. |
| `mfa.require` | `false` | Require all users to enroll in at least one 2FA method (TOTP or Email OTP) before accessing the application. Users without any 2FA enrolled are redirected to the Account page on login. Admins are not exempt. |

---

## OIDC settings

The `oidc` block configures optional OIDC single sign-on. All keys are ignored when `enabled` is `false`.

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Set to `true` to activate OIDC |
| `display_name` | `'SSO'` | Label on the login page button |
| `client_id` | `''` | OAuth 2.0 client ID from your IdP |
| `client_secret` | `''` | OAuth 2.0 client secret from your IdP |
| `discovery_url` | `''` | IdP base URL (`/.well-known/openid-configuration` appended automatically) |
| `redirect_uri` | `''` | Callback URL — must match exactly what is registered with the IdP |
| `scopes` | `'openid email profile'` | Space-separated scopes |
| `auto_link` | `false` | Auto-link existing local accounts to an OIDC identity on first SSO login |
| `auto_provision` | `false` | Auto-create a new local account on first OIDC login (implies `auto_link`) |
| `default_role` | `'readonly'` | Role assigned to auto-provisioned users |
| `disable_local_login` | `false` | Hide the password form when OIDC is enabled |
| `hide_emergency_link` | `false` | Hide the emergency local access link text |
| `disable_emergency_bypass` | `false` | Make `login.php?local=1` completely ineffective. **Warning:** locks you out if your IdP goes down |

See the [OIDC guide](oidc.md) for IdP setup examples, user provisioning details, and troubleshooting.

---

## `update_check`

Controls the automatic update check shown in the page footer and admin banner.

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Set to `false` to disable the update check entirely |
| `ttl_seconds` | `86400` | How long to cache the result (default: 24 hours; minimum: 3600) |
| `notify_prerelease` | `false` | Set to `true` to also alert for alpha/beta/RC releases |

The check fetches the GitHub releases API once per TTL period, caches the result in `data/tmp/update-check.json`, and shows:
- A badge in the page footer for all logged-in users
- A dismissible banner at the top of each page for admins

Network failures are silently ignored. Drafts are never shown.

---

## `backup`

Controls automatic database backups.

### Prerequisites

The backup mechanism depends on the database driver in use:

| Driver | Requirement |
|--------|-------------|
| SQLite | No external tools needed — the backup is a WAL-checkpointed file copy performed entirely in PHP/PDO. |
| MySQL / MariaDB | **`mysqldump`** must be installed on the web server and available in `$PATH`. Most Linux distributions provide it in the `mysql-client` or `default-mysql-client` package. |
| PostgreSQL | **`pg_dump`** must be installed on the web server and available in `$PATH`. Most distributions provide it in the `postgresql-client` package. |

If the required tool is absent, `cron.php` will record a `failure` status in the backup history and no backup file will be written. The **Health Dashboard** (⚙ Admin → Health) shows a **critical** badge when the tool is missing.

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `false` | Set to `true` to enable automatic backups |
| `frequency` | `'daily'` | Backup interval: `'daily'` (24 h) or `'weekly'` (7 days) |
| `retention` | `7` | Number of most-recent backup files to keep; older ones are deleted |
| `dir` | `''` | Directory for backup files; empty string uses `data/backups/` |

Backups run at most once per interval on normal page load. The backup format is a WAL-checkpointed SQLite file copy with a timestamp filename (`ipam-YYYY-MM-DD-HHmmss.sqlite`). You can also trigger a manual backup from the **Database Tools** admin page.

> **Security note:** If you set a custom `dir` path, ensure it is either outside the webroot or protected by your web server configuration.

---

### `status_hide_version`

**Default:** `false`

When set to `true`, the health check endpoint (`status.php`) omits the `version` field from its JSON response. Use this to prevent unauthenticated version discovery.

```php
'status_hide_version' => true,
```

---

## Health check endpoint

The application exposes an unauthenticated health check at `GET /status.php`. Returns:

- **HTTP 200** with `{"status":"ok","version":"1.9","db":"ok"}` when healthy.
- **HTTP 503** with `{"status":"error","db":"error"}` when the database is unreachable.

Use this with uptime monitors, load balancer health probes, or container `HEALTHCHECK` directives.

---

## Behind a reverse proxy

If HTTPS is terminated at a load balancer or reverse proxy that forwards `X-Forwarded-Proto: https`, set:

```php
'proxy_trust' => true,
```

Only do this if:
- You control the proxy
- The proxy reliably strips or overwrites the `X-Forwarded-Proto` header from untrusted clients
