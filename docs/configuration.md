# Configuration Reference

## Where configuration lives

Starting in **v2.6.0**, configuration lives in two places:

1. **`config.php`** — bootstrap keys only. These are values the app needs before it can open the database, and they will always live on disk: `db_path`, `session_name`, `proxy_trust`, and the HTTPS / base URL settings. Edit by hand on the server.
2. **Database (`settings` table)** — everything else. Edited through the admin UI under **⚙ Admin → Settings**. Reads are cached per-request.

When the app reads a setting it checks each source in order and returns the first match: `settings` table row → `config.php` value at the same key (or nested path) → default from `ipam_setting_definitions()`. The admin page shows a source badge next to every setting — 🟢 Database, 🟡 config.php, or ⚪ Default — so you can see where the effective value comes from.

### Transition from `config.php` (v2.6 → v2.7 → v3.0)

- **v2.6.0** — groundwork release. Introduces the `settings` table, the `ipam_setting()` helper, the registry, and the `settings.php` admin UI. The migration seeds every registered key into the table on first boot using the current `config.php` value (or the registry default). Wherever `ipam_setting()` is called, the database row takes precedence over `config.php`. The runtime subsystems (OIDC, alerting, branding, login protection, update checker) are not yet rewired to call `ipam_setting()`, though, so they keep reading straight from `$config` at request time — saving a value in the admin UI lands it in the `settings` table correctly, but most subsystems still behave as if you had edited `config.php`.
- **v2.7.0** — **(current)** every subsystem (OIDC, alerting, branding, login protection, update checker, reCAPTCHA Enterprise, security) routes through `ipam_setting()`, so edits in the admin UI take effect on the next request without a `config.php` change or a restart. `config.php` continues to work as a fallback. Admin UI now shows a deprecation banner listing any registered key still being served from `config.php` with a one-click **Import to database** action; the dashboard shows a matching warning card for admins.
- **v3.0.0** — the `config.php` fallback is removed. Only bootstrap keys are still read from the file. An upgrade-time migration copies any stragglers into the database.

### Settings reference (database-backed)

The keys below are seeded into the `settings` table by the v2.6.0 migration and can be edited at **⚙ Admin → Settings**. Generated from `ipam_setting_definitions()` in `lib.php`; if it drifts from the registry, the registry is authoritative.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `branding.site_name` | string | `Simple PHP IPAM` | App display name in browser tab, nav bar, and login page. |
| `branding.timezone` | string | `UTC` | PHP timezone identifier used to render timestamps. |
| `security.session_idle_seconds` | int | `1800` | Session idle timeout before auto-logout. |
| `security.login_max_attempts` | int | `5` | Failed logins per window before IP lockout. |
| `security.login_lockout_seconds` | int | `900` | Lockout window length. |
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

## Legacy reference (`config.php`)

**Every non-bootstrap key in this section is deprecated as of v2.7.0 and will be removed in v3.0.0.** Edit them from **⚙ Admin → Settings** instead. If the admin UI shows a "config.php settings to migrate" banner, click **Import to database** on each listed key to copy its current value into the `settings` table; after that you can remove the key from `config.php` at your convenience.

The rest of this document describes the full `config.php` file. In v2.7.0 every non-bootstrap key here is also editable from the admin UI, and the UI value takes precedence at runtime. This section will shrink to just the bootstrap keys in v3.0.0.

All non-bootstrap settings currently still read from `config.php` when no database row exists. This file is preserved automatically during upgrades — you will never need to re-apply your settings after an upgrade.

New configuration keys are added automatically when you upgrade: on the first page load after an upgrade, any missing keys are appended to `config.php` with their default values. An admin notice is shown once to confirm what was added.

## Contents

- [Full example](#full-example)
- [Settings reference](#settings-reference)
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

```php
return [
    // Path to the SQLite database file.
    // The directory must be writable by the web server user.
    'db_path' => __DIR__ . '/data/ipam.sqlite',

    // Session cookie name.
    'session_name' => 'IPAMSESSID',

    // Set to true only if the app sits behind a trusted reverse proxy
    // that sets X-Forwarded-Proto. Leave false if accessed directly.
    'proxy_trust' => false,

    // Bootstrap admin account — created on first run if no users exist.
    // CHANGE THIS PASSWORD before exposing the site.
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // Session idle timeout (seconds). Users are logged out after this
    // much inactivity. Default: 1800 (30 minutes).
    'session_idle_seconds' => 1800,

    // Login rate limiting: lock out an IP after this many consecutive
    // failed attempts within the lockout window.
    'login_max_attempts'    => 5,
    'login_lockout_seconds' => 900,

    // Maximum CSV upload size for the import wizard (MB). Range: 5–50.
    'import_csv_max_mb' => 5,

    // How long (seconds) to keep uploaded CSV temp files before cleanup.
    'tmp_cleanup_ttl_seconds' => 86400,

    // Audit log retention (days). Entries older than this are pruned during housekeeping.
    // Set to 0 to keep the audit log forever (default).
    'audit_log_retention_days' => 0,

    // Address history retention (days). 0 = keep forever.
    'address_history_retention_days' => 0,

    // Lazy housekeeping: runs on normal site access at most once per interval.
    'housekeeping' => [
        'enabled'          => true,
        'interval_seconds' => 86400, // once per day
    ],

    // Subnet utilization thresholds for the colour-coded progress bars.
    'utilization_warn'     => 80,
    'utilization_critical' => 95,

    // Update check: fetches GitHub releases and shows a banner/badge when a
    // newer version is available. notify_prerelease includes alpha/beta/RC builds.
    'update_check' => [
        'enabled'           => true,
        'ttl_seconds'       => 86400,  // cache 24 hours
        'notify_prerelease' => false,
    ],

    // Automatic database backups (opt-in). Backups run on page load when the
    // interval elapses. Older files beyond retention count are pruned.
    'backup' => [
        'enabled'   => false,
        'frequency' => 'daily',   // 'daily' | 'weekly'
        'retention' => 7,
        'dir'       => '',        // empty = data/backups/
    ],

    // Login form bot protection (opt-in). See login_protection section below.
    'login_protection' => [
        'method'      => null,
        'site_key'    => '',
        'secret_key'  => '',
        'min_seconds' => 3,
        'version'     => 2,
    ],

    // Demo mode (opt-in). See demo_mode section below.
    'demo_mode' => [
        'enabled'    => false,
        'gate'       => null,
        'site_key'   => '',
        'secret_key' => '',
    ],

    // Password complexity policy.
    'password_policy' => [
        'min_length'            => 12,
        'require_uppercase'     => false,
        'require_lowercase'     => false,
        'require_number'        => false,
        'require_symbol'        => false,
        'max_password_age_days' => 0,
    ],

    // OIDC single sign-on — see docs/oidc.md for full setup guide.
    'oidc' => [
        'enabled'                   => false,
        'display_name'              => 'SSO',
        'client_id'                 => '',
        'client_secret'             => '',
        'discovery_url'             => '',
        'redirect_uri'              => '',
        'scopes'                    => 'openid email profile',
        'auto_link'                 => false,
        'auto_provision'            => false,
        'default_role'              => 'readonly',
        'disable_local_login'       => false,
        'hide_emergency_link'       => false,
        'disable_emergency_bypass'  => false,
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
| Audit log pruning | `audit_log_retention_days` | No — skipped when `retention_days=0` |
| Address history pruning | `address_history_retention_days` | No — skipped when `retention_days=0` |
| Subnet utilisation alerts | `alert.recipient_user_ids` (v2.8.0+; legacy `alert_email`) | No — skipped when no eligible recipients resolve |
| Database backup | `backup.enabled`, `backup.frequency` | Yes — honours frequency setting |
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

Controls password complexity requirements and optional rotation.

```php
'password_policy' => [
    'min_length'            => 12,
    'require_uppercase'     => false,
    'require_lowercase'     => false,
    'require_number'        => false,
    'require_symbol'        => false,
    'max_password_age_days' => 0,
],
```

| Key | Default | Description |
|-----|---------|-------------|
| `min_length` | `12` | Minimum number of characters (multi-byte safe) |
| `require_uppercase` | `false` | Require at least one uppercase letter |
| `require_lowercase` | `false` | Require at least one lowercase letter |
| `require_number` | `false` | Require at least one digit |
| `require_symbol` | `false` | Require at least one non-alphanumeric character |
| `max_password_age_days` | `0` | Force change after N days; `0` = never expires |

All failing rules are reported at once rather than one at a time.

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
