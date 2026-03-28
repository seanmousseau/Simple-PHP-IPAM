# Configuration Reference

All application settings live in `config.php` in the application root. This file is preserved automatically during upgrades — you will never need to re-apply your settings after an upgrade.

## Contents

- [Full example](#full-example)
- [Settings reference](#settings-reference)
- [OIDC settings](#oidc-settings)
- [`update_check`](#update_check)
- [`backup`](#backup)
- [`audit_log_retention_days`](#audit_log_retention_days)
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

    // OIDC single sign-on — see docs/oidc.md for full setup guide.
    'oidc' => [
        'enabled'                   => false,
        'display_name'              => 'SSO',
        'client_id'                 => '',
        'client_secret'             => '',
        'discovery_url'             => '',
        'redirect_uri'              => '',
        'scopes'                    => 'openid email profile',
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

### `proxy_trust`

**Default:** `false`

Set to `true` if the application is behind a reverse proxy that sets `X-Forwarded-Proto: https`. See [Behind a reverse proxy](#behind-a-reverse-proxy).

---

### `bootstrap_admin`

**Default:** `username: admin`, `password: ChangeMeNow!12345`

Credentials for the initial admin account. This account is created automatically when the database is first initialised (i.e. when no users exist). **Change the password before the site receives any traffic.**

Once any user exists in the database, changes to this setting have no effect.

As of v1.1, a security warning banner is displayed to all logged-in users until the password is changed away from the default value (`ChangeMeNow!12345`).

---

### `session_idle_seconds`

**Default:** `1800` (30 minutes)

How long a session can be idle before the user is automatically logged out. On the next page load after the timeout the user is redirected to the login page with an informational message.

Set to a higher value (e.g. `28800` for 8 hours) if your users work in long sessions.

---

### `login_max_attempts`

**Default:** `5`

Maximum number of consecutive failed login attempts from a single IP address before that IP is locked out. Works together with `login_lockout_seconds`.

---

### `login_lockout_seconds`

**Default:** `900` (15 minutes)

How long an IP address is locked out after exceeding `login_max_attempts`. Stale attempt records are purged automatically — no manual intervention is needed.

Locked-out login attempts are recorded in the audit log as `auth.login_blocked`.

---

### `import_csv_max_mb`

**Default:** `5`

Maximum allowed CSV file size for the import wizard, in megabytes. Accepted range: `5`–`50`.

---

### `tmp_cleanup_ttl_seconds`

**Default:** `86400` (24 hours)

How long uploaded CSV files and import plan files in `data/tmp/` are kept before being eligible for deletion. Cleanup runs automatically via lazy housekeeping and can also be triggered manually with `php tmp_cleanup.php`.

---

### `audit_log_retention_days`

**Default:** `0` (keep forever)

When set to a positive integer, audit log entries older than this many days are pruned during the next scheduled housekeeping run. Pruning is performed safely via an internal staging table swap that preserves the append-only integrity triggers.

**Example — keep 90 days of audit history:**

```php
'audit_log_retention_days' => 90,
```

Set to `0` (or omit the key) to keep all audit entries indefinitely. Note that the audit log grows unboundedly without retention; for busy installations consider setting a retention period for compliance and storage reasons.

---

### `housekeeping`

Controls lazy background housekeeping (temp file cleanup, stale login attempt purge).

| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | `true` | Whether housekeeping runs automatically |
| `interval_seconds` | `86400` | Minimum seconds between housekeeping runs (min: 3600) |

Housekeeping runs at most once per `interval_seconds` on normal web traffic. It does not require a cron job, but you can also run `php tmp_cleanup.php` manually.

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
| `auto_provision` | `false` | Automatically create or link a local account on first OIDC login |
| `default_role` | `'readonly'` | Role assigned to auto-provisioned users (`admin` or `readonly`) |
| `disable_local_login` | `false` | Hide the password form when OIDC is enabled |
| `hide_emergency_link` | `false` | Hide the emergency local access link text (URL still works unless `disable_emergency_bypass` is also set) |
| `disable_emergency_bypass` | `false` | Make `login.php?local=1` completely ineffective. **Warning:** locks you out if your IdP goes down |

See the [OIDC guide](oidc.md) for IdP setup examples, user provisioning details, and troubleshooting.

---

## `utilization_warn` / `utilization_critical`

**Defaults:** `80` / `95`

Percentage thresholds for the IPv4 subnet utilization progress bars in the subnet list. The bar turns yellow at `utilization_warn` and red at `utilization_critical`.

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

Backups run at most once per interval on normal page load (file-locked, non-blocking). The backup format is a WAL-checkpointed SQLite file copy with a timestamp filename (`ipam-YYYY-MM-DD-HHmmss.sqlite`). You can also trigger a manual backup from the **Database Tools** admin page.

> **Security note:** If you set a custom `dir` path, ensure it is either outside the webroot or protected by your web server configuration. The default `data/backups/` is inside the webroot but `.htaccess` blocks direct HTTP access to `*.sqlite` files.

---

## `audit_log_retention_days`

See [`audit_log_retention_days`](#audit_log_retention_days) in the settings reference above.

---

## Health check endpoint

The application exposes an unauthenticated health check at `GET /status.php`. No configuration is required. It returns:

- **HTTP 200** with `{"status":"ok","version":"1.0","db":"ok"}` when the app and database are healthy.
- **HTTP 503** with `{"status":"error","db":"error"}` when the database is unreachable.

Use this URL with uptime monitors, load balancer health probes, or container `HEALTHCHECK` directives.

---

## Behind a reverse proxy

If HTTPS is terminated at a load balancer or reverse proxy that forwards `X-Forwarded-Proto: https`, set:

```php
'proxy_trust' => true,
```

Only do this if:
- You control the proxy
- The proxy reliably strips or overwrites the `X-Forwarded-Proto` header from untrusted clients

Setting `proxy_trust` to `true` on a server with no proxy in front of it allows clients to spoof HTTPS detection, which would bypass the HTTP→HTTPS redirect.
