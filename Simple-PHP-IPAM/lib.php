<?php
declare(strict_types=1);
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/lib/utils.php';
require_once __DIR__ . '/lib/ip.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/presentation.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/user_preferences.php';
require_once __DIR__ . '/lib/auth.php';     // core session + CSRF + login (ADR-004 Task 6.1)
require_once __DIR__ . '/lib/auth_password.php'; // password policy + reset tokens (ADR-004 Task 6.2)
require_once __DIR__ . '/lib/auth_rate_limit.php'; // login/IP rate limiting + lockout (ADR-004 Task 6.3)
require_once __DIR__ . '/lib/BackupClientInterface.php';
require_once __DIR__ . '/lib/S3Client.php';
require_once __DIR__ . '/lib/SftpClient.php';
require_once __DIR__ . '/lib/LocalBackupClient.php';
require_once __DIR__ . '/lib/vault.php';
require_once __DIR__ . '/lib/app_secret.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/auth_step_up.php';

/* ---------------- View helpers (v3.8.0, #522) ---------------- */

/* ---------------- Timestamp display ---------------- */

/**
 * Convert a UTC SQLite timestamp string to the configured timezone for display.
 *
 * All timestamps are stored as UTC ('YYYY-MM-DD HH:MM:SS'). This helper converts
 * them to the timezone configured in config.php['timezone'] (default 'UTC').
 * Apply to every timestamp echo in the UI rather than using the raw DB value.
 *
 * @param  string $utcStr  UTC datetime string from the database, or empty string.
 * @param  string $format  PHP date() format string. Defaults to 'Y-m-d H:i:s'.
 * @return string          Formatted datetime in the configured timezone, or '' if input is empty.
 */
function display_datetime(string $utcStr, string $format = 'Y-m-d H:i:s'): string
{
    return ipam_format_datetime($utcStr, $format);
}

/**
 * Resolve the effective display timezone for a user.
 *
 * Fallback chain: per-user users.timezone → branding.timezone setting → PHP default → UTC.
 * Result is cached per userId per request to avoid redundant DB queries.
 */
function ipam_user_timezone(?int $userId = null): string
{
    static $userCache = [];

    if ($userId === null) {
        $uid = isset($_SESSION) ? to_int($_SESSION['uid'] ?? 0) : 0;
        $userId = $uid > 0 ? $uid : null;
    }

    // Cache per-user DB lookups only; ipam_setting() has its own cache.
    if ($userId !== null && isset($userCache[$userId])) {
        return $userCache[$userId];
    }

    $tz = '';

    if ($userId !== null) {
        /** @var \PDO|null $globalDb */
        $globalDb = $GLOBALS['db'] ?? null;
        if ($globalDb instanceof \PDO) {
            try {
                $st = $globalDb->prepare("SELECT timezone FROM users WHERE id = :id");
                $st->execute([':id' => $userId]);
                $row = $st->fetch();
                if (is_array($row) && isset($row['timezone']) && is_string($row['timezone']) && $row['timezone'] !== '') {
                    $tz = $row['timezone'];
                }
            } catch (\Exception) {}
        }
    }

    if ($tz === '') $tz = to_str(ipam_setting('branding.timezone'));
    if ($tz === '') $tz = date_default_timezone_get() ?: 'UTC';

    try { new \DateTimeZone($tz); } catch (\Exception) { $tz = 'UTC'; }

    if ($userId !== null) $userCache[$userId] = $tz;
    return $tz;
}

/**
 * Format a UTC timestamp for display in the current user's timezone.
 *
 * Accepts either:
 *   - a UTC datetime string (e.g. "2026-04-30 12:34:56" or ISO-8601 "...Z")
 *   - an int Unix epoch (seconds since 1970-01-01 UTC)
 *
 * Default format includes the TZ abbreviation ('Y-m-d H:i T'). Pass $fmt to
 * override, or $userId to render in a specific user's timezone rather than the
 * session user's. Empty string / 0 / null returns ''.
 *
 * #782: this is the single display-side path for UTC→user-TZ conversion. New
 * code MUST route through here rather than calling gmdate()/date() inline.
 */
function ipam_format_datetime(string|int|null $utc, ?string $fmt = null, ?int $userId = null): string
{
    // Guard zero-ish inputs in either form: int 0 from epoch math, string '0'
    // from `to_str()` of a DB column, or literal empty string. Without the
    // string-'0' check, a default/zero timestamp falls through to DateTime()
    // and renders as a bogus 1970 date instead of blank.
    if ($utc === null || $utc === '' || $utc === 0 || $utc === '0') return '';
    $fmt = $fmt ?? 'Y-m-d H:i T';
    try {
        if (is_int($utc)) {
            $dt = (new \DateTime('@' . $utc))->setTimezone(new \DateTimeZone('UTC'));
        } else {
            $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
        }
        $dt->setTimezone(new \DateTimeZone(ipam_user_timezone($userId)));
        return $dt->format($fmt);
    } catch (\Exception) {
        return is_int($utc) ? (string) $utc : $utc;
    }
}

/* csrf_token(), csrf_require(), is_logged_in(),                            */
/* ipam_post_login_redirect_stash(), ipam_post_login_redirect_consume(),    */
/* and require_login() moved to lib/auth.php in v3.30.0                     */
/* (ADR-004 Phase 6 Task 6.1, #907).                                        */

/* validate_password_complexity() moved to lib/auth_password.php in         */
/* v3.30.0 (ADR-004 Phase 6 Task 6.2, #907).                                */

/* current_user(), require_role(), require_write_access(), and login_user()  */
/* moved to lib/auth.php in v3.30.0 (ADR-004 Phase 6 Task 6.1, #907).        */

/* Login/IP rate-limiting + account-lockout helpers moved to              */
/* lib/auth_rate_limit.php in v3.30.0 (ADR-004 Phase 6 Task 6.3, #907):   */
/* auth_rate_limited(), record_auth_failure(), clear_auth_failures(),     */
/* login_rate_limited(), auth_rate_limit_unlock_at(),                     */
/* ipam_audit_ip_rate_limited(), prune_rate_limit_dampener(),             */
/* record_login_failure(), clear_login_failures(), account_locked_out(),  */
/* clear_account_lockout(), purge_old_login_attempts().                   */

/** @param IpamConfig $config */
function recovery_mode_enabled(array $config): bool
{
    return (bool)($config['recovery_mode'] ?? false);
}

/* logout_user() and client_ip() moved to lib/auth.php in v3.30.0           */
/* (ADR-004 Phase 6 Task 6.1, #907).                                        */

/**
 * v3.28.2 #1178 — write the one-shot `install_keys_announce.<key>` banner
 * flag directly, bypassing `ipam_setting_set()`'s `setting.update` audit
 * emit so the only audit row for an install-key event is the intentional
 * `<key>_autogenerated` row. The flag row itself was seeded into the
 * settings table by `ipam_setting_definitions()` + the bottom-of-
 * migrations seed loop, so an UPDATE is sufficient on any v3.28.2 install
 * — INSERT-IF-NOT-EXISTS covers installs that somehow missed the seed.
 *
 * Internal helper: external callers should not call this directly.
 *
 * @throws \PDOException on DB error; callers wrap in try/catch.
 */
function _ipam_install_key_announce_write(PDO $db, string $key, string $value): void
{
    // MySQL's UNIQUE(tenant_id, key) does NOT enforce uniqueness on
    // rows with tenant_id = NULL (SQL standard: NULL != NULL in
    // uniqueness). Two concurrent writers can both see
    // `rowCount() === 0` and both INSERT, creating duplicate global
    // rows. Mirror ipam_setting_set()'s GET_LOCK serialisation
    // (SQLite + Postgres are immune via their partial-unique index
    // on (key) WHERE tenant_id IS NULL).
    $lockName = null;
    if (ipam_dialect()->driver_name() === 'mysql') {
        // 64-byte cap on MySQL lock names; hash to bound length.
        $lockName = 'ipam_setting:' . md5($key . ':__GLOBAL__');
        $lockStmt = $db->prepare("SELECT GET_LOCK(:n, 5)");
        $lockStmt->execute([':n' => $lockName]);
        // GET_LOCK returns 1 (acquired), 0 (timeout), or NULL (error). If
        // we proceed without holding it, the duplicate-NULL-tenant race
        // is back. Bail loudly — the caller's try/catch logs + swallows.
        if ((string)$lockStmt->fetchColumn() !== '1') {
            $lockName = null; // do not RELEASE_LOCK in finally
            throw new RuntimeException(
                '_ipam_install_key_announce_write: GET_LOCK timeout/error for ' . $key
            );
        }
    }
    try {
        $kc  = ipam_key_col();
        $upd = $db->prepare(
            "UPDATE settings SET value = :v WHERE tenant_id IS NULL AND {$kc} = :k"
        );
        $upd->execute([':v' => $value, ':k' => $key]);
        if ($upd->rowCount() === 0) {
            // MySQL's PDO rowCount() returns affected (changed) rows, not
            // matched. An UPDATE that sets the same value the row already
            // has reports 0 even though the row exists. Without this probe,
            // every re-write of the same value would fall through to INSERT
            // and accumulate duplicate NULL-tenant rows. The GET_LOCK above
            // already serialises concurrent writers on MySQL, so this SELECT
            // is race-free.
            $probe = $db->prepare(
                "SELECT 1 FROM settings WHERE tenant_id IS NULL AND {$kc} = :k"
            );
            $probe->execute([':k' => $key]);
            if ($probe->fetchColumn() !== false) {
                return;
            }
            $ins = $db->prepare(
                "INSERT INTO settings (tenant_id, {$kc}, value, updated_at) "
                . "VALUES (NULL, :k, :v, CURRENT_TIMESTAMP)"
            );
            $ins->execute([':k' => $key, ':v' => $value]);
        }
    } finally {
        // $lockName === null on non-MySQL drivers (no lock taken); when
        // it is set, we threw if GET_LOCK didn't return 1, so reaching
        // this point means we hold the lock and must release it.
        if ($lockName !== null) {
            $db->prepare("SELECT RELEASE_LOCK(:n)")->execute([':n' => $lockName]);
        }
    }
}

/**
 * v3.28.2 #1178 — record that an install-root secret (`app_secret` or
 * `bootstrap_key`) was just auto-generated on first use.
 *
 * Writes an `audit_log` row plus a one-shot KV flag
 * (`install_keys_announce.<key>` — written via the silent
 * `_ipam_install_key_announce_write()` helper so the only audit row is
 * the intentional `<key>_autogenerated` event). The admin UI consumes
 * the flag to render a dismissible banner. The banner gap is the real
 * v3.28.2 fix — historically `bootstrap_key` could appear silently and
 * operators only noticed when a `config.php` mishap broke encrypted
 * backups; now both auto-gen paths surface the event.
 *
 * Defensive contract: this helper MUST NOT throw. The auto-gen callers
 * (`ipam_app_secret()`, `ipam_bootstrap_key()`) run on the hot path of
 * any request that needs the secret; a failed audit insert or a missing
 * `settings` table during very early bootstrap must not crater the
 * user-facing request. Any error is logged and swallowed.
 */
function ipam_install_key_announce_record(string $key): void
{
    if ($key !== 'app_secret' && $key !== 'bootstrap_key') {
        return;
    }

    // The project's primary PDO is opened by init.php and exposed via
    // `global $db;`. The auto-gen callers (ipam_app_secret() /
    // ipam_bootstrap_key()) only invoke this helper on the one-shot
    // generation path, so missing the announcement here drops the audit
    // row + banner forever — there is no "next request" that re-records
    // the event. When `$db` is not yet a PDO (very early bootstrap, or a
    // CLI seed script that opens its own connection), fall back to a
    // fresh `ipam_db($config)` connection rather than silently no-op.
    global $db, $config;
    $conn = $db instanceof PDO ? $db : null;
    if ($conn === null && isset($config) && function_exists('ipam_db')) {
        try {
            $conn = ipam_db($config);
        } catch (\Throwable $e) {
            error_log('[ipam_install_key_announce_record] ' . $key . ': fallback ipam_db() failed: ' . $e->getMessage());
            $conn = null;
        }
    }
    if (!($conn instanceof PDO)) {
        return; // Truly no DB reachable — bootstrap is too early; nothing we can do.
    }

    // The two writes are independent — an audit_log INSERT failure must
    // NOT prevent the banner flag from being set, because the auto-gen
    // path only runs once. If we lose the flag, the operator never sees
    // the banner. Wrap each call in its own try/catch.
    try {
        audit(
            $conn,
            $key . '_autogenerated',
            'install_key',
            null,
            'Auto-generated on first use; review docs/internal/runbooks.md'
        );
    } catch (\Throwable $e) {
        error_log('[ipam_install_key_announce_record] audit ' . $key . ': ' . $e->getMessage());
    }
    try {
        _ipam_install_key_announce_write($conn, 'install_keys_announce.' . $key, '1');
    } catch (\Throwable $e) {
        error_log('[ipam_install_key_announce_record] flag ' . $key . ': ' . $e->getMessage());
    }
}

/* ---------------- Settings (v2.6.0) ---------------- */

/* The settings registry, codec, accessors, per-request cache and the ADR-001 */
/* logical-type dispatch layer moved to lib/settings.php in v3.30.0 (ADR-004   */
/* Phase 5 Task 5.2b, sub of #907; #915). ipam_config_stale_keys() stays here  */
/* — it is a config concern, not a setting.                                   */

/**
 * v3.0.0: detect non-bootstrap keys still present in config.php after the
 * stub migration. Returns a list of key names that should be removed.
 *
 * @param array<string, mixed> $config
 * @return list<string>
 */
function ipam_config_stale_keys(array $config): array
{
    $bootstrap = [
        'db_driver', 'db_dsn', 'db_user', 'db_pass', 'db_path',
        'session_name', 'session_cookie_path', 'force_https',
        'proxy_trust', 'base_url', 'bootstrap_admin',
        'recovery_mode', 'demo_mode',
        // v3.6.0 security-sensitive keys — must remain in config.php because
        // they are needed before or during the DB open / session start
        // sequence. See docs/configuration.md. These are top-level array keys
        // in config.php; nested members (e.g. session.absolute_lifetime_minutes)
        // live underneath them and are reached via $config['session'][...].
        'app_secret', 'session', 'auth', 'api',
        // v3.24.0 — IPAMBKP3 stored-mode backup encryption key. Lives in
        // config.php (not the DB) for the same reason as app_secret: a key
        // stored inside the data it protects defeats the security model.
        'backup_vault_key',
        // v3.26.0 (#1098) — the vault bootstrap_key. Auto-generated into
        // config.php by ipam_bootstrap_key() and required at runtime to
        // unwrap the backup_vault_key envelope stored in the settings table.
        // Must never be flagged stale: removing it breaks every IPAMBKP3
        // stored-mode backup on the install. See lib/vault.php.
        'bootstrap_key',
    ];
    $stale = [];
    foreach (array_keys($config) as $key) {
        if (!in_array($key, $bootstrap, true)) {
            $stale[] = to_str($key);
        }
    }
    return $stale;
}

/* ---------------- History ---------------- */

/**
 * @param array<string, mixed>|null $before
 * @param array<string, mixed>|null $after
 */
function history_log_address(PDO $db, string $action, int $subnetId, string $ip, ?int $addressId, ?array $before, ?array $after): void
{
    $u = current_user();
    $st = $db->prepare("
        INSERT INTO address_history
          (address_id, subnet_id, ip, action, user_id, username, client_ip, user_agent, before_json, after_json)
        VALUES
          (:aid, :sid, :ip, :ac, :uid, :un, :cip, :ua, :bj, :aj)
    ");
    $st->execute([
        ':aid' => $addressId,
        ':sid' => $subnetId,
        ':ip'  => $ip,
        ':ac'  => $action,
        ':uid' => $u['id'] ?: null,
        ':un'  => $u['username'] ?: null,
        ':cip' => client_ip() ?: null,
        ':ua'  => to_str($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ':bj'  => $before ? json_encode($before, JSON_UNESCAPED_SLASHES) : null,
        ':aj'  => $after ? json_encode($after, JSON_UNESCAPED_SLASHES) : null,
    ]);
}


/**
 * v3.29.0 #897 — Centralised cache-buster query value for asset URLs.
 *
 * Returns the value to embed after `?v=` in static asset URLs. With no
 * argument, returns the bare IPAM_VERSION — the right buster for
 * favicons / vendored open-props (where the file content only changes
 * when IPAM_VERSION changes). With a path relative to the Simple-PHP-IPAM/
 * directory, appends the file's mtime so in-version edits to that file
 * invalidate the browser cache without requiring an IPAM_VERSION bump.
 *
 * Returns the RAW value — callers `e()` it where it lands in HTML.
 * `IPAM_VERSION` is always a numeric string in practice but escaping at
 * the call site keeps the contract uniform with the rest of page_header()'s
 * defensive HTML emission.
 *
 * Pre-v3.29.0 this logic was duplicated between `page_header()` (lib.php)
 * and `demo_gate.php`'s head block; both call sites now route through
 * here so a future cache-buster change happens in one place.
 *
 * @param string $relPath Path relative to `__DIR__` (the Simple-PHP-IPAM/
 *                        directory). Empty string returns the version
 *                        only.
 */
function ipam_asset_buster(string $relPath = ''): string
{
    if (!defined('IPAM_VERSION')) {
        require_once __DIR__ . '/version.php';
    }
    $av = (string) IPAM_VERSION;
    if ($relPath === '') {
        return $av;
    }
    $mtime = (int) @filemtime(__DIR__ . '/' . ltrim($relPath, '/'));
    return $mtime > 0 ? $av . '.' . $mtime : $av;
}

/**
 * @deprecated v3.0.0 — ipam_config_sync removed; config.php is now a bootstrap stub.
 * @return array<string, array{default: mixed, comment: string}>
 */
function ipam_config_defaults(): array
{
    return [
        'db_path' => [
            'default' => null, // path-dependent, skip auto-append
            'comment' => '',
        ],
        'session_name' => ['default' => null, 'comment' => ''],
        'base_url'     => [
            'default' => null,
            'comment' => "Canonical HTTPS base URL (e.g. 'https://ipam.example.com'). "
                       . "Used for the HTTP→HTTPS redirect. If null, falls back to HTTP_HOST.",
        ],
        'proxy_trust'  => ['default' => null, 'comment' => ''],
        'app_name' => [
            'default' => 'Simple PHP IPAM',
            'comment' => "Application display name shown in the browser tab, nav bar, and login page. Default: 'Simple PHP IPAM'.",
        ],
        'timezone' => [
            'default' => 'UTC',
            'comment' => "Timezone for displaying timestamps in the UI. Use a PHP timezone identifier, "
                       . "e.g. 'America/Toronto', 'Europe/London', 'UTC'. "
                       . "All timestamps are stored in UTC; this setting converts them for display only.",
        ],
        'bootstrap_admin' => ['default' => null, 'comment' => ''],
        'session_idle_seconds' => ['default' => null, 'comment' => ''],
        'login_max_attempts'   => ['default' => null, 'comment' => ''],
        'login_lockout_seconds'=> ['default' => null, 'comment' => ''],
        'api_max_attempts' => [
            'default' => 20,
            'comment' => 'Max failed API key attempts per IP before lockout.',
        ],
        'api_lockout_seconds' => [
            'default' => 300,
            'comment' => 'Duration (seconds) of API key lockout after too many failed attempts.',
        ],
        'api_bulk_limit' => [
            'default' => 500,
            'comment' => 'Maximum number of records per bulk API write request (POST ?resource=addresses&bulk=1).',
        ],
        'recaptcha_enterprise' => [
            'default' => [
                'enabled'          => false,
                'project_id'       => '',
                'api_key'          => '',
                'expected_action'  => 'login',
                'score_threshold'  => 0.5,
            ],
            'comment' => "reCAPTCHA Enterprise (v3). Set login_protection.method = 'recaptcha' and enable this block to use the Enterprise API for backend verification. project_id: GCP project ID. api_key: server-side API key. expected_action: action name from widget. score_threshold: 0.0–1.0 (default 0.5).",
        ],
        'import_csv_max_mb'    => ['default' => null, 'comment' => ''],
        // v2.9.2: auto-populate import_sql_max_mb on upgrade. The key was
        // missing from the registry, so ipam_config_sync() silently skipped
        // it on upgrades from older releases, and db_tools.php's unguarded
        // read produced an E_WARNING cascade when the key was absent.
        'import_sql_max_mb' => [
            'default' => 200,
            'comment' => 'Maximum SQL import file size (MB). App-level soft cap '
                . 'enforced by db_tools.php. If you raise it above .htaccess '
                . 'post_max_size / upload_max_filesize, raise those values too.',
        ],
        'tmp_cleanup_ttl_seconds' => ['default' => null, 'comment' => ''],
        'audit_log_retention_days' => [
            'default' => 0,
            'comment' => 'Audit log retention (days). Entries older than this are pruned during housekeeping. 0 = keep forever.',
        ],
        'address_history_retention_days' => [
            'default' => 0,
            'comment' => 'Address history retention (days). Entries older than this are pruned during housekeeping. 0 = keep forever.',
        ],
        'housekeeping' => ['default' => null, 'comment' => ''],
        'utilization_warn'     => ['default' => null, 'comment' => ''],
        'utilization_critical' => ['default' => null, 'comment' => ''],
        'update_check' => [
            'default' => [
                'enabled'           => true,
                'ttl_seconds'       => 86400,
                'notify_prerelease' => false,
            ],
            'comment' => 'Update check: fetches releases from GitHub and shows a banner when a newer version is available.',
        ],
        'backup' => [
            'default' => [
                'enabled'   => false,
                'frequency' => 'daily',
                'retention' => 7,
                'dir'       => '',
            ],
            'comment' => "Automatic database backups. frequency: 'daily' | 'weekly'. retention: keep last N backups.",
        ],
        'oidc' => [
            'default' => [
                'enabled'                  => false,
                'display_name'             => 'SSO',
                'client_id'                => '',
                'client_secret'            => '',
                'discovery_url'            => '',
                'redirect_uri'             => '',
                'scopes'                   => 'openid email profile',
                'auto_link'                => false,
                'auto_provision'           => false,
                'default_role'             => 'readonly',
                'disable_local_login'      => false,
                'hide_emergency_link'      => false,
                'disable_emergency_bypass' => false,
            ],
            'comment' => 'OIDC SSO configuration. See docs/oidc.md for full details.',
        ],
        'password_policy' => [
            'default' => [
                'min_length'            => 12,
                'require_uppercase'     => false,
                'require_lowercase'     => false,
                'require_number'        => false,
                'require_symbol'        => false,
                'max_password_age_days' => 0,
            ],
            'comment' => "Password complexity and rotation policy. min_length: minimum chars. require_*: enforce character classes. max_password_age_days: 0 = never expires.",
        ],
        'login_protection' => [
            'default' => [
                'method'      => null,
                'site_key'    => '',
                'secret_key'  => '',
                'min_seconds' => 3,
                'version'     => 2,
            ],
            'comment' => "Login form bot protection. method: null | 'honeypot' | 'time_check' | 'turnstile' | 'hcaptcha' | 'recaptcha' | 'friendly_captcha'. site_key/secret_key required for widget methods.",
        ],
        'demo_mode' => [
            'default' => [
                'enabled'    => false,
                'gate'       => null,
                'site_key'   => '',
                'secret_key' => '',
            ],
            'comment' => "Demo mode: only demo/demo can log in; data resets nightly. gate: optional pre-login bot challenge (null | 'honeypot' | 'turnstile' | 'hcaptcha' | 'recaptcha' | 'friendly_captcha').",
        ],
    ];
}

/**
 * Format a PHP value as clean source code with array [] syntax.
 */
function ipam_php_export(mixed $val, int $indent = 1): string
{
    if (is_null($val))    return 'null';
    if (is_bool($val))    return $val ? 'true' : 'false';
    if (is_int($val))     return (string)$val;
    if (is_float($val))   return rtrim(number_format($val, 10, '.', ''), '0') ?: '0.0';
    if (is_string($val))  return "'" . addcslashes($val, "'\\") . "'";

    if (is_array($val)) {
        if (count($val) === 0) return '[]';
        $pad = str_repeat('    ', $indent);
        $outerPad = str_repeat('    ', $indent - 1);
        $isList = array_keys($val) === range(0, count($val) - 1);
        $out = "[\n";
        foreach ($val as $k => $v) {
            $keyStr = $isList ? '' : "'" . addcslashes((string)$k, "'\\") . "' => ";
            $out .= $pad . $keyStr . ipam_php_export($v, $indent + 1) . ",\n";
        }
        $out .= $outerPad . ']';
        return $out;
    }

    return var_export($val, true);
}

/**
 * Check config.php for missing top-level keys and missing sub-keys within
 * existing nested blocks, and append them with their defaults.
 *
 * Returns list of key names added (top-level as 'key', nested as 'key.subkey').
 * Only keys whose default is not null are auto-appended.
 * The 'bootstrap_admin' block is never deep-merged (admin sets it intentionally).
 */
/**
 * @param array<string, mixed> $loaded
 * @return list<string>
 */
function ipam_config_sync(string $configPath, array $loaded): array
{
    $defaults = ipam_config_defaults();
    $added    = [];

    foreach ($defaults as $key => $meta) {
        if ($meta['default'] === null) continue;

        if (!array_key_exists($key, $loaded)) {
            // --- Top-level key missing: append whole block ---
            $content = @file_get_contents($configPath);
            if ($content === false) break;
            if (!preg_match('/\n\];\s*$/', $content)) break;

            $comment  = to_str($meta['comment']);
            $valuePhp = ipam_php_export($meta['default'], 2);
            $block    = '';
            if ($comment !== '') $block .= "\n    // " . $comment;
            $block .= "\n    '" . addcslashes($key, "'\\") . "' => " . $valuePhp . ",\n";

            $content = preg_replace('/\n\];\s*$/', $block . "\n];", $content);
            if ($content === null) break;
            if (@file_put_contents($configPath, $content) !== false) {
                $added[] = $key;
            } else {
                $_SESSION['config_unwritable'] = true; // #119: surface in page_header()
                break;
            }
        } elseif ($key !== 'bootstrap_admin'
               && is_array($meta['default'])
               && is_array($loaded[$key])) {
            // --- Nested block exists: deep-merge missing sub-keys ---
            $missingSubKeys = array_diff_key($meta['default'], $loaded[$key]);
            foreach ($missingSubKeys as $subKey => $subDefault) {
                $content = @file_get_contents($configPath);
                if ($content === false) break 2;

                $escapedKey = preg_quote($key, '/');
                $subValuePhp = ipam_php_export($subDefault, 3);
                $newLine = "\n        '" . addcslashes((string)$subKey, "'\\") . "' => " . $subValuePhp . ",";

                // Match '    'key' => [  ...content...  \n    ],'
                $pattern = '/(\n    \'' . $escapedKey . '\'\s*=>\s*\[)(.*?)(\n    \],)/s';
                if (!preg_match($pattern, $content)) break;

                $content = preg_replace_callback($pattern, static function (array $m) use ($newLine): string {
                    return $m[1] . $m[2] . $newLine . $m[3];
                }, $content);

                if ($content === null) break;
                if (@file_put_contents($configPath, $content) !== false) {
                    $added[] = $key . '.' . $subKey;
                } else {
                    $_SESSION['config_unwritable'] = true; // #119: surface in page_header()
                    break 2;
                }
            }
        }
    }

    return $added;
}

/**
 * Validate loaded config values and return a list of human-readable warning strings.
 * Logs each warning to the PHP error log as well.
 *
 * @param IpamConfig $config
 * @return list<string>
 */
function ipam_validate_config(array $config): array
{
    $warnings = [];
    $checks = [
        ['security.session_idle_seconds',  60, '>=', 'session_idle_seconds'],
        ['security.login_max_attempts',     1, '>=', 'login_max_attempts'],
        ['security.login_lockout_seconds',  1, '>=', 'login_lockout_seconds'],
        ['housekeeping.audit_log_retention_days', 0, '>=', 'audit_log_retention_days'],
    ];
    foreach ($checks as [$key, $min, $op, $label]) {
        $val = to_int(ipam_setting($key));
        if ($val < $min) {
            $warnings[] = "{$label} is {$val}; minimum is {$min}.";
        }
    }
    foreach ($warnings as $w) {
        error_log("Simple PHP IPAM config warning: {$w}");
    }
    return $warnings;
}

/* ---------------- Housekeeping ---------------- */

function housekeeping_state_path(): string
{
    return __DIR__ . '/data/housekeeping.json';
}

/**
 * @phpstan-impure
 * @param IpamConfig $config
 */
function housekeeping_should_run(array $config): bool
{
    if (!(bool)ipam_setting('housekeeping.enabled')) return false;

    $interval = to_int(ipam_setting('housekeeping.interval_seconds'));
    if ($interval < 3600) $interval = 3600;

    $path = housekeeping_state_path();
    if (!is_file($path)) return true;

    $last = @filemtime($path);
    if ($last === false) return true;

    return (time() - $last) >= $interval;
}

function housekeeping_mark_ran(): void
{
    $path = housekeeping_state_path();
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);

    @file_put_contents($path, json_encode(['last_run' => time()], JSON_PRETTY_PRINT));
    @chmod($path, 0600);
}

function prune_address_history(PDO $db, int $retentionDays): int
{
    if ($retentionDays <= 0) return 0;
    $cutoff = date('Y-m-d H:i:s', (int)strtotime("-{$retentionDays} days"));
    $st = $db->prepare("DELETE FROM address_history WHERE created_at < :cutoff");
    $st->execute([':cutoff' => $cutoff]);
    return $st->rowCount();
}

/**
 * Send email utilization alerts for subnets that have crossed warn/crit thresholds.
 * Deduplicates sends using the alert_state table (max one alert per subnet+level per 24 h).
 * Auto-clears alert_state rows when utilization drops back below the threshold.
 *
 * @param IpamConfig $config Unused since v2.7.0 — thresholds and the
 *                           recipient now flow through ipam_setting().
 */
/**
 * #443: resolve the configured `alert.recipient_user_ids` list to a list of
 * current email addresses. Inactive users and users whose email has been
 * cleared since the recipient was selected drop out automatically — no need
 * to re-save the settings page when staffing changes.
 *
 * @return list<string>
 */
function ipam_resolve_alert_recipients(PDO $db): array
{
    $ids = ipam_setting('alert.recipient_user_ids', []);
    return ipam_resolve_recipients_for_user_ids($db, is_array($ids) ? $ids : []);
}

/**
 * v3.25.0 #1078: backup-specific recipient resolution. If the
 * `backup.notify_recipient_user_ids` override is set (non-empty array),
 * resolve against that list; otherwise fall back to the global alert
 * recipients. Combine with any extra free-form addresses from
 * `backup.notify_recipient_email_extra` (CSV, no user account required).
 *
 * @return list<string>
 */
function ipam_resolve_backup_notify_recipients(PDO $db): array
{
    $override = ipam_setting('backup.notify_recipient_user_ids', []);
    $useOverride = is_array($override) && $override !== [];
    $emails = $useOverride
        ? ipam_resolve_recipients_for_user_ids($db, $override)
        : ipam_resolve_alert_recipients($db);

    $extraRaw = ipam_setting('backup.notify_recipient_email_extra', '');
    if (is_string($extraRaw) && trim($extraRaw) !== '') {
        foreach (explode(',', $extraRaw) as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $e;
            }
        }
    }
    return array_values(array_unique($emails));
}

/**
 * Resolve a list of user IDs to email addresses. Skips inactive users and
 * users without an email. Accepts a mixed array (JSON-decoded settings
 * value) and coerces each entry to int; non-positive entries are dropped.
 *
 * @param  array<int|string, mixed> $rawIds
 * @return list<string>
 */
function ipam_resolve_recipients_for_user_ids(PDO $db, array $rawIds): array
{
    if ($rawIds === []) return [];
    $intIds = array_values(array_unique(array_map(fn($v) => (int)to_str($v), $rawIds)));
    $intIds = array_values(array_filter($intIds, fn($i) => $i > 0));
    if ($intIds === []) return [];

    $placeholders = implode(',', array_fill(0, count($intIds), '?'));
    $st = $db->prepare(
        "SELECT email FROM users
         WHERE id IN ($placeholders)
           AND is_active = 1
           AND email IS NOT NULL
           AND email != ''
         ORDER BY id"
    );
    $st->execute($intIds);
    /** @var list<string> $out */
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $email) {
        $email = trim(to_str($email));
        if ($email !== '') $out[] = $email;
    }
    return $out;
}

/**
 * Send an email via SMTP (PHPMailer) or native mail(), based on smtp.enabled setting.
 *
 * @return array{success: bool, error: ?string, transport: string}
 */
function ipam_send_mail(string $to, string $subject, string $bodyText, string $bodyHtml = '', ?string $transportOverride = null): array
{
    // v3.25.0 #1078: optional transport override lets the backup notify
    // dispatcher force SMTP regardless of the global smtp.enabled setting.
    // Accepted values: 'smtp' (force SMTP) or null (use global).
    $smtpEnabled = $transportOverride === 'smtp'
        ? true
        : (bool) ipam_setting('smtp.enabled');

    if ($smtpEnabled) {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            // Primary: vendor/ bundled inside the web root (release tarball installs).
            // Fallback: vendor/ at the project root, one level above the web root
            // (dev/Docker setups where vendor is mounted outside the web root).
            $autoload = __DIR__ . '/vendor/autoload.php';
            if (!file_exists($autoload)) {
                $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            }
            if (file_exists($autoload)) require_once $autoload;
        }
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return ['success' => false, 'error' => 'PHPMailer is not available (check vendor/autoload.php)', 'transport' => 'smtp'];
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host    = to_str(ipam_setting('smtp.host'));
            $mail->Port    = to_int(ipam_setting('smtp.port'));
            $mail->Timeout = to_int(ipam_setting('smtp.timeout_seconds'));

            $enc = to_str(ipam_setting('smtp.encryption'));
            if ($enc === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($enc === 'starttls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $authUser = to_str(ipam_setting('smtp.auth_user'));
            $authPass = to_str(ipam_setting('smtp.auth_pass'));
            if ($authUser !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $authUser;
                $mail->Password = $authPass;
            }

            if (!(bool) ipam_setting('smtp.verify_peer')) {
                $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            }

            $fromAddr = to_str(ipam_setting('smtp.from_address'));
            $fromName = to_str(ipam_setting('smtp.from_name'));
            if ($fromAddr !== '') {
                $mail->setFrom($fromAddr, $fromName ?: $fromAddr);
            }

            // PHPMailer defaults CharSet to ISO-8859-1, which mojibakes any
            // UTF-8 in subject/body (em-dash, smart quotes, accented chars).
            // Encoding stays at the PHPMailer default (8bit) — UTF-8 text bodies
            // travel fine on modern SMTP and stay human-readable in test parsers.
            $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;

            $mail->addAddress($to);
            $mail->Subject = $subject;
            if ($bodyHtml !== '') {
                $mail->isHTML(true);
                $mail->Body    = $bodyHtml;
                $mail->AltBody = $bodyText;
            } else {
                $mail->Body = $bodyText;
            }

            $mail->send();
            return ['success' => true, 'error' => null, 'transport' => 'smtp'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage(), 'transport' => 'smtp'];
        }
    }

    // Native mail() fallback
    $safeSubject = preg_replace('/[\r\n]/', '', $subject) ?? '';
    $safeTo      = preg_replace('/[\r\n]/', '', $to) ?? '';
    error_clear_last();
    $ok = @mail($safeTo, $safeSubject, $bodyText); // nosemgrep
    if (!$ok) {
        $last = error_get_last();
        $err  = $last !== null ? $last['message'] : 'mail() returned false';
        return ['success' => false, 'error' => $err, 'transport' => 'mail'];
    }
    return ['success' => true, 'error' => null, 'transport' => 'mail'];
}

/** @param IpamConfig $config */
function check_utilization_alerts(PDO $db, array $config): void
{
    unset($config);
    $recipients = ipam_resolve_alert_recipients($db);
    if ($recipients === []) return;

    $warnPct = to_int(ipam_setting('alert.util_warn_pct'));
    $critPct = to_int(ipam_setting('alert.util_crit_pct'));
    $appName = to_str(ipam_setting('branding.site_name'));

    // Use the shared utilization function that excludes infrastructure IPs (#566)
    $utilData = ipv4_unassigned_summary($db);

    // Build rows from utilData for the alert loop (#457: skip subnets with alerts_enabled = 0)
    $subnetRows = ($db->query("SELECT id, cidr, prefix FROM subnets WHERE ip_version = 4 AND alerts_enabled = 1")
        ?: throw new \RuntimeException('Query failed'))->fetchAll();
    $rows = [];
    foreach ($subnetRows as $sr) {
        $sid = to_int($sr['id']);
        $u = $utilData[$sid] ?? null;
        $rows[] = [
            'id'     => $sr['id'],
            'cidr'   => $sr['cidr'],
            'prefix' => $sr['prefix'],
            'assigned' => $u !== null ? $u['assigned_assignable'] : 0,
        ];
    }

    // Load existing alert state
    $stateRows = ($db->query("SELECT subnet_id, level, last_alerted_at FROM alert_state")
        ?: throw new \RuntimeException('Query failed'))->fetchAll();
    $alertState = [];
    foreach ($stateRows as $sr) {
        $alertState[to_int($sr['subnet_id'])][to_str($sr['level'])] = to_str($sr['last_alerted_at']);
    }

    $cooldownSeconds = 86400; // 24 hours

    foreach ($rows as $row) {
        $sid    = to_int($row['id']);
        $cidr   = to_str($row['cidr']);
        $prefix = to_int($row['prefix']);
        $assigned = to_int($row['assigned']);

        $assignable = ipv4_assignable_count($prefix);
        if ($assignable <= 0) continue;

        $pct = (int)round($assigned / $assignable * 100);

        // Determine active levels
        $levels = [];
        if ($pct >= $critPct) $levels[] = 'crit';
        elseif ($pct >= $warnPct) $levels[] = 'warn';

        // Auto-clear stale alert_state rows when below thresholds
        foreach (['warn', 'crit'] as $lvl) {
            if (isset($alertState[$sid][$lvl]) && !in_array($lvl, $levels, true)) {
                $db->prepare("DELETE FROM alert_state WHERE subnet_id = :sid AND level = :lvl")
                   ->execute([':sid' => $sid, ':lvl' => $lvl]);
                unset($alertState[$sid][$lvl]);
            }
        }

        foreach ($levels as $lvl) {
            $lastAlerted = $alertState[$sid][$lvl] ?? null;
            if ($lastAlerted !== null) {
                $lastTs = strtotime($lastAlerted);
                if ($lastTs !== false && (time() - $lastTs) < $cooldownSeconds) continue;
            }

            $levelLabel = $lvl === 'crit' ? 'CRITICAL' : 'WARNING';
            $subject    = "[{$appName}] {$levelLabel}: subnet {$cidr} at {$pct}% utilization";
            $body       = "{$levelLabel}: Subnet {$cidr} has reached {$pct}% utilization.\n"
                        . "Assigned: {$assigned} / Assignable: {$assignable}\n\n"
                        . "-- {$appName}";
            $safeSubject = preg_replace('/[\r\n]/', '', $subject) ?? '';

            // #443: loop-per-recipient delivery. Best-effort: a bad address
            // for one recipient does not block delivery to the others, and
            // each send produces its own audit row so failures are debuggable.
            $anyDelivered = false;
            foreach ($recipients as $recipient) {
                $safeEmail   = preg_replace('/[\r\n]/', '', $recipient) ?? '';
                $maskedEmail = preg_replace('/(^.).*(@.*$)/', '$1***$2', $safeEmail) ?? '';
                $result = ipam_send_mail($safeEmail, $safeSubject, $body);
                if ($result['success']) {
                    $anyDelivered = true;
                    audit($db, 'alert.send', 'subnet', $sid, "level={$lvl} pct={$pct} email={$maskedEmail} transport={$result['transport']}");
                } else {
                    audit($db, 'mail.send_failed', 'subnet', $sid, "level={$lvl} pct={$pct} email={$maskedEmail} transport={$result['transport']} error=" . json_encode($result['error']));
                }
            }

            // Only consume the 24h cooldown when at least one delivery succeeded;
            // a fully-failed send must be retried on the next housekeeping run.
            if ($anyDelivered) {
                $now = date('Y-m-d H:i:s');
                // #379: route through the dialect's upsert() so v2.10.0+ can
                // swap to ON DUPLICATE KEY UPDATE / ON CONFLICT DO UPDATE
                // without touching this call site.
                $alertUpsert = ipam_dialect()->upsert('alert_state', ['subnet_id', 'level'], ['last_alerted_at']);
                $db->prepare(
                    "INSERT INTO alert_state (subnet_id, level, last_alerted_at)
                     VALUES (:sid, :lvl, :now)
                     $alertUpsert"
                )->execute([':sid' => $sid, ':lvl' => $lvl, ':now' => $now]);
                $alertState[$sid][$lvl] = $now;
            }
        }
    }
}

/**
 * Run utilization alert check if the alert interval has elapsed.
 * Uses a dedicated state file so it can fire more frequently than main housekeeping.
 *
 * @param IpamConfig $config Unused since v2.7.0 — kept for signature stability.
 */
function alerts_check_if_due(array $config, PDO $db): void
{
    if (ipam_resolve_alert_recipients($db) === []) return;

    $interval = to_int(ipam_setting('alert.interval_seconds'));
    if ($interval < 60) $interval = 60;

    $statePath = __DIR__ . '/data/alerts_last_run.txt';
    if (is_file($statePath)) {
        $last = (int)@file_get_contents($statePath);
        if ((time() - $last) < $interval) return;
    }

    check_utilization_alerts($db, $config);

    @file_put_contents($statePath, (string)time());
    @chmod($statePath, 0600);
}

/** @param IpamConfig $config */
function run_housekeeping_if_due(array $config, ?PDO $db = null): void
{
    if (!housekeeping_should_run($config)) return;

    $lockPath = __DIR__ . '/data/housekeeping.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) return;

    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return;
    }

    try {
        if (!housekeeping_should_run($config)) return;

        $ttl = to_int(ipam_setting('housekeeping.tmp_cleanup_ttl_seconds'));
        if ($ttl < 3600) $ttl = 3600;

        cleanup_tmp_import_files($ttl);
        cleanup_tmp_import_plans($ttl);

        if ($db !== null) {
            $retentionDays = to_int(ipam_setting('housekeeping.audit_log_retention_days'));
            if ($retentionDays > 0) {
                prune_audit_log($db, $retentionDays);
            }
            $histRetention = to_int(ipam_setting('housekeeping.address_history_retention_days'));
            if ($histRetention > 0) {
                prune_address_history($db, $histRetention);
            }
            prune_rate_limit_dampener($db);
            capture_utilization_snapshot($db);
        }

        housekeeping_mark_ran();
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/**
 * Capture a utilization snapshot for every IPv4 subnet (/8–/32).
 * Called from run_housekeeping_if_due() when housekeeping fires.
 * Returns the number of rows inserted.
 */
function capture_utilization_snapshot(PDO $db): int
{
    $utilData = ipv4_unassigned_summary($db);
    $st = $db->prepare("SELECT id, prefix FROM subnets WHERE ip_version = 4 AND prefix BETWEEN 8 AND 32");
    $st->execute();
    /** @var list<array<string, mixed>> $subnets */
    $subnets = $st->fetchAll();

    $today = gmdate('Y-m-d');
    $now   = $today . ' ' . gmdate('H:i:s');
    // Build set of subnet IDs already snapped today to avoid duplicate daily rows.
    $doneStmt = $db->prepare(
        "SELECT DISTINCT subnet_id FROM utilization_snapshots WHERE snapped_at >= :day"
    );
    $doneStmt->execute([':day' => $today . ' 00:00:00']);
    $alreadyDone = array_flip(array_column($doneStmt->fetchAll(), 'subnet_id'));
    $ins = $db->prepare(
        "INSERT INTO utilization_snapshots (subnet_id, snapped_at, used_count, free_count, total_hosts)
         VALUES (:sid, :ts, :used, :free, :total)"
    );
    $count = 0;
    foreach ($subnets as $row) {
        $sid = to_int($row['id']);
        if (isset($alreadyDone[$sid])) continue;
        $u = $utilData[$sid] ?? null;
        if ($u === null) continue;
        $prefix = to_int($row['prefix']);
        $total  = ipv4_assignable_count($prefix);
        if ($total <= 0) continue;
        $used = to_int($u['assigned_assignable']);
        $ins->execute([
            ':sid'   => $sid,
            ':ts'    => $now,
            ':used'  => $used,
            ':free'  => max(0, $total - $used),
            ':total' => $total,
        ]);
        $count++;
    }

    // Prune old snapshots
    $retention = to_int(ipam_setting('housekeeping.snapshot_retention_days'));
    if ($retention > 0) {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retention * 86400);
        $db->prepare("DELETE FROM utilization_snapshots WHERE snapped_at < :cutoff")
           ->execute([':cutoff' => $cutoff]);
    }

    return $count;
}

/**
 * Demo nightly reset — runs independently of the housekeeping schedule.
 * Called directly from init.php, wrapped in try-catch so a seed failure
 * never crashes the page load (which would leave $_SESSION without a
 * CSRF token and cause "Bad CSRF token" on all subsequent requests).
 */
function run_demo_reset_if_due(PDO $db): void
{
    if (!demo_mode_enabled() || !demo_require_reset()) return;

    $marker = __DIR__ . '/data/demo_last_reset.txt';
    try {
        demo_reset_db($db);
        file_put_contents($marker, (string)time());
    } catch (Throwable $e) {
        // Log but do not re-throw — page must continue so CSRF is initialised
        error_log('Simple PHP IPAM demo reset failed: ' . $e->getMessage());
    }
}

/* ---------------- Database Backups ---------------- */

/**
 * Legacy SQLite/MySQL/PostgreSQL retention prune (#828 / B-P1-15).
 *
 * The legacy v3.7 backup runner globs filesystem dumps named
 * `ipam-YYYY-MM-DD-HHMMSS.{sqlite,sql}` and keeps the most recent N. The
 * pre-#828 implementation used `rsort()` on filenames, which only matches
 * creation order while the timestamp prefix is intact. Operator-renamed
 * files, alternate timestamp formats, or files copied in from another
 * install would silently become "oldest" by lex rule and get pruned first.
 *
 * Sort by filemtime descending so the most recent N (by actual disk
 * timestamp) are kept regardless of filename. Best-effort — files that
 * vanish between glob() and stat() simply sort to the end.
 *
 * Hard-removal of this whole legacy runner is tracked separately in
 * v3.26.0 #1059. Until then, this helper is the correct retention policy.
 */
function ipam_legacy_retention_prune_by_mtime(string $glob, int $retention): void
{
    $files = glob($glob);
    if (!is_array($files) || $files === []) return;

    // Build [path => mtime] then sort path desc by mtime.
    $stamped = [];
    foreach ($files as $f) {
        $m = @filemtime($f);
        $stamped[$f] = $m === false ? 0 : $m;
    }
    arsort($stamped, SORT_NUMERIC);
    $ordered = array_keys($stamped);

    foreach (array_slice($ordered, $retention) as $old) {
        @unlink($old); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $old is a glob() result, not user input
    }
}



/**
 * Write a MySQL [client] defaults-extra-file containing the password and return
 * its absolute path. The file is created with 0600 permissions because it stores
 * the database credential at rest in a temp directory shared with other users
 * on the host (#820). Caller MUST `unlink()` the returned path on every exit
 * path — typically wrapped in try/finally around the proc_open invocation that
 * consumes `--defaults-extra-file=<path>`.
 *
 * The file's `[client]` section is consumed by mysql/mysqldump when passed as
 * the FIRST argument (must come before all other CLI args).
 */

function ipam_backup_write_mysql_defaults_file(string $pass): string
{
    $path = tempnam(sys_get_temp_dir(), 'ipam_dbcred_');
    if ($path === false) {
        throw new RuntimeException('Failed to allocate temp file for MySQL credential');
    }
    // tempnam creates with 0600 on most unixes, but tighten explicitly before
    // writing so the secret is never observable through a wider mode.
    @chmod($path, 0600);
    $contents = "[client]\npassword=" . $pass . "\n";
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        throw new RuntimeException('Failed to write MySQL credential file');
    }
    @chmod($path, 0600);
    return $path;
}

/**
 * Write a Postgres pgpass-format file containing the password and return its
 * absolute path. The file is created with 0600 permissions (libpq REQUIRES
 * mode <= 0600 or it ignores the file). Caller MUST `unlink()` the returned
 * path on every exit path.
 *
 * Format is libpq's documented pgpass syntax: `host:port:database:user:password`
 * with `*` as a wildcard for everything but the password (#820). The path is
 * passed to psql/pg_dump via the `PGPASSFILE` env var, which is the documented
 * Postgres pattern for non-interactive scripts — the env var carries the path,
 * not the secret itself.
 */
function ipam_backup_write_pgpass_file(string $pass): string
{
    $path = tempnam(sys_get_temp_dir(), 'ipam_dbcred_');
    if ($path === false) {
        throw new RuntimeException('Failed to allocate temp file for Postgres credential');
    }
    @chmod($path, 0600);
    // Escape ':' and '\' inside the password per libpq's pgpass rules.
    $escaped = str_replace(['\\', ':'], ['\\\\', '\\:'], $pass);
    $contents = "*:*:*:*:" . $escaped . "\n";
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
        throw new RuntimeException('Failed to write Postgres credential file');
    }
    @chmod($path, 0600);
    return $path;
}

/**
 * @param list<string>            $cmd
 * @param array<string,string>    $env
 * @param string                  $errorOut Captured stderr/diagnostic on failure (v3.22.2).
 *                                          Populated even when the function returns false so
 *                                          callers can surface the cause in a backup-run row's
 *                                          `error_message` instead of leaving operators to
 *                                          grep PHP error_log.
 */
function backup_run_dump(array $cmd, array $env, string $destPath, int $timeoutSecs = 120, string &$errorOut = ''): bool
{
    $errorOut = '';
    $pipes = [];
    $bin = $cmd[0] ?? 'dump';
    // $cmd is built from admin config values only (never user input); array-form
    // proc_open bypasses the shell entirely so no injection is possible.
    //
    // PHP 8+ raises Error (not returns false) when an internal function appears
    // in disable_functions, so we catch Throwable and route both failure modes
    // through the same diagnostic surface — otherwise a hardened php.ini with
    // proc_open disabled would skip $errorOut population entirely and leave
    // operators with an empty backup_runs.error_message.
    try {
        $proc = proc_open($cmd, // nosemgrep
            [
                0 => ['pipe', 'r'],
                1 => ['file', $destPath, 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env
        );
    } catch (Throwable $e) {
        $errorOut = 'proc_open threw ' . get_class($e) . ' starting ' . $bin . ': ' . $e->getMessage();
        error_log('backup_run_dump: ' . $errorOut);
        @unlink($destPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
        return false;
    }
    if (!is_resource($proc)) {
        // Most likely cause: dump binary not on $PATH in the SAPI's restricted
        // environment. Surface the binary name so an operator looking at
        // backup_runs.error_message immediately sees "mysqldump not executable"
        // rather than an empty diagnostic.
        $errorOut = 'proc_open failed to start ' . $bin . ' (not on PATH or disabled)';
        error_log('backup_run_dump: ' . $errorOut);
        @unlink($destPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
        return false;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[2], false);

    // Poll for process completion with a hard deadline so a hung mysqldump/pg_dump
    // never blocks a web request thread indefinitely. Non-blocking stderr reads
    // prevent the process from stalling when the pipe buffer fills up.
    $stderr    = '';
    $finalExit = -1;
    $deadline  = time() + $timeoutSecs;
    while (true) {
        $chunk = fread($pipes[2], 4096);
        if ($chunk !== false && $chunk !== '') $stderr .= $chunk;
        $status = proc_get_status($proc);
        if (!$status['running']) {
            // Capture exitcode here BEFORE proc_close — on PHP builds with
            // --enable-sigchild, proc_close returns -1 unconditionally because
            // the SIGCHLD has already been reaped. proc_get_status returns the
            // real code on glibc builds and -1 on sigchild-enabled builds; we
            // treat -1 as "unreliable, fall back to file inspection".
            $finalExit = $status['exitcode'];
            break;
        }
        if (time() > $deadline) {
            proc_terminate($proc);
            fclose($pipes[2]);
            proc_close($proc);
            @unlink($destPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
            $errorOut = 'killed after ' . $timeoutSecs . 's timeout';
            error_log('backup_run_dump: ' . $errorOut);
            return false;
        }
        usleep(100000); // 100ms polling interval
    }
    while (!feof($pipes[2])) {
        $chunk = fread($pipes[2], 4096);
        if ($chunk !== false && $chunk !== '') $stderr .= $chunk;
    }
    fclose($pipes[2]);
    proc_close($proc);

    // Exit-code interpretation:
    //   $finalExit > 0  → definite failure (tool reported error code).
    //   $finalExit == 0 → definite success.
    //   $finalExit == -1 → unreliable (sigchild build); use file-size as
    //                       fallback. mysqldump and pg_dump never produce
    //                       output on auth/connection failure, so a non-empty
    //                       dest file is a strong success signal.
    if ($finalExit > 0) {
        @unlink($destPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
        $errorOut = 'exit=' . $finalExit . ': ' . trim($stderr);
        error_log('backup_run_dump failed (' . $errorOut . ')');
        return false;
    }
    $size = @filesize($destPath);
    if ($size === false || $size === 0) {
        @unlink($destPath); // nosemgrep: php.lang.security.unlink-use.unlink-use
        $errorOut = 'exit=' . $finalExit . ', empty output: ' . trim($stderr);
        error_log('backup_run_dump failed (' . $errorOut . ')');
        return false;
    }
    @chmod($destPath, 0600);
    return true;
}

// v3.26.0 (#1059): the legacy v3.7 backup runner and its helpers were
// removed:
//   ipam_legacy_backup_migrate_if_due()  — init-time conversion helper
//   backup_dir() / backup_state_path()    — single-directory accessors
//   backup_interval_seconds() / backup_is_due() — schedule readers
//   backup_runs_insert_cli()              — CLI-runner row inserter
//   run_db_backup_if_due()                — SQLite/MySQL/Postgres runner
//   backup_info()                         — admin-page status accessor
// Backups are now driven by ipam_backup_run_destination() (further below)
// iterating every active row in backup_destinations + backup_schedules.


// ── Backup encryption constants (Phase 2 / #694) ────────────────────────────
const BACKUP_MAGIC   = 'IPAMBKP1';  // 8-byte magic + version tag
const BACKUP_IV_LEN  = 12;          // AES-256-GCM recommended IV length (12 random
                                    // bytes per RFC 5116; #838 B-P2-46 clarifying note)
const BACKUP_TAG_LEN = 16;          // GCM authentication tag length (16 bytes,
                                    // SP 800-38D Table 1; #838 B-P2-46)
const BACKUP_MAGIC_V2     = 'IPAMBKP2';  // 8-byte streaming format magic
const BACKUP_SALT_LEN     = 16;          // HKDF salt length (v2)
const BACKUP_CTR_IV_LEN   = 16;          // AES-256-CTR initial counter block
const BACKUP_HMAC_LEN     = 32;          // HMAC-SHA256 tag length
const BACKUP_STREAM_CHUNK = 65536;       // 64 KiB; counter step = 4096 blocks per chunk

// ── v3.24.0 IPAMBKP3 / IPAMBKU1 constants (#836) ─────────────────────────────
//
// IPAMBKP3 supersedes IPAMBKP2: it derives keys from `backup_vault_key`
// (stored mode) or an operator-supplied passphrase via Argon2id (transitory
// mode), separating backup-at-rest protection from app_secret. IPAMBKU1 is
// an integrity-only wrapper for trusted-local destinations.
//
// Header layout (big-endian on multi-byte fields):
//
//   IPAMBKP3 (8) | mode (1) | argon_t (4) | argon_m_kib (4) | argon_p (1)
//                | reserved (2 zero) | argon_salt (16) | hkdf_salt (16)
//                | ctr_iv (16) | ciphertext (N) | hmac (32)
//   ── header size: 68 bytes
//
// IPAMBKU1 layout: magic (8) | sha256 (32) | plaintext (N).
//
// Argon2id defaults (OWASP 2024 minimum for password hashing): t=3, m=64 MiB,
// p=1. Header-embedded so callers can tune per install without breaking
// existing backups.
const BACKUP_MAGIC_V3       = 'IPAMBKP3';
const BACKUP_MAGIC_UNENC    = 'IPAMBKU1';
const BACKUP_V3_MODE_STORED      = 1;
const BACKUP_V3_MODE_TRANSITORY  = 2;
const BACKUP_V3_HEADER_LEN  = 68;
const BACKUP_V3_RESERVED_LEN = 2;
const BACKUP_ARGON2_SALT_LEN     = 16;
const BACKUP_ARGON2_TIME_DEFAULT       = 3;
const BACKUP_ARGON2_MEMORY_KIB_DEFAULT = 65536;  // 64 MiB
const BACKUP_ARGON2_PARALLELISM_DEFAULT = 1;
const BACKUP_VAULT_KEY_LEN = 32;

/**
 * Assert that PHP's CSPRNG is functional. Cheap, idempotent (caches first
 * success). Called from every encrypt entry point; addresses #838 B-P1-35.
 *
 * @throws RuntimeException if random_bytes is unavailable or fails.
 */
function ipam_assert_random_bytes_available(): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    if (!function_exists('random_bytes')) {
        throw new RuntimeException('random_bytes() not available; PHP build is missing CSPRNG');
    }
    try {
        $probe = random_bytes(1);
    } catch (Throwable $e) {
        throw new RuntimeException('CSPRNG probe failed: ' . $e->getMessage(), 0, $e);
    }
    if (strlen($probe) !== 1) {
        throw new RuntimeException('CSPRNG probe returned wrong length');
    }
    $checked = true;
}

/**
 * Derive an Argon2id tag from a passphrase. Used by IPAMBKP3 transitory mode
 * (#836). Wraps libsodium's `sodium_crypto_pwhash` — RFC 9106 / Argon2id v1.3.
 *
 * libsodium constraint: parallelism is fixed at 1; we assert this so the
 * header-recorded value cannot drift from the value used to compute the tag.
 *
 * @param string $passphrase   Operator-typed secret. Must be non-empty.
 * @param string $salt         Per-file random salt (BACKUP_ARGON2_SALT_LEN bytes).
 * @param int    $time         Argon2 t parameter (≥ 1).
 * @param int    $memoryKib    Argon2 m parameter in KiB (≥ 8 × parallelism).
 * @param int    $parallelism  Argon2 p parameter — must be 1 with libsodium.
 * @param int    $outLen       Output tag length in bytes (≥ 16).
 * @throws RuntimeException on any parameter violation or KDF failure.
 */
function ipam_argon2id_derive(
    string $passphrase,
    string $salt,
    int $time,
    int $memoryKib,
    int $parallelism,
    int $outLen
): string {
    if ($passphrase === '') {
        throw new RuntimeException('ipam_argon2id_derive: passphrase must be non-empty');
    }
    if (strlen($salt) !== BACKUP_ARGON2_SALT_LEN) {
        throw new RuntimeException(
            'ipam_argon2id_derive: salt must be exactly ' . BACKUP_ARGON2_SALT_LEN . ' bytes'
        );
    }
    if ($time < 1) {
        throw new RuntimeException('ipam_argon2id_derive: time must be >= 1');
    }
    if ($parallelism !== 1) {
        throw new RuntimeException(
            'ipam_argon2id_derive: parallelism must be 1 (libsodium constraint); ' .
            'tracked for future tuning when sodium exposes the parameter'
        );
    }
    if ($memoryKib < 8 * $parallelism) {
        throw new RuntimeException('ipam_argon2id_derive: memoryKib must be >= 8 * parallelism');
    }
    if ($outLen < 16) {
        throw new RuntimeException('ipam_argon2id_derive: outLen must be >= 16');
    }
    if (!function_exists('sodium_crypto_pwhash')) {
        throw new RuntimeException(
            'ipam_argon2id_derive: libsodium pwhash not available; PHP must be built with --with-sodium'
        );
    }
    try {
        $hash = sodium_crypto_pwhash(
            $outLen,
            $passphrase,
            $salt,
            $time,
            $memoryKib * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    } catch (SodiumException $e) {
        throw new RuntimeException('ipam_argon2id_derive: Argon2id derivation failed: ' . $e->getMessage(), 0, $e);
    }
    if (strlen($hash) !== $outLen) {
        throw new RuntimeException('ipam_argon2id_derive: Argon2id derivation produced unexpected length');
    }
    return $hash;
}


/**
 * Read-only accessor for the IPAMBKP3 vault key. Returns the 32 raw
 * bytes if config.php holds a well-formed value, NULL otherwise.
 *
 * Read-only accessor: never generates, never writes config.php, never
 * throws on absent / malformed values.
 * Intended for the decrypt path — autogen during a restore would mask
 * the real failure (the key that ENCRYPTED the backup is gone), and we
 * want to surface that as a clear error rather than silently produce a
 * fresh-and-useless key.
 */
function ipam_backup_vault_key_get_raw(): ?string
{
    /** @var array<string,mixed> $config */
    global $config;

    // v3.26.0 (#1098 + CR #1100): DB-resident wrapped envelope is the
    // primary store. Falls back to the legacy config field for one
    // release for downgrade safety, but ONLY when the DB read or unwrap
    // shows the envelope is genuinely absent or malformed — never on a
    // transient PDO failure or a real unwrap error. A blanket Throwable
    // catch would let a flapping settings query silently switch
    // runtime key selection back to a stale config value, producing
    // backups that stop round-tripping once the DB path recovers.
    //
    // - ipam_setting() schema-missing: caught internally and returns
    //   the registry default ('' here). We treat empty-string as
    //   "DB has no row, fall back to config" — this is the legitimate
    //   pre-migration upgrade window.
    // - ipam_setting() throwing: an unexpected condition (e.g. the
    //   helper itself isn't loaded). Treated as "no DB row" so we
    //   don't crash the decrypt path; the legacy fallback applies.
    // - ipam_vault_unwrap() returning a wrong-length plaintext: bail
    //   to legacy. The wrap function never produces a wrong length, so
    //   this would imply ciphertext truncation — surface via error_log.
    // - ipam_vault_unwrap() throwing RuntimeException with the explicit
    //   "authentication failed" message: the envelope is malformed or
    //   the bootstrap_key changed. Surface and bail to legacy (the
    //   operator may have a saved key in config.php to recover with).
    // - Any OTHER throw (PDO transient errors propagated through
    //   ipam_setting bubbling up as Throwable, sodium extension
    //   missing, etc.): rethrow so the caller surfaces a real failure
    //   instead of silently using stale config.
    $envelope = '';
    if (function_exists('ipam_setting')) {
        $envRaw = ipam_setting('backup_vault_key', '');
        $envelope = is_string($envRaw) ? $envRaw : '';
    }
    if ($envelope !== '' && function_exists('ipam_vault_unwrap') && function_exists('ipam_bootstrap_key')) {
        try {
            $raw = ipam_vault_unwrap($envelope, ipam_bootstrap_key());
        } catch (\RuntimeException $e) {
            // RuntimeException is the documented unwrap failure mode
            // (bad envelope / wrong bootstrap key / tampered cipher).
            // Surface and fall through so a saved config-side key can
            // still recover existing backups.
            error_log('backup_vault_key DB unwrap failed: ' . $e->getMessage());
            $raw = '';
        }
        if ($raw !== '' && strlen($raw) === BACKUP_VAULT_KEY_LEN) {
            if (isset($config['backup_vault_key']) && $config['backup_vault_key'] !== '') {
                error_log(
                    'backup_vault_key present in BOTH config.php and the settings table; '
                    . 'using the DB row. Remove the config.php field once you have confirmed '
                    . 'backups continue to round-trip.'
                );
            }
            return $raw;
        }
    }

    $b64 = $config['backup_vault_key'] ?? null;
    if (!is_string($b64) || $b64 === '') {
        return null;
    }
    $raw = base64_decode($b64, true);
    if (!is_string($raw) || strlen($raw) !== BACKUP_VAULT_KEY_LEN) {
        return null;
    }
    return $raw;
}

/**
 * Atomic in-place rewrite of a config.php-style PHP file to set
 * `'$key' => '$valueB64'`. Used by ipam_bootstrap_key() (lib/vault.php)
 * to seed the bootstrap key on first use, and available for any future
 * autogen scenarios.
 *
 * Behaviour:
 *   - Existing single-quoted/double-quoted line for $key → replaced.
 *   - Key absent → injected immediately before the file's last "];".
 *   - Atomic: writes adjacent tempfile, then rename(). Preserves file
 *     mode best-effort.
 *
 * Assumes $valueB64 contains only base64 characters ([A-Za-z0-9+/=]); no
 * additional escaping is performed. Refuses to operate if the value
 * contains a single quote.
 *
 * @throws RuntimeException on any I/O, parse, or atomicity failure.
 */
function ipam_config_inject_or_replace_key(string $configPath, string $key, string $valueB64): void
{
    if (!is_file($configPath)) {
        throw new RuntimeException('config file not found: ' . $configPath);
    }
    if (!is_writable($configPath)) {
        throw new RuntimeException('config file is not writable: ' . $configPath);
    }
    if (str_contains($valueB64, "'") || str_contains($valueB64, "\n") || str_contains($valueB64, "\r")) {
        throw new RuntimeException('refusing to inject value containing quotes or newlines');
    }
    $contents = @file_get_contents($configPath);
    if ($contents === false) {
        throw new RuntimeException('failed to read config file');
    }

    // Match a single-line scalar string literal for $key in either quoting
    // style, anchored to the start of a line (with optional leading
    // whitespace) so commented-out config lines like
    //   //  'backup_vault_key' => '',
    // do NOT match. The line-anchor relies on the first non-whitespace
    // character being a quote; comment prefixes (//, #, *) start with a
    // non-quote character and are naturally rejected. Restricted to no
    // embedded quotes or newlines — sufficient for base64 values which
    // use only [A-Za-z0-9+/=].
    $pattern = sprintf(
        '/^[ \t]*([\'"])%s\1\s*=>\s*([\'"])[A-Za-z0-9+\/=]*\2/m',
        preg_quote($key, '/')
    );
    $replacement = sprintf("'%s' => '%s'", $key, $valueB64);

    $count = 0;
    if (preg_match($pattern, $contents) === 1) {
        $new = preg_replace($pattern, $replacement, $contents, 1, $count);
        if ($new === null || $count !== 1) {
            throw new RuntimeException('regex replacement failed for key: ' . $key);
        }
    } else {
        // Inject just before the LAST occurrence of "];" — the close of the
        // top-level return array. Search RTL so a nested array close cannot
        // win. Newline before injection keeps formatting parseable.
        $lastClosePos = strrpos($contents, '];');
        if ($lastClosePos === false) {
            throw new RuntimeException('config file has no closing "];" — refusing to inject');
        }
        $injection = "    '" . $key . "' => '" . $valueB64 . "',\n";
        $new = substr($contents, 0, $lastClosePos) . $injection . substr($contents, $lastClosePos);
    }

    // Atomic write: tempfile in the same directory + rename().
    $dir = dirname($configPath);
    $tmp = @tempnam($dir, '.config.tmp.');
    if ($tmp === false) {
        throw new RuntimeException('cannot create tempfile next to config file');
    }
    try {
        $written = @file_put_contents($tmp, $new);
        if ($written !== strlen($new)) {
            throw new RuntimeException('short write to tempfile');
        }
        $perms = @fileperms($configPath);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }
        if (!@rename($tmp, $configPath)) {
            throw new RuntimeException('rename of tempfile to config file failed');
        }
        $tmp = null; // ownership transferred
    } finally {
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmp from tempnam(), no user input
        }
    }

    // Best-effort: invalidate opcache so the next require sees the new value.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($configPath, true);
    }
}

/**
 * Derive a fixed-length key from a master secret using HKDF-SHA-256.
 *
 * Thin wrapper around PHP's native hash_hkdf() (PHP 7.1.2+). Keeping this as
 * a named function makes call-sites self-documenting and lets tests verify the
 * RFC 5869 Test Case 1 vector directly. The $salt parameter is optional and
 * defaults to the all-zeros salt that HKDF specifies when salt is omitted.
 *
 * @param string $ikm    Input keying material (e.g. $config['app_secret'])
 * @param string $info   Context string distinguishing key purposes
 * @param int    $length Output length in bytes (1–255 × hash-length for SHA-256)
 * @param string $salt   Optional salt; pass '' to use HKDF's default zero-salt
 */
function ipam_hkdf_sha256(string $ikm, string $info, int $length, string $salt = ''): string
{
    if ($length <= 0) {
        throw new RuntimeException('ipam_hkdf_sha256: length must be positive');
    }
    return hash_hkdf('sha256', $ikm, $length, $info, $salt);
}

/**
 * Encrypt a backup payload with AES-256-GCM.
 *
 * The output format is:
 *   BACKUP_MAGIC (8 bytes) | IV (12 bytes) | GCM tag (16 bytes) | ciphertext
 *
 * The AES key is derived from $appSecret via HKDF-SHA-256 with a fixed
 * purpose string, so a compromise of the ciphertext alone does not expose
 * $appSecret or any other derived key.
 *
 * @throws RuntimeException on openssl failure (should never happen on a
 *         supported PHP build, but we must not silently return bad data)
 */
function backup_encrypt(string $plain, string $appSecret): string
{
    ipam_assert_random_bytes_available(); // #838 B-P1-35
    if ($appSecret === '') {
        throw new RuntimeException('backup encryption requires app_secret to be set in config.php');
    }
    $key = ipam_hkdf_sha256($appSecret, 'ipam-v3:backup', 32);
    // IPAMBKP1 random-IV note (#838 B-P2-5): GCM with a 96-bit random IV is
    // safe for the small message counts a single IPAM install produces with
    // one app_secret-derived key (NIST SP 800-38D allows ~2^32 messages
    // before the birthday bound becomes non-negligible). Operators with
    // legacy IPAMBKP1 archives are advised in docs/upgrading.md to
    // re-encrypt as IPAMBKP3 over time, but no immediate action is needed.
    $iv  = random_bytes(BACKUP_IV_LEN);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', BACKUP_TAG_LEN);
    if ($ct === false) {
        throw new RuntimeException('backup encryption failed');
    }
    return BACKUP_MAGIC . $iv . $tag . $ct;
}

/**
 * Stream-encrypt $srcPath into $dstPath using the v2 (IPAMBKP2) format:
 * AES-256-CTR + HMAC-SHA256 in encrypt-then-MAC mode.
 *
 * Memory bound is BACKUP_STREAM_CHUNK + bookkeeping, regardless of input size.
 *
 * @throws RuntimeException on I/O, openssl, or appSecret failure.
 */
function backup_encrypt_stream(string $srcPath, string $dstPath, string $appSecret): void
{
    ipam_assert_random_bytes_available(); // #838 B-P1-35
    if ($appSecret === '') {
        throw new RuntimeException('backup encryption requires app_secret to be set in config.php');
    }
    $salt = random_bytes(BACKUP_SALT_LEN);
    $iv   = random_bytes(BACKUP_CTR_IV_LEN);
    // Per-file salt is passed via HKDF's salt parameter (used in HKDF-Extract)
    // for proper RFC 5869 strengthening. The fixed 'ipam-v3:backup-v2' goes in
    // info for domain separation across purposes.
    $keys = ipam_hkdf_sha256($appSecret, 'ipam-v3:backup-v2', 64, $salt);
    $encKey = substr($keys, 0, 32);
    $macKey = substr($keys, 32, 32);

    $in = @fopen($srcPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('backup_encrypt_stream: cannot open source');
    }
    $out = @fopen($dstPath, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('backup_encrypt_stream: cannot open dest');
    }

    try {
        $header = BACKUP_MAGIC_V2 . $salt . $iv;
        if (fwrite($out, $header) !== strlen($header)) {
            throw new RuntimeException('backup_encrypt_stream: short write on header');
        }
        $hmacCtx = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($hmacCtx, $header);

        $counter = $iv;
        while (!feof($in)) {
            $chunk = fread($in, BACKUP_STREAM_CHUNK);
            if ($chunk === false) {
                throw new RuntimeException('backup_encrypt_stream: read failed');
            }
            if ($chunk === '') {
                continue;
            }
            $ct = openssl_encrypt($chunk, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $counter);
            if ($ct === false) {
                throw new RuntimeException('backup_encrypt_stream: openssl_encrypt failed');
            }
            if (fwrite($out, $ct) !== strlen($ct)) {
                throw new RuntimeException('backup_encrypt_stream: short write on ciphertext');
            }
            hash_update($hmacCtx, $ct);
            $counter = ipam_backup_advance_ctr($counter, intdiv(strlen($chunk) + 15, 16));
        }

        $tag = hash_final($hmacCtx, true);
        if (fwrite($out, $tag) !== BACKUP_HMAC_LEN) {
            throw new RuntimeException('backup_encrypt_stream: short write on hmac');
        }
    } finally {
        fclose($in);
        fclose($out);
    }
}

/**
 * Stream-decrypt $srcPath (v2 IPAMBKP2 format) into $dstPath.
 *
 * Single-pass: decrypts each ciphertext chunk into a temporary file in the
 * same directory as $dstPath while accumulating an HMAC-SHA256 over the
 * exact bytes that were just decrypted (encrypt-then-MAC verification of
 * the same buffer). The temp file is atomically renamed to $dstPath only
 * after the trailing HMAC tag matches; on any failure path the temp is
 * unlinked, so a failed verification leaves no plaintext file behind at
 * $dstPath. Avoids the TOCTOU window of a two-pass design where the
 * source file could change between verify and decrypt.
 *
 * @throws RuntimeException on bad magic, truncation, openssl failure, or HMAC mismatch.
 */
function backup_decrypt_stream(string $srcPath, string $dstPath, string $appSecret): void
{
    if ($appSecret === '') {
        throw new RuntimeException('backup decryption requires app_secret to be set in config.php');
    }
    $size = @filesize($srcPath);
    if ($size === false) {
        throw new RuntimeException('backup_decrypt_stream: cannot stat source');
    }
    $headerLen = strlen(BACKUP_MAGIC_V2) + BACKUP_SALT_LEN + BACKUP_CTR_IV_LEN;
    $minLen = $headerLen + BACKUP_HMAC_LEN;
    if ($size < $minLen) {
        throw new RuntimeException('backup_decrypt_stream: file too short');
    }
    $ctLen = $size - $minLen;

    $in = @fopen($srcPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('backup_decrypt_stream: cannot open source');
    }

    $tmpPath = $dstPath . '.decrypting.' . bin2hex(random_bytes(4));
    $out = null;
    try {
        $header = (string) fread($in, $headerLen);
        if (strlen($header) !== $headerLen) {
            throw new RuntimeException('backup_decrypt_stream: short header read');
        }
        if (substr($header, 0, 8) !== BACKUP_MAGIC_V2) {
            throw new RuntimeException('backup_decrypt_stream: bad magic');
        }
        $salt = substr($header, 8, BACKUP_SALT_LEN);
        $iv   = substr($header, 8 + BACKUP_SALT_LEN, BACKUP_CTR_IV_LEN);

        // Per-file salt is passed via HKDF's salt parameter (HKDF-Extract).
        $keys = ipam_hkdf_sha256($appSecret, 'ipam-v3:backup-v2', 64, $salt);
        $encKey = substr($keys, 0, 32);
        $macKey = substr($keys, 32, 32);

        $out = @fopen($tmpPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('backup_decrypt_stream: cannot open tmp dst');
        }

        // Single-pass decrypt + HMAC over the exact bytes processed.
        $hmacCtx = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($hmacCtx, $header);
        $counter = $iv;
        $remaining = $ctLen;
        while ($remaining > 0) {
            $want = (int) min(BACKUP_STREAM_CHUNK, $remaining);
            $buf = fread($in, $want);
            if ($buf === false || strlen($buf) !== $want) {
                throw new RuntimeException('backup_decrypt_stream: short ciphertext read');
            }
            hash_update($hmacCtx, $buf);
            $pt = openssl_decrypt($buf, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $counter);
            if ($pt === false) {
                throw new RuntimeException('backup_decrypt_stream: openssl_decrypt failed');
            }
            if (fwrite($out, $pt) !== strlen($pt)) {
                throw new RuntimeException('backup_decrypt_stream: short write to tmp');
            }
            $counter = ipam_backup_advance_ctr($counter, intdiv(strlen($buf) + 15, 16));
            $remaining -= $want;
        }
        $observed = (string) fread($in, BACKUP_HMAC_LEN);
        if (strlen($observed) !== BACKUP_HMAC_LEN) {
            throw new RuntimeException('backup_decrypt_stream: short hmac read');
        }
        $expected = hash_final($hmacCtx, true);
        if (!hash_equals($expected, $observed)) {
            throw new RuntimeException('backup_decrypt_stream: hmac mismatch');
        }

        // HMAC verified — close out, atomically rename tmp into place.
        fclose($out);
        $out = null;
        if (!@rename($tmpPath, $dstPath)) {
            throw new RuntimeException('backup_decrypt_stream: rename to dst failed');
        }
    } catch (Throwable $e) {
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_file($tmpPath)) {
            @unlink($tmpPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpPath built from $dstPath + random suffix; no user input
        }
        throw $e;
    } finally {
        fclose($in);
    }
}

// ── IPAMBKP3 (v3.24.0) — three-mode streaming codec ─────────────────────────
//
// Header packing/unpacking helpers. The wire format is:
//
//   offset  size  field
//        0     8  magic ('IPAMBKP3')
//        8     1  mode (1=STORED, 2=TRANSITORY)
//        9     4  argon_time         (BE uint32)
//       13     4  argon_memory_kib   (BE uint32)
//       17     1  argon_parallelism  (uint8)
//       18     2  reserved (zero)
//       20    16  argon_salt
//       36    16  hkdf_salt
//       52    16  ctr_iv
//   total: 68 bytes — see BACKUP_V3_HEADER_LEN.
//
// Argon parameters are header-embedded so an install can tune memory/time
// later without breaking already-encrypted backups. Bounds checks below
// keep the parameter space sane and the verify cost bounded.

const BACKUP_V3_ARGON_TIME_MAX     = 16;
const BACKUP_V3_ARGON_MEM_KIB_MAX  = 1048576; // 1 GiB
const BACKUP_V3_INFO_STORED        = 'ipam-backup-v3:stored:enc-mac';
const BACKUP_V3_INFO_TRANSITORY    = 'ipam-backup-v3:transitory:enc-mac';

function ipam_backup_v3_pack_header(
    int $mode,
    int $argonTime,
    int $argonMemKib,
    int $argonPar,
    string $argonSalt,
    string $hkdfSalt,
    string $ctrIv
): string {
    if (strlen($argonSalt) !== BACKUP_ARGON2_SALT_LEN
        || strlen($hkdfSalt) !== BACKUP_SALT_LEN
        || strlen($ctrIv) !== BACKUP_CTR_IV_LEN) {
        throw new RuntimeException('ipam_backup_v3_pack_header: salt/iv lengths invalid');
    }
    $hdr = BACKUP_MAGIC_V3
         . chr($mode)
         . pack('N', $argonTime)
         . pack('N', $argonMemKib)
         . chr($argonPar)
         . str_repeat("\x00", BACKUP_V3_RESERVED_LEN)
         . $argonSalt
         . $hkdfSalt
         . $ctrIv;
    if (strlen($hdr) !== BACKUP_V3_HEADER_LEN) {
        throw new RuntimeException('ipam_backup_v3_pack_header: assembled wrong length');
    }
    return $hdr;
}

/**
 * @return array{mode:int,argon_time:int,argon_mem_kib:int,argon_par:int,argon_salt:string,hkdf_salt:string,ctr_iv:string}
 */
function ipam_backup_v3_unpack_header(string $hdr): array
{
    if (strlen($hdr) !== BACKUP_V3_HEADER_LEN) {
        throw new RuntimeException('IPAMBKP3 header: wrong length');
    }
    if (substr($hdr, 0, 8) !== BACKUP_MAGIC_V3) {
        throw new RuntimeException('IPAMBKP3 header: bad magic');
    }
    /** @var array{1:int} $tu */
    $tu = unpack('N', substr($hdr, 9, 4));
    /** @var array{1:int} $mu */
    $mu = unpack('N', substr($hdr, 13, 4));
    return [
        'mode'          => ord($hdr[8]),
        'argon_time'    => $tu[1],
        'argon_mem_kib' => $mu[1],
        'argon_par'     => ord($hdr[17]),
        'argon_salt'    => substr($hdr, 20, BACKUP_ARGON2_SALT_LEN),
        'hkdf_salt'     => substr($hdr, 36, BACKUP_SALT_LEN),
        'ctr_iv'        => substr($hdr, 52, BACKUP_CTR_IV_LEN),
    ];
}

/**
 * Stream-encrypt $srcPath into $dstPath using the IPAMBKP3 format.
 *
 * Modes:
 *   STORED      — server-side `backup_vault_key` (32 raw bytes).
 *                 Passphrase ignored; argon_salt zero-filled.
 *   TRANSITORY  — operator passphrase; Argon2id derives the kdf input.
 *                 vaultKey ignored.
 *
 * Argon2id parameters fall back to BACKUP_ARGON2_*_DEFAULT when null.
 * Bounded by BACKUP_V3_ARGON_TIME_MAX / BACKUP_V3_ARGON_MEM_KIB_MAX.
 *
 * Memory-bounded streaming (BACKUP_STREAM_CHUNK + bookkeeping). HMAC-SHA256
 * is computed over (header || ciphertext) in encrypt-then-MAC order.
 *
 * @throws RuntimeException on bad parameters, I/O, openssl, or KDF failure.
 */
function backup_encrypt_stream_v3(
    string $srcPath,
    string $dstPath,
    int $mode,
    ?string $passphrase,
    ?string $vaultKey,
    ?int $argonTime = null,
    ?int $argonMemKib = null,
    ?int $argonParallelism = null
): void {
    ipam_assert_random_bytes_available();

    if ($mode !== BACKUP_V3_MODE_STORED && $mode !== BACKUP_V3_MODE_TRANSITORY) {
        throw new RuntimeException('backup_encrypt_stream_v3: invalid mode');
    }

    $aTime  = $argonTime        ?? BACKUP_ARGON2_TIME_DEFAULT;
    $aMem   = $argonMemKib      ?? BACKUP_ARGON2_MEMORY_KIB_DEFAULT;
    $aPar   = $argonParallelism ?? BACKUP_ARGON2_PARALLELISM_DEFAULT;
    if ($aTime < 1 || $aTime > BACKUP_V3_ARGON_TIME_MAX) {
        throw new RuntimeException('backup_encrypt_stream_v3: argon time out of bounds');
    }
    if ($aMem < 8 || $aMem > BACKUP_V3_ARGON_MEM_KIB_MAX) {
        throw new RuntimeException('backup_encrypt_stream_v3: argon memory out of bounds');
    }
    // Mirror the decrypt-time constraint: parallelism is fixed at 1 by the
    // libsodium pwhash API (see ipam_argon2id_derive). Reject anything
    // else here so a caller cannot produce an archive whose header records
    // a parallelism the decrypt path will refuse, leaving the archive
    // undecryptable. This is the same check the decrypt path enforces.
    if ($aPar !== 1) {
        throw new RuntimeException('backup_encrypt_stream_v3: argon parallelism must be 1 (libsodium constraint)');
    }

    if ($mode === BACKUP_V3_MODE_TRANSITORY) {
        if ($passphrase === null || $passphrase === '') {
            throw new RuntimeException('backup_encrypt_stream_v3: transitory mode requires passphrase');
        }
        $argonSalt = random_bytes(BACKUP_ARGON2_SALT_LEN);
        $kdfInput  = ipam_argon2id_derive($passphrase, $argonSalt, $aTime, $aMem, $aPar, 32);
        $info      = BACKUP_V3_INFO_TRANSITORY;
    } else {
        if ($vaultKey === null || strlen($vaultKey) !== BACKUP_VAULT_KEY_LEN) {
            throw new RuntimeException(
                'backup_encrypt_stream_v3: stored mode requires ' . BACKUP_VAULT_KEY_LEN . '-byte vaultKey'
            );
        }
        // Stored mode does not run Argon2id — argon_salt is zero-filled in
        // the header but the parameter fields still record the install's
        // current defaults so decryption can reuse them if the mode flag is
        // ever flipped during an upgrade migration.
        $argonSalt = str_repeat("\x00", BACKUP_ARGON2_SALT_LEN);
        $kdfInput  = $vaultKey;
        $info      = BACKUP_V3_INFO_STORED;
    }

    $hkdfSalt = random_bytes(BACKUP_SALT_LEN);
    $ctrIv    = random_bytes(BACKUP_CTR_IV_LEN);
    $keys     = ipam_hkdf_sha256($kdfInput, $info, 64, $hkdfSalt);
    $encKey   = substr($keys, 0, 32);
    $macKey   = substr($keys, 32, 32);

    $header = ipam_backup_v3_pack_header($mode, $aTime, $aMem, $aPar, $argonSalt, $hkdfSalt, $ctrIv);

    $in = @fopen($srcPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('backup_encrypt_stream_v3: cannot open source');
    }
    $out = @fopen($dstPath, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('backup_encrypt_stream_v3: cannot open dest');
    }

    try {
        if (fwrite($out, $header) !== strlen($header)) {
            throw new RuntimeException('backup_encrypt_stream_v3: short write on header');
        }
        $hmacCtx = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($hmacCtx, $header);

        $counter = $ctrIv;
        while (!feof($in)) {
            $chunk = fread($in, BACKUP_STREAM_CHUNK);
            if ($chunk === false) {
                throw new RuntimeException('backup_encrypt_stream_v3: read failed');
            }
            if ($chunk === '') {
                continue;
            }
            $ct = openssl_encrypt($chunk, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $counter);
            if ($ct === false) {
                throw new RuntimeException('backup_encrypt_stream_v3: openssl_encrypt failed');
            }
            if (fwrite($out, $ct) !== strlen($ct)) {
                throw new RuntimeException('backup_encrypt_stream_v3: short write on ciphertext');
            }
            hash_update($hmacCtx, $ct);
            $counter = ipam_backup_advance_ctr($counter, intdiv(strlen($chunk) + 15, 16));
        }

        $tag = hash_final($hmacCtx, true);
        if (fwrite($out, $tag) !== BACKUP_HMAC_LEN) {
            throw new RuntimeException('backup_encrypt_stream_v3: short write on hmac');
        }
    } finally {
        fclose($in);
        fclose($out);
    }
}

/**
 * Stream-decrypt an IPAMBKP3 file at $srcPath into $dstPath.
 *
 * Re-derives keys from header-embedded params + salts. Verifies HMAC
 * before atomically renaming the tempfile to $dstPath, so a failed
 * verification leaves no plaintext on disk.
 *
 * Constant-time HMAC compare per #838 B-P1-40: a verify-key wrap means
 * the success/fail paths each run a fixed number of HMACs.
 *
 * @throws RuntimeException on bad magic, truncation, openssl failure,
 *         missing key/passphrase, or HMAC mismatch.
 */
function backup_decrypt_stream_v3(
    string $srcPath,
    string $dstPath,
    ?string $passphrase,
    ?string $vaultKey
): void {
    ipam_assert_random_bytes_available();

    $size = @filesize($srcPath);
    if ($size === false) {
        throw new RuntimeException('backup_decrypt_stream_v3: cannot stat source');
    }
    $minLen = BACKUP_V3_HEADER_LEN + BACKUP_HMAC_LEN;
    if ($size < $minLen) {
        throw new RuntimeException('backup_decrypt_stream_v3: file too short');
    }
    $ctLen = $size - $minLen;

    $in = @fopen($srcPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('backup_decrypt_stream_v3: cannot open source');
    }

    $tmpPath = $dstPath . '.decrypting.' . bin2hex(random_bytes(4));
    $out = null;
    try {
        $hdrRaw = (string) fread($in, BACKUP_V3_HEADER_LEN);
        if (strlen($hdrRaw) !== BACKUP_V3_HEADER_LEN) {
            throw new RuntimeException('backup_decrypt_stream_v3: short header read');
        }
        $hdr = ipam_backup_v3_unpack_header($hdrRaw);

        $mode = $hdr['mode'];
        if ($mode !== BACKUP_V3_MODE_STORED && $mode !== BACKUP_V3_MODE_TRANSITORY) {
            throw new RuntimeException('backup_decrypt_stream_v3: unknown mode');
        }
        if ($hdr['argon_time'] < 1 || $hdr['argon_time'] > BACKUP_V3_ARGON_TIME_MAX) {
            throw new RuntimeException('backup_decrypt_stream_v3: argon time out of bounds');
        }
        if ($hdr['argon_mem_kib'] < 8 || $hdr['argon_mem_kib'] > BACKUP_V3_ARGON_MEM_KIB_MAX) {
            throw new RuntimeException('backup_decrypt_stream_v3: argon memory out of bounds');
        }
        if ($hdr['argon_par'] !== 1) {
            throw new RuntimeException('backup_decrypt_stream_v3: argon parallelism != 1');
        }

        if ($mode === BACKUP_V3_MODE_TRANSITORY) {
            if ($passphrase === null || $passphrase === '') {
                throw new RuntimeException('backup_decrypt_stream_v3: transitory mode requires passphrase');
            }
            $kdfInput = ipam_argon2id_derive(
                $passphrase,
                $hdr['argon_salt'],
                $hdr['argon_time'],
                $hdr['argon_mem_kib'],
                $hdr['argon_par'],
                32
            );
            $info = BACKUP_V3_INFO_TRANSITORY;
        } else {
            if ($vaultKey === null || strlen($vaultKey) !== BACKUP_VAULT_KEY_LEN) {
                throw new RuntimeException(
                    'backup_decrypt_stream_v3: stored mode requires ' . BACKUP_VAULT_KEY_LEN . '-byte vaultKey'
                );
            }
            $kdfInput = $vaultKey;
            $info     = BACKUP_V3_INFO_STORED;
        }

        $keys   = ipam_hkdf_sha256($kdfInput, $info, 64, $hdr['hkdf_salt']);
        $encKey = substr($keys, 0, 32);
        $macKey = substr($keys, 32, 32);

        $out = @fopen($tmpPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('backup_decrypt_stream_v3: cannot open tmp dst');
        }

        $hmacCtx = hash_init('sha256', HASH_HMAC, $macKey);
        hash_update($hmacCtx, $hdrRaw);
        $counter   = $hdr['ctr_iv'];
        $remaining = $ctLen;
        while ($remaining > 0) {
            $want = (int) min(BACKUP_STREAM_CHUNK, $remaining);
            $buf  = fread($in, $want);
            if ($buf === false || strlen($buf) !== $want) {
                throw new RuntimeException('backup_decrypt_stream_v3: short ciphertext read');
            }
            hash_update($hmacCtx, $buf);
            $pt = openssl_decrypt($buf, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $counter);
            if ($pt === false) {
                throw new RuntimeException('backup_decrypt_stream_v3: openssl_decrypt failed');
            }
            if (fwrite($out, $pt) !== strlen($pt)) {
                throw new RuntimeException('backup_decrypt_stream_v3: short write to tmp');
            }
            $counter    = ipam_backup_advance_ctr($counter, intdiv(strlen($buf) + 15, 16));
            $remaining -= $want;
        }
        $observed = (string) fread($in, BACKUP_HMAC_LEN);
        if (strlen($observed) !== BACKUP_HMAC_LEN) {
            throw new RuntimeException('backup_decrypt_stream_v3: short hmac read');
        }
        $expected = hash_final($hmacCtx, true);

        // #838 B-P1-40: double-HMAC compare so success/failure paths run the
        // same operations. The verify key is derived from macKey via a fixed
        // sub-purpose so it cannot be swapped for a different signing key.
        $verifyKey = ipam_hkdf_sha256($macKey, 'ipam-backup-v3:verify', 32);
        $expectedTag = hash_hmac('sha256', $expected, $verifyKey, true);
        $observedTag = hash_hmac('sha256', $observed, $verifyKey, true);
        if (!hash_equals($expectedTag, $observedTag)) {
            throw new RuntimeException('backup_decrypt_stream_v3: hmac mismatch');
        }

        fclose($out);
        $out = null;
        if (!@rename($tmpPath, $dstPath)) {
            throw new RuntimeException('backup_decrypt_stream_v3: rename to dst failed');
        }
    } catch (Throwable $e) {
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_file($tmpPath)) {
            @unlink($tmpPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpPath built from $dstPath + random suffix
        }
        throw $e;
    } finally {
        fclose($in);
    }
}

// ── IPAMBKU1 (v3.24.0) — integrity-only framing for trusted-local backups ────
//
// Wire format: magic(8 'IPAMBKU1') | sha256(32) | plaintext(N).
// No confidentiality — used only when the operator opts out of encryption
// for a destination they trust (e.g. full-disk-encrypted local volume).
// The SHA-256 catches accidental corruption / disk bit-rot.

const BACKUP_UNENC_HEADER_LEN = 8 + 32; // magic + sha256

/**
 * Stream-wrap a plaintext file with the IPAMBKU1 framing.
 *
 * The SHA-256 is computed incrementally during write so memory stays
 * bounded regardless of input size.
 *
 * @throws RuntimeException on I/O failure.
 */
function backup_unencrypted_wrap_stream(string $srcPath, string $dstPath): void
{
    $in = @fopen($srcPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('backup_unencrypted_wrap_stream: cannot open source');
    }
    $out = @fopen($dstPath, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException('backup_unencrypted_wrap_stream: cannot open dest');
    }
    try {
        // Reserve space for the 32-byte SHA-256 by writing zero bytes; we
        // patch this region after the body is fully streamed and hashed.
        $headerStub = BACKUP_MAGIC_UNENC . str_repeat("\x00", 32);
        if (fwrite($out, $headerStub) !== BACKUP_UNENC_HEADER_LEN) {
            throw new RuntimeException('backup_unencrypted_wrap_stream: short write on header');
        }
        $hashCtx = hash_init('sha256');
        while (!feof($in)) {
            $chunk = fread($in, BACKUP_STREAM_CHUNK);
            if ($chunk === false) {
                throw new RuntimeException('backup_unencrypted_wrap_stream: read failed');
            }
            if ($chunk === '') {
                continue;
            }
            hash_update($hashCtx, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('backup_unencrypted_wrap_stream: short write on body');
            }
        }
        $digest = hash_final($hashCtx, true);
        if (strlen($digest) !== 32) {
            throw new RuntimeException('backup_unencrypted_wrap_stream: digest wrong length');
        }
        // Seek back and patch the digest region.
        if (fseek($out, 8) !== 0) {
            throw new RuntimeException('backup_unencrypted_wrap_stream: seek to digest failed');
        }
        if (fwrite($out, $digest) !== 32) {
            throw new RuntimeException('backup_unencrypted_wrap_stream: short write on digest');
        }
    } finally {
        fclose($in);
        fclose($out);
    }
}

/**
 * Stream-unwrap an IPAMBKU1 file. Verifies the SHA-256 over the body
 * before atomically renaming the tempfile to $dstPath.
 *
 * @throws RuntimeException on bad magic, truncation, I/O failure, or
 *         hash mismatch.
 */
function backup_unencrypted_unwrap_stream(string $srcPath, string $dstPath): void
{
    ipam_assert_random_bytes_available();

    $size = @filesize($srcPath);
    if ($size === false) {
        throw new RuntimeException('backup_unencrypted_unwrap_stream: cannot stat source');
    }
    if ($size < BACKUP_UNENC_HEADER_LEN) {
        throw new RuntimeException('backup_unencrypted_unwrap_stream: file too short');
    }

    $in = @fopen($srcPath, 'rb');
    if ($in === false) {
        throw new RuntimeException('backup_unencrypted_unwrap_stream: cannot open source');
    }

    $tmpPath = $dstPath . '.unwrapping.' . bin2hex(random_bytes(4));
    $out = null;
    try {
        $hdr = (string) fread($in, BACKUP_UNENC_HEADER_LEN);
        if (strlen($hdr) !== BACKUP_UNENC_HEADER_LEN) {
            throw new RuntimeException('backup_unencrypted_unwrap_stream: short header read');
        }
        if (substr($hdr, 0, 8) !== BACKUP_MAGIC_UNENC) {
            throw new RuntimeException('backup_unencrypted_unwrap_stream: bad magic');
        }
        $expected = substr($hdr, 8, 32);

        $out = @fopen($tmpPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('backup_unencrypted_unwrap_stream: cannot open tmp dst');
        }
        $hashCtx = hash_init('sha256');
        $remaining = $size - BACKUP_UNENC_HEADER_LEN;
        while ($remaining > 0) {
            $want = (int) min(BACKUP_STREAM_CHUNK, $remaining);
            $buf  = fread($in, $want);
            if ($buf === false || strlen($buf) !== $want) {
                throw new RuntimeException('backup_unencrypted_unwrap_stream: short body read');
            }
            hash_update($hashCtx, $buf);
            if (fwrite($out, $buf) !== strlen($buf)) {
                throw new RuntimeException('backup_unencrypted_unwrap_stream: short write to tmp');
            }
            $remaining -= $want;
        }
        $observed = hash_final($hashCtx, true);
        if (!hash_equals($expected, $observed)) {
            throw new RuntimeException('backup_unencrypted_unwrap_stream: sha256 mismatch');
        }

        fclose($out);
        $out = null;
        if (!@rename($tmpPath, $dstPath)) {
            throw new RuntimeException('backup_unencrypted_unwrap_stream: rename to dst failed');
        }
    } catch (Throwable $e) {
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_file($tmpPath)) {
            @unlink($tmpPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpPath built from $dstPath + random suffix
        }
        throw $e;
    } finally {
        fclose($in);
    }
}

/**
 * Raised by backup_decrypt_to_path() when it recognises an IPAMBKP3 archive
 * but the caller did not supply the credential needed to decrypt it. The
 * `mode` property tells the caller which prompt to render (passphrase for
 * transitory mode, vault-key load for stored mode).
 */
class IpamBackupKeyRequiredException extends RuntimeException
{
    /** @var int BACKUP_V3_MODE_STORED or BACKUP_V3_MODE_TRANSITORY */
    public int $mode;

    public function __construct(int $mode, string $message)
    {
        parent::__construct($message);
        $this->mode = $mode;
    }
}

/**
 * Format-detecting decrypt-to-path. Peeks the 8-byte magic header and
 * dispatches to the matching codec.
 *
 *   IPAMBKP3 → backup_decrypt_stream_v3() (v3.24+; stored or transitory).
 *   IPAMBKU1 → backup_unencrypted_unwrap_stream() (v3.24+; integrity only).
 *   IPAMBKP2 → backup_decrypt_stream()    (v3.19+ streaming, app_secret HKDF).
 *   IPAMBKP1 → backup_decrypt()           (v3.17–v3.18, full-file GCM, legacy).
 *
 * Optional credentials are mode-specific:
 *   - $passphrase  — required for IPAMBKP3 transitory mode; ignored otherwise.
 *   - $vaultKey    — 32 raw bytes; required for IPAMBKP3 stored mode; ignored otherwise.
 *   - $appSecret   — required for IPAMBKP1 / IPAMBKP2 legacy formats; ignored
 *                    for v3 / unencrypted.
 *
 * Missing credentials throw IpamBackupKeyRequiredException so the caller can
 * render a credential prompt without parsing exception text.
 *
 * @throws IpamBackupKeyRequiredException when the archive needs a
 *         credential the caller did not provide.
 * @throws RuntimeException on unknown magic, I/O failure, or codec errors.
 */
function backup_decrypt_to_path(
    string $srcPath,
    string $dstPath,
    string $appSecret,
    ?string $passphrase = null,
    ?string $vaultKey = null
): void {
    $fh = @fopen($srcPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException('backup_decrypt_to_path: cannot open source');
    }
    $magic = (string) fread($fh, 8);
    // For IPAMBKP3 we also need the mode byte at offset 8 to decide which
    // credential is required before delegating.
    $modeByte = (string) fread($fh, 1);
    fclose($fh);

    if ($magic === BACKUP_MAGIC_V3) {
        if (strlen($modeByte) !== 1) {
            throw new RuntimeException('backup_decrypt_to_path: IPAMBKP3 truncated before mode byte');
        }
        $mode = ord($modeByte);
        if ($mode === BACKUP_V3_MODE_TRANSITORY && ($passphrase === null || $passphrase === '')) {
            throw new IpamBackupKeyRequiredException(
                $mode,
                'backup_decrypt_to_path: IPAMBKP3 transitory archive requires a passphrase'
            );
        }
        if ($mode === BACKUP_V3_MODE_STORED && ($vaultKey === null || $vaultKey === '')) {
            throw new IpamBackupKeyRequiredException(
                $mode,
                'backup_decrypt_to_path: IPAMBKP3 stored archive requires backup_vault_key'
            );
        }
        backup_decrypt_stream_v3($srcPath, $dstPath, $passphrase, $vaultKey);
        return;
    }
    if ($magic === BACKUP_MAGIC_UNENC) {
        backup_unencrypted_unwrap_stream($srcPath, $dstPath);
        return;
    }
    if ($magic === BACKUP_MAGIC_V2) {
        backup_decrypt_stream($srcPath, $dstPath, $appSecret);
        return;
    }
    if ($magic === BACKUP_MAGIC) {
        $blob = @file_get_contents($srcPath);
        if ($blob === false) {
            throw new RuntimeException('backup_decrypt_to_path: cannot read v1 blob');
        }
        $plain = backup_decrypt($blob, $appSecret);
        $written = @file_put_contents($dstPath, $plain);
        if ($written !== strlen($plain)) {
            // Partial write (e.g. disk full) — discard truncated plaintext.
            if (is_file($dstPath)) {
                @unlink($dstPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $dstPath is caller-supplied tmpfile path, no user input
            }
            throw new RuntimeException('backup_decrypt_to_path: cannot write v1 plaintext');
        }
        return;
    }
    throw new RuntimeException('backup_decrypt_to_path: unknown backup format');
}

/**
 * Advance a 16-byte big-endian counter block by $blocks (treats the whole
 * 16 bytes as a single big-endian integer). Returns the new counter; the
 * input is unchanged.
 */
function ipam_backup_advance_ctr(string $counter, int $blocks): string
{
    $unpacked = unpack('C*', $counter);
    if ($unpacked === false) {
        throw new RuntimeException('ipam_backup_advance_ctr: unpack failed');
    }
    $bytes = array_values($unpacked);
    $carry = $blocks;
    for ($i = 15; $i >= 0 && $carry > 0; $i--) {
        $sum = $bytes[$i] + ($carry & 0xff);
        $bytes[$i] = $sum & 0xff;
        $carry = ($carry >> 8) + ($sum >> 8);
    }
    return pack('C*', ...$bytes);
}

/**
 * Decrypt a backup payload produced by backup_encrypt().
 *
 * Verifies the magic header and GCM authentication tag before returning
 * plaintext. Any tampering with the ciphertext, tag, or IV — or passing
 * a plain-text file instead of an encrypted blob — causes a RuntimeException
 * with a non-leaky message (no oracle information about which part failed).
 *
 * Forward-compat note: future format versions will use a different magic
 * (e.g. "IPAMBKP2") and a newer decoder. The error message on version
 * mismatch is intentionally identical to the bad-magic path so the format
 * version itself is not leaked through the exception text. Callers should
 * surface a user-friendly "backup created by newer version" error when
 * appropriate based on the loaded software version, not by parsing the
 * exception message.
 *
 * @throws RuntimeException on bad magic, truncation, or authentication failure
 */
function backup_decrypt(string $blob, string $appSecret): string
{
    if ($appSecret === '') {
        throw new RuntimeException('backup decryption requires app_secret to be set in config.php');
    }
    $minLen = strlen(BACKUP_MAGIC) + BACKUP_IV_LEN + BACKUP_TAG_LEN;
    if (strlen($blob) < $minLen) {
        throw new RuntimeException('encrypted blob too short');
    }
    if (substr($blob, 0, strlen(BACKUP_MAGIC)) !== BACKUP_MAGIC) {
        throw new RuntimeException('not an IPAM backup blob (bad magic)');
    }
    $offset = strlen(BACKUP_MAGIC);
    $iv  = substr($blob, $offset, BACKUP_IV_LEN);
    $tag = substr($blob, $offset + BACKUP_IV_LEN, BACKUP_TAG_LEN);
    $ct  = substr($blob, $offset + BACKUP_IV_LEN + BACKUP_TAG_LEN);
    $key = ipam_hkdf_sha256($appSecret, 'ipam-v3:backup', 32);
    $pt  = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($pt === false) {
        throw new RuntimeException('backup decryption failed (auth or key)');
    }
    return $pt;
}

// ── GFS Retention Engine (#695) ───────────────────────────────────────────────

/**
 * Select backup log IDs that should be deleted according to a GFS
 * (Grandfather-Father-Son) retention policy.
 *
 * Algorithm
 * ---------
 * 1. Sort $backups newest-first by created_at.
 * 2. Walk in order, assigning each backup to up to four tier slots:
 *    - hourly  → slot key "Y-m-d H" (UTC)
 *    - daily   → slot key "Y-m-d"   (UTC)
 *    - weekly  → slot key "YYYY-WW"  (ISO 8601 week)
 *    - monthly → slot key "Y-m"     (UTC)
 * 3. For each tier with a positive keep_* count, the FIRST backup encountered
 *    in a given slot (i.e. the newest in that slot) wins the slot until the
 *    tier's capacity is exhausted.
 * 4. A backup is KEPT if it wins at least one slot in any tier.
 * 5. Safety guard: the newest backup overall is always kept, even when all
 *    keep_* counts are zero.
 * 6. Everything else goes into the delete list.
 *
 * Tier promotion note: tiers are independent. A backup that falls outside the
 * hourly window (e.g. yesterday's backup when keep_hourly=1) can still win a
 * daily, weekly, or monthly slot without first winning an hourly slot.
 *
 * @param array<int, array{id: int, created_at: string}> $backups
 *        Flat list of backup records; order does not matter.
 * @param array{keep_hourly: int, keep_daily: int, keep_weekly: int, keep_monthly: int} $config
 *        Retention counts per tier. A count of 0 disables that tier entirely.
 * @return int[]  IDs from $backups that should be deleted.
 */
function ipam_gfs_select_for_deletion(array $backups, array $config): array
{
    if (count($backups) === 0) {
        return [];
    }

    // Sort newest-first; stable sort preserves relative order of ties (not critical,
    // but deterministic across PHP versions).
    usort($backups, static function (array $a, array $b): int {
        return strcmp($b['created_at'], $a['created_at']);
    });

    $newestId = (int) $backups[0]['id'];

    $keepHourly  = max(0, (int) $config['keep_hourly']);
    $keepDaily   = max(0, (int) $config['keep_daily']);
    $keepWeekly  = max(0, (int) $config['keep_weekly']);
    $keepMonthly = max(0, (int) $config['keep_monthly']);

    // Slot tracking per tier: slot_key → count of backups already assigned to that slot.
    $hourlySlots  = [];
    $dailySlots   = [];
    $weeklySlots  = [];
    $monthlySlots = [];

    // Count of unique slots filled so far per tier (used to stop once capacity reached).
    $hourlyFilled  = 0;
    $dailyFilled   = 0;
    $weeklyFilled  = 0;
    $monthlyFilled = 0;

    $keepIds = [];

    foreach ($backups as $backup) {
        $id = (int) $backup['id'];

        // Safety guard: always keep the newest backup.
        if ($id === $newestId) {
            $keepIds[$id] = true;
            // Still run through the tier logic below so it counts toward slot capacity.
        }

        $epoch = strtotime($backup['created_at']);
        if ($epoch === false) {
            // Unparseable timestamp: treat as very old (epoch 0) — should never happen on well-formed data.
            $epoch = 0;
        }

        $kept = isset($keepIds[$id]);

        // Hourly tier
        if ($keepHourly > 0) {
            $slot = gmdate('Y-m-d H', $epoch);
            if (!isset($hourlySlots[$slot])) {
                // First (newest) backup in this slot wins it, if capacity remains.
                if ($hourlyFilled < $keepHourly) {
                    $hourlySlots[$slot] = true;
                    $hourlyFilled++;
                    $keepIds[$id] = true;
                    $kept = true;
                }
                // If capacity exhausted, the slot winner is still recorded as "seen"
                // so later (older) backups in the same slot are not mistakenly counted.
                $hourlySlots[$slot] = true;
            }
            // Subsequent backups in the same slot: slot already claimed; they do not win it.
        }

        // Daily tier
        if ($keepDaily > 0) {
            $slot = gmdate('Y-m-d', $epoch);
            if (!isset($dailySlots[$slot])) {
                if ($dailyFilled < $keepDaily) {
                    $dailySlots[$slot] = true;
                    $dailyFilled++;
                    $keepIds[$id] = true;
                    $kept = true;
                }
                $dailySlots[$slot] = true;
            }
        }

        // Weekly tier (ISO 8601 week: "YYYY-WW")
        if ($keepWeekly > 0) {
            $slot = gmdate('o-W', $epoch); // 'o' = ISO year (may differ from 'Y' at year boundary)
            if (!isset($weeklySlots[$slot])) {
                if ($weeklyFilled < $keepWeekly) {
                    $weeklySlots[$slot] = true;
                    $weeklyFilled++;
                    $keepIds[$id] = true;
                    $kept = true;
                }
                $weeklySlots[$slot] = true;
            }
        }

        // Monthly tier
        if ($keepMonthly > 0) {
            $slot = gmdate('Y-m', $epoch);
            if (!isset($monthlySlots[$slot])) {
                if ($monthlyFilled < $keepMonthly) {
                    $monthlySlots[$slot] = true;
                    $monthlyFilled++;
                    $keepIds[$id] = true;
                    $kept = true;
                }
                $monthlySlots[$slot] = true;
            }
        }

        // Suppress unused variable warning from static analysis: $kept is set for
        // documentation clarity but only $keepIds drives the decision.
        unset($kept);
    }

    // Build delete list: every input ID not in the keep set.
    $delete = [];
    foreach ($backups as $backup) {
        $id = (int) $backup['id'];
        if (!isset($keepIds[$id])) {
            $delete[] = $id;
        }
    }

    return $delete;
}

/**
 * Compute the list of backup_runs IDs to prune for a single destination.
 *
 * Pure-ish: reads from backup_destinations + backup_schedules + backup_runs,
 * but performs no mutations. Wraps ipam_gfs_select_for_deletion() with the
 * DB plumbing — schedule resolution (with conservative defaults if absent),
 * eligible-row filtering (status='success', is_protected=0), and slot
 * assignment via the GFS selector. Testable in isolation against an
 * in-memory SQLite.
 *
 * Reads from backup_runs (v3.21.0 #799 §A1; replaces backup_log).
 *
 * @param PDO $db             Application database connection.
 * @param int $destinationId  ID of the backup_destinations row.
 * @return int[]              backup_runs IDs that GFS retention selects for deletion.
 *                            Empty array means "nothing to prune" (no candidates,
 *                            below capacity, or all candidates protected).
 * @throws \InvalidArgumentException  if the destination row does not exist.
 */
function ipam_retention_compute_deletions(PDO $db, int $destinationId): array
{
    // ── 1. Fetch destination retention — v3.25.0 #846 retention rehome ──────
    // Pre-v3.25.0 retention lived on backup_schedules. v3.25.0 moved it to
    // backup_destinations so it describes "how much to keep at this storage
    // location" rather than "how often we write to it" (per
    // archive/backup_overhaul.md §3 AGREED). The migration backfilled values from
    // any per-schedule rows; the schedule columns remain in place for one
    // release cycle for downgrade safety but are no longer the source of
    // truth.
    //
    // Read from backup_destinations first; fall back to the per-schedule
    // row only if the destination columns are missing (pre-migration
    // upgrade window).
    // Probe whether the v3.25.0 retention columns are present. Pre-migration
    // upgrade windows and minimal-schema unit-test fixtures lack them; the
    // destination read falls back to the legacy schedule read in that case.
    $destRow = null;
    try {
        $destStmt = $db->prepare(
            "SELECT id, retention_hourly, retention_daily, retention_weekly, retention_monthly
               FROM backup_destinations WHERE id = :id"
        );
        $destStmt->execute([':id' => $destinationId]);
        $destRow = $destStmt->fetch();
    } catch (\PDOException $e) {
        // Only swallow undefined-column errors — other PDO failures
        // (deadlock, connection drop, etc.) must propagate so retention
        // doesn't silently fall back to legacy/default values and prune
        // too aggressively. SQLSTATE 42S22 (mysql) and 42703 (pgsql) are
        // "undefined column"; SQLite reports HY000 with the textual
        // 'no such column' fragment in errorInfo[2].
        $sqlstate = (string) ($e->errorInfo[0] ?? '');
        $msg      = (string) ($e->errorInfo[2] ?? '');
        $missingColumn =
            $sqlstate === '42S22'
            || $sqlstate === '42703'
            || ($sqlstate === 'HY000' && str_contains($msg, 'no such column'));
        if (!$missingColumn) {
            throw $e;
        }
        $destRow = null;
    }

    if ($destRow === null || $destRow === false) {
        // Either columns missing or row missing — distinguish by an
        // existence-only probe so we still throw on bad ID.
        $existsStmt = $db->prepare("SELECT id FROM backup_destinations WHERE id = :id");
        $existsStmt->execute([':id' => $destinationId]);
        if (!is_array($existsStmt->fetch())) {
            throw new \InvalidArgumentException("backup_destinations row not found: id={$destinationId}");
        }
    }

    if (is_array($destRow) && array_key_exists('retention_hourly', $destRow)) {
        $gfsConfig = [
            'keep_hourly'  => to_int($destRow['retention_hourly']),
            'keep_daily'   => to_int($destRow['retention_daily']),
            'keep_weekly'  => to_int($destRow['retention_weekly']),
            'keep_monthly' => to_int($destRow['retention_monthly']),
        ];
    } else {
        // Pre-migration: aggregate retention across any active schedules
        // pointing at this destination using MAX() — same most-generous
        // semantics the migration backfill uses (CR #1096 major finding).
        // LIMIT 1 would silently bias to whichever row PDO returned first
        // and could prune more aggressively than intended.
        $schedStmt = $db->prepare(
            "SELECT MAX(retention_hourly)  AS h,
                    MAX(retention_daily)   AS d,
                    MAX(retention_weekly)  AS w,
                    MAX(retention_monthly) AS m
             FROM backup_schedules
             WHERE destination_id = :did AND is_active = 1"
        );
        $schedStmt->execute([':did' => $destinationId]);
        $sched = $schedStmt->fetch();
        if (is_array($sched) && $sched['h'] !== null) {
            $gfsConfig = [
                'keep_hourly'  => to_int($sched['h']),
                'keep_daily'   => to_int($sched['d']),
                'keep_weekly'  => to_int($sched['w']),
                'keep_monthly' => to_int($sched['m']),
            ];
        } else {
            $gfsConfig = [
                'keep_hourly'  => 0,
                'keep_daily'   => 7,
                'keep_weekly'  => 4,
                'keep_monthly' => 3,
            ];
        }
    }

    // ── 3. Fetch successful backup_runs rows for this destination ────────────
    // backup_runs uses started_at for GFS bucketing (no created_at column).
    // CR feedback PR #1054: exclude is_protected=1 rows from the prune set.
    // Retention must respect the manual protection flag the same way the UI
    // delete-action does, otherwise auto-prune can wipe a row the operator
    // explicitly protected.
    $logStmt = $db->prepare(
        "SELECT id, started_at AS created_at
         FROM backup_runs
         WHERE destination_id = :did
           AND status = 'success'
           AND is_protected = 0
         ORDER BY started_at DESC"
    );
    $logStmt->execute([':did' => $destinationId]);
    $rows = $logStmt->fetchAll();

    if (count($rows) === 0) {
        return [];
    }

    return ipam_gfs_select_for_deletion($rows, $gfsConfig);
}

/**
 * Apply a precomputed deletion list: remote-delete each file via $client, and
 * mark each successfully-deleted backup_runs row as 'retention_pruned'. I/O only.
 *
 * Failure model: the row is marked pruned ONLY if the remote delete succeeded
 * (or the client is null and the row had no remote object). A remote-delete
 * failure leaves the row at status='success' so the next retention pass will
 * try again. Exceptions from the client are caught and logged; this function
 * never throws.
 *
 * Audit: a single 'backup.retention_pruned' event is written when count > 0,
 * with details "count=N destination=ID".
 *
 * @param PDO                        $db             Application database connection.
 * @param BackupClientInterface|null $client         Transport client for the
 *                                                   destination, or null if no
 *                                                   client could be constructed
 *                                                   (rows then stay live).
 * @param int                        $destinationId  Destination ID — used in
 *                                                   audit details only.
 * @param int[]                      $ids            backup_runs IDs to prune.
 * @return int                                       Count of rows actually
 *                                                   marked retention_pruned.
 */
function ipam_retention_apply_deletions(PDO $db, ?BackupClientInterface $client, int $destinationId, array $ids): int
{
    if (count($ids) === 0) {
        return 0;
    }

    // Fetch filenames for the IDs we'll attempt to delete remotely.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rowStmt = $db->prepare(
        "SELECT id, filename FROM backup_runs WHERE id IN ($placeholders)"
    );
    $rowStmt->execute(array_map('intval', $ids));
    $rowById = [];
    foreach ($rowStmt->fetchAll() as $r) {
        if (is_array($r) && isset($r['id']) && is_numeric($r['id'])) {
            $rowById[(int) $r['id']] = $r;
        }
    }

    $pruned = 0;
    foreach ($ids as $logId) {
        $row = $rowById[(int) $logId] ?? null;
        $filename = is_array($row) && is_string($row['filename'] ?? null) ? $row['filename'] : '';
        $remoteDeleted = false;

        if ($client !== null && $filename !== '') {
            try {
                // BackupClientInterface::delete() returns bool — true = removed, false = not found.
                // Treat both as success for retention purposes (the goal is "no longer present").
                $client->delete($filename);
                $remoteDeleted = true;
            } catch (\Throwable $e) {
                error_log('[ipam_retention_apply_deletions] remote delete failed for log_id=' . $logId . ' file=' . $filename . ': ' . $e->getMessage());
                $remoteDeleted = false;
            }
        }

        if (!$remoteDeleted) {
            continue;
        }

        try {
            $db->prepare(
                "UPDATE backup_runs SET status = 'retention_pruned' WHERE id = :id"
            )->execute([':id' => $logId]);
            $pruned++;
        } catch (\Exception $e) {
            error_log('[ipam_retention_apply_deletions] failed to mark log_id=' . $logId . ': ' . $e->getMessage());
        }
    }

    if ($pruned > 0) {
        // Matches the rest of the destinations.* / backup.* audit events,
        // which all use entity_type='destination'. CR review on PR #1050
        // flagged the prior 'backup_destination' as the outlier.
        audit(
            $db,
            'backup.retention_pruned',
            'destination',
            $destinationId,
            'count=' . $pruned . ' destination=' . $destinationId
        );
    }

    return $pruned;
}

/**
 * Build a typed BackupClientInterface for a destination row, or null if the
 * destination type is unknown or the client constructor throws (e.g. invalid
 * config). Errors are logged but never propagated — retention must remain
 * resilient to one misconfigured destination.
 */
function ipam_retention_build_client(PDO $db, int $destinationId): ?BackupClientInterface
{
    $stmt = $db->prepare("SELECT type, config FROM backup_destinations WHERE id = :id");
    $stmt->execute([':id' => $destinationId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $type = is_string($row['type'] ?? null) ? $row['type'] : '';
    $cfgJson = is_string($row['config'] ?? null) ? $row['config'] : '';
    $cfgArr = json_decode($cfgJson, true);
    $typedCfg = [];
    if (is_array($cfgArr)) {
        foreach ($cfgArr as $k => $v) {
            if (is_string($k)) $typedCfg[$k] = $v;
        }
    }

    try {
        return match ($type) {
            's3'    => new S3Client($typedCfg),
            'sftp'  => new SftpClient($typedCfg),
            'local' => new LocalBackupClient($typedCfg),
            default => null,
        };
    } catch (\Throwable $e) {
        error_log('[ipam_retention_build_client] cannot construct client for destination=' . $destinationId . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * DB-aware GFS retention orchestrator for a single backup destination.
 *
 * Thin wrapper that composes ipam_retention_compute_deletions() (pure-ish
 * planning) → ipam_retention_build_client() (client construction) →
 * ipam_retention_apply_deletions() (remote delete + DB mark + audit).
 *
 * Existing call signature is preserved (#826 refactor splits the body into
 * three testable functions; the public surface stays the same so cron.php
 * and lib/backup.php continue to work without changes).
 *
 * @param PDO $db             Application database connection.
 * @param int $destinationId  ID of the backup_destinations row.
 * @return int                Count of backup_runs rows marked as retention_pruned.
 */
function ipam_backup_apply_retention(PDO $db, int $destinationId): int
{
    $ids = ipam_retention_compute_deletions($db, $destinationId);
    if (count($ids) === 0) {
        return 0;
    }
    $client = ipam_retention_build_client($db, $destinationId);
    return ipam_retention_apply_deletions($db, $client, $destinationId, $ids);
}

/**
 * Compute the next clock-aligned run time for a backup schedule.
 *
 * @param array<string,mixed> $schedule schedule row, requires 'frequency'; uses
 *                                       'time_of_day' (HH:MM), 'day_of_week' (0-6, Mon=1),
 *                                       'day_of_month' (1-28).
 * @param ?int $nowEpoch injectable UTC epoch for testing; defaults to time()
 * @return int next-run UTC epoch
 */
function ipam_backup_next_run_at(array $schedule, ?int $nowEpoch = null): int
{
    $now = $nowEpoch ?? time();
    $freq = is_string($schedule['frequency'] ?? null) ? $schedule['frequency'] : 'daily';

    [$hour, $minute] = ipam_backup_parse_time_of_day(
        is_string($schedule['time_of_day'] ?? null) ? $schedule['time_of_day'] : '02:00'
    );

    $utc = new DateTimeZone('UTC');
    $nowDt = (new DateTimeImmutable('@' . $now))->setTimezone($utc);

    if ($freq === 'hourly') {
        // Next exact HH:00 strictly after now (ignores time_of_day).
        return $nowDt
            ->setTime((int) $nowDt->format('H'), 0, 0)
            ->modify('+1 hour')
            ->getTimestamp();
    }

    if ($freq === 'weekly') {
        $schemaDow = isset($schedule['day_of_week']) && is_numeric($schedule['day_of_week'])
            ? ((int) $schedule['day_of_week']) : 1; // Mon default
        $phpDow = ipam_backup_dow_schema_to_php($schemaDow);
        $currentDow = (int) $nowDt->format('N');
        $daysAhead = ($phpDow - $currentDow + 7) % 7;
        $candidate = $nowDt
            ->setTime($hour, $minute, 0)
            ->modify("+{$daysAhead} days");
        if ($candidate->getTimestamp() <= $now) {
            $candidate = $candidate->modify('+7 days');
        }
        return $candidate->getTimestamp();
    }

    if ($freq === 'monthly') {
        $schemaDom = isset($schedule['day_of_month']) && is_numeric($schedule['day_of_month'])
            ? ((int) $schedule['day_of_month']) : 1;
        // Clamp 1..28 — anything higher would risk PHP's month-overflow
        // normalisation pushing 31st in a 30-day month into the next month.
        $targetDom = max(1, min(28, $schemaDom));
        $candidate = $nowDt
            ->setDate((int) $nowDt->format('Y'), (int) $nowDt->format('n'), $targetDom)
            ->setTime($hour, $minute, 0);
        if ($candidate->getTimestamp() <= $now) {
            $candidate = $candidate->modify('+1 month');
        }
        return $candidate->getTimestamp();
    }

    // 'daily' (and any unknown frequency string) — next HH:MM today or tomorrow.
    $candidate = $nowDt->setTime($hour, $minute, 0);
    if ($candidate->getTimestamp() <= $now) {
        $candidate = $candidate->modify('+1 day');
    }
    return $candidate->getTimestamp();
}

/**
 * Convert a schema day_of_week (0=Sun..6=Sat, the convention used by
 * backup_schedules.day_of_week per #690) to PHP's gmdate('N') convention
 * (1=Mon..7=Sun). Out-of-range inputs clamp into [0, 6] before conversion.
 *
 * Pure helper — extracted from ipam_backup_next_run_at during #826/#827
 * refactor so it can be tested in isolation and reused.
 */
function ipam_backup_dow_schema_to_php(int $schemaDow): int
{
    $clamped = max(0, min(6, $schemaDow));
    return $clamped === 0 ? 7 : $clamped;
}

/**
 * Parse a "HH:MM" string into a [hour, minute] tuple, clamping invalid hours
 * to 02 (the project default backup hour) and invalid minutes to 0. A bare
 * "HH" without a colon is accepted; the minute defaults to 0.
 *
 * @return array{0:int,1:int} [hour, minute] in the half-open ranges [0,24) and [0,60).
 */
function ipam_backup_parse_time_of_day(string $timeOfDay): array
{
    $parts = explode(':', $timeOfDay);
    $hour = (int) $parts[0];
    $minute = count($parts) > 1 ? (int) $parts[1] : 0;
    if ($hour < 0 || $hour > 23) $hour = 2;
    if ($minute < 0 || $minute > 59) $minute = 0;
    return [$hour, $minute];
}

/**
 * Merge secrets from an existing destination config into a submitted form payload
 * so that omitted or blank-string secret fields preserve the stored value, and
 * non-empty submitted values replace it (#793).
 *
 * Explicit clear: callers may submit "<postKey>__clear=1" to force a stored
 * secret to be removed. This wins over the preserve-on-blank behaviour and lets
 * operators rotate authentication modes (e.g. switching SFTP from password to
 * key-only) without delete-and-recreate.
 *
 * Pure function — no I/O. Designed for unit testing.
 *
 * @param  array<string, mixed> $post         Submitted form fields ($_POST shape).
 * @param  array<string, mixed> $existingCfg  Decoded JSON config from backup_destinations.config.
 * @param  string               $type         's3'|'sftp'|'local'.
 * @return array<string, mixed>               $post with secret fields backfilled or cleared.
 */
function ipam_destination_merge_secrets(array $post, array $existingCfg, string $type): array
{
    $pairs = [];
    if ($type === 's3') {
        $pairs = [['s3_secret_key', 'secret_key']];
    } elseif ($type === 'sftp') {
        $pairs = [
            ['sftp_password',    'password'],
            ['sftp_private_key', 'private_key'],
        ];
    }
    foreach ($pairs as [$postKey, $cfgKey]) {
        $clearKey = $postKey . '__clear';
        $clearRequested = isset($post[$clearKey])
            && in_array($post[$clearKey], ['1', 1, true, 'true', 'on'], true);
        if ($clearRequested) {
            // Explicit clear: blow away both the submitted value (if any) and the
            // preserved one. collect_config will then treat the field as absent.
            $post[$postKey] = '';
            continue;
        }
        $omitted = !array_key_exists($postKey, $post);
        $blank   = !$omitted && is_string($post[$postKey]) && $post[$postKey] === '';
        if (($omitted || $blank) && isset($existingCfg[$cfgKey])) {
            $post[$postKey] = to_str($existingCfg[$cfgKey]);
        }
    }
    return $post;
}

/**
 * Probe a backup destination for reachability and credential validity.
 *
 * Centralised so test_destination.php (manual click) and the auto-on-save path
 * in destinations.php (#787) share one implementation. Loads the row, decodes
 * config, constructs the typed client, and returns the same JSON-shape the
 * client's test() method produces. Audit-logs result with the supplied
 * triggered_by tag.
 *
 * @param  PDO    $db
 * @param  int    $destId
 * @param  string $triggeredBy 'manual' | 'auto-on-save'
 * @return array{ok:bool,message:string,latency_ms:?int}
 */
function ipam_destination_test_now(PDO $db, int $destId, string $triggeredBy = 'manual'): array
{
    // Audit every failure on the way to the client too — invalid IDs, missing
    // rows, and unparseable config previously slipped past the destination.test
    // audit trail.
    $fail = static function (?int $entityId, string $message) use ($db, $triggeredBy): array {
        audit($db, 'destination.test', 'destination', $entityId, "triggered_by=$triggeredBy fail");
        return ['ok' => false, 'message' => $message, 'latency_ms' => null];
    };

    if ($destId <= 0) {
        return $fail(null, 'Invalid destination id');
    }
    $stmt = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id");
    $stmt->execute([':id' => $destId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $fail($destId, 'Destination not found');
    }
    $type = is_string($row['type'] ?? null) ? $row['type'] : '';
    $cfgJson = is_string($row['config'] ?? null) ? $row['config'] : '{}';
    $cfg = json_decode($cfgJson, true);
    if (!is_array($cfg)) {
        return $fail($destId, 'Destination config invalid');
    }
    /** @var array<string,mixed> $typedCfg */
    $typedCfg = [];
    foreach ($cfg as $k => $v) {
        if (is_string($k)) $typedCfg[$k] = $v;
    }
    try {
        $client = match ($type) {
            's3'    => new S3Client($typedCfg),
            'sftp'  => new SftpClient($typedCfg),
            'local' => new LocalBackupClient($typedCfg),
            default => throw new RuntimeException('Unknown destination type'),
        };
        $result = $client->test();
        audit($db, 'destination.test', 'destination', $destId,
            "triggered_by=$triggeredBy " . ($result['ok'] ? 'ok' : 'fail'));
        return $result;
    } catch (Throwable $e) {
        // Don't log the raw exception message — backup-transport exceptions can
        // include endpoint URLs, access keys, or auth payloads. Log only the
        // class name; the client layer is responsible for surfacing redacted
        // user-facing detail via the returned message.
        error_log('[destination_test] dest=' . $destId . ' exception=' . get_class($e));
        audit($db, 'destination.test', 'destination', $destId, "triggered_by=$triggeredBy fail");
        return [
            'ok'         => false,
            'message'    => 'Connection failed (' . get_class($e) . ')',
            'latency_ms' => null,
        ];
    }
}

/**
 * Email notification dispatcher for backup-subsystem events. Best-effort:
 * failures logged, never thrown. Each event reads its own enable flag from
 * the settings registry and returns early if disabled.
 *
 * Supported events (v3.22.0 §2.4):
 *   - 'success_scheduled'         context: ['dest' => row, 'detail' => string]
 *   - 'success_manual'            context: ['dest' => row, 'detail' => string]
 *   - 'failure_scheduled'         context: ['dest' => row, 'detail' => string]
 *   - 'failure_manual'            context: ['dest' => row, 'detail' => string]
 *   - 'destination_conn_failure'  context: ['dest' => row, 'message' => string]
 *   - 'schedule_overdue'          context: ['schedule_id' => int, 'destination_name' => string,
 *                                            'expected_at' => string, 'overdue_minutes' => int]
 *   - 'retention_prune'           context: ['dest' => row, 'pruned' => int]
 *   - 'encryption_change'         context: ['dest' => row, 'old_mode' => string, 'new_mode' => string]
 *
 * Backwards compatibility: the v3.21.x signature
 *     ipam_backup_notify(PDO $db, array $dest, string $status, string $detail)
 * is preserved — when the second arg is an array, this delegates to the new
 * dispatch path with `$status` mapped to the appropriate event by inspecting
 * `$dest['triggered_by']` if present (defaults to 'scheduled').
 *
 * @param array<string,mixed>|string $eventOrDest  Event slug, or legacy $dest row
 * @param array<string,mixed>|string $contextOrStatus  Event context, or legacy 'success'|'failure'
 * @param string                     $legacyDetail  Legacy detail string when called with old signature
 */
function ipam_backup_notify(
    PDO $db,
    array|string $eventOrDest,
    array|string $contextOrStatus = [],
    string $legacyDetail = ''
): void {
    // ----- Legacy signature shim -------------------------------------------
    // Pre-v3.22.0: ipam_backup_notify($db, $destRow, 'success'|'failure', $detail)
    // The BackupNotifyWiringTest source-scan test still asserts the old
    // call-site shape, so we preserve it. New callers should use the
    // event/context form.
    if (is_array($eventOrDest)) {
        $dest      = $eventOrDest;
        $status    = is_string($contextOrStatus) ? $contextOrStatus : '';
        $triggered = is_string($dest['triggered_by'] ?? null) ? $dest['triggered_by'] : 'scheduled';
        $event = match (true) {
            $status === 'success' && $triggered === 'manual'   => 'success_manual',
            $status === 'success'                              => 'success_scheduled',
            $status === 'failure' && $triggered === 'manual'   => 'failure_manual',
            $status === 'failure'                              => 'failure_scheduled',
            default                                            => '',
        };
        if ($event === '') return;
        ipam_backup_notify_dispatch($db, $event, ['dest' => $dest, 'detail' => $legacyDetail]);
        return;
    }

    // ----- New signature ---------------------------------------------------
    $event   = $eventOrDest;
    $context = is_array($contextOrStatus) ? $contextOrStatus : [];
    ipam_backup_notify_dispatch($db, $event, $context);
}

/**
 * Internal dispatch — looks up the per-event enable flag, formats subject +
 * body, and hands off to the shared mail pipeline.
 *
 * @param array<string,mixed> $context
 */
function ipam_backup_notify_dispatch(PDO $db, string $event, array $context): void
{
    $settingKey = match ($event) {
        'success_scheduled'        => 'backup.notify_success_scheduled',
        'success_manual'           => 'backup.notify_success_manual',
        'failure_scheduled'        => 'backup.notify_failure_scheduled',
        'failure_manual'           => 'backup.notify_failure_manual',
        'destination_conn_failure' => 'backup.notify_destination_conn_failure',
        'schedule_overdue'         => 'backup.notify_schedule_overdue',
        'retention_prune'          => 'backup.notify_retention_prune',
        'encryption_change'        => 'backup.notify_encryption_change',
        default                    => '',
    };
    if ($settingKey === '') return;

    // Per-schedule overrides apply only to scheduled-flow events. The
    // scheduling concept doesn't bind to manual / connection-test / overdue
    // / retention-prune / encryption-change events, which stay global.
    // schedule_id arrives via $dest['schedule_id'] (orchestrator threads it
    // alongside triggered_by; null on manual runs).
    $dest = is_array($context['dest'] ?? null) ? $context['dest'] : [];
    $rawSched = $dest['schedule_id'] ?? null;
    $scheduleId = is_int($rawSched) ? $rawSched
        : (is_numeric($rawSched) ? (int) $rawSched : null);

    $globalEnabled = (bool) ipam_setting($settingKey);
    $shouldSend = match ($event) {
        'failure_scheduled' => ipam_backup_notify_resolve_pref($db, $scheduleId, 'notify_on_failure', $globalEnabled),
        'success_scheduled' => ipam_backup_notify_resolve_pref($db, $scheduleId, 'notify_on_success', $globalEnabled),
        default             => $globalEnabled,
    };
    if (!$shouldSend) return;

    // Recipients: v3.25.0 #1078 backup-specific override takes precedence
    // over the global alert recipients. Empty override falls through to the
    // legacy alert.recipient_user_ids + alert.email path.
    $recipients = ipam_resolve_backup_notify_recipients($db);
    if ($recipients === []) {
        $legacy = trim(to_str(ipam_setting('alert.email')));
        if ($legacy !== '') $recipients = [$legacy];
    }
    // Per-schedule recipient override (v3.23.0 #825): applies on top of the
    // backup-tab override for scheduled-flow events. Manual / system events
    // skip this layer because there's no schedule_id in scope.
    $applyRecipientOverride = $event === 'failure_scheduled' || $event === 'success_scheduled';
    if ($applyRecipientOverride) {
        $recipients = ipam_backup_notify_resolve_recipients($db, $scheduleId, $recipients);
    }
    if ($recipients === []) return;

    $destName = is_string($dest['name'] ?? null) ? $dest['name'] : 'unknown';

    [$subject, $body] = match ($event) {
        'success_scheduled' => [
            sprintf('[IPAM] Backup SUCCESS (scheduled): %s', $destName),
            sprintf("Scheduled backup succeeded for destination \"%s\".\n\nDetail: %s\n",
                $destName, to_str($context['detail'] ?? '')),
        ],
        'success_manual' => [
            sprintf('[IPAM] Backup SUCCESS (manual): %s', $destName),
            sprintf("Manual backup succeeded for destination \"%s\".\n\nDetail: %s\n",
                $destName, to_str($context['detail'] ?? '')),
        ],
        'failure_scheduled' => [
            sprintf('[IPAM] Backup FAILURE (scheduled): %s', $destName),
            sprintf("Scheduled backup FAILED for destination \"%s\".\n\nDetail: %s\n",
                $destName, to_str($context['detail'] ?? '')),
        ],
        'failure_manual' => [
            sprintf('[IPAM] Backup FAILURE (manual): %s', $destName),
            sprintf("Manual backup FAILED for destination \"%s\".\n\nDetail: %s\n",
                $destName, to_str($context['detail'] ?? '')),
        ],
        'destination_conn_failure' => [
            sprintf('[IPAM] Destination connection test failing: %s', $destName),
            sprintf(
                "Periodic connection test for backup destination \"%s\" started failing.\n\nMessage: %s\n\n"
                . "No further alerts will be sent for this destination until it recovers, then fails again.\n",
                $destName, to_str($context['message'] ?? 'unknown')
            ),
        ],
        'schedule_overdue' => [
            sprintf('[IPAM] Backup schedule overdue: %s',
                to_str($context['destination_name'] ?? 'unknown')),
            sprintf(
                "A backup schedule has not fired when expected.\n\n"
                . "Destination: %s\nExpected at: %s\nOverdue by: %d minute(s)\nSchedule ID: %d\n\n"
                . "Likely causes: cron not running, host crashed, or the orchestrator is stuck.\n",
                to_str($context['destination_name'] ?? 'unknown'),
                to_str($context['expected_at'] ?? 'unknown'),
                to_int($context['overdue_minutes'] ?? 0),
                to_int($context['schedule_id'] ?? 0)
            ),
        ],
        'retention_prune' => [
            sprintf('[IPAM] Retention prune ran on %s', $destName),
            sprintf("Retention deleted %d backup blob(s) from destination \"%s\".\n",
                to_int($context['pruned'] ?? 0), $destName),
        ],
        'encryption_change' => [
            sprintf('[IPAM] Destination encryption mode changed: %s', $destName),
            sprintf(
                "An administrator changed the encryption mode on destination \"%s\".\n\n"
                . "Old mode: %s\nNew mode: %s\n",
                $destName, to_str($context['old_mode'] ?? ''), to_str($context['new_mode'] ?? '')
            ),
        ],
        default => ['', ''],
    };
    if ($subject === '') return;

    // v3.25.0 #1078: read the backup-tab delivery method override.
    // 'smtp' forces SMTP regardless of the global mail.transport / smtp.enabled
    // settings; 'inherit' keeps the legacy behaviour. Future values
    // ('webhook', 'slack', 'pushover') will dispatch through different
    // helpers — for now only the inherit/smtp axis is wired.
    $deliveryMethod = is_string(ipam_setting('backup.notify_delivery_method'))
        ? (string) ipam_setting('backup.notify_delivery_method')
        : 'inherit';
    $transportOverride = $deliveryMethod === 'smtp' ? 'smtp' : null;

    foreach ($recipients as $to) {
        try {
            if (function_exists('ipam_send_mail')) {
                $sendResult = ipam_send_mail($to, $subject, $body, '', $transportOverride);
                // ipam_send_mail returns success=false on transport failure
                // without throwing — surface that here so the new
                // forced-SMTP override doesn't fail silently.
                if (!$sendResult['success']) {
                    $err = is_string($sendResult['error']) ? $sendResult['error'] : 'unknown error';
                    error_log('[backup] notify failed for ' . $to . ': ' . $err);
                }
            } else {
                @mail($to, $subject, $body);
            }
        } catch (Throwable $e) {
            error_log('[backup] notify failed for ' . $to . ': ' . $e->getMessage());
        }
    }
}

/**
 * Map the per-field UI tri-state radio value to the storage form for the
 * notify_on_failure / notify_on_success columns:
 *   'on'      → 1   (override-and-enable)
 *   'off'     → 0   (override-and-suppress)
 *   anything else (incl. 'inherit') → null
 *
 * Centralised here so the controller and any future API endpoint share one
 * source of truth for the encoding.
 */
function ipam_admin_notify_tristate_to_db(mixed $raw): ?int
{
    if (!is_string($raw)) return null;
    return match ($raw) {
        'on'  => 1,
        'off' => 0,
        default => null,
    };
}

/**
 * Inverse of ipam_admin_notify_tristate_to_db() — picks the radio value
 * to mark `checked` when rendering the per-schedule override form.
 */
function ipam_admin_notify_tristate_from_db(mixed $raw): string
{
    if ($raw === null) return 'inherit';
    if (is_numeric($raw)) {
        return ((int) $raw) === 1 ? 'on' : 'off';
    }
    return 'inherit';
}

/**
 * Per-schedule resolver for a notification boolean (notify_on_failure /
 * notify_on_success). Returns the schedule's column when notify_override = 1
 * and the column is non-NULL; otherwise the global default.
 *
 * Wired in by E3 alongside the Notifications-tab UI. Pure function — only
 * touches backup_schedules; safe to call from anywhere with a $db handle.
 */
function ipam_backup_notify_resolve_pref(
    PDO $db,
    ?int $scheduleId,
    string $boolCol,
    bool $globalDefault
): bool {
    if (!in_array($boolCol, ['notify_on_failure', 'notify_on_success'], true)) {
        throw new InvalidArgumentException(
            "ipam_backup_notify_resolve_pref: column must be notify_on_failure or notify_on_success, got '$boolCol'"
        );
    }
    if ($scheduleId === null) {
        return $globalDefault;
    }
    $sql = "SELECT notify_override, $boolCol AS pref FROM backup_schedules WHERE id = :id";
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $scheduleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $globalDefault;
    }
    $rawOverride = $row['notify_override'] ?? 0;
    $override = is_numeric($rawOverride) && (int) $rawOverride === 1;
    if (!$override) {
        return $globalDefault;
    }
    $rawPref = $row['pref'] ?? null;
    if ($rawPref === null || !is_numeric($rawPref)) {
        // Override row but this particular preference left NULL — inherit global.
        return $globalDefault;
    }
    return (int) $rawPref === 1;
}

/**
 * Per-schedule resolver for notification recipients. Returns the schedule's
 * CSV recipient list when notify_override = 1 AND notify_recipients is non-NULL
 * AND parses to a non-empty list; otherwise the global recipients.
 *
 * @param  list<string> $globalRecipients
 * @return list<string>
 */
function ipam_backup_notify_resolve_recipients(
    PDO $db,
    ?int $scheduleId,
    array $globalRecipients
): array {
    if ($scheduleId === null) {
        return $globalRecipients;
    }
    $stmt = $db->prepare(
        "SELECT notify_override, notify_recipients FROM backup_schedules WHERE id = :id"
    );
    $stmt->execute([':id' => $scheduleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $globalRecipients;
    }
    $rawOverride = $row['notify_override'] ?? 0;
    $override = is_numeric($rawOverride) && (int) $rawOverride === 1;
    if (!$override) {
        return $globalRecipients;
    }
    $csv = $row['notify_recipients'] ?? null;
    if (!is_string($csv) || trim($csv) === '') {
        return $globalRecipients;
    }
    $parts = array_values(array_filter(
        array_map('trim', explode(',', $csv)),
        static fn($s) => $s !== ''
    ));
    return $parts === [] ? $globalRecipients : $parts;
}

/* ---------------- Pagination ---------------- */

/**
 * Escape SQL LIKE wildcard characters in a user-supplied search string.
 * Returns the escaped string ready to be wrapped in % delimiters.
 * Use with `LIKE :q ESCAPE '!'` in your SQL.
 *
 * Uses `!` as the escape character because it is a normal character in
 * both SQLite and MySQL string literals — whereas `\\` is parsed as an
 * escape sequence inside single-quoted strings by MySQL (so `ESCAPE '\\'`
 * in PHP source, which renders as `ESCAPE '\'` in SQL text, is a syntax
 * error on MySQL). `!` avoids the cross-engine quoting landmine.
 */
function like_escape(string $q): string
{
    return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
}

/**
 * Parse and validate ?sort=col&dir=asc|desc from $_GET.
 *
 * @param array<string,string> $allowed  Map of GET param value → SQL column expression (whitelisted)
 * @param string $defaultCol  Key in $allowed to use when no valid sort param is provided
 * @param string $defaultDir  'asc' or 'desc'
 * @return array{col: string, dir: string, sql: string}
 */
function parse_sort(array $allowed, string $defaultCol, string $defaultDir = 'asc'): array
{
    $col = to_str($_GET['sort'] ?? $defaultCol);
    $dir = strtolower(to_str($_GET['dir'] ?? $defaultDir));
    if (!isset($allowed[$col])) $col = $defaultCol;
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = $defaultDir;
    return ['col' => $col, 'dir' => $dir, 'sql' => $allowed[$col] . ' ' . strtoupper($dir)];
}

/* ---------------- Login protection (#124) ---------------- */

/**
 * Verify a reCAPTCHA token against the Enterprise Assessment API.
 * Returns null on pass, error string on fail, null on network error (fail-open).
 *
 * @param array{enabled: bool, project_id: string, api_key: string, expected_action: string, score_threshold: float} $cfg
 */
function recaptcha_enterprise_verify(string $token, string $siteKey, array $cfg): ?string
{
    $projectId      = to_str($cfg['project_id']);
    $apiKey         = to_str($cfg['api_key']);
    $expectedAction = to_str($cfg['expected_action']);
    $threshold      = (float)$cfg['score_threshold'];

    if ($projectId === '' || $apiKey === '') {
        error_log('reCAPTCHA Enterprise: project_id and api_key must be configured.');
        return null; // fail open — misconfiguration should not block users
    }

    $url     = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey);
    $payload = (string)json_encode(['event' => ['token' => $token, 'expectedAction' => $expectedAction, 'siteKey' => $siteKey]]);
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 10,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    try {
        $raw = @file_get_contents($url, false, $ctx, 0, 1048576);
        if ($raw === false) {
            error_log('reCAPTCHA Enterprise: HTTP request failed.');
            return null;
        }
        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            error_log('reCAPTCHA Enterprise: Invalid JSON response.');
            return null;
        }
        /** @var array<string, mixed> $resp */
        $tokenProps  = is_array($resp['tokenProperties'] ?? null) ? $resp['tokenProperties'] : [];
        $riskAnalysis = is_array($resp['riskAnalysis'] ?? null)   ? $resp['riskAnalysis']   : [];

        if (!empty($tokenProps['invalid'])) return 'Security check failed. Please try again.';
        if ($expectedAction !== '' && isset($tokenProps['action']) && to_str($tokenProps['action']) !== $expectedAction) {
            return 'Security check failed. Please try again.';
        }

        $score = (isset($riskAnalysis['score']) && is_numeric($riskAnalysis['score'])) ? (float)$riskAnalysis['score'] : 0.0;
        return $score >= $threshold ? null : 'Security check failed. Please try again.';
    } catch (Throwable $e) {
        error_log('reCAPTCHA Enterprise verify error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resolve the reCAPTCHA v3 expected action name, honouring the legacy
 * top-level $config['recaptcha_action'] key (documented since #289) before
 * falling back to the v2.6.0 registry key recaptcha_enterprise.expected_action.
 * Both the widget render and the Enterprise verify path must go through this
 * helper so the action emitted in the hidden input matches the action checked
 * during verification — otherwise valid Enterprise tokens fail action matching.
 */
function recaptcha_expected_action_resolved(): string
{
    $legacyCfg    = $GLOBALS['config'] ?? null;
    $legacyAction = is_array($legacyCfg) ? ($legacyCfg['recaptcha_action'] ?? null) : null;
    $resolved = (is_string($legacyAction) && $legacyAction !== '')
        ? $legacyAction
        : to_str(ipam_setting('recaptcha_enterprise.expected_action'));
    return $resolved !== '' ? $resolved : 'login';
}

/**
 * Verify the login form protection token/field for the current POST request.
 *
 * Returns null on pass, '' for a silent honeypot rejection (no error shown),
 * or a non-empty error string that should be shown to the user.
 * Fails open on network errors so a broken CAPTCHA provider never blocks login.
 *
 * @param array<string, mixed> $config Stub config (demo_gate) or empty array (login.php); falls back to ipam_setting().
 * @param array<string, mixed> $post
 */
function login_protection_verify(array $config, array $post): ?string
{
    // demo_gate.php passes its own stub; fall back to ipam_setting() for login.php
    $raw = $config['login_protection'] ?? [];
    $lp  = is_array($raw) ? $raw : [];
    $cfg = fn(string $k): mixed => array_key_exists($k, $lp) ? $lp[$k] : ipam_setting("login_protection.{$k}");

    $method = to_str($cfg('method'));
    if ($method === '' || $method === 'null') return null;

    if ($method === 'honeypot') {
        return ($post['website'] ?? '') !== '' ? '' : null;
    }

    if ($method === 'time_check') {
        $min = max(1, to_int($cfg('min_seconds')));
        $ts  = to_int($_SESSION['login_form_at'] ?? 0);
        unset($_SESSION['login_form_at']);
        if ($ts === 0 || (time() - $ts) < $min) {
            return 'Form submission was too fast. Please wait a moment and try again.';
        }
        return null;
    }

    $secretKey = to_str($cfg('secret_key'));
    $siteKey   = to_str($cfg('site_key'));

    if ($method === 'turnstile') {
        $token = to_str($post['cf-turnstile-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';
        try {
            $resp = oidc_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('Turnstile verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'hcaptcha') {
        $token = to_str($post['h-captcha-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';
        try {
            $resp = oidc_http_post('https://hcaptcha.com/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('hCaptcha verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'recaptcha') {
        $token = to_str($post['g-recaptcha-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';

        // Use Enterprise API if configured; fall back to standard reCAPTCHA API
        if ((bool)ipam_setting('recaptcha_enterprise.enabled')) {
            $rawThreshold = ipam_setting('recaptcha_enterprise.score_threshold');
            $enterprise = [
                'enabled'         => true,
                'project_id'      => to_str(ipam_setting('recaptcha_enterprise.project_id')),
                'api_key'         => to_str(ipam_setting('recaptcha_enterprise.api_key')),
                // Must match the action the widget emits (see
                // recaptcha_expected_action_resolved for the precedence rules).
                'expected_action' => recaptcha_expected_action_resolved(),
                'score_threshold' => is_numeric($rawThreshold) ? (float)$rawThreshold : 0.5,
            ];
            return recaptcha_enterprise_verify($token, $siteKey, $enterprise);
        }

        try {
            $resp = oidc_http_post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('reCAPTCHA verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'friendly_captcha') {
        $token = to_str($post['frc-captcha-solution'] ?? '');
        if ($token === '' || $token === '.UNSTARTED') return 'Please complete the security check.';
        if ($token === '.FETCHING') return null; // in-progress, fail open
        try {
            $resp = oidc_http_post('https://api.friendlycaptcha.com/api/v1/siteverify', [
                'secret'  => $secretKey,
                'solution'=> $token,
                'sitekey' => $siteKey,
            ]);
        } catch (Throwable $e) {
            error_log('FriendlyCaptcha verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    return null; // unknown method — pass through
}

/**
 * Return the HTML widget snippet to embed in the login/gate form.
 * For time_check, also sets the session timestamp on GET requests.
 *
 * @param array<string, mixed> $config Stub config (demo_gate) or empty array (login.php); falls back to ipam_setting().
 */
function login_protection_widget_html(array $config): string
{
    $raw = $config['login_protection'] ?? [];
    $lp  = is_array($raw) ? $raw : [];
    $cfg = fn(string $k): mixed => array_key_exists($k, $lp) ? $lp[$k] : ipam_setting("login_protection.{$k}");

    $method  = to_str($cfg('method'));
    $siteKey = e(to_str($cfg('site_key')));

    switch ($method) {
        case 'honeypot':
            return "<input type='text' name='website' autocomplete='off' tabindex='-1' aria-hidden='true' class='hidden'>";
        case 'time_check':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $_SESSION['login_form_at'] = time();
            }
            return ''; // no visible widget
        case 'turnstile':
            return "<script src='https://challenges.cloudflare.com/turnstile/v0/api.js' async defer></script>"
                 . "<div class='cf-turnstile' data-sitekey='{$siteKey}'></div>";
        case 'hcaptcha':
            return "<script src='https://js.hcaptcha.com/1/api.js' async defer></script>"
                 . "<div class='h-captcha' data-sitekey='{$siteKey}'></div>";
        case 'recaptcha':
            $ver   = to_int($cfg('version'));
            $isEnt = (bool)ipam_setting('recaptcha_enterprise.enabled');
            if ($ver === 3) {
                $scriptSrc    = $isEnt
                    ? "https://www.google.com/recaptcha/enterprise.js?render={$siteKey}"
                    : "https://www.google.com/recaptcha/api.js?render={$siteKey}";
                $entAttr      = $isEnt ? " data-recaptcha-enterprise='1'" : '';
                $action       = e(recaptcha_expected_action_resolved());
                $actionAttr   = " data-recaptcha-action='{$action}'";
                return "<script src='{$scriptSrc}' async defer></script>"
                     . "<input type='hidden' name='g-recaptcha-response' id='g-recaptcha-response' data-recaptcha-v3-key='{$siteKey}'{$entAttr}{$actionAttr}>";
            }
            return "<script src='https://www.google.com/recaptcha/api.js' async defer></script>"
                 . "<div class='g-recaptcha' data-sitekey='{$siteKey}'></div>";
        case 'friendly_captcha':
            return "<script src='https://cdn.jsdelivr.net/npm/friendly-challenge@latest/widget.module.min.js' async defer></script>"
                 . "<div class='frc-captcha' data-sitekey='{$siteKey}'></div>";
        default:
            return '';
    }
}

/**
 * Return extra CSP directives needed for the active login protection method.
 * Returns ['script_src' => '...', 'frame_src' => '...'] — either may be empty.
 * Turnstile, hCaptcha, and reCAPTCHA render inside an iframe so frame_src must
 * be explicitly allowed; Friendly Captcha uses Web Components (no iframe needed).
 */
/**
 * @param array<string, mixed> $config Stub config (demo_gate) or empty array (login.php); falls back to ipam_setting().
 * @return array{script_src: string, style_src: string, frame_src: string}
 */
function login_protection_extra_csp(array $config): array
{
    $raw = $config['login_protection'] ?? [];
    $lp  = is_array($raw) ? $raw : [];
    $method = to_str(array_key_exists('method', $lp) ? $lp['method'] : ipam_setting('login_protection.method'));
    return match ($method) {
        'turnstile'        => [
            'script_src' => 'https://challenges.cloudflare.com',
            'style_src'  => "'unsafe-inline'",
            'frame_src'  => 'https://challenges.cloudflare.com',
        ],
        'hcaptcha'         => [
            'script_src' => 'https://hcaptcha.com https://assets.hcaptcha.com',
            'style_src'  => '',
            'frame_src'  => 'https://newassets.hcaptcha.com',
        ],
        'recaptcha'        => [
            'script_src' => 'https://www.google.com https://www.gstatic.com',
            'style_src'  => '',
            'frame_src'  => 'https://www.google.com',
        ],
        'friendly_captcha' => [
            'script_src' => 'https://cdn.jsdelivr.net',
            'style_src'  => '',
            'frame_src'  => '',
        ],
        default            => ['script_src' => '', 'style_src' => '', 'frame_src' => ''],
    };
}

/* ---------------- Auto-reserve IPs ---------------- */

/**
 * Auto-reserve the network address, broadcast address (IPv4), and optionally
 * a gateway IP immediately after a subnet is created.
 *
 * IPv4: reserves network (x.x.x.0) and broadcast (x.x.x.255) as status=reserved.
 * IPv6: reserves network address only (broadcast concept does not apply).
 * Gateway (if provided): reserved as status=reserved with hostname 'gateway'.
 *
 * Skips any IP that is already assigned (INSERT OR IGNORE).
 */
function auto_reserve_subnet_ips(PDO $db, int $subnetId, string $cidr, ?string $gateway): void
{
    $p = parse_cidr($cidr);
    if (!$p) return;

    // Wrap the (up to) three INSERT + audit pairs in a transaction so a failure
    // partway through doesn't leave the subnet half-reserved (e.g. network +
    // broadcast committed, gateway audit dies, original v3.25.0 behaviour left
    // a row without its audit trail). Honour an outer transaction if one is
    // already open — callers like subnet create/update wrap their own.
    $owns = !$db->inTransaction();
    if ($owns) $db->beginTransaction();

    try {
    // #379/#410: bind ip_bin via ipam_bind_binary() (PARAM_LOB) so the stored
    // affinity is BLOB on SQLite and bytes round-trip safely on MySQL/Postgres.
    // The scalar params still come through bindValue so we can mix the two
    // binding styles on a single statement.
    $ignoreClause = ipam_dialect()->upsert_or_ignore('addresses', ['subnet_id', 'ip']);
    $ins = $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status, owner, note, grp, mac)
         VALUES (:sid, :ip, :ipbin, :host, 'reserved', '', 'Auto-reserved', '', '') $ignoreClause"
    );
    // Returns the new row's id on a fresh insert, or 0 when the row was
    // ignored by the upsert-or-ignore clause. Using rowCount() instead of
    // lastInsertId() is critical: on MySQL, lastInsertId() after a
    // duplicate-key no-op returns the previous successful insert id, which
    // would otherwise produce a bogus address.create audit entry.
    $reserveBind = function (int $sid, string $ip, string $bin, string $host) use ($ins, $db): int {
        $ins->bindValue(':sid',  $sid,  PDO::PARAM_INT);
        $ins->bindValue(':ip',   $ip,   PDO::PARAM_STR);
        ipam_bind_binary($ins, ':ipbin', $bin);
        $ins->bindValue(':host', $host, PDO::PARAM_STR);
        $ins->execute();
        return $ins->rowCount() > 0 ? ipam_last_insert_id($db, 'addresses') : 0;
    };

    // Network address
    $netIp  = $p['network'];
    $netBin = $p['net_bin'];
    $newId = $reserveBind($subnetId, $netIp, $netBin, 'network');
    if ($newId > 0) {
        audit($db, 'address.create', 'address', $newId, "auto-reserve network $netIp in subnet $subnetId");
    }

    if ($p['version'] === 4) {
        // Broadcast address for IPv4 — uses the same helper as the scanner so
        // /31 (RFC 3021 point-to-point) and /32 correctly reserve no broadcast
        // and the UI agrees with the scanner's reserved set.
        $bcastBin = ipam_compute_broadcast_bin($netBin, $p['prefix']);
        if ($bcastBin !== null) {
            $bcastIp = inet_ntop($bcastBin) ?: '';
            if ($bcastIp !== '' && $bcastIp !== $netIp) {
                $newId = $reserveBind($subnetId, $bcastIp, $bcastBin, 'broadcast');
                if ($newId > 0) {
                    audit($db, 'address.create', 'address', $newId, "auto-reserve broadcast $bcastIp in subnet $subnetId");
                }
            }
        }
    }

    // Gateway (optional, any version)
    if ($gateway !== null && $gateway !== '') {
        $gwNorm = normalize_ip($gateway);
        if ($gwNorm && ip_in_cidr($gwNorm['ip'], $p['network'], $p['prefix'])) {
            $newId = $reserveBind($subnetId, $gwNorm['ip'], $gwNorm['bin'], 'gateway');
            if ($newId > 0) {
                audit($db, 'address.create', 'address', $newId, "auto-reserve gateway {$gwNorm['ip']} in subnet $subnetId");
            }
        }
    }
        if ($owns) $db->commit();
    } catch (\Throwable $e) {
        // Same PHPStan 2.1.54 narrowing as ipam_setting_set above:
        // wrap rollBack() in try/catch so a "no active transaction"
        // PDOException does not mask the original exception.
        if ($owns) {
            try { $db->rollBack(); } catch (\Throwable) {}
        }
        throw $e;
    }
}

/* ---------------- Tags ---------------- */

/**
 * Return tags for a given entity (subnet or address).
 * @return array<array{id: int, name: string, colour: string}>
 */
function get_tags_for_entity(PDO $db, string $type, int $id): array
{
    if ($type === 'subnet') {
        $sql = "SELECT t.id, t.name, t.colour FROM tags t JOIN subnet_tags st ON st.tag_id = t.id WHERE st.subnet_id = :id ORDER BY t.name";
    } elseif ($type === 'address') {
        $sql = "SELECT t.id, t.name, t.colour FROM tags t JOIN address_tags at ON at.tag_id = t.id WHERE at.address_id = :id ORDER BY t.name";
    } else {
        return [];
    }
    $st = $db->prepare($sql);
    $st->execute([':id' => $id]);
    return array_map(fn($r) => ['id' => to_int($r['id']), 'name' => to_str($r['name']), 'colour' => to_str($r['colour'])], $st->fetchAll());
}

/**
 * Replace all tags for a given entity with the supplied tag IDs.
 * @param list<int> $tagIds
 */
function save_tags_for_entity(PDO $db, string $type, int $id, array $tagIds): void
{
    if ($type === 'subnet') {
        $db->prepare("DELETE FROM subnet_tags WHERE subnet_id = :id")->execute([':id' => $id]);
        $ins = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (:eid, :tid) " . ipam_dialect()->upsert_or_ignore("subnet_tags", ["subnet_id", "tag_id"]) . "");
    } elseif ($type === 'address') {
        $db->prepare("DELETE FROM address_tags WHERE address_id = :id")->execute([':id' => $id]);
        $ins = $db->prepare("INSERT INTO address_tags (address_id, tag_id) VALUES (:eid, :tid) " . ipam_dialect()->upsert_or_ignore("address_tags", ["address_id", "tag_id"]) . "");
    } else {
        return;
    }
    foreach ($tagIds as $tid) {
        $ins->execute([':eid' => $id, ':tid' => $tid]);
    }
}

/**
 * Get contacts assigned to a site or subnet.
 * @return list<array{id: int, name: string, email: string, role: string}>
 */
function get_contacts_for_entity(PDO $db, string $type, int $id): array
{
    if ($type === 'site') {
        $sql = "SELECT c.id, c.name, c.email, sc.role FROM contacts c JOIN site_contacts sc ON sc.contact_id = c.id WHERE sc.site_id = :id ORDER BY c.name";
    } elseif ($type === 'subnet') {
        $sql = "SELECT c.id, c.name, c.email, sc.role FROM contacts c JOIN subnet_contacts sc ON sc.contact_id = c.id WHERE sc.subnet_id = :id ORDER BY c.name";
    } else {
        return [];
    }
    $st = $db->prepare($sql);
    $st->execute([':id' => $id]);
    /** @var list<array<string, mixed>> $rows */
    $rows = $st->fetchAll();
    return array_map(fn($r) => [
        'id'    => to_int($r['id']),
        'name'  => to_str($r['name']),
        'email' => to_str($r['email']),
        'role'  => to_str($r['role']),
    ], $rows);
}

/**
 * Replace all contact assignments for a site or subnet.
 * @param list<array{contact_id: int, role: string}> $contacts
 */
function save_contacts_for_entity(PDO $db, string $type, int $id, array $contacts): void
{
    if ($type === 'site') {
        $delSql = "DELETE FROM site_contacts WHERE site_id = :id";
        $insSql = "INSERT INTO site_contacts (site_id, contact_id, role) VALUES (:eid, :cid, :role)";
    } elseif ($type === 'subnet') {
        $delSql = "DELETE FROM subnet_contacts WHERE subnet_id = :id";
        $insSql = "INSERT INTO subnet_contacts (subnet_id, contact_id, role) VALUES (:eid, :cid, :role)";
    } else {
        return;
    }
    $seen = [];
    $deduped = [];
    foreach ($contacts as $c) {
        $cid = $c['contact_id'];
        if (isset($seen[$cid])) continue;
        $seen[$cid] = true;
        $deduped[] = $c;
    }
    $wasInTxn = $db->inTransaction();
    if (!$wasInTxn) $db->beginTransaction();
    try {
        $db->prepare($delSql)->execute([':id' => $id]);
        $ins = $db->prepare($insSql);
        foreach ($deduped as $c) {
            $ins->execute([':eid' => $id, ':cid' => $c['contact_id'], ':role' => $c['role']]);
        }
        if (!$wasInTxn) $db->commit();
    } catch (\Throwable $e) {
        if (!$wasInTxn && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Parse contact_id[] and contact_role[] from POST data.
 * @param array<string, mixed> $post
 * @return list<array{contact_id: int, role: string}>
 */
function parse_contact_assignments(array $post): array
{
    $ids   = (array)($post['contact_id'] ?? []);
    $roles = (array)($post['contact_role'] ?? []);
    $out = [];
    foreach ($ids as $i => $raw) {
        $cid = to_int($raw);
        if ($cid <= 0) continue;
        $out[] = ['contact_id' => $cid, 'role' => trim(to_str($roles[$i] ?? ''))];
    }
    return $out;
}

/* ---------------- Demo mode ---------------- */

function demo_mode_enabled(): bool
{
    /** @var IpamConfig $gConf */
    $gConf = $GLOBALS['config'];
    return !empty($gConf['demo_mode']['enabled']);
}

function demo_require_reset(): bool
{
    $marker = __DIR__ . '/data/demo_last_reset.txt';
    if (!is_file($marker)) return true;
    $lastReset = (int)file_get_contents($marker);
    $midnight  = mktime(0, 0, 0);
    return $lastReset < $midnight;
}

/* ---------------- CSV export helpers ---------------- */

function safe_export_filename(string $base): string
{
    // Preserve original extension (e.g. .conf, .json) — strip it, sanitize
    // the stem, then re-attach so non-CSV downloads keep the right extension.
    $ext  = pathinfo($base, PATHINFO_EXTENSION);
    $stem = pathinfo($base, PATHINFO_FILENAME);
    $ext  = $ext !== '' ? '.' . strtolower(preg_replace('/[^a-z0-9]+/i', '', $ext) ?? '') : '.csv';
    $stem = strtolower($stem);
    $stem = preg_replace('/[^a-z0-9._-]+/', '-', $stem) ?? 'export';
    $stem = trim($stem, '-.');
    if ($stem === '') $stem = 'export';
    return $stem . '-' . date('Y-m-d-His') . $ext;
}

function csv_download_headers(string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility
}

/** @return resource */
function csv_output_handle()
{
    static $fh = null;
    if ($fh === null) {
        $fh = fopen('php://output', 'wb');
        if (!$fh) throw new RuntimeException('Cannot open php://output');
    }
    return $fh;
}

/** @param list<string> $row */
function csv_out(array $row): void
{
    $fh = csv_output_handle();
    // CodeRabbit C1 (PR #450): defuse spreadsheet formula injection. Excel /
    // LibreOffice / Numbers treat any cell starting with =, +, -, @, TAB, CR
    // or LF as a formula. Prefix offending cells with a single quote so they
    // render as literal text. Applies to every CSV export site-wide because
    // every export goes through this helper.
    $sanitized = array_map(static function (string $cell): string {
        if ($cell === '') return $cell;
        $first = $cell[0];
        if ($first === '=' || $first === '+' || $first === '-' || $first === '@'
            || $first === "\t" || $first === "\r" || $first === "\n") {
            return "'" . $cell;
        }
        return $cell;
    }, $row);
    fputcsv($fh, $sanitized, ',', '"', '');
}

/* ---------------- Install-key banner dismiss handler ---------------- */
/* The banner renderers (render_security_banner, render_install_key_banner) */
/* moved to lib/presentation.php in v3.30.0 (ADR-004 Task 5.1, #910). This  */
/* POST handler stays here — page_header() calls it before any HTML output. */

/**
 * v3.28.2 #1178 — handle the install-key banner dismiss POST.
 *
 * Must run BEFORE any HTML output: csrf_require() emits a 403 response
 * (status + body) on a bad/missing token, which is impossible once
 * page_header() has flushed `<!doctype html>`. Called from the top of
 * page_header() so every admin page can be the dismiss target without
 * each entry script having to wire it explicitly.
 *
 * On a successful dismiss, the function redirects to the same URL via
 * GET so a refresh of the resulting page does not re-POST the dismiss
 * action.
 */
function ipam_install_key_banner_handle_dismiss(PDO $db, string $role): void
{
    if ($role !== 'admin') {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || (($_POST['action'] ?? '') !== 'dismiss_install_key_banner')) {
        return;
    }
    csrf_require();
    $postedKey = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    if ($postedKey !== 'app_secret' && $postedKey !== 'bootstrap_key') {
        return;
    }
    try {
        _ipam_install_key_announce_write($db, 'install_keys_announce.' . $postedKey, '0');
    } catch (\Throwable $e) {
        error_log('[ipam_install_key_banner_handle_dismiss] ' . $postedKey . ': ' . $e->getMessage());
    }
    // Redirect to the same URL via GET so the dismiss is idempotent on
    // refresh (no stale POST in the navigation history) and the banner
    // is gone on the next render. Reflecting raw REQUEST_URI into
    // Location would accept a protocol-relative target like
    // `//attacker.example/...` and redirect cross-origin; rebuild the
    // target from a parsed path/query that's guaranteed same-origin.
    // Also reject backslashes (browsers normalise `/\evil.example` to
    // `//evil.example` cross-origin) and CR/LF (header-injection).
    $self  = to_str($_SERVER['REQUEST_URI'] ?? '/');
    $parts = parse_url($self);
    $path  = is_array($parts)
        && is_string($parts['path'] ?? null)
        && ($parts['path'][0] ?? '') === '/'
        && !str_starts_with($parts['path'], '//')
        && !str_contains($parts['path'], '\\')
        && preg_match('/[\r\n]/', $parts['path']) !== 1
        ? $parts['path']
        : '/';
    $query = is_array($parts)
        && is_string($parts['query'] ?? null)
        && $parts['query'] !== ''
        && preg_match('/[\r\n]/', $parts['query']) !== 1
        ? '?' . $parts['query']
        : '';
    header('Location: ' . $path . $query, true, 303);
    exit;
}

/* ---------------- Demo mode seed/reset ---------------- */

function demo_reset_db(PDO $db): void
{
    $driver = ipam_dialect()->driver_name();

    // audit_log has append-only triggers that block DELETE, so bypass via rename+drop,
    // then immediately recreate the table and triggers.
    $db->exec("ALTER TABLE audit_log RENAME TO audit_log_old");
    $db->exec("DROP TABLE audit_log_old");
    ensure_audit_log_table($db);

    // Clear in FK-safe order; CASCADE removes subnet_tags, address_tags, alert_state.
    // schema_migrations is only wiped on SQLite, where the historical migration
    // closures are expected to re-run after the reset. On MySQL / Postgres,
    // schema.{engine}.sql pre-seeds schema_migrations with every historical
    // version row at fresh-install time (v2.10.0 #484 decision), and the
    // historical closures use SQLite-specific PRAGMA / sqlite_master queries
    // that would fail on other engines. Keep the pre-seed intact so
    // apply_migrations() remains a no-op.
    $tables = ['address_history', 'login_attempts', 'api_keys',
               'addresses', 'subnets', 'vlans', 'vrfs', 'contacts', 'tags',
               'sites', 'users'];
    if ($driver === 'sqlite') {
        $tables[] = 'schema_migrations';
    }
    foreach ($tables as $t) {
        $db->exec("DELETE FROM $t");
        // Rewind the table's auto-increment counter so subsequent inserts
        // start at 1 and the fixture IDs that demo_seed_data passes
        // explicitly land deterministically on every reset. Critical on
        // MySQL: DELETE alone does NOT rewind AUTO_INCREMENT, so on a
        // second reset any row inserted WITHOUT an explicit id (e.g. the
        // audit rows written by audit()) would carry a drifted id and
        // break fixtures that reference it by id.
        if ($driver === 'sqlite') {
            $db->exec("DELETE FROM sqlite_sequence WHERE name='$t'");
        } elseif ($driver === 'mysql') {
            $db->exec("ALTER TABLE $t AUTO_INCREMENT = 1");
        } elseif ($driver === 'pgsql') {
            $db->exec("ALTER SEQUENCE {$t}_id_seq RESTART WITH 1");
        }
    }
    if ($driver === 'sqlite') {
        $db->exec("DELETE FROM sqlite_sequence WHERE name='audit_log'");
    } elseif ($driver === 'mysql') {
        $db->exec("ALTER TABLE audit_log AUTO_INCREMENT = 1");
    } elseif ($driver === 'pgsql') {
        $db->exec("ALTER SEQUENCE audit_log_id_seq RESTART WITH 1");
    }
    apply_migrations($db);
    demo_seed_data($db);
}

function demo_seed_data(PDO $db): void
{
    // --- Sites (id=5,6 are region parents inserted first for self-referential FK) ---
    $si = $db->prepare("INSERT INTO sites (id, name, description, parent_id) VALUES (?,?,?,?)");
    foreach ([
        [5, 'EMEA Region',     'Europe, Middle East & Africa', null],
        [6, 'Americas Region', 'North & South America',        null],
        [1, 'London HQ',       'Primary headquarters',         5],
        [2, 'New York DC',     'East coast data centre',       6],
        [3, 'Sydney Office',   'APAC regional office',         null],
        [4, 'AWS eu-west-1',   'Cloud infrastructure',         5],
        ] as $s) $si->execute($s);

    // --- VRFs ---
    $vr = $db->prepare("INSERT INTO vrfs (id, name, description, rd) VALUES (?,?,?,?)");
    foreach ([
        [1, 'DEFAULT',  'Default global routing table', '65000:0'],
        [2, 'MGMT-VRF', 'Management plane VRF',         '65000:100'],
        ] as $v) $vr->execute($v);

    // --- VLANs ---
    $vl = $db->prepare("INSERT INTO vlans (id, vlan_id, name, description, site_id) VALUES (?,?,?,?,?)");
    foreach ([
        [1, 10,  'Management',    'Out-of-band management VLAN',    1],
        [2, 20,  'Servers',       'Server infrastructure VLAN',      1],
        [3, 30,  'DMZ',           'Demilitarised zone',              1],
        [4, 100, 'Cloud-Connect', 'AWS Direct Connect peering VLAN', 4],
        ] as $v) $vl->execute($v);

    // --- Contacts ---
    $ct = $db->prepare("INSERT INTO contacts (id, name, email, phone, org, note) VALUES (?,?,?,?,?,?)");
    foreach ([
        [1, 'Alice Smith', 'alice@example.com', '+44 20 7946 0001', 'NetOps',   'Primary network contact'],
        [2, 'Bob Jones',   'bob@example.com',   '+44 20 7946 0002', 'DBA',      'Database team lead'],
        [3, 'Carol Wu',    'carol@example.com', '+1 212 555 0103',  'Security', 'Security operations engineer'],
        ] as $c) $ct->execute($c);

    // --- Tags ---
    $tg = $db->prepare("INSERT INTO tags (id, name, colour) VALUES (?,?,?)");
    foreach ([
        [1, 'Production',  '#28a745'],
        [2, 'Development', '#17a2b8'],
        [3, 'Critical',    '#dc3545'],
        [4, 'Monitored',   '#6c757d'],
        ] as $t) $tg->execute($t);

    // #379/#410: helper that binds the ten subnet columns onto a prepared
    // INSERT, routing network_bin through ipam_bind_binary() (PARAM_LOB) so
    // every demo-seeded row is BLOB-affinity from the start. Positional ? in
    // the prepare maps to 1-based bindValue indexes.
    $bindSubnetRow = function (PDOStatement $stmt, int $id, string $cidr, string $netNorm, string $netBin, int $pfx, string $desc, ?int $siteId, ?int $vlanFk, ?int $vrfId): void {
        $stmt->bindValue(1,  $id,      PDO::PARAM_INT);
        $stmt->bindValue(2,  $cidr,    PDO::PARAM_STR);
        $stmt->bindValue(3,  $netNorm, PDO::PARAM_STR);
        ipam_bind_binary($stmt, 4, $netBin);
        $stmt->bindValue(5,  $pfx,     PDO::PARAM_INT);
        $stmt->bindValue(6,  $desc,    PDO::PARAM_STR);
        $stmt->bindValue(7,  $siteId,  $siteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(8,  $vlanFk,  $vlanFk === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(9,  $vrfId,   $vrfId  === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    };

    // --- Subnets (IPv4) ---
    // [id, cidr, site_id, vlan_fk, vrf_id, description]
    $sn = $db->prepare(
        "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_fk, vrf_id)
         VALUES (?,?,4,?,?,?,?,?,?,?)"
    );
    foreach ([
        [1,  '10.0.0.0/8',    null, null, null, 'RFC-1918 supernet (informational)'],
        [2,  '10.10.0.0/16',  1,    null, null, 'London HQ corporate'],
        [3,  '10.10.1.0/24',  1,    1,    2,    'London management'],
        [4,  '10.10.2.0/24',  1,    2,    null, 'London servers'],
        [5,  '10.10.3.0/27',  1,    3,    null, 'London DMZ'],
        [6,  '10.20.0.0/16',  2,    null, null, 'New York DC corporate'],
        [7,  '10.20.1.0/24',  2,    null, null, 'New York servers'],
        [8,  '10.20.2.0/24',  2,    null, null, 'New York management'],
        [9,  '172.16.0.0/16', 3,    null, null, 'Sydney corporate'],
        [10, '172.16.1.0/24', 3,    null, null, 'Sydney servers'],
        ] as [$id, $cidr, $siteId, $vlanFk, $vrfId, $desc]) {
        [$net, $pfx] = explode('/', $cidr);
        $rawBin  = inet_pton($net) ?: throw new \RuntimeException("Invalid IP: $net");
        $netNorm = inet_ntop(apply_prefix_mask($rawBin, (int)$pfx)) ?: throw new \RuntimeException("inet_ntop failed");
        $netBin  = inet_pton($netNorm) ?: throw new \RuntimeException("inet_pton failed on $netNorm");
        $bindSubnetRow($sn, $id, $cidr, $netNorm, $netBin, (int)$pfx, $desc, $siteId, $vlanFk, $vrfId);
    }

    // --- Subnets (IPv6) ---
    $sn6 = $db->prepare(
        "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix, description, site_id, vlan_fk, vrf_id)
         VALUES (?,?,6,?,?,?,?,?,?,?)"
    );
    foreach ([
        [11, '2001:db8::/32',   1, null, null, 'London HQ IPv6 allocation'],
        [12, '2001:db8:1::/48', 1, null, null, 'London servers IPv6'],
        [13, '2001:db8:2::/64', 2, null, null, 'New York IPv6 segment'],
        ] as [$id, $cidr, $siteId, $vlanFk, $vrfId, $desc]) {
        [$net, $pfx] = explode('/', $cidr);
        $rawBin6  = inet_pton($net) ?: throw new \RuntimeException("Invalid IP: $net");
        $netNorm6 = inet_ntop(apply_prefix_mask($rawBin6, (int)$pfx)) ?: throw new \RuntimeException("inet_ntop failed");
        $netBin6  = inet_pton($netNorm6) ?: throw new \RuntimeException("inet_pton failed on $netNorm6");
        $bindSubnetRow($sn6, $id, $cidr, $netNorm6, $netBin6, (int)$pfx, $desc, $siteId, $vlanFk, $vrfId);
    }

    // --- Subnet tags ---
    $st = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (?,?)");
    foreach ([
        [4, 1], [4, 4],   // London servers: Production, Monitored
        [5, 1], [5, 3],   // London DMZ: Production, Critical
        [12, 2],          // London servers IPv6: Development
        ] as $t) $st->execute($t);

    // --- Users ---
    // demo: admin, password 'demo' | readonly-user / netops-user: locked accounts for display
    $us = $db->prepare(
        "INSERT INTO users (username, password_hash, role, is_active, name, email) VALUES (?,?,?,?,?,?)"
    );
    foreach ([
        ['demo',          password_hash('demo', PASSWORD_DEFAULT), 'admin',    1, 'Demo Admin',  'demo@example.com'],
        ['readonly-user', '!disabled',                              'readonly', 1, 'Read Only',   'readonly@example.com'],
        ['netops-user',   '!disabled',                              'netops',   1, 'NetOps User', 'netops@example.com'],
        ] as $u) $us->execute($u);

    // --- Addresses ---
    // [subnet_id, ip, hostname, owner, status, note, mac, expires_at, owner_contact_id]
    // Address IDs assigned sequentially: subnet 3 = 1–9, subnet 4 = 10–27,
    // subnet 5 = 28–37, subnet 7 = 38–45, subnet 8 = 46–49, subnet 10 = 50–54.
    $ai = $db->prepare(
        "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, status, note, mac, expires_at, owner_contact_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ([
        // 10.10.1.0/24 — London management (id=1..9)
        [3, '10.10.1.1',   'gw-lon-mgmt',    'NetOps',   'used',     'Default gateway',        'aa:bb:cc:00:01:01', null,         1],
        [3, '10.10.1.2',   'sw-lon-core-01', 'NetOps',   'used',     'Core switch',            'aa:bb:cc:00:01:02', null,         1],
        [3, '10.10.1.3',   'sw-lon-core-02', 'NetOps',   'used',     'Core switch redundant',  'aa:bb:cc:00:01:03', null,         1],
        [3, '10.10.1.10',  'mon-lon-01',     'NetOps',   'used',     'Monitoring server',      '',                  null,         null],
        [3, '10.10.1.20',  'ntp-lon-01',     'NetOps',   'used',     'NTP server',             '',                  null,         null],
        [3, '10.10.1.30',  'dns-lon-01',     'NetOps',   'used',     'Primary DNS',            '',                  null,         null],
        [3, '10.10.1.31',  'dns-lon-02',     'NetOps',   'used',     'Secondary DNS',          '',                  null,         null],
        [3, '10.10.1.50',  '',               '',         'reserved', 'Reserved for IPMI',      '',                  null,         null],
        [3, '10.10.1.51',  '',               '',         'reserved', 'Reserved for IPMI',      '',                  null,         null],
        // 10.10.2.0/24 — London servers (id=10..27)
        [4, '10.10.2.1',   'gw-lon-srv',     'NetOps',   'used',     'Server gateway',         'aa:bb:cc:00:02:01', null,         1],
        [4, '10.10.2.10',  'web-lon-01',     'WebTeam',  'used',     'Web frontend 1',         'de:ad:be:ef:00:01', '2027-06-30', null],
        [4, '10.10.2.11',  'web-lon-02',     'WebTeam',  'used',     'Web frontend 2',         'de:ad:be:ef:00:02', '2027-06-30', null],
        [4, '10.10.2.12',  'web-lon-03',     'WebTeam',  'used',     'Web frontend 3',         'de:ad:be:ef:00:03', '2027-06-30', null],
        [4, '10.10.2.20',  'app-lon-01',     'AppTeam',  'used',     'Application server 1',   '',                  null,         null],
        [4, '10.10.2.21',  'app-lon-02',     'AppTeam',  'used',     'Application server 2',   '',                  null,         null],
        [4, '10.10.2.22',  'app-lon-03',     'AppTeam',  'used',     'Application server 3',   '',                  null,         null],
        [4, '10.10.2.30',  'db-lon-01',      'DBA',      'used',     'Primary database',       'fa:ce:b0:00:00:01', null,         2],
        [4, '10.10.2.31',  'db-lon-02',      'DBA',      'used',     'Replica database',       'fa:ce:b0:00:00:02', null,         2],
        [4, '10.10.2.32',  'db-lon-03',      'DBA',      'used',     'Backup database',        'fa:ce:b0:00:00:03', null,         2],
        [4, '10.10.2.40',  'cache-lon-01',   'AppTeam',  'used',     'Redis cache 1',          '',                  null,         null],
        [4, '10.10.2.41',  'cache-lon-02',   'AppTeam',  'used',     'Redis cache 2',          '',                  null,         null],
        [4, '10.10.2.50',  'storage-lon-01', 'Infra',    'used',     'NFS storage',            '',                  null,         null],
        [4, '10.10.2.51',  'storage-lon-02', 'Infra',    'used',     'NFS storage replica',    '',                  null,         null],
        [4, '10.10.2.100', 'backup-lon-01',  'Infra',    'used',     'Backup server',          '',                  null,         null],
        [4, '10.10.2.200', '',               '',         'free',     '',                        '',                  null,         null],
        [4, '10.10.2.201', '',               '',         'free',     '',                        '',                  null,         null],
        [4, '10.10.2.202', '',               '',         'free',     '',                        '',                  null,         null],
        // 10.10.3.0/27 — London DMZ (id=28..37)
        [5, '10.10.3.1',   'fw-lon-dmz',     'Security', 'used',     'DMZ firewall inside',    '00:50:56:a1:b2:c3', null,         3],
        [5, '10.10.3.2',   'proxy-lon-01',   'Security', 'used',     'Squid proxy',            '00:50:56:a1:b2:c4', null,         3],
        [5, '10.10.3.3',   'proxy-lon-02',   'Security', 'used',     'Squid proxy standby',    '00:50:56:a1:b2:c5', null,         3],
        [5, '10.10.3.4',   'waf-lon-01',     'Security', 'used',     'WAF node 1',             '',                  '2026-12-31', null],
        [5, '10.10.3.5',   'waf-lon-02',     'Security', 'used',     'WAF node 2',             '',                  '2026-12-31', null],
        [5, '10.10.3.6',   'mailgw-lon-01',  'Infra',    'used',     'Mail gateway',           '',                  null,         null],
        [5, '10.10.3.10',  'vpn-lon-01',     'NetOps',   'used',     'VPN concentrator',       '',                  null,         1],
        [5, '10.10.3.20',  '',               '',         'reserved', 'Future load balancer',   '',                  null,         null],
        [5, '10.10.3.21',  '',               '',         'reserved', 'Future load balancer',   '',                  null,         null],
        [5, '10.10.3.30',  '',               '',         'free',     '',                        '',                  null,         null],
        // 10.20.1.0/24 — New York servers (id=38..45)
        [7, '10.20.1.1',   'gw-nyc-srv',     'NetOps',   'used',     'NY server gateway',      '',                  null,         null],
        [7, '10.20.1.10',  'web-nyc-01',     'WebTeam',  'used',     'Web server NY 1',        '',                  '2027-03-31', null],
        [7, '10.20.1.11',  'web-nyc-02',     'WebTeam',  'used',     'Web server NY 2',        '',                  '2027-03-31', null],
        [7, '10.20.1.20',  'app-nyc-01',     'AppTeam',  'used',     'App server NY 1',        '',                  null,         null],
        [7, '10.20.1.21',  'app-nyc-02',     'AppTeam',  'used',     'App server NY 2',        '',                  null,         null],
        [7, '10.20.1.30',  'db-nyc-01',      'DBA',      'used',     'NY database',            '',                  null,         2],
        [7, '10.20.1.40',  'backup-nyc-01',  'Infra',    'used',     'NY backup server',       '',                  null,         null],
        [7, '10.20.1.200', '',               '',         'free',     '',                        '',                  null,         null],
        // 10.20.2.0/24 — New York management (id=46..49)
        [8, '10.20.2.1',   'gw-nyc-mgmt',   'NetOps',   'used',     'NY mgmt gateway',        '',                  null,         1],
        [8, '10.20.2.10',  'mon-nyc-01',    'NetOps',   'used',     'NY monitoring',           '',                  null,         null],
        [8, '10.20.2.20',  'ntp-nyc-01',    'NetOps',   'used',     'NY NTP',                  '',                  null,         null],
        [8, '10.20.2.30',  'dns-nyc-01',    'NetOps',   'used',     'NY DNS primary',          '',                  null,         null],
        // 172.16.1.0/24 — Sydney servers (id=50..54)
        [10, '172.16.1.1',  'gw-syd-srv',   'NetOps',   'used',     'Sydney server gateway',  '',                  null,         null],
        [10, '172.16.1.10', 'web-syd-01',   'WebTeam',  'used',     'Sydney web server',      '',                  null,         null],
        [10, '172.16.1.20', 'app-syd-01',   'AppTeam',  'used',     'Sydney app server',      '',                  null,         null],
        [10, '172.16.1.30', 'db-syd-01',    'DBA',      'used',     'Sydney database',        '',                  null,         null],
        [10, '172.16.1.100','',             '',          'free',     '',                        '',                  null,         null],
        ] as [$sid, $ip, $hn, $ow, $st, $nt, $mac, $exp, $cid]) {
        $bin = inet_pton($ip);
        if ($bin === false) throw new \RuntimeException("inet_pton failed on $ip");
        // #379/#410: bind ip_bin via ipam_bind_binary() (PARAM_LOB) so demo
        // seed rows are BLOB affinity from the start. Other params use
        // bindValue with explicit PARAM_* so the prepare's positional ?
        // placeholders bind cleanly (no execute(array) shorthand).
        $ai->bindValue(1,  $sid, PDO::PARAM_INT);
        $ai->bindValue(2,  $ip,  PDO::PARAM_STR);
        ipam_bind_binary($ai, 3, $bin);
        $ai->bindValue(4,  $hn,  PDO::PARAM_STR);
        $ai->bindValue(5,  $ow,  PDO::PARAM_STR);
        $ai->bindValue(6,  $st,  PDO::PARAM_STR);
        $ai->bindValue(7,  $nt,  PDO::PARAM_STR);
        $ai->bindValue(8,  $mac, PDO::PARAM_STR);
        $ai->bindValue(9,  $exp, $exp === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $ai->bindValue(10, $cid, $cid === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $ai->execute();
    }

    // --- Address tags ---
    // db-lon-01 id=17: Critical(3), Monitored(4) | db-lon-02 id=18: Monitored(4) | fw-lon-dmz id=28: Critical(3)
    //
    // We look up the target IDs by IP rather than hard-coding them because
    // MySQL and SQLite can disagree on the starting value and increment of
    // AUTO_INCREMENT under some configurations (especially when a schema
    // file has pre-populated another table with explicit IDs — which is
    // exactly what v2.10.0 schema.mysql.sql does for schema_migrations).
    // Locking the address_tags fixtures to the actual inserted row IDs
    // keeps the demo fixture engine-agnostic.
    $idByIp = [];
    $idSt = $db->prepare("SELECT id, ip FROM addresses WHERE ip IN ('10.10.2.30','10.10.2.31','10.10.3.1')");
    $idSt->execute();
    /** @var list<array<string, mixed>> $idRows */
    $idRows = $idSt->fetchAll();
    foreach ($idRows as $r) {
        $idByIp[to_str($r['ip'])] = to_int($r['id']);
    }
    $at = $db->prepare("INSERT INTO address_tags (address_id, tag_id) VALUES (?,?)");
    foreach ([
        // db-lon-01 → Critical + Monitored
        [$idByIp['10.10.2.30'] ?? 0, 3],
        [$idByIp['10.10.2.30'] ?? 0, 4],
        // db-lon-02 → Monitored
        [$idByIp['10.10.2.31'] ?? 0, 4],
        // fw-lon-dmz → Critical
        [$idByIp['10.10.3.1']  ?? 0, 3],
        ] as $t) {
        if ($t[0] > 0) $at->execute($t);
    }

    // --- API Keys ---
    $ak = $db->prepare(
        "INSERT INTO api_keys (name, key_hash, is_active, created_by) VALUES (?,?,?,?)"
    );
    $ak->execute(['Monitoring (active)',   hash('sha256', 'demo-api-key-monitoring-1234567890abcdef'), 1, 'demo']);
    $ak->execute(['Old script (inactive)', hash('sha256', 'demo-api-key-old-script-0987654321fedcba'), 0, 'demo']);

    // --- Audit log (backdated) ---
    // Compute the backdated created_at timestamp in PHP so the SQL stays
    // engine-agnostic. The last tuple element remains a human-readable
    // offset string ('-30 days', '-5 days', '-0 seconds', etc.) that
    // strtotime() parses directly, and we format the result as ISO
    // 'YYYY-MM-DD HH:MM:SS' UTC which both SQLite TEXT storage and
    // MySQL DATETIME accept.
    $al = $db->prepare(
        "INSERT INTO audit_log (action, entity_type, entity_id, username, ip, details, created_at)
         VALUES (?,?,?,?,?,?,?)"
    );
    foreach ([
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-30 days'],
        ['subnet.create',     'subnet',  3,    'demo',          '192.168.1.100', 'cidr=10.10.1.0/24',                      '-29 days'],
        ['subnet.create',     'subnet',  4,    'demo',          '192.168.1.100', 'cidr=10.10.2.0/24',                      '-29 days'],
        ['address.create',    'address', 1,    'demo',          '192.168.1.100', 'ip=10.10.1.1 subnet_id=3',               '-28 days'],
        ['address.create',    'address', 2,    'demo',          '192.168.1.100', 'ip=10.10.1.2 subnet_id=3',               '-28 days'],
        ['user.create',       'user',    2,    'demo',          '192.168.1.100', 'username=readonly-user role=readonly',   '-27 days'],
        ['user.create',       'user',    3,    'demo',          '192.168.1.100', 'username=netops-user role=netops',        '-27 days'],
        ['auth.login',        'user',    2,    'readonly-user', '10.10.1.55',    'login ok',                               '-26 days'],
        ['address.update',    'address', 11,   'demo',          '192.168.1.100', 'hostname=web-lon-01',                    '-25 days'],
        ['address.update',    'address', 12,   'demo',          '192.168.1.100', 'hostname=web-lon-02',                    '-25 days'],
        ['subnet.create',     'subnet',  5,    'demo',          '192.168.1.100', 'cidr=10.10.3.0/27',                      '-24 days'],
        ['address.create',    'address', 28,   'demo',          '192.168.1.100', 'ip=10.10.3.1 subnet_id=5',               '-23 days'],
        ['apikey.create',     'api_key', 1,    'demo',          '192.168.1.100', 'name=Monitoring (active)',                '-22 days'],
        ['apikey.create',     'api_key', 2,    'demo',          '192.168.1.100', 'name=Old script (inactive)',              '-22 days'],
        ['apikey.deactivate', 'api_key', 2,    'demo',          '192.168.1.100', '',                                       '-21 days'],
        ['site.create',       'site',    2,    'demo',          '192.168.1.100', 'name=New York DC',                       '-20 days'],
        ['site.create',       'site',    3,    'demo',          '192.168.1.100', 'name=Sydney Office',                     '-20 days'],
        ['auth.login',        'user',    3,    'netops-user',   '10.20.1.55',    'login ok',                               '-19 days'],
        ['address.create',    'address', 38,   'demo',          '192.168.1.100', 'ip=10.20.1.1 subnet_id=7',               '-18 days'],
        ['address.create',    'address', 50,   'demo',          '192.168.1.100', 'ip=172.16.1.1 subnet_id=10',             '-17 days'],
        ['vlan.create',       'vlan',    1,    'demo',          '192.168.1.100', 'vlan_id=10 name=Management',             '-16 days'],
        ['vrf.create',        'vrf',     2,    'demo',          '192.168.1.100', 'name=MGMT-VRF rd=65000:100',             '-16 days'],
        ['contact.create',    'contact', 1,    'demo',          '192.168.1.100', 'name=Alice Smith',                       '-15 days'],
        ['contact.create',    'contact', 2,    'demo',          '192.168.1.100', 'name=Bob Jones',                         '-15 days'],
        ['auth.login_failed', 'user',    null, '',              '203.0.113.50',  'username=hacker',                        '-15 days'],
        ['auth.login_failed', 'user',    null, '',              '203.0.113.50',  'username=hacker',                        '-15 days'],
        ['auth.login_blocked','user',    null, '',              '203.0.113.50',  'ip=203.0.113.50',                        '-15 days'],
        ['tag.create',        'tag',     1,    'demo',          '192.168.1.100', 'name=Production colour=#28a745',         '-14 days'],
        ['address.update',    'address', 35,   'demo',          '192.168.1.100', 'status=reserved',                        '-10 days'],
        ['export.csv',        'address', null, 'demo',          '192.168.1.100', 'subnet_id=4',                            '-8 days'],
        ['address.create',    'address', 51,   'demo',          '192.168.1.100', 'ip=172.16.1.10 subnet_id=10',            '-5 days'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-3 days'],
        ['address.bulk_update','address',null, 'demo',          '192.168.1.100', 'subnet_id=4 selected=3 affected=3',      '-2 days'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-1 day'],
        ['auth.login',        'user',    1,    'demo',          '192.168.1.100', 'login ok',                               '-0 seconds'],
        ] as $e) {
        // Replace the human-readable offset (last element) with an
        // absolute 'YYYY-MM-DD HH:MM:SS' UTC timestamp computed in PHP.
        $offset = array_pop($e);
        $ts = strtotime((string)$offset);
        $e[] = ($ts !== false) ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        $al->execute($e);
    }

    // --- Address history ---
    // Address IDs looked up by IP so we don't hardcode AUTO_INCREMENT
    // positions (same rationale as the address_tags block above).
    // Backdated created_at timestamps computed in PHP because the SQLite
    // relative-time modifier syntax is not portable to MySQL.
    $histIds = [];
    $histIdSt = $db->prepare("SELECT id, ip FROM addresses WHERE ip IN ('10.10.1.1','10.10.2.10','10.10.2.12','10.10.2.20','10.10.3.1','10.10.3.20')");
    $histIdSt->execute();
    /** @var list<array<string, mixed>> $histIdRows */
    $histIdRows = $histIdSt->fetchAll();
    foreach ($histIdRows as $r) {
        $histIds[to_str($r['ip'])] = to_int($r['id']);
    }

    $hist = $db->prepare(
        "INSERT INTO address_history (address_id, subnet_id, ip, action, username, client_ip, before_json, after_json, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    foreach ([
        // gw-lon-mgmt
        [$histIds['10.10.1.1']  ?? 0, 3, '10.10.1.1',  'create', 'demo', '192.168.1.100', null,
         '{"hostname":"gw-lon-mgmt","owner":"NetOps","status":"used","note":"Default gateway","mac":"aa:bb:cc:00:01:01"}',
         '-28 days'],
        // web-lon-01
        [$histIds['10.10.2.10'] ?? 0, 4, '10.10.2.10', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"","owner":"WebTeam","status":"used","note":"","mac":""}',
         '-28 days'],
        [$histIds['10.10.2.10'] ?? 0, 4, '10.10.2.10', 'update', 'demo', '192.168.1.100',
         '{"hostname":"","owner":"WebTeam","status":"used","note":"","mac":""}',
         '{"hostname":"web-lon-01","owner":"WebTeam","status":"used","note":"Web frontend 1","mac":"de:ad:be:ef:00:01","expires_at":"2027-06-30"}',
         '-25 days'],
        // web-lon-03
        [$histIds['10.10.2.12'] ?? 0, 4, '10.10.2.12', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"web-lon-03","owner":"WebTeam","status":"used","note":"Web frontend 3","mac":"de:ad:be:ef:00:03"}',
         '-28 days'],
        // app-lon-01
        [$histIds['10.10.2.20'] ?? 0, 4, '10.10.2.20', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"app-lon-01","owner":"AppTeam","status":"used","note":"Application server 1","mac":""}',
         '-28 days'],
        // fw-lon-dmz
        [$histIds['10.10.3.1']  ?? 0, 5, '10.10.3.1',  'create', 'demo', '192.168.1.100', null,
         '{"hostname":"fw-lon-dmz","owner":"Security","status":"used","note":"DMZ firewall inside","mac":"00:50:56:a1:b2:c3"}',
         '-23 days'],
        // 10.10.3.20 (future load balancer)
        [$histIds['10.10.3.20'] ?? 0, 5, '10.10.3.20', 'create', 'demo', '192.168.1.100', null,
         '{"hostname":"","owner":"","status":"free","note":"","mac":""}',
         '-23 days'],
        [$histIds['10.10.3.20'] ?? 0, 5, '10.10.3.20', 'update', 'demo', '192.168.1.100',
         '{"hostname":"","owner":"","status":"free","note":"","mac":""}',
         '{"hostname":"","owner":"","status":"reserved","note":"Future load balancer","mac":""}',
         '-10 days'],
        ] as $h) {
        if ($h[0] === 0) continue;
        // Replace the human-readable offset with an absolute UTC timestamp.
        $offset = array_pop($h);
        $ts = strtotime((string)$offset);
        $h[] = ($ts !== false) ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        $hist->execute($h);
    }

    // v2.11.0 #388: advance every identity sequence past the explicit IDs
    // the seed just inserted. Postgres `GENERATED BY DEFAULT AS IDENTITY`
    // columns accept explicit ids at INSERT time, but do NOT auto-advance
    // the backing sequence — so a subsequent implicit insert would pick
    // id=1 and collide against our fixture ids. SQLite and MySQL handle
    // this automatically via ROWID / AUTO_INCREMENT.
    if (ipam_dialect()->driver_name() === 'pgsql') {
        $seedTables = [
            'sites', 'vrfs', 'vlans', 'vlan_ranges', 'subnets', 'contacts',
            'tags', 'addresses', 'api_keys', 'users', 'aggregates',
            'pd_pools', 'pd_delegations', 'scan_schedules', 'scan_results',
            'address_history', 'audit_log',
        ];
        foreach ($seedTables as $t) {
            $db->exec(
                "SELECT setval(pg_get_serial_sequence('$t', 'id'), "
                . "COALESCE((SELECT MAX(id) FROM $t), 1), "
                . "(SELECT MAX(id) FROM $t) IS NOT NULL)"
            );
        }
    }
}

/* ---------------- IP helpers ----------------
 * Pure IP/CIDR math + ipam_bind_binary() now live in lib/ip.php
 * (ADR-004 Phase 2 Task 2.2, v3.30.0). normalize_status() stays here
 * because it is an address-record status string normaliser, not IP math.
 */

function normalize_status(?string $s): string
{
    $s = strtolower(trim((string)$s));
    if ($s === '') return 'used';
    if (in_array($s, ['used','reserved','free'], true)) return $s;
    if (in_array($s, ['inuse','in-use','active'], true)) return 'used';
    if (in_array($s, ['res','reservation'], true)) return 'reserved';
    if (in_array($s, ['avail','available','unused'], true)) return 'free';
    return 'used';
}

/* ---------------- IPv4 helpers ----------------
 * Pure IPv4 helpers (ipv4_bin_to_int, ipv4_int_to_bin, ipv4_int_to_text,
 * ipv4_assignable_count, ipv4_broadcast_bin, ipv4_broadcast_int,
 * subnet_contains_bin) now live in lib/ip.php (ADR-004 Phase 2 Task 2.2).
 */

/* ── Subnet tree + utilization helpers (shared by subnets.php, dashboard, API) ── */

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>}
 */
function build_subnet_tree(array $rows): array
{
    $byId = [];
    foreach ($rows as $r) $byId[to_int($r['id'])] = $r;

    $sorted = $byId;
    uasort($sorted, function(array $a, array $b): int {
        $vrfa = $a['vrf_id'] !== null ? to_int($a['vrf_id']) : 0;
        $vrfb = $b['vrf_id'] !== null ? to_int($b['vrf_id']) : 0;
        if ($vrfa !== $vrfb) return $vrfa <=> $vrfb;
        $va = to_int($a['ip_version']); $vb = to_int($b['ip_version']);
        if ($va !== $vb) return $va <=> $vb;
        $pa = to_int($a['prefix']); $pb = to_int($b['prefix']);
        if ($pa !== $pb) return $pa <=> $pb;
        return strcmp(to_str($a['network_bin']), to_str($b['network_bin']));
    });

    $children = [];
    $roots = [];
    $stack = [];

    foreach ($sorted as $id => $row) {
        $ver    = to_int($row['ip_version']);
        $prefix = to_int($row['prefix']);
        $netBin = to_str($row['network_bin']);
        $curVrf = $row['vrf_id'] !== null ? to_int($row['vrf_id']) : 0;

        while (!empty($stack)) {
            $top    = end($stack);
            $topVrf = $top['vrf_id'] !== null ? to_int($top['vrf_id']) : 0;
            if ($topVrf !== $curVrf || to_int($top['ip_version']) !== $ver) {
                $stack = [];
                break;
            }
            if (to_int($top['prefix']) < $prefix
                && subnet_contains_bin(to_str($top['network_bin']), to_int($top['prefix']), $netBin)) {
                break;
            }
            array_pop($stack);
        }

        if (!empty($stack)) {
            $parent = end($stack);
            $children[to_int($parent['id'])][] = $id;
        } else {
            $roots[] = $id;
        }

        $stack[] = ['id' => $id, 'ip_version' => $ver, 'prefix' => $prefix, 'network_bin' => $netBin, 'vrf_id' => $row['vrf_id']];
    }

    $cmpFn = function(int $a, int $b) use ($byId): int {
        $ra = $byId[$a]; $rb = $byId[$b];
        $va = to_int($ra['ip_version']); $vb = to_int($rb['ip_version']);
        if ($va !== $vb) return $va <=> $vb;
        $c = strcmp(to_str($ra['network_bin']), to_str($rb['network_bin']));
        if ($c !== 0) return $c;
        return to_int($ra['prefix']) <=> to_int($rb['prefix']);
    };

    usort($roots, $cmpFn);
    foreach ($children as $pid => $arr) {
        usort($arr, $cmpFn);
        $children[$pid] = $arr;
    }

    return ['roots' => $roots, 'children' => $children, 'byId' => $byId];
}

/** @return array<int, array{used: int, reserved: int, free: int, total: int}> */
function subnet_direct_counts(PDO $db): array
{
    $st = $db->prepare("SELECT subnet_id, status, COUNT(*) AS c FROM addresses GROUP BY subnet_id, status");
    $st->execute();
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $sid = to_int($r['subnet_id']);
        $status = to_str($r['status']);
        $c = to_int($r['c']);
        $out[$sid] ??= ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
        if (isset($out[$sid][$status])) $out[$sid][$status] += $c;
        $out[$sid]['total'] += $c;
    }
    return $out;
}

/**
 * @param array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>} $tree
 * @param array<int, array{used: int, reserved: int, free: int, total: int}> $directCounts
 * @return array<int, array{used: int, reserved: int, free: int, total: int}>
 */
function subnet_aggregated_counts(array $tree, array $directCounts): array
{
    $children = $tree['children'];
    $agg = [];

    $sumNode = function(int $id) use (&$sumNode, &$agg, $children, $directCounts): array {
        if (isset($agg[$id])) return $agg[$id];

        $base = $directCounts[$id] ?? ['used'=>0,'reserved'=>0,'free'=>0,'total'=>0];
        $sum = $base;

        foreach (($children[$id] ?? []) as $cid) {
            $c = $sumNode((int)$cid);
            $sum['used'] += $c['used'];
            $sum['reserved'] += $c['reserved'];
            $sum['free'] += $c['free'];
            $sum['total'] += $c['total'];
        }
        return $agg[$id] = $sum;
    };

    foreach ($tree['byId'] as $id => $_row) $sumNode((int)$id);
    return $agg;
}

/** @return array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}> */
function ipv4_unassigned_summary(PDO $db): array
{
    $st = $db->prepare("SELECT id, prefix, network_bin FROM subnets WHERE ip_version=4");
    $st->execute();
    /** @var list<array<string, mixed>> $subs */
    $subs = $st->fetchAll();
    if (!$subs) return [];

    $cntSt = $db->prepare(
        "SELECT a.subnet_id, COUNT(*) AS c
         FROM addresses a JOIN subnets s ON s.id = a.subnet_id
         WHERE s.ip_version = 4 AND a.status IN ('used','reserved')
         GROUP BY a.subnet_id"
    );
    $cntSt->execute();
    $countBySubnet = [];
    foreach ($cntSt->fetchAll() as $r) {
        $countBySubnet[to_int($r['subnet_id'])] = to_int($r['c']);
    }

    $excludedBySubnet = [];

    $netExcl = $db->query(
        "SELECT a.subnet_id, COUNT(*) AS c
         FROM addresses a
         JOIN subnets s ON s.id = a.subnet_id
         WHERE s.ip_version = 4 AND s.prefix <= 30
           AND a.status IN ('used','reserved')
           AND a.ip_bin = s.network_bin
         GROUP BY a.subnet_id"
    );
    if ($netExcl !== false) {
        foreach ($netExcl->fetchAll() as $r) {
            $excludedBySubnet[to_int($r['subnet_id'])] = to_int($r['c']);
        }
    }

    /** @var array<int, string> $bcastBins */
    $bcastBins = [];
    foreach ($subs as $s) {
        $sid    = to_int($s['id']);
        $prefix = to_int($s['prefix']);
        if ($prefix <= 30 && isset($countBySubnet[$sid])) {
            $bcastBins[$sid] = ipv4_broadcast_bin(to_str($s['network_bin']), $prefix);
        }
    }
    if ($bcastBins !== []) {
        $binType = ipam_dialect()->binary_type(4);
        $db->exec("CREATE TEMPORARY TABLE IF NOT EXISTS _bcast_excl (subnet_id INTEGER NOT NULL, bcast_bin $binType NOT NULL)");
        $db->exec("DELETE FROM _bcast_excl");
        $ins = $db->prepare("INSERT INTO _bcast_excl (subnet_id, bcast_bin) VALUES (:s, :b)");
        foreach ($bcastBins as $sid => $bcast) {
            $ins->bindValue(':s', $sid, PDO::PARAM_INT);
            ipam_bind_binary($ins, ':b', $bcast);
            $ins->execute();
        }
        $bcastExcl = $db->query(
            "SELECT a.subnet_id, COUNT(*) AS c
             FROM addresses a
             JOIN _bcast_excl t ON t.subnet_id = a.subnet_id AND t.bcast_bin = a.ip_bin
             WHERE a.status IN ('used','reserved')
             GROUP BY a.subnet_id"
        );
        if ($bcastExcl !== false) {
            foreach ($bcastExcl->fetchAll() as $r) {
                $sid = to_int($r['subnet_id']);
                $excludedBySubnet[$sid] = ($excludedBySubnet[$sid] ?? 0) + to_int($r['c']);
            }
        }
    }

    $out = [];
    foreach ($subs as $s) {
        $sid    = to_int($s['id']);
        $prefix = to_int($s['prefix']);

        $assignableTotal = ipv4_assignable_count($prefix);
        $assignedCount   = $countBySubnet[$sid] ?? 0;

        if ($prefix <= 30 && $assignedCount > 0) {
            $excluded = $excludedBySubnet[$sid] ?? 0;
            $assignedAssignable = $assignedCount - $excluded;
        } else {
            $assignedAssignable = $assignedCount;
        }

        if ($assignedAssignable < 0) $assignedAssignable = 0;
        $unassigned = $assignableTotal - $assignedAssignable;
        if ($unassigned < 0) $unassigned = 0;

        $out[$sid] = [
            'assignable_total'      => (int)$assignableTotal,
            'assigned_assignable'   => (int)$assignedAssignable,
            'unassigned_assignable' => (int)$unassigned,
        ];
    }
    return $out;
}

/**
 * @param array{roots: list<int>, children: array<int, list<int>>, byId: array<int, array<string, mixed>>} $tree
 * @param array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}> $directUnassigned
 * @return array<int, array{assignable_total: int, assigned_assignable: int, unassigned_assignable: int}>
 */
function ipv4_unassigned_aggregated(array $tree, array $directUnassigned): array
{
    $children = $tree['children'];
    $agg = [];

    $sumNode = function(int $id) use (&$sumNode, &$agg, $children, $directUnassigned, $tree): array {
        if (isset($agg[$id])) return $agg[$id];

        $ipVer = to_int($tree['byId'][$id]['ip_version'] ?? 0);
        $base = ($ipVer === 4 && isset($directUnassigned[$id]))
            ? $directUnassigned[$id]
            : ['assignable_total' => 0, 'assigned_assignable' => 0, 'unassigned_assignable' => 0];

        $sum = $base;
        foreach (($children[$id] ?? []) as $cid) {
            $c = $sumNode((int)$cid);
            $sum['assignable_total']      += $c['assignable_total'];
            $sum['assigned_assignable']   += $c['assigned_assignable'];
            $sum['unassigned_assignable'] += $c['unassigned_assignable'];
        }
        return $agg[$id] = $sum;
    };

    foreach ($tree['byId'] as $id => $_row) $sumNode((int)$id);
    return $agg;
}

/**
 * Find the first unassigned IPv4 host address in a subnet.
 * Returns the IP as text, or null if none available.
 */
function find_next_available_ipv4(PDO $db, int $subnetId, string $network, int $prefix): ?string
{
    if ($prefix > 32 || $prefix < 8) return null;
    $networkBin = inet_pton($network);
    if ($networkBin === false) return null;
    $netInt = ipv4_bin_to_int($networkBin);
    $first  = ($prefix >= 31) ? $netInt : $netInt + 1;
    $last   = ($prefix >= 31) ? $netInt + ((1 << (32 - $prefix)) - 1)
                              : $netInt + ((1 << (32 - $prefix)) - 1) - 1;

    // Fetch all used IPs in this subnet as a set
    $st = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
    $st->execute([':sid' => $subnetId]);
    $used = [];
    foreach ($st->fetchAll() as $r) $used[to_str($r['ip'])] = true;

    for ($i = $first; $i <= $last; $i++) {
        $ip = ipv4_int_to_text($i);
        if (!isset($used[$ip])) return $ip;
    }
    return null;
}

/* ---------------- IPv6 enumeration helpers ----------------
 * Pure IPv6 helpers (ipv6_bin_increment) now live in lib/ip.php
 * (ADR-004 Phase 2 Task 2.2). DB-driven enumeration stays here pending
 * the v3.32.0 lib/addresses.php extraction.
 */

/**
 * Return the first $n unassigned IPv6 host addresses in a subnet.
 * Scans at most ($n + count(assigned) + 1) candidates from the first host address.
 *
 * @return list<string>
 */
function ipv6_enumerate_first_n(PDO $db, int $subnetId, string $networkBin, int $prefix, int $n): array
{
    if ($prefix >= 128) {
        $ip = inet_ntop($networkBin) ?: '';
        $st = $db->prepare("SELECT id FROM addresses WHERE subnet_id = :sid AND ip = :ip LIMIT 1");
        $st->execute([':sid' => $subnetId, ':ip' => $ip]);
        return ($ip !== '' && $st->fetch() === false) ? [$ip] : [];
    }

    $st = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
    $st->execute([':sid' => $subnetId]);
    $assigned = [];
    foreach ($st->fetchAll() as $r) $assigned[to_str($r['ip'])] = true;

    $current = ipv6_bin_increment($networkBin);
    $scanLimit = $n + count($assigned) + 1;
    $result = [];

    for ($i = 0; $i < $scanLimit && count($result) < $n; $i++) {
        $ip = inet_ntop($current) ?: '';
        if ($ip !== '' && !isset($assigned[$ip])) {
            $result[] = $ip;
        }
        $current = ipv6_bin_increment($current);
    }

    return $result;
}

/* ---------------- CSV import helpers ---------------- */

/** @param IpamConfig $config */
function import_max_bytes(array $config): int
{
    $mb = to_int(ipam_setting('limits.import_csv_max_mb'));
    if ($mb < 5) $mb = 5;
    if ($mb > 50) $mb = 50;
    return $mb * 1024 * 1024;
}

function tmp_dir(): string
{
    return __DIR__ . '/data/tmp';
}

function ensure_tmp_dir(): void
{
    $d = tmp_dir();
    if (!is_dir($d)) mkdir($d, 0700, true);
}

function cleanup_tmp_import_files(int $ttlSeconds): int
{
    ensure_tmp_dir();
    $now = time();
    $deleted = 0;

    foreach (new DirectoryIterator(tmp_dir()) as $f) {
        if ($f->isDot() || !$f->isFile()) continue;
        $name = $f->getFilename();
        if (!preg_match('~^import-[a-f0-9]{16}\.csv$~', $name)) continue;

        $age = $now - $f->getMTime();
        if ($age > $ttlSeconds) {
            @unlink($f->getPathname()); // nosemgrep: php.lang.security.unlink-use.unlink-use
            $deleted++;
        }
    }
    return $deleted;
}

/* -------- Import plan helpers -------- */

function import_plan_dir(): string
{
    return tmp_dir();
}

/** @param array<string, mixed> $plan */
function save_import_plan(array $plan): string
{
    ensure_tmp_dir();
    $path = import_plan_dir() . '/import-plan-' . bin2hex(random_bytes(8)) . '.json';
    $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Failed to encode import plan');
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Failed to write import plan');
    }
    @chmod($path, 0600);
    return $path;
}

/** @return array<string, mixed> */
function load_import_plan(string $path): array
{
    if (!is_file($path)) throw new RuntimeException('Import plan file not found');
    $json = file_get_contents($path);
    if ($json === false) throw new RuntimeException('Failed to read import plan');
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException('Invalid import plan');
    /** @var array<string, mixed> $data */
    return $data;
}

function delete_import_plan(string $path): void
{
    if ($path !== '' && is_file($path)) {
        @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
}

function cleanup_tmp_import_plans(int $ttlSeconds): int
{
    ensure_tmp_dir();
    $now = time();
    $deleted = 0;

    foreach (new DirectoryIterator(tmp_dir()) as $f) {
        if ($f->isDot() || !$f->isFile()) continue;
        $name = $f->getFilename();
        if (!preg_match('~^import-plan-[a-f0-9]{16}\.json$~', $name)) continue;

        $age = $now - $f->getMTime();
        if ($age > $ttlSeconds) {
            @unlink($f->getPathname()); // nosemgrep: php.lang.security.unlink-use.unlink-use
            $deleted++;
        }
    }
    return $deleted;
}

function detect_csv_delimiter(string $sample): string
{
    $candidates = ["," , ";" , "\t" , "|"];
    $best = ",";
    $bestCount = -1;

    foreach ($candidates as $d) {
        $lines = preg_split("/\r\n|\n|\r/", $sample) ?: [];
        $counts = [];
        foreach (array_slice($lines, 0, 10) as $line) {
            if ($line === '') continue;
            $counts[] = count(str_getcsv($line, $d, '"', ''));
        }
        if (!$counts) continue;
        $avg = array_sum($counts) / count($counts);
        if ($avg > $bestCount) {
            $bestCount = $avg;
            $best = $d;
        }
    }
    return $best;
}

/** @return list<list<string>> */
function csv_read_preview(string $path, string $delimiter, int $maxRows = 20): array
{
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException("Cannot open upload");
    $rows = [];
    while (!feof($fh) && count($rows) < $maxRows) {
        $row = fgetcsv($fh, 0, $delimiter, '"', '');
        if ($row === false) break;
        $row = array_map('strval', $row);
        if (count($row) === 1 && trim($row[0]) === '') continue;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

/**
 * @param array{ip: string, bin: string, version: int} $normIp
 * @return array<string, mixed>|null
 */
function find_containing_subnet(PDO $db, array $normIp): ?array
{
    static $cache = [];
    $ver = to_int($normIp['version']);
    if (!isset($cache[$ver])) {
        $st = $db->prepare("SELECT id, network, prefix, ip_version FROM subnets WHERE ip_version = :v ORDER BY prefix DESC");
        $st->execute([':v' => $ver]);
        $cache[$ver] = $st->fetchAll();
    }
    foreach ($cache[$ver] as $s) {
        if (ip_in_cidr($normIp['ip'], to_str($s['network']), to_int($s['prefix']))) return $s;
    }
    return null;
}

function ensure_subnet_exists(PDO $db, string $cidr, string $description = ''): int
{
    $p = parse_cidr($cidr);
    if (!$p) throw new RuntimeException("Invalid CIDR to create: $cidr");

    $normalized = $p['network'] . '/' . $p['prefix'];

    $st = $db->prepare("SELECT id FROM subnets WHERE cidr = :c");
    $st->execute([':c' => $normalized]);
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    if ($row) return to_int($row['id']);

    // #379/#410: bind network_bin via ipam_bind_binary() (PARAM_LOB).
    $ins = $db->prepare("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
                         VALUES (:cidr,:ver,:net,:nb,:pre,:d)");
    $ins->bindValue(':cidr', $normalized,    PDO::PARAM_STR);
    $ins->bindValue(':ver',  $p['version'],  PDO::PARAM_INT);
    $ins->bindValue(':net',  $p['network'],  PDO::PARAM_STR);
    ipam_bind_binary($ins, ':nb', $p['net_bin']);
    $ins->bindValue(':pre',  $p['prefix'],   PDO::PARAM_INT);
    $ins->bindValue(':d',    $description,   PDO::PARAM_STR);
    $ins->execute();

    return ipam_last_insert_id($db, 'subnets');
}

/* ---------------- Subnet overlap detection ---------------- */

/**
 * Detect parent/child relationships for a proposed CIDR against existing subnets.
 *
 * In valid CIDR math, two subnets of different prefix lengths either have a strict
 * parent/child containment relationship or are completely disjoint — partial overlap
 * is impossible. Exact duplicates are prevented by the DB UNIQUE constraint on cidr.
 *
 * Returns:
 *   'parents'  — existing subnets that contain the proposed CIDR (new is a child)
 *   'children' — existing subnets that fall inside the proposed CIDR (new is a parent)
 *
 * Both are informational warnings; neither case blocks the operation, as hierarchical
 * nesting is the expected use-case. Pass $excludeId when checking an update so the
 * subnet being edited is not compared against itself.
 *
 * @return array{parents: list<string>, children: list<string>}
 */
function detect_subnet_overlaps(PDO $db, string $cidr, ?int $excludeId = null, ?int $vrfId = null): array
{
    $p = parse_cidr($cidr);
    if (!$p) return ['parents' => [], 'children' => []];

    $ver    = to_int($p['version']);
    $prefix = to_int($p['prefix']);
    $netBin = to_str($p['net_bin']);

    // Scope overlap detection to the same VRF (NULL = global routing table).
    // SQLite's IS operator handles NULL equality correctly.
    $sql    = "SELECT id, cidr, prefix, network_bin FROM subnets WHERE ip_version = :v AND " . ipam_dialect()->null_safe_eq("vrf_id", ":vrf") . "";
    $params = [':v' => $ver, ':vrf' => $vrfId];
    if ($excludeId !== null) {
        $sql .= " AND id != :excl";
        $params[':excl'] = $excludeId;
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    /** @var list<array<string, mixed>> $rows */
    $rows = $st->fetchAll();

    $parents  = [];
    $children = [];

    foreach ($rows as $row) {
        $rowPrefix = to_int($row['prefix']);
        $rowNetBin = to_str($row['network_bin']);

        if ($rowPrefix < $prefix) {
            // Candidate parent: does the existing broader subnet contain our new one?
            if (hash_equals(apply_prefix_mask($netBin, $rowPrefix), $rowNetBin)) {
                $parents[] = to_str($row['cidr']);
            }
        } elseif ($rowPrefix > $prefix) {
            // Candidate child: does our new broader subnet contain the existing one?
            if (hash_equals(apply_prefix_mask($rowNetBin, $prefix), $netBin)) {
                $children[] = to_str($row['cidr']);
            }
        }
        // Same prefix: exact duplicate — handled by DB UNIQUE constraint on cidr
    }

    return ['parents' => $parents, 'children' => $children];
}

/* ---------------- Webhook helpers ---------------- */

/**
 * Validate a webhook target URL for SSRF safety.
 * Returns false if the URL scheme is not http/https, the hostname cannot be
 * resolved, or the resolved IP falls in a private/loopback range (unless
 * webhook.allow_private_ips is true in settings).
 *
 * @param array<string, mixed> $config
 */
function ipam_validate_webhook_url(string $url, array $config = []): bool
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        return false;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = $parts['host'];
    // Strip IPv6 brackets
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
    // Resolve hostname to all addresses (A + AAAA) for dual-stack SSRF safety.
    // gethostbyname() is IPv4-only; use dns_get_record() to cover AAAA records.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips      = [];
        $aRecs    = @dns_get_record($host, DNS_A);
        $aaaaRecs = @dns_get_record($host, DNS_AAAA);
        foreach ((array)$aRecs as $r) { if (isset($r['ip']))   $ips[] = $r['ip']; }
        foreach ((array)$aaaaRecs as $r) { if (isset($r['ipv6'])) $ips[] = $r['ipv6']; }
        if (empty($ips)) {
            return false; // DNS resolution failed / NXDOMAIN
        }
    }

    $settingVal   = ipam_setting('webhook.allow_private_ips');
    $configVal    = is_array($config['webhook'] ?? null) ? ($config['webhook']['allow_private_ips'] ?? false) : false;
    $allowPrivate = (bool)($settingVal ?? $configVal);
    if ($allowPrivate) {
        return true;
    }

    // Block RFC-1918, loopback, link-local, IPv6 ULA/loopback, "this network"
    // (#872 — 0.0.0.0/8), CGNAT (100.64.0.0/10), multicast (224.0.0.0/4 and
    // ff00::/8), the IPv6 unspecified address (::/128), and the IPv4-mapped
    // IPv6 prefix (::ffff:0:0/96 — defence in depth alongside the explicit
    // unwrap below). ALL resolved addresses must be public (blocks DNS
    // rebinding).
    $privateRangesV4 = [
        ['0.0.0.0',     8],   // "this network" — covers 0.0.0.0 itself
        ['10.0.0.0',    8],
        ['100.64.0.0', 10],   // CGNAT (RFC 6598)
        ['127.0.0.0',   8],
        ['169.254.0.0',16],
        ['172.16.0.0', 12],
        ['192.168.0.0',16],
        ['224.0.0.0',   4],   // multicast + reserved (224.0.0.0–255.255.255.255)
    ];
    $privateRangesV6 = [
        ['::',         128],  // unspecified
        ['::1',        128],  // loopback
        ['::ffff:0:0', 96],   // IPv4-mapped IPv6 (defence in depth)
        ['64:ff9b::',  96],   // NAT64 well-known (RFC 6052)
        ['fc00::',      7],   // ULA
        ['fe80::',     10],   // link-local
        ['ff00::',      8],   // multicast
    ];
    foreach ($ips as $ip) {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            // Resolver returned something that doesn't parse — treat as
            // unresolvable (i.e. unsafe) rather than silently passing it.
            return false;
        }
        // If we resolved an IPv4-mapped IPv6 address (::ffff:a.b.c.d), test
        // both the v6 prefix list AND the unwrapped IPv4 against the v4
        // list. Otherwise an attacker controlling DNS could route 127.0.0.1
        // past the v4 check by encoding it as ::ffff:127.0.0.1.
        if (strlen($bin) === 16) {
            $prefix = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";
            if (str_starts_with($bin, $prefix)) {
                $v4 = inet_ntop(substr($bin, 12));
                if (is_string($v4)) {
                    foreach ($privateRangesV4 as [$net, $p]) {
                        if (ip_in_cidr($v4, $net, $p)) {
                            return false;
                        }
                    }
                }
            }
            foreach ($privateRangesV6 as [$net, $p]) {
                if (ip_in_cidr($ip, $net, $p)) {
                    return false;
                }
            }
        } else {
            foreach ($privateRangesV4 as [$net, $p]) {
                if (ip_in_cidr($ip, $net, $p)) {
                    return false;
                }
            }
        }
    }
    return true;
}

/**
 * Build the audit_log.details string for a webhook test-fire row.
 *
 * Records the webhook id and host only — never the full URL — so query
 * strings carrying tokens/secrets and any XSS-shaped path or fragment
 * never land in the audit details column. (#1152, S-001)
 */
function ipam_webhook_test_fire_audit_detail(int $id, string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    // parse_url returns a host *token*, not a validated hostname/IP. A
    // malformed URL can still leave attacker-controlled bytes here, which
    // would keep the S-001 sink partly open. Require a syntactically valid
    // IPv4/IPv6 address or RFC 1123 hostname; otherwise '(invalid)'.
    if (!is_string($host) || $host === '') {
        $host = '(invalid)';
    } else {
        $isIp     = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isDomain = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
        if (!$isIp && !$isDomain) {
            $host = '(invalid)';
        }
    }
    if (strlen($host) > 100) {
        $host = substr($host, 0, 100);
    }
    return "id={$id} host={$host}";
}

/** Sign a webhook payload with HMAC-SHA256. */
function ipam_webhook_sign(string $payload, string $secret): string
{
    return 'sha256=' . hash_hmac('sha256', $payload, $secret);
}

/**
 * v3.27.7 (F-S3-01): encrypt a webhook signing secret for at-rest storage.
 *
 * Pre-v3.27.7, webhooks.secret was stored as plaintext. A DB dump (legitimate
 * backup, replication snapshot, db_tools export) leaked every outbound webhook
 * URL and its HMAC signing key, enabling forged deliveries. Mirrors the TOTP
 * envelope shape (ipam_totp_encrypt_secret) — AES-256-GCM with 12-byte IV +
 * 16-byte tag, '$2W$' prefix to namespace from TOTP's '$2$'.
 *
 * Key derived via SHA-256 of app_secret (same derivation as TOTP). Webhook
 * signing is HMAC-SHA256 (not AES), so the encrypted secret is only ever
 * unwrapped in memory at delivery time and re-encrypted on any save.
 *
 * @param string $secret  Plaintext HMAC signing secret. Empty string returns
 *                        empty (unsigned webhook).
 * @param string $key     app_secret string from config.php.
 * @return string         '$2W$' + base64(iv || tag || ciphertext), or empty.
 */
function ipam_webhook_encrypt_secret(string $secret, string $key): string
{
    if ($secret === '') {
        // Empty signing secret means the webhook is unsigned. Store empty
        // verbatim so the reader can distinguish "no secret" from "encrypted
        // blob that fails to decrypt". CR review (PR #1148): this fast path
        // runs BEFORE the $key check so save/migration of an intentionally-
        // unsigned webhook works on installs without app_secret configured.
        return '';
    }
    if ($key === '') {
        throw new \RuntimeException('Webhook secret encryption requires a non-empty app_secret');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $enc = openssl_encrypt($secret, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($enc === false) {
        throw new \RuntimeException('Webhook secret encryption failed');
    }
    return '$2W$' . base64_encode($iv . $tag . $enc);
}

/**
 * v3.27.7: decrypt a webhook signing secret previously written by
 * ipam_webhook_encrypt_secret(). Returns empty string on decrypt failure so
 * callers signing an HMAC always get a usable string (the HMAC will fail
 * receiver-side verification, surfacing the misconfig there).
 *
 * Accepts both encrypted ('$2W$...') and plaintext values; the plaintext
 * branch exists because the 3.27.7-webhook-secret-encrypt migration is
 * defensive — an install upgrading from pre-v3.27.7 with existing rows must
 * keep delivering until the migration completes. (All deployed targets at
 * v3.27.6 had webhooks count = 0 so the migration is no-op in practice, but
 * the branch keeps the code safe for any future install restoring an older
 * backup and then upgrading.)
 */
/**
 * Return contract (CR review PR #1148):
 *   - '' (empty string)        — stored row was empty (intentionally unsigned)
 *                                 OR a legacy plaintext row that just happened
 *                                 to be empty. Caller may sign with empty key.
 *   - string (non-empty)       — successful decrypt (or legacy plaintext pass-
 *                                 through). Caller signs with this secret.
 *   - null                     — decrypt FAILURE: malformed envelope, wrong
 *                                 app_secret, or GCM tag mismatch. Caller
 *                                 MUST treat this as a delivery failure
 *                                 (persist an error row, do NOT sign with an
 *                                 empty key — that would silently produce a
 *                                 verifiably-wrong HMAC at the receiver).
 *   - throws \RuntimeException — caller passed an empty $key but the stored
 *                                 value is a real '$2W$' envelope. This is a
 *                                 config-time misconfig (app_secret missing);
 *                                 surface it to the operator.
 */
function ipam_webhook_decrypt_secret(string $stored, string $key): ?string
{
    if ($stored === '') {
        return '';
    }
    if (!str_starts_with($stored, '$2W$')) {
        // Legacy plaintext row — return as-is so the existing webhook keeps
        // signing correctly until the migration encrypts it.
        return $stored;
    }
    if ($key === '') {
        throw new \RuntimeException('Webhook secret decryption requires a non-empty app_secret');
    }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $iv      = substr($raw, 0, 12);
    $tag     = substr($raw, 12, 16);
    $payload = substr($raw, 28);
    $decrypted = openssl_decrypt($payload, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
    if ($decrypted === false) {
        return null;
    }
    return $decrypted;
}

/**
 * Attempt a single HTTP delivery of a webhook payload via ext-curl.
 * Returns ['status' => int|null, 'body' => string, 'error' => string|null].
 *
 * @param array<string, mixed> $webhook  Row from the webhooks table
 * @return array{status: int|null, body: string, error: string|null}
 */
function ipam_webhook_deliver(array $webhook, string $eventType, string $payload, string $signature): array
{
    require_once __DIR__ . '/version.php';
    $ch = curl_init();
    if ($ch === false) {
        return ['status' => null, 'body' => '', 'error' => 'curl_init() failed'];
    }
    $url = to_str($webhook['url']);
    if ($url === '') {
        return ['status' => null, 'body' => '', 'error' => 'empty webhook URL'];
    }
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS,      3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'X-IPAM-Signature: ' . $signature,
        'X-IPAM-Event: ' . $eventType,
        'User-Agent: SimpleIPAM/' . IPAM_VERSION,
    ]);
    $body   = (string)curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
    $err    = curl_errno($ch) ? curl_error($ch) : null;
    // Truncate response body to 2KB for storage
    return ['status' => $status ?: null, 'body' => substr($body, 0, 2048), 'error' => $err];
}

/**
 * Dispatch a webhook event to all active subscribers.
 * Never throws — dispatch failures are silently swallowed so the triggering
 * action always succeeds.
 *
 * @param array<string, mixed> $data    Entity snapshot to include in payload
 * @param array<string, mixed> $config
 */
function ipam_webhook_dispatch(PDO $db, string $event, array $data, array $config = []): void
{
    try {
        require_once __DIR__ . '/version.php';
        $u = current_user();

        // Find active webhooks subscribed to this event. `events` is a JSON
        // array of strings written by webhooks.php. Use engine-native JSON
        // containment where available (MySQL JSON_CONTAINS, Postgres @>) and
        // fall back to a quote-anchored LIKE on SQLite, which lacks a
        // guaranteed json1 build. The quote anchors prevent substring
        // confusion between e.g. 'subnet.create' and 'subnet.create.bulk'.
        $driver = ipam_dialect()->driver_name();
        if ($driver === 'mysql') {
            $sqlFrag = 'JSON_CONTAINS(events, :ev)';
            $bindEv  = (string)json_encode($event);
        } elseif ($driver === 'pgsql') {
            $sqlFrag = '(events::jsonb) @> :ev::jsonb';
            $bindEv  = (string)json_encode($event);
        } else {
            $sqlFrag = 'events LIKE :ev';
            $bindEv  = '%"' . $event . '"%';
        }
        $hooks = $db->prepare(
            "SELECT id, url, secret FROM webhooks
             WHERE is_active = 1 AND $sqlFrag"
        );
        $hooks->execute([':ev' => $bindEv]);
        $rows = $hooks->fetchAll();
        if (!$rows) {
            return;
        }

        $payload = json_encode([
            'event'     => $event,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'version'   => IPAM_VERSION,
            'actor'     => ['user_id' => $u['id'] ?? null, 'username' => $u['username'] ?? 'system'],
            'data'      => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }

        // CR review (PR #1148): on every terminal-failure `continue` below
        // (SSRF blocked, decrypt throw, decrypt null), update the parent
        // webhook's last_delivery_at + clear last_delivery_status so the row
        // doesn't continue to advertise an old success after a local failure.
        // Matches the shape used at the successful-delivery path below
        // (lib.php :: $wUpd) where status is the integer HTTP code on a real
        // attempt — NULL here means "we never got far enough to make an
        // HTTP request."
        $touchLastDelivery = $db->prepare(
            "UPDATE webhooks
             SET last_delivery_at = :now, last_delivery_status = NULL
             WHERE id = :id"
        );

        foreach ($rows as $hook) {
            $now = gmdate('Y-m-d H:i:s');
            if (!ipam_validate_webhook_url((string)$hook['url'], $config)) {
                // Log a permanently-failed delivery row (attempt=3 prevents retries)
                $db->prepare(
                    "INSERT INTO webhook_deliveries
                        (webhook_id, event_type, payload, signature, attempt, error, created_at)
                     VALUES (:wid, :ev, :pl, :sig, 3, :err, :now)"
                )->execute([
                    ':wid' => $hook['id'], ':ev' => $event,
                    ':pl'  => $payload,    ':sig' => '',
                    ':err' => 'URL blocked: failed SSRF validation',
                    ':now' => $now,
                ]);
                $touchLastDelivery->execute([':id' => $hook['id'], ':now' => $now]);
                continue;
            }
            // v3.27.7 (F-S3-01): decrypt the stored secret before signing.
            // The plaintext only lives in this local scope long enough to
            // compute the HMAC for this single dispatch. CR review (PR #1148):
            //   - null means decrypt failure (wrong app_secret, tampered
            //     envelope); do NOT sign with empty key — record an attempt=3
            //     error row and skip dispatch so the receiver doesn't see a
            //     wrong HMAC.
            //   - The helper *throws* RuntimeException when $key is empty and
            //     the stored value is a real '$2W$' envelope. That throw is a
            //     per-webhook config issue (one row references an envelope but
            //     this install has no app_secret) — it must NOT kill the whole
            //     dispatch batch. Wrap in try/catch + record-and-continue per
            //     PR #1148 CR.
            // CR review (PR #1148): the function signature defaults
            // $config to [] for older 3-arg call sites. Fall back to the
            // global bootstrap config (set by init.php) so app_secret is
            // available — it's a config.php-only key, never in the
            // settings table, per CLAUDE.md.
            $appSecret = to_str($config['app_secret']
                ?? (is_array($GLOBALS['config'] ?? null)
                    ? ($GLOBALS['config']['app_secret'] ?? '')
                    : ''));
            try {
                $secretPlain = ipam_webhook_decrypt_secret(
                    (string)$hook['secret'],
                    $appSecret
                );
            } catch (\RuntimeException $e) {
                $db->prepare(
                    "INSERT INTO webhook_deliveries
                        (webhook_id, event_type, payload, signature, attempt, error, created_at)
                     VALUES (:wid, :ev, :pl, :sig, 3, :err, :now)"
                )->execute([
                    ':wid' => $hook['id'], ':ev' => $event,
                    ':pl'  => $payload,    ':sig' => '',
                    ':err' => 'Webhook secret decrypt threw: ' . $e->getMessage(),
                    ':now' => $now,
                ]);
                $touchLastDelivery->execute([':id' => $hook['id'], ':now' => $now]);
                continue;
            }
            if ($secretPlain === null) {
                $db->prepare(
                    "INSERT INTO webhook_deliveries
                        (webhook_id, event_type, payload, signature, attempt, error, created_at)
                     VALUES (:wid, :ev, :pl, :sig, 3, :err, :now)"
                )->execute([
                    ':wid' => $hook['id'], ':ev' => $event,
                    ':pl'  => $payload,    ':sig' => '',
                    ':err' => 'Secret decryption failed (wrong app_secret or tampered ciphertext)',
                    ':now' => $now,
                ]);
                $touchLastDelivery->execute([':id' => $hook['id'], ':now' => $now]);
                continue;
            }
            $sig = ipam_webhook_sign($payload, $secretPlain);

            // Insert pending delivery row
            $ins = $db->prepare(
                "INSERT INTO webhook_deliveries
                    (webhook_id, event_type, payload, signature, attempt, created_at)
                 VALUES (:wid, :ev, :pl, :sig, 1, :now)"
            );
            $ins->execute([':wid' => $hook['id'], ':ev' => $event, ':pl' => $payload, ':sig' => $sig, ':now' => $now]);
            $delId = ipam_last_insert_id($db, 'webhook_deliveries');

            // Attempt synchronous delivery
            $result = ipam_webhook_deliver($hook, $event, $payload, $sig);

            $ok = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;
            $upd = $db->prepare(
                "UPDATE webhook_deliveries
                 SET http_status   = :st,
                     response_body = :body,
                     error         = :err,
                     delivered_at  = CASE WHEN :ok THEN :now ELSE NULL END
                 WHERE id = :id"
            );
            $upd->execute([
                ':st'   => $result['status'],
                ':body' => $result['body'],
                ':err'  => $result['error'],
                ':ok'   => $ok ? 1 : 0,
                ':id'   => $delId,
                ':now'  => gmdate('Y-m-d H:i:s'),
            ]);

            // Update webhook last-delivery metadata
            $wUpd = $db->prepare(
                "UPDATE webhooks
                 SET last_delivery_at = :now, last_delivery_status = :st
                 WHERE id = :id"
            );
            $wUpd->execute([':st' => $result['status'], ':id' => $hook['id'], ':now' => gmdate('Y-m-d H:i:s')]);
        }
    } catch (\Throwable $e) {
        // Dispatch must never surface to the user, but a swallowed throwable
        // here masks PDO/JSON failures and future regressions. Leave an
        // operator-recoverable signal in the server log (Pass C F-S6/S-006).
        error_log('[webhook_dispatch] ' . $e->getMessage());
    }
}

/**
 * Retry pending webhook deliveries (called from cron.php).
 * Backoff: attempt 2 at T+1min, attempt 3 at T+6min (5 min after attempt 2).
 * Returns count of delivery rows attempted.
 *
 * @param array<string, mixed> $config
 */
function ipam_webhook_retry_pending(PDO $db, array $config = []): int
{
    $cutoff1min  = gmdate('Y-m-d H:i:s', time() - 60);
    $cutoff6min  = gmdate('Y-m-d H:i:s', time() - 360);
    $due = $db->prepare(
        "SELECT d.id, d.webhook_id, d.event_type, d.payload, d.signature, d.attempt,
                w.url, w.secret
         FROM webhook_deliveries d
         JOIN webhooks w ON w.id = d.webhook_id
         WHERE d.delivered_at IS NULL
           AND d.attempt < 3
           AND w.is_active = 1
           AND (
               (d.attempt = 1 AND d.created_at <= :c1)
            OR (d.attempt = 2 AND d.created_at <= :c6)
           )"
    );
    $due->execute([':c1' => $cutoff1min, ':c6' => $cutoff6min]);
    if ($due === false) {
        return 0;
    }
    $rows  = $due->fetchAll();
    $count = 0;
    foreach ($rows as $row) {
        if (!ipam_validate_webhook_url((string)$row['url'], $config)) {
            // Mark row exhausted so it is not retried again
            $db->prepare(
                "UPDATE webhook_deliveries SET attempt = 3, error = :err WHERE id = :id"
            )->execute([':err' => 'URL blocked: failed SSRF validation', ':id' => $row['id']]);
            continue;
        }
        $hook    = ['url' => $row['url'], 'secret' => $row['secret'], 'id' => $row['webhook_id']];
        $result  = ipam_webhook_deliver($hook, (string)$row['event_type'], (string)$row['payload'], (string)$row['signature']);
        $attempt = (int)$row['attempt'] + 1;
        $ok      = $result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300;

        $upd = $db->prepare(
            "UPDATE webhook_deliveries
             SET attempt       = :att,
                 http_status   = :st,
                 response_body = :body,
                 error         = :err,
                 delivered_at  = CASE WHEN :ok THEN :now ELSE NULL END
             WHERE id = :id"
        );
        $upd->execute([
            ':att'  => $attempt,
            ':st'   => $result['status'],
            ':body' => $result['body'],
            ':err'  => $result['error'],
            ':ok'   => $ok ? 1 : 0,
            ':id'   => $row['id'],
            ':now'  => gmdate('Y-m-d H:i:s'),
        ]);

        $wUpd = $db->prepare(
            "UPDATE webhooks SET last_delivery_at=:now, last_delivery_status=:st WHERE id=:id"
        );
        $wUpd->execute([':st' => $result['status'], ':id' => $row['webhook_id'], ':now' => gmdate('Y-m-d H:i:s')]);
        $count++;
    }
    return $count;
}

/**
 * Prune old webhook delivery rows. Returns count deleted.
 */
function ipam_webhook_prune(PDO $db, int $days): int
{
    if ($days <= 0) {
        return 0;
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
    $st = $db->prepare(
        "DELETE FROM webhook_deliveries WHERE created_at < :cutoff"
    );
    $st->execute([':cutoff' => $cutoff]);
    return $st->rowCount();
}

/* ---------------- UI helpers ---------------- */

function ipam_skeleton_flush(int $rows = 8): void
{
    echo '<div id="skeleton-shell" class="skeleton-shell" aria-hidden="true">';
    for ($i = 0; $i < $rows; $i++) echo '<div class="skeleton-row"></div>';
    echo '</div>';
    if (ob_get_level() > 0) ob_flush();
    flush();
}

function ipam_skeleton_remove(): void
{
    // Skeleton removal is handled by app.js (CSP-safe, no inline script).
}

/**
 * Check GitHub for a newer release. Results are cached in data/tmp/ for the
 * configured TTL (default 6 hours). Network failures are silently ignored.
 *
 * Returns ['version' => '1.2.1', 'url' => 'https://...'] if newer, otherwise null.
 */
/**
 * @param IpamConfig $config Unused since v2.7.0 — kept for signature stability.
 * @return array{version: string, url: string}|null
 */
function ipam_update_check(array $config): ?array
{
    unset($config);
    // Memoize within a single request — page_header() and page_footer() both call this
    static $memo = false;
    if ($memo !== false) return $memo;

    if (!(bool)ipam_setting('update_check.enabled')) { $memo = null; return null; }

    $ttl              = max(3600, to_int(ipam_setting('update_check.ttl_seconds')));
    $notifyPrerelease = (bool)ipam_setting('update_check.notify_prerelease');

    ensure_tmp_dir();
    $cache = tmp_dir() . '/update-check.json';

    if (is_file($cache) && (time() - (int)filemtime($cache)) < $ttl) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && array_key_exists('checked', $d)) {
            // If the cached update version is <= the running version, we've already
            // upgraded — invalidate so the next check fetches fresh data from GitHub
            require_once __DIR__ . '/version.php';
            if (isset($d['update']['version'])
                && version_compare(ipam_normalise_version(to_str($d['update']['version'])), ipam_normalise_version(IPAM_VERSION), '<=')) {
                @unlink($cache); // nosemgrep: php.lang.security.unlink-use.unlink-use
            } else {
                $u = isset($d['update']) && is_array($d['update']) ? $d['update'] : null;
                $memo = ($u !== null && isset($u['version'], $u['url']))
                    ? ['version' => to_str($u['version']), 'url' => to_str($u['url'])]
                    : null;
                return $memo;
            }
        }
    }

    require_once __DIR__ . '/version.php';
    $result = null;

    try {
        // Fetch list of releases (up to 10) so we can honour notify_prerelease.
        // /releases/latest skips pre-releases entirely, so we use /releases instead.
        $url = 'https://api.github.com/repos/seanmousseau/Simple-PHP-IPAM/releases?per_page=10';
        $ctx = stream_context_create(['http' => [
            'timeout' => 5,
            'ignore_errors' => true,
            'header' => "User-Agent: Simple-PHP-IPAM/" . IPAM_VERSION . "\r\n"
                      . "Accept: application/vnd.github+json\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false && $raw !== '') {
            $releases = json_decode($raw, true);
            if (is_array($releases)) {
                foreach ($releases as $rel) {
                    if (!is_array($rel)) continue;
                    if (!empty($rel['draft'])) continue;
                    if (!empty($rel['prerelease']) && !$notifyPrerelease) continue;
                    if (empty($rel['tag_name'])) continue;

                    $latest = ltrim(to_str($rel['tag_name']), 'v');
                    if (version_compare(ipam_normalise_version($latest), ipam_normalise_version(IPAM_VERSION), '>')) {
                        $result = [
                            'version'    => $latest,
                            'url'        => to_str($rel['html_url'] ?? ''),
                            'prerelease' => !empty($rel['prerelease']),
                        ];
                    }
                    break; // releases are newest-first; first match wins
                }
            }
        }
    } catch (Throwable) {
        // Non-critical — silently skip on network failure
    }

    @file_put_contents($cache, json_encode(['checked' => time(), 'update' => $result]));
    @chmod($cache, 0600);
    $memo = $result;
    return $result;
}

/**
 * Find the site_id a subnet should inherit from its tightest parent.
 * Returns null if no parent exists or no parent has a site assigned.
 */
function find_parent_site_id(PDO $db, string $cidr, ?int $excludeId = null, ?int $vrfId = null): ?int
{
    $overlaps = detect_subnet_overlaps($db, $cidr, $excludeId, $vrfId);
    if (empty($overlaps['parents'])) return null;

    $placeholders = implode(',', array_fill(0, count($overlaps['parents']), '?'));
    $vrfEq = ipam_dialect()->null_safe_eq('vrf_id', '?');
    $st = $db->prepare(
        "SELECT site_id FROM subnets
         WHERE cidr IN ($placeholders) AND site_id IS NOT NULL AND $vrfEq
         ORDER BY prefix DESC LIMIT 1"
    );
    $st->execute(array_merge($overlaps['parents'], [$vrfId]));
    /** @var array<string, mixed>|false $row */
    $row = $st->fetch();
    return $row ? to_int($row['site_id']) : null;
}

/* ============================================================
 * OIDC — Authorization Code + PKCE (pure PHP, no dependencies)
 * ============================================================ */

/**
 * @param IpamConfig $config Unused since v2.7.0 — kept for back-compat with
 *                           existing callers. Reads go through ipam_setting()
 *                           which falls back to $GLOBALS['config'] on a miss,
 *                           so legacy config.php-only installs still work.
 */
function oidc_enabled(array $config): bool
{
    unset($config); // keep the signature stable; body now reads through ipam_setting()
    return (bool)ipam_setting('oidc.enabled')
        && to_str(ipam_setting('oidc.client_id'))     !== ''
        && to_str(ipam_setting('oidc.client_secret')) !== ''
        && to_str(ipam_setting('oidc.discovery_url')) !== ''
        && to_str(ipam_setting('oidc.redirect_uri'))  !== '';
}

/**
 * Fetch and cache the IdP's OpenID Connect discovery document.
 * Appends /.well-known/openid-configuration if the URL doesn't already
 * contain that path.
 */
/**
 * @param IpamConfig $config Unused since v2.7.0 — kept for signature stability.
 * @return array<string, mixed>
 */
function oidc_discovery(array $config): array
{
    unset($config);
    $base = rtrim(to_str(ipam_setting('oidc.discovery_url')), '/');
    if ($base === '') throw new RuntimeException('OIDC discovery_url not set');

    $url = (str_contains($base, '.well-known')) ? $base : $base . '/.well-known/openid-configuration';

    ensure_tmp_dir();
    $cache = tmp_dir() . '/oidc-disc-' . md5($url) . '.json';

    if (is_file($cache) && (time() - (int)filemtime($cache)) < 3600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && !empty($d['authorization_endpoint'])) {
            /** @var array<string, mixed> $d */
            return $d;
        }
    }

    $raw = oidc_http_get($url);
    $d   = json_decode($raw, true);
    if (!is_array($d) || empty($d['authorization_endpoint'])) {
        throw new RuntimeException('Invalid OIDC discovery document from ' . $url);
    }
    /** @var array<string, mixed> $d */

    file_put_contents($cache, json_encode($d));
    @chmod($cache, 0600);
    return $d;
}

/**
 * Fetch and cache the IdP's JSON Web Key Set.
 * Pass $forceRefresh = true to bypass the cache (used after a verify failure
 * to handle key rotation).
 */
/** @return list<mixed> */
function oidc_jwks(string $jwksUri, bool $forceRefresh = false): array
{
    ensure_tmp_dir();
    $cache = tmp_dir() . '/oidc-jwks-' . md5($jwksUri) . '.json';

    if (!$forceRefresh && is_file($cache) && (time() - (int)filemtime($cache)) < 3600) {
        $d = json_decode((string)file_get_contents($cache), true);
        if (is_array($d) && !empty($d['keys'])) return array_values((array)$d['keys']);
    }

    $raw = oidc_http_get($jwksUri);
    $d   = json_decode($raw, true);
    if (!is_array($d) || !isset($d['keys'])) {
        throw new RuntimeException('Invalid JWKS from ' . $jwksUri);
    }

    file_put_contents($cache, json_encode($d));
    @chmod($cache, 0600);
    return array_values((array)$d['keys']);
}

/** HTTP GET via file_get_contents with a short timeout. */
function oidc_http_get(string $url): string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx, 0, 1048576);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('HTTP GET failed for ' . $url);
    }
    return $raw;
}

/**
 * POST application/x-www-form-urlencoded and return decoded JSON array.
 *
 * @param array<string, string> $params
 * @return array<string, mixed>
 */
function oidc_http_post(string $url, array $params): array
{
    $body = http_build_query($params);
    $ctx  = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . "Content-Length: " . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx, 0, 1048576);
    if ($raw === false) throw new RuntimeException('Token endpoint request failed');
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new RuntimeException('Invalid JSON from token endpoint');
    /** @var array<string, mixed> $d */
    if (!empty($d['error'])) {
        throw new RuntimeException('Token endpoint error: ' . to_str($d['error'])
            . (isset($d['error_description']) ? ' — ' . to_str($d['error_description']) : ''));
    }
    return $d;
}

/* ---- PKCE ---- */
/* base64url_encode() and base64url_decode() moved to lib/utils.php in v3.30.0 (ADR-004). */

/**
 * Generate a PKCE verifier and S256 challenge pair.
 * @return array{verifier: string, challenge: string}
 */
function oidc_pkce_pair(): array
{
    $verifier  = base64url_encode(random_bytes(32));
    $challenge = base64url_encode(hash('sha256', $verifier, true));
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

/* ---- JWT / JWK verification ---- */

/**
 * Decode and verify an RS256/RS384/RS512 signed ID token.
 * Returns the verified payload claims array.
 *
 * @param list<mixed> $jwks                  Keys from the IdP's JWKS endpoint
 * @param array<string, string> $expect       Claims to validate: iss, aud, nonce
 * @return array<string, mixed>
 */
function oidc_verify_id_token(string $idToken, array $jwks, array $expect): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) throw new RuntimeException('Malformed JWT');

    [$hdrB64, $payB64, $sigB64] = $parts;

    $header  = json_decode(base64url_decode($hdrB64), true);
    $payload = json_decode(base64url_decode($payB64), true);
    if (!is_array($header) || !is_array($payload)) {
        throw new RuntimeException('JWT header/payload decoding failed');
    }

    $alg = to_str($header['alg'] ?? '');
    $algMap = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
    if (!isset($algMap[$alg])) {
        throw new RuntimeException("Unsupported JWT alg: $alg");
    }

    // Find the matching JWK
    $kid = $header['kid'] ?? null;
    $jwk = null;
    foreach ($jwks as $k) {
        if (!is_array($k) || ($k['kty'] ?? '') !== 'RSA') continue;
        if ($kid !== null && ($k['kid'] ?? '') !== $kid) continue;
        $jwk = $k;
        break;
    }
    if ($jwk === null) {
        throw new RuntimeException('No matching RSA JWK for kid=' . to_str($kid ?? 'none'));
    }

    $pem    = jwk_rsa_to_pem($jwk);
    $pubKey = openssl_pkey_get_public($pem);
    if ($pubKey === false) throw new RuntimeException('Failed to import JWK public key');

    $sig    = base64url_decode($sigB64);
    $result = openssl_verify($hdrB64 . '.' . $payB64, $sig, $pubKey, $algMap[$alg]);
    if ($result !== 1) throw new RuntimeException('JWT signature invalid');

    // Standard claim validation
    $now = time();
    if (isset($payload['exp']) && to_int($payload['exp']) < $now - 60) {
        throw new RuntimeException('ID token has expired');
    }
    if (isset($payload['iat']) && to_int($payload['iat']) > $now + 60) {
        throw new RuntimeException('ID token iat is in the future');
    }
    if (isset($expect['iss']) && ($payload['iss'] ?? '') !== $expect['iss']) {
        throw new RuntimeException('ID token issuer mismatch');
    }
    if (isset($expect['aud'])) {
        $aud   = $payload['aud'] ?? '';
        $audOk = (is_string($aud) && $aud === $expect['aud'])
              || (is_array($aud)  && in_array($expect['aud'], $aud, true));
        if (!$audOk) throw new RuntimeException('ID token audience mismatch');
    }
    if (isset($expect['nonce']) && ($payload['nonce'] ?? '') !== $expect['nonce']) {
        throw new RuntimeException('ID token nonce mismatch');
    }

    return $payload;
}

/**
 * v3.29.0 #1099 — Normalise OIDC ID-token claims into the four fields
 * `oidc_callback.php` (and `oidc_resolve_user()` / `oidc_provision_user()`)
 * actually use. Extracted from the inline payload-handling block at the
 * top of `oidc_callback.php` for testability.
 *
 * Rules (unchanged from the v2.x behaviour):
 *
 *   - `sub`                 : whatever the IdP sent, stringified.
 *   - `email`               : trim → first 255 bytes.
 *   - `name`                : trim → first 255 bytes.
 *   - `preferred_username`  : trim → first 64 bytes → strip everything
 *                             except `[a-zA-Z0-9._@\-]` (#111). The
 *                             sanitisation is necessary because this
 *                             field flows directly into local username
 *                             matching + auto-provisioning.
 *
 * Returns an associative array with exactly those four keys, all
 * strings (possibly empty).
 *
 * @param array<string, mixed> $payload Raw decoded ID-token claims.
 * @return array{sub: string, email: string, name: string, preferred_username: string}
 */
function oidc_extract_claims(array $payload): array
{
    $sub                = to_str($payload['sub']                ?? '');
    $email              = substr(trim(to_str($payload['email']              ?? '')), 0, 255);
    $name               = substr(trim(to_str($payload['name']               ?? '')), 0, 255);
    $preferredUsername  = substr(trim(to_str($payload['preferred_username'] ?? '')), 0, 64);
    // #111 sanitise: strip characters not allowed in local usernames.
    $preferredUsername  = preg_replace('/[^a-zA-Z0-9._@\-]/', '', $preferredUsername) ?? '';
    return [
        'sub'                => $sub,
        'email'              => $email,
        'name'               => $name,
        'preferred_username' => $preferredUsername,
    ];
}

/**
 * v3.29.0 #1099 — Resolve an OIDC login to an existing local user via
 * the three-step lookup chain documented in #867:
 *
 *   1. **current-by-sub**: any user already linked to this `oidc_sub`.
 *      Wins unconditionally — this is the steady-state path for every
 *      established OIDC login.
 *   2. **unlinked-by-username**: an existing local account whose
 *      `username` matches the (sanitised) `preferred_username` claim
 *      AND that has no `oidc_sub` yet. Allows auto-link to flip a
 *      legacy local account onto OIDC on first SSO. Skipped if the
 *      claim is empty.
 *   3. **unlinked-by-email**: an existing local account whose `email`
 *      matches AND that has no `oidc_sub` yet. Email-only match is
 *      separate from username match by design (#107) — we never match
 *      an account by BOTH username and email simultaneously, to
 *      prevent cross-account linking when a user happens to have
 *      another user's email in their preferred_username slot.
 *
 * Returns the user row (with `id`, `username`, `role`, `is_active`) or
 * null when none of the three lookups hit. The caller is responsible
 * for then calling `oidc_provision_user()` if auto-provision is on.
 *
 * @return array<string, mixed>|null
 */
function oidc_resolve_user(PDO $db, string $sub, string $email, string $preferredUsername): ?array
{
    // PR #1205 review: an empty sub creates a shared oidc_sub='' bucket —
    // any later token missing `sub` would resolve to whichever row was
    // provisioned first. Refuse before touching the DB.
    if ($sub === '') {
        throw new RuntimeException('oidc_resolve_user: missing OIDC subject (sub)');
    }
    $st = $db->prepare("SELECT id, username, role, is_active FROM users WHERE oidc_sub = :sub");
    $st->execute([':sub' => $sub]);
    $row = $st->fetch();
    if (is_array($row)) {
        /** @var array<string,mixed> $row */
        return $row;
    }

    if ($preferredUsername !== '') {
        $st2 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE username = :u AND oidc_sub IS NULL");
        $st2->execute([':u' => $preferredUsername]);
        $row = $st2->fetch();
        if (is_array($row)) {
            /** @var array<string,mixed> $row */
            return $row;
        }
    }

    if ($email !== '') {
        // PR #1205 review: SQLite and PostgreSQL compare email case-sensitively
        // by default — an IdP that varies the email casing across requests
        // would miss the existing local row and either auto-provision a
        // duplicate or fail the SSO login. Normalise both sides to LOWER().
        $st3 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE LOWER(email) = LOWER(:e) AND oidc_sub IS NULL");
        $st3->execute([':e' => $email]);
        $row = $st3->fetch();
        if (is_array($row)) {
            /** @var array<string,mixed> $row */
            return $row;
        }
    }

    return null;
}

/**
 * v3.29.0 #1099 — Provision a new local user from OIDC claims. Caller
 * has decided auto-provision is allowed and the resolve-chain missed.
 *
 * Username derivation (each fallback is exercised by the unit tests):
 *
 *   1. `claims['preferred_username']` if non-empty (already sanitised
 *      by `oidc_extract_claims()`).
 *   2. Local-part of `claims['email']` (everything before the `@`),
 *      with the same `#111` sanitisation rule applied.
 *   3. First 64 bytes of `claims['sub']`, sanitised.
 *   4. Literal string `'oidcuser'`.
 *
 * Username collisions are handled by appending `_2`, `_3`, … up to 5
 * total attempts; throws `RuntimeException` on persistent collision so
 * the caller can surface the failure (in production this surfaces via
 * `oidc_fail()` which renders the public error page).
 *
 * Password hash is the `!disabled` sentinel (#1120) — `password_verify`
 * returns false against it for any input, and lockout-protection
 * guards can recognise the value as "OIDC-only account".
 *
 * Returns the new user's auto-increment ID. Audit is the caller's job
 * (we don't have the canonical username back until the INSERT lands).
 *
 * @param array{sub: string, email: string, name: string, preferred_username: string} $claims
 * @return array{id: int, username: string}
 */
function oidc_provision_user(PDO $db, array $claims, string $role): array
{
    $allowedRoles = ['admin', 'netops', 'readonly'];
    $effectiveRole = in_array($role, $allowedRoles, true) ? $role : 'readonly';

    $sub               = $claims['sub'];
    // PR #1205 review: never persist an empty oidc_sub — see
    // oidc_resolve_user() for the shared-bucket rationale.
    if ($sub === '') {
        throw new RuntimeException('oidc_provision_user: missing OIDC subject (sub)');
    }
    $email             = $claims['email'];
    $name              = $claims['name'];
    $preferredUsername = $claims['preferred_username'];

    $emailLocalPart = '';
    if ($email !== '' && strpos($email, '@') !== false) {
        // PR #1205 review: cap at users.username's 64-char limit so a long
        // local-part fallback doesn't hard-fail the initial INSERT before
        // the collision-retry path ever runs.
        $emailLocalPart = preg_replace('/[^a-zA-Z0-9._@\-]/', '', explode('@', $email)[0]) ?? '';
        $emailLocalPart = substr($emailLocalPart, 0, 64);
    }
    $subSanitised = preg_replace('/[^a-zA-Z0-9._@\-]/', '', substr($sub, 0, 64)) ?? '';

    $newUsername = $preferredUsername !== ''
        ? $preferredUsername
        : ($emailLocalPart !== ''
            ? $emailLocalPart
            : ($subSanitised !== '' ? $subSanitised : 'oidcuser'));

    $unusableHash = '!disabled'; // #1120 sentinel — see oidc_callback.php

    $baseUsername = $newUsername;
    $insertSql = "INSERT INTO users (username, password_hash, role, is_active, oidc_sub, name, email)
                  VALUES (:u, :h, :r, 1, :sub, :n, :e)";
    for ($attempt = 0; $attempt < 5; $attempt++) {
        // Re-prepare per attempt: SQLite leaves a PDOStatement in a "bad
        // parameter or other API misuse" state after a UNIQUE-violation
        // execute(), so re-using the same statement for the retry would
        // fail with SQLSTATE[HY000] regardless of the bind values.
        $ins = $db->prepare($insertSql);
        try {
            $ins->execute([
                ':u'   => $newUsername,
                ':h'   => $unusableHash,
                ':r'   => $effectiveRole,
                ':sub' => $sub,
                ':n'   => $name,
                ':e'   => $email,
            ]);
            $newId = ipam_last_insert_id($db, 'users');
            return ['id' => $newId, 'username' => $newUsername];
        } catch (PDOException $ex) {
            // Only retry on a username UNIQUE violation. Any other DB error
            // (e.g. an oidc_sub collision race, schema mismatch, connection
            // loss) must surface — silently retrying 5x and rebranding as
            // "username collision" was the original v3.29.0 PR #1205 finding.
            $sqlstate = (string)($ex->errorInfo[0] ?? '');
            $msg      = $ex->getMessage();
            $isUnique = $sqlstate === '23000' || $sqlstate === '23505'
                || stripos($msg, 'unique') !== false
                || stripos($msg, 'duplicate') !== false;
            $isUsernameCollision = $isUnique && stripos($msg, 'username') !== false;
            if (!$isUsernameCollision) {
                throw $ex;
            }
            if ($attempt >= 4) {
                throw new RuntimeException(
                    'oidc_provision_user: username collision after 5 attempts',
                    0,
                    $ex
                );
            }
            // PR #1205 review: keep retry username within the users.username
            // 64-char limit — a 64-char base would otherwise turn a recoverable
            // collision into a hard insert failure on the retry path.
            $suffix      = '_' . ($attempt + 2);
            $newUsername = substr($baseUsername, 0, 64 - strlen($suffix)) . $suffix;
        }
    }
    // Loop always returns or throws.
    throw new RuntimeException('oidc_provision_user: unreachable');
}

/**
 * Convert an RSA JWK (n, e fields) to a PEM-encoded public key.
 * Builds the DER SubjectPublicKeyInfo structure manually so we have
 * no dependency on ext-gmp or any JOSE library.
 */
/** @param array<string, mixed> $jwk */
function jwk_rsa_to_pem(array $jwk): string
{
    $n = base64url_decode(to_str($jwk['n'] ?? ''));
    $e = base64url_decode(to_str($jwk['e'] ?? ''));
    if ($n === '' || $e === '') throw new RuntimeException('JWK missing n or e');

    // DER integers must not have a leading 1-bit (would be interpreted as negative)
    if (ord($n[0]) & 0x80) $n = "\x00" . $n;
    if (ord($e[0]) & 0x80) $e = "\x00" . $e;

    $intN   = "\x02" . der_len(strlen($n)) . $n;
    $intE   = "\x02" . der_len(strlen($e)) . $e;
    $rsaSeq = "\x30" . der_len(strlen($intN) + strlen($intE)) . $intN . $intE;

    // AlgorithmIdentifier for rsaEncryption (OID 1.2.840.113549.1.1.1) with NULL params
    $oid   = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $algId = "\x30" . der_len(strlen($oid)) . $oid;

    // BIT STRING: 0x00 unused-bits prefix + DER RSAPublicKey
    $bitStr = "\x03" . der_len(strlen($rsaSeq) + 1) . "\x00" . $rsaSeq;

    // SubjectPublicKeyInfo SEQUENCE
    $spki = "\x30" . der_len(strlen($algId) + strlen($bitStr)) . $algId . $bitStr;

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/** Encode an ASN.1 DER length. */
function der_len(int $len): string
{
    if ($len < 0x80) return chr($len);
    if ($len < 0x100) return "\x81" . chr($len);
    return "\x82" . chr($len >> 8) . chr($len & 0xFF);
}

// ---------------------------------------------------------------------------
// Network scanning — v2.3.0 (#319, #320, #321, #322, #323, #324)
// ---------------------------------------------------------------------------

/**
 * Probe a single IP via ICMP ping.
 *
 * Uses the system `ping` binary (available on Linux, macOS, BSD).
 * The IP MUST be validated by normalize_ip() before this call is made.
 *
 * @return int|null  Round-trip latency in milliseconds parsed from ping output,
 *                   or null if the host did not respond or ICMP is unavailable.
 *                   Returns null and emits an error_log entry when CAP_NET_RAW
 *                   is missing (ping exit code 2 — permission denied).
 */
function ipam_probe_icmp(string $ip, int $timeoutMs = 1000): ?int
{
    // Detect OS for the correct timeout flag
    $isWindows = stripos(PHP_OS, 'WIN') === 0;
    if ($isWindows) return null; // not supported

    $isMac = stripos(PHP_OS, 'Darwin') === 0;
    $timeoutSec = max(1, (int) round($timeoutMs / 1000));

    if ($isMac) {
        // macOS: -W <milliseconds>
        $cmd = ['ping', '-c1', '-W' . $timeoutMs, $ip];
    } else {
        // Linux/BSD: -W <seconds>
        $cmd = ['ping', '-c1', '-W' . $timeoutSec, $ip];
    }

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $desc, $pipes); // nosemgrep: php.lang.security.exec-use.exec-use
    if (!is_resource($proc)) return null;

    // Read stdout/stderr to EOF *before* closing pipes. Closing the read end
    // while ping is still writing causes SIGPIPE, which makes ping exit non-zero
    // even when the host responded — producing false "host down" results.
    $stdout = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]); // drain stderr
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);

    if ($exitCode === 2) {
        // Permission denied — CAP_NET_RAW capability not available (common in
        // Docker containers). Log once so operators know; return null so the
        // scan records the address as non-responsive rather than silently
        // masking the capability failure.
        error_log('ipam_probe_icmp: ping exited with code 2 (permission denied) — '
            . 'ensure CAP_NET_RAW is available (e.g. cap_add: [NET_RAW] in Docker Compose)');
        return null;
    }

    if ($exitCode !== 0) return null; // host did not respond or timed out

    // Parse actual RTT from ping stdout: matches "time=1.23 ms" or "time<1.23 ms"
    // This is the true round-trip time, not wall-clock process duration.
    if (preg_match('/time[=<]([\d.]+)\s*ms/i', $stdout, $m)) {
        return max(0, (int) round((float) $m[1]));
    }

    // Ping reported success but stdout had no RTT line (unusual) — return 0.
    return 0;
}

/**
 * Probe a single IP via TCP connection.
 *
 * The IP MUST be validated by normalize_ip() before this call is made.
 *
 * @return int|null  Connection latency in milliseconds, or null on failure.
 */
function ipam_probe_tcp(string $ip, int $port, int $timeoutMs = 1000): ?int
{
    $timeout = $timeoutMs / 1000.0;
    $start = microtime(true);
    $sock = @fsockopen($ip, $port, $errno, $errstr, $timeout);
    if (!is_resource($sock)) return null;
    $latency = (int) round((microtime(true) - $start) * 1000);
    fclose($sock);
    return $latency;
}

/**
 * Scan all registered addresses in a subnet and persist the results.
 *
 * @param  string   $method   'icmp' | 'tcp' | 'both'
 * @param  int|null $tcpPort  TCP port to probe when method is 'tcp' or 'both'
 * @param  int      $staleThreshold  Consecutive misses before marking address stale (default: 3)
 * @return array{scanned:int, up:int, down:int, stale_marked:int}
 */
/**
 * Return the reserved binary IPs for a subnet (network + IPv4 broadcast).
 *
 * @return array{network:?string, broadcast:?string}
 */
function ipam_subnet_reserved_bins(PDO $db, int $subnetId): array
{
    $st = $db->prepare("SELECT cidr FROM subnets WHERE id = :id");
    $st->execute([':id' => $subnetId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return ['network' => null, 'broadcast' => null];
    }
    $cidr = to_str($row['cidr'] ?? '');
    if ($cidr === '') {
        return ['network' => null, 'broadcast' => null];
    }
    $parsed = parse_cidr($cidr);
    if ($parsed === null) {
        return ['network' => null, 'broadcast' => null];
    }
    return [
        'network'   => $parsed['net_bin'],
        'broadcast' => ipam_compute_broadcast_bin($parsed['net_bin'], $parsed['prefix']),
    ];
}

/**
 * @return array{scanned:int, up:int, down:int, skipped:int, stale_marked:int}
 */
function ipam_scan_subnet(PDO $db, int $subnetId, string $method, ?int $tcpPort, int $staleThreshold = 3): array
{
    // Normalise and validate inputs
    if (!in_array($method, ['icmp', 'tcp', 'both'], true)) $method = 'icmp';
    if (in_array($method, ['tcp', 'both'], true) && ($tcpPort === null || $tcpPort < 1 || $tcpPort > 65535)) {
        // Invalid TCP port — fall back to ICMP-only to avoid false-stale marking
        $method = 'icmp';
        $tcpPort = null;
    }
    // After validation: if method needs TCP, $tcpPort is a valid port integer
    $validTcpPort = ($method === 'tcp' || $method === 'both') ? (int) $tcpPort : 0;

    // Reserved IPs for this subnet (network + IPv4 broadcast). Excluded from scan
    // targets: probing network/broadcast produces misleading results and some hosts
    // respond to broadcast pings. IPv6 and IPv4 /31, /32 have no reserved set.
    $reserved = ipam_subnet_reserved_bins($db, $subnetId);

    // Load all addresses in the subnet
    $st = $db->prepare("SELECT id, ip, ip_bin FROM addresses WHERE subnet_id = :sid ORDER BY ip_bin");
    $st->execute([':sid' => $subnetId]);
    $addresses = $st->fetchAll();

    $stats = ['scanned' => 0, 'up' => 0, 'down' => 0, 'skipped' => 0, 'stale_marked' => 0];
    $insert = $db->prepare("
        INSERT INTO scan_results (subnet_id, address_id, ip, method, is_up, latency_ms, scanned_at)
        VALUES (:sid, :aid, :ip, :method, :is_up, :lat, " . ipam_dialect()->now() . ")
    ");
    $updateSeen = $db->prepare("
        UPDATE addresses SET last_seen_at = " . ipam_dialect()->now() . ", is_stale = 0 WHERE id = :id
    ");

    foreach ($addresses as $row) {
        $ip = is_string($row['ip']) ? $row['ip'] : '';
        $addrId = is_int($row['id']) ? $row['id'] : (int) $row['id'];
        $ipBin = isset($row['ip_bin']) && is_string($row['ip_bin']) ? $row['ip_bin'] : '';

        // Skip the subnet's reserved IPs (network + IPv4 broadcast). These must
        // never be probed — some hosts respond to broadcast ICMP, producing
        // misleading up/down results.
        if ($ipBin !== '') {
            if ($reserved['network']   !== null && hash_equals($reserved['network'],   $ipBin)) { $stats['skipped']++; continue; }
            if ($reserved['broadcast'] !== null && hash_equals($reserved['broadcast'], $ipBin)) { $stats['skipped']++; continue; }
        }

        // Validate IP before any system call
        $norm = normalize_ip($ip);
        if ($norm === null) continue;
        $validIp = $norm['ip'];

        $latency = null;
        $isUp = false;

        if ($method === 'icmp' || $method === 'both') {
            $lat = ipam_probe_icmp($validIp);
            if ($lat !== null) { $latency = $lat; $isUp = true; }
        }
        if (($method === 'tcp' || $method === 'both') && $validTcpPort > 0 && !$isUp) {
            $lat = ipam_probe_tcp($validIp, $validTcpPort);
            if ($lat !== null) { $latency = $lat; $isUp = true; }
        }

        $insert->execute([
            ':sid'    => $subnetId,
            ':aid'    => $addrId,
            ':ip'     => $validIp,
            ':method' => $method,
            ':is_up'  => $isUp ? 1 : 0,
            ':lat'    => $latency,
        ]);

        if ($isUp) {
            $updateSeen->execute([':id' => $addrId]);
            $stats['up']++;
        } else {
            $stats['down']++;
        }
        $stats['scanned']++;
    }

    $stats['stale_marked'] = ipam_mark_stale_addresses($db, $subnetId, $staleThreshold);
    return $stats;
}

/**
 * Mark addresses stale when they missed N consecutive scans.
 * Clears is_stale when the most recent scan result is up.
 *
 * @return int  Number of addresses whose is_stale flag changed.
 */
function ipam_mark_stale_addresses(PDO $db, int $subnetId, int $missThreshold = 3): int
{
    // Defensive clamp — caller threshold comes from a tenant setting that
    // operators can mis-edit. 0/negative produces nonsense SQL (LIMIT 0
    // means no stale marking ever happens; negative errors on some engines).
    // Very large values fan out scan_results reads. Bound to [1, 50].
    // (#1162, PASS-C F-S2-05)
    if ($missThreshold < 1)  $missThreshold = 1;
    if ($missThreshold > 50) $missThreshold = 50;

    // Reserved IPs (network, IPv4 broadcast) are excluded from stale marking so
    // they don't accrue a stale flag from historical scan_results rows.
    $reserved = ipam_subnet_reserved_bins($db, $subnetId);

    // Fetch per-address: count of recent consecutive down results
    $st = $db->prepare("
        SELECT a.id,
               a.ip_bin,
               a.is_stale,
               (
                 SELECT COALESCE(SUM(CASE WHEN r.is_up = 0 THEN 1 ELSE 0 END), 0)
                 FROM (
                   SELECT is_up
                   FROM scan_results
                   WHERE address_id = a.id
                   ORDER BY scanned_at DESC
                   LIMIT :thresh
                 ) r
               ) AS recent_misses,
               (SELECT is_up FROM scan_results WHERE address_id = a.id ORDER BY scanned_at DESC LIMIT 1) AS last_up
        FROM addresses a
        WHERE a.subnet_id = :sid
    ");
    $st->bindValue(':sid', $subnetId, PDO::PARAM_INT);
    $st->bindValue(':thresh', $missThreshold, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();

    $changed = 0;
    $updates = [];

    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $currentlyStale = (int) $row['is_stale'];
        $misses = (int) $row['recent_misses'];
        $lastUp = $row['last_up'];
        $ipBin = isset($row['ip_bin']) && is_string($row['ip_bin']) ? $row['ip_bin'] : '';

        // Skip reserved IPs — they are never probed, so any historical scan data
        // should not drive their stale flag.
        if ($ipBin !== '') {
            if ($reserved['network']   !== null && hash_equals($reserved['network'],   $ipBin)) continue;
            if ($reserved['broadcast'] !== null && hash_equals($reserved['broadcast'], $ipBin)) continue;
        }

        $shouldBeStale = ($misses >= $missThreshold && $lastUp !== null && (int) $lastUp === 0) ? 1 : 0;

        if ($shouldBeStale !== $currentlyStale) {
            $updates[] = ['id' => $id, 'stale' => $shouldBeStale];
        }
    }

    if ($updates !== []) {
        $db->beginTransaction();
        try {
            $setStale = $db->prepare("UPDATE addresses SET is_stale = :stale, updated_at = " . ipam_dialect()->now() . " WHERE id = :id");
            foreach ($updates as $u) {
                $setStale->execute([':stale' => $u['stale'], ':id' => $u['id']]);
            }
            $changed = count($updates);
            // Audit with system actor (works in CLI and web contexts)
            $u = current_user();
            $db->prepare("INSERT INTO audit_log (user_id, username, action, entity_type, entity_id, details)
                          VALUES (:uid, :un, 'scan.stale_update', 'subnet', :eid, :dt)")
               ->execute([
                   ':uid' => $u['id'] ?: null,
                   ':un'  => $u['username'] !== '' ? $u['username'] : 'system',
                   ':eid' => $subnetId,
                   ':dt'  => "marked=$changed threshold=$missThreshold",
               ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    return $changed;
}

/**
 * Parse a pasted ARP / neighbour table into IP+MAC pairs.
 *
 * Accepts common dump formats:
 *   - `ip mac`         (space-separated, one per line)
 *   - `ip\tmac`        (tab-separated)
 *   - `ip,mac`         (CSV)
 *   - `ip mac iface`   (extra columns ignored)
 *
 * @return list<array{ip:string, mac:string}>
 */
function ipam_parse_arp_table(string $raw): array
{
    $results = [];
    $lines = preg_split('/\r?\n/', trim($raw));
    if ($lines === false) return [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        // Split on whitespace or commas
        $parts = preg_split('/[\s,]+/', $line);
        if ($parts === false || count($parts) < 2) continue;

        // Find the first valid IP and first valid MAC in the parts.
        // Strip parentheses to handle Linux `arp -a` format: hostname (ip) at mac ...
        $foundIp = null;
        $foundMac = null;
        foreach ($parts as $part) {
            $bare = trim($part, '()');
            if ($foundIp === null && filter_var($bare, FILTER_VALIDATE_IP) !== false) {
                $foundIp = $bare;
            } elseif ($foundMac === null && preg_match('/^([0-9a-fA-F]{2}[:\-.]){5}[0-9a-fA-F]{2}$/', $part)) {
                $foundMac = $part;
            }
            if ($foundIp !== null && $foundMac !== null) break;
        }

        if ($foundIp === null || $foundMac === null) continue;

        // Validate IP through normalize_ip for canonicalization
        $norm = normalize_ip($foundIp);
        if ($norm === null) continue;

        $results[] = ['ip' => $norm['ip'], 'mac' => $foundMac];
    }
    return $results;
}

/**
 * Apply parsed ARP entries: update addresses.mac for matching IPs in a subnet.
 *
 * @param  list<array{ip:string, mac:string}> $entries
 * @return array{matched:int, updated:int, skipped:int}
 */
function ipam_apply_arp_import(PDO $db, array $entries, int $subnetId): array
{
    $stats = ['matched' => 0, 'updated' => 0, 'skipped' => 0];
    $find = $db->prepare("SELECT id, mac FROM addresses WHERE subnet_id = :sid AND ip = :ip LIMIT 1");
    $update = $db->prepare("UPDATE addresses SET mac = :mac, updated_at = " . ipam_dialect()->now() . " WHERE id = :id");

    foreach ($entries as $entry) {
        $ip  = $entry['ip'];
        $mac = $entry['mac'];
        if ($ip === '' || $mac === '') { $stats['skipped']++; continue; }

        $find->execute([':sid' => $subnetId, ':ip' => $ip]);
        /** @var array<string, mixed>|false $addr */
        $addr = $find->fetch();
        if (!$addr) { $stats['skipped']++; continue; }

        $stats['matched']++;
        if (to_str($addr['mac']) === $mac) { $stats['skipped']++; continue; }

        $update->execute([':mac' => $mac, ':id' => to_int($addr['id'])]);
        $stats['updated']++;
    }
    return $stats;
}

// ── Password reset + email verification helpers (v3.2.0) ─────────────────────

/**
 * Return the canonical HTTPS base URL of this install (no trailing slash).
 * Used to build absolute links in emails.
 */
function ipam_app_base_url(): string
{
    $cfg  = $GLOBALS['config'] ?? null;
    $base = is_array($cfg) ? rtrim(to_str($cfg['base_url'] ?? ''), '/') : '';
    $parsed = parse_url($base);
    if ($base === '' || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) {
        throw new RuntimeException('config.base_url must be set to an https:// URL for email links.');
    }
    return $base;
}

/* ipam_create_reset_token(), ipam_consume_reset_token(), and               */
/* ipam_send_reset_email() moved to lib/auth_password.php in v3.30.0        */
/* (ADR-004 Phase 6 Task 6.2, #907).                                        */

/**
 * Initiate an email-change verification for $userId.
 * Stores pending_email + token hash + expiry on the user row and sends
 * a verification email to the NEW address.
 */
/**
 * @return array{success: bool, error: string}
 */
function ipam_send_email_verification(PDO $db, int $userId, string $newEmail): array
{
    $appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
    try {
        $base = ipam_app_base_url();
    } catch (\RuntimeException $e) {
        error_log('ipam_send_email_verification: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $link      = $base . '/change_password.php?verify_email=' . rawurlencode($rawToken);

    $subject = $appName . ' — Verify your new email address';

    $text = "Please verify your new email address for " . $appName . ".\n\n"
        . "Verification link (valid for 1 hour):\n" . $link . "\n\n"
        . "If you did not request this change, you can safely ignore this email.\n\n"
        . "— " . $appName;

    $html = "<p>Please verify your new email address for <strong>" . e($appName) . "</strong>.</p>"
        . "<p><a href=\"" . e($link) . "\">Verify email address</a> (link valid for 1 hour).</p>"
        . "<p>If you did not request this change, you can safely ignore this email.</p>"
        . "<p>— " . e($appName) . "</p>";

    $db->prepare(
        "UPDATE users SET pending_email = :email,
                          pending_email_token_hash = :hash,
                          pending_email_expires_at = :exp
          WHERE id = :id"
    )->execute([':email' => $newEmail, ':hash' => $tokenHash, ':exp' => $expiresAt, ':id' => $userId]);

    $result = ipam_send_mail($newEmail, $subject, $text, $html);
    if (!$result['success']) {
        $err = to_str($result['error'] ?? 'Email send failed.');
        error_log('ipam_send_email_verification: ' . $err);
        $db->prepare(
            "UPDATE users SET pending_email = NULL,
                              pending_email_token_hash = NULL,
                              pending_email_expires_at = NULL
              WHERE id = :id"
        )->execute([':id' => $userId]);
        return ['success' => false, 'error' => $err];
    }

    return ['success' => true, 'error' => ''];
}

// ---------------------------------------------------------------------------
// DHCP config renderers — v3.4.0 #403
// ---------------------------------------------------------------------------

/**
 * Render an ISC dhcpd.conf snippet for the given (or all) IPv4 subnets.
 *
 * @param int[] $subnetIds Leave empty to include all IPv4 subnets.
 */
function ipam_render_dhcpd_conf(PDO $db, array $subnetIds): string
{
    require_once __DIR__ . '/version.php';
    $subnets = ipam_dhcp_load_subnets($db, $subnetIds);
    $lines   = [
        '# Generated by Simple PHP IPAM v' . IPAM_VERSION
            . ' on ' . gmdate('Y-m-d H:i:s') . ' UTC',
        '# Subnets: ' . count($subnets),
        '',
    ];

    foreach ($subnets as $s) {
        $netmask = ipam_prefix_to_netmask(to_int($s['prefix']));
        $lines[] = 'subnet ' . to_str($s['network']) . ' netmask ' . $netmask . ' {';

        if (!empty($s['dhcp_routers'])) {
            $safeIps = implode(', ', array_filter(
                array_map('trim', explode(',', to_str($s['dhcp_routers']))),
                fn($t) => $t !== '' && filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ));
            if ($safeIps !== '') $lines[] = '  option routers ' . $safeIps . ';';
        }
        if (!empty($s['dhcp_dns_servers'])) {
            $safeIps = implode(', ', array_filter(
                array_map('trim', explode(',', to_str($s['dhcp_dns_servers']))),
                fn($t) => $t !== '' && filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ));
            if ($safeIps !== '') $lines[] = '  option domain-name-servers ' . $safeIps . ';';
        }
        if (!empty($s['dhcp_domain_name'])) {
            $domain = trim(to_str($s['dhcp_domain_name']));
            if (preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-.]{0,253}[a-zA-Z0-9])?$/', $domain)) {
                $quoted  = str_replace(['\\', '"'], ['\\\\', '\\"'], $domain);
                $lines[] = '  option domain-name "' . $quoted . '";';
            }
        }
        if ($s['dhcp_lease_default'] !== null && $s['dhcp_lease_default'] !== '') {
            $lines[] = '  default-lease-time ' . to_int($s['dhcp_lease_default']) . ';';
        }
        if ($s['dhcp_lease_max'] !== null && $s['dhcp_lease_max'] !== '') {
            $lines[] = '  max-lease-time ' . to_int($s['dhcp_lease_max']) . ';';
        }
        if (!empty($s['dhcp_next_server'])) {
            $nextSrv = trim(to_str($s['dhcp_next_server']));
            if (filter_var($nextSrv, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $lines[] = '  next-server ' . $nextSrv . ';';
            }
        }
        if (!empty($s['dhcp_boot_filename'])) {
            $bootFile = preg_replace('/[;\n\r{}]/', '', trim(to_str($s['dhcp_boot_filename']))) ?? '';
            if ($bootFile !== '') {
                $quoted  = str_replace(['\\', '"'], ['\\\\', '\\"'], $bootFile);
                $lines[] = '  filename "' . $quoted . '";';
            }
        }

        foreach (ipam_dhcp_load_reservations($db, to_int($s['id'])) as $r) {
            $mac  = ipam_normalize_mac_for_dhcp(to_str($r['mac']));
            if ($mac === null) continue;
            $rawHost = to_str($r['hostname']);
            $label   = ipam_dhcp_normalize_hostname($rawHost) ?? 'host';
            $suffix  = str_replace('.', '-', to_str($r['ip']));
            $name    = $label . '-' . $suffix;
            $lines[] = '  host ' . $name . ' {';
            $lines[] = '    hardware ethernet ' . $mac . ';';
            $lines[] = '    fixed-address ' . to_str($r['ip']) . ';';
            $lines[] = '  }';
        }

        $lines[] = '}';
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * Render a Kea 2.x JSON config fragment for the given (or all) IPv4 subnets.
 *
 * @param int[] $subnetIds Leave empty to include all IPv4 subnets.
 */
function ipam_render_kea_json(PDO $db, array $subnetIds): string
{
    $subnets = ipam_dhcp_load_subnets($db, $subnetIds);
    $subnet4 = [];

    foreach ($subnets as $s) {
        /** @var array<string,mixed> $entry */
        $entry = ['subnet' => to_str($s['cidr'])];

        $optionData = [];
        if (!empty($s['dhcp_routers'])) {
            $safeRouters = implode(',', array_filter(
                array_map('trim', explode(',', to_str($s['dhcp_routers']))),
                fn($t) => $t !== '' && filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ));
            if ($safeRouters !== '') $optionData[] = ['name' => 'routers', 'data' => $safeRouters];
        }
        if (!empty($s['dhcp_dns_servers'])) {
            $safeDns = implode(',', array_filter(
                array_map('trim', explode(',', to_str($s['dhcp_dns_servers']))),
                fn($t) => $t !== '' && filter_var($t, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ));
            if ($safeDns !== '') $optionData[] = ['name' => 'domain-name-servers', 'data' => $safeDns];
        }
        if (!empty($s['dhcp_domain_name'])) {
            $optionData[] = ['name' => 'domain-name', 'data' => trim(to_str($s['dhcp_domain_name']))];
        }
        if (!empty($s['dhcp_next_server'])) {
            $optionData[] = ['name' => 'tftp-server-name', 'data' => trim(to_str($s['dhcp_next_server']))];
        }
        if (!empty($s['dhcp_boot_filename'])) {
            $optionData[] = ['name' => 'boot-file-name', 'data' => trim(to_str($s['dhcp_boot_filename']))];
        }
        if (!empty($optionData)) {
            $entry['option-data'] = $optionData;
        }

        if ($s['dhcp_lease_default'] !== null && $s['dhcp_lease_default'] !== '') {
            $entry['valid-lifetime'] = to_int($s['dhcp_lease_default']);
        }
        if ($s['dhcp_lease_max'] !== null && $s['dhcp_lease_max'] !== '') {
            $entry['max-valid-lifetime'] = to_int($s['dhcp_lease_max']);
        }

        $reservations = ipam_dhcp_load_reservations($db, to_int($s['id']));
        if (!empty($reservations)) {
            $resArr = [];
            foreach ($reservations as $r) {
                $mac = ipam_normalize_mac_for_dhcp(to_str($r['mac']));
                if ($mac === null) continue;
                /** @var array<string,mixed> $resEntry */
                $resEntry = [
                    'hw-address' => $mac,
                    'ip-address' => to_str($r['ip']),
                ];
                $label = ipam_dhcp_normalize_hostname(to_str($r['hostname']));
                if ($label !== null) {
                    $resEntry['hostname'] = $label;
                }
                $resArr[] = $resEntry;
            }
            $entry['reservations'] = $resArr;
        }

        $subnet4[] = $entry;
    }

    $doc = ['Dhcp4' => ['subnet4' => $subnet4]];
    $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    return $json;
}

/**
 * Load IPv4 subnets with DHCP option columns, ordered by network_bin.
 *
 * @param int[] $subnetIds If non-empty, restrict to these IDs.
 * @return list<array<string,mixed>>
 */
function ipam_dhcp_load_subnets(PDO $db, array $subnetIds): array
{
    $sql = "SELECT id, cidr, network, prefix,
                   dhcp_routers, dhcp_dns_servers, dhcp_domain_name,
                   dhcp_lease_default, dhcp_lease_max,
                   dhcp_next_server, dhcp_boot_filename
            FROM subnets
            WHERE ip_version = 4";

    if (empty($subnetIds)) {
        $st = $db->query($sql . " ORDER BY network_bin");
        if ($st === false) return [];
        /** @var list<array<string,mixed>> */
        return $st->fetchAll();
    }

    $placeholders = implode(',', array_fill(0, count($subnetIds), '?'));
    $st = $db->prepare($sql . " AND id IN ({$placeholders}) ORDER BY network_bin");
    $st->execute(array_values(array_map('intval', $subnetIds)));
    /** @var list<array<string,mixed>> */
    return $st->fetchAll();
}

/**
 * Load reserved addresses with a non-empty MAC for a subnet.
 *
 * @return list<array<string,mixed>>
 */
function ipam_dhcp_load_reservations(PDO $db, int $subnetId): array
{
    $st = $db->prepare(
        "SELECT ip, mac, hostname FROM addresses
         WHERE subnet_id = :sid AND status = 'reserved' AND mac != ''
         ORDER BY ip_bin"
    );
    $st->execute([':sid' => $subnetId]);
    /** @var list<array<string,mixed>> */
    return $st->fetchAll();
}

/** Convert a CIDR prefix length to a dotted-decimal netmask string. */
function ipam_prefix_to_netmask(int $prefix): string
{
    if ($prefix === 0) return '0.0.0.0';
    $mask = (int)((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
    return (string)(inet_ntop(pack('N', $mask)) ?: '0.0.0.0');
}

/** Normalise any MAC address format to colon-separated lowercase octets. */
function ipam_normalize_mac_for_dhcp(string $mac): ?string
{
    $hex = preg_replace('/[^0-9a-fA-F]/', '', $mac);
    if (!is_string($hex) || strlen($hex) !== 12) return null;
    return implode(':', str_split(strtolower($hex), 2));
}

/**
 * Normalise a DHCP reservation hostname for both Kea and ISC dhcpd output.
 * Returns null if no usable label can be derived; caller falls back to
 * 'host' synthesis.
 *
 * Single-label, RFC 1123 letter-digit-hyphen, leading alpha (after trimming
 * leading digits/hyphens), max 63 chars per label. Dotted inputs take the
 * leftmost label — multi-label FQDNs are out of scope; neither current
 * renderer supports them well.
 *
 * (#1163, PASS-C F-S6-01) — both renderers must agree on what's emitted.
 */
function ipam_dhcp_normalize_hostname(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') return null;
    $label = explode('.', $raw, 2)[0];
    $label = preg_replace('/[^a-zA-Z0-9-]/', '-', $label) ?? '';
    $label = ltrim($label, '0123456789-');
    $label = rtrim($label, '-');
    if ($label === '') return null;
    if (strlen($label) > 63) {
        $label = substr($label, 0, 63);
        // Truncation can land on a hyphen; re-trim so the emitted label
        // still conforms to RFC 1123 (no trailing '-').
        $label = rtrim($label, '-');
        if ($label === '') return null;
    }
    return $label;
}

// ── Custom field definitions (v3.5.0, #313/#596) ──────────────────────────

/**
 * Return all non-deleted custom field definitions, ordered by entity_type,
 * sort_order, then key. When $entityType is provided only that entity's
 * definitions are returned.
 *
 * @return list<array<string,mixed>>
 */
function custom_field_def_list(PDO $db, ?string $entityType = null): array
{
    $k = ipam_key_col();
    if ($entityType !== null) {
        $st = $db->prepare(
            "SELECT * FROM custom_field_defs WHERE is_deleted = 0 AND entity_type = :et
             ORDER BY sort_order, $k"
        );
        $st->execute([':et' => $entityType]);
    } else {
        $st = $db->query(
            "SELECT * FROM custom_field_defs WHERE is_deleted = 0
             ORDER BY entity_type, sort_order, $k"
        );
        if ($st === false) throw new \RuntimeException('Query failed');
    }
    /** @var list<array<string,mixed>> */
    return $st->fetchAll();
}

/**
 * Return true if any row in the table that corresponds to $entityType has a
 * non-null JSON value stored for $key.  Uses dialect-specific JSON extraction
 * so it works on SQLite, MySQL 8.0+, and PostgreSQL 14+.
 *
 * $key is validated to match ^[a-z][a-z0-9_]{0,62}$ before this is called,
 * so it is safe to embed in the JSON path string.
 */
function custom_field_in_use(PDO $db, string $key, string $entityType): bool
{
    $tbl    = $entityType === 'subnet' ? 'subnets' : 'addresses';
    $driver = ipam_dialect()->driver_name();

    if ($driver === 'sqlite') {
        $st = $db->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$tbl} WHERE json_extract(custom_fields, '$.' || :k) IS NOT NULL)"
        );
    } elseif ($driver === 'mysql') {
        $st = $db->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$tbl} WHERE JSON_EXTRACT(custom_fields, CONCAT('$.', :k)) IS NOT NULL)"
        );
    } else {
        $st = $db->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$tbl} WHERE (custom_fields::json)->>:k IS NOT NULL)"
        );
    }
    $st->execute([':k' => $key]);
    return (bool)$st->fetchColumn();
}

/**
 * Decode a JSON custom_fields column value into an associative array.
 * Returns [] on empty, null, or malformed JSON.
 *
 * @return array<string,mixed>
 */
function parse_custom_fields_row(string $json): array
{
    if ($json === '' || $json === 'null') return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Canonical JSON serialization for the custom_fields column.
 * Null values are stripped so the stored JSON stays compact.
 *
 * @param array<mixed,mixed> $values
 */
function serialize_custom_fields_row(array $values): string
{
    $filtered = array_filter($values, fn($v) => $v !== null);
    return json_encode($filtered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
}

/**
 * Validate a raw POST payload against the active custom field definitions.
 * Returns the typed values array (ready for serialize_custom_fields_row).
 * Throws InvalidArgumentException on the first type mismatch or missing required field.
 *
 * @param list<array<string,mixed>> $defs     Output of custom_field_def_list()
 * @param array<mixed,mixed>        $payload  Flat key→raw-value map from $_POST or json_decode (keys without the 'cf_' prefix)
 * @return array<string,mixed>
 */
function validate_custom_fields_payload(array $defs, array $payload): array
{
    $result = [];
    foreach ($defs as $def) {
        $key      = to_str($def['key']);
        $type     = to_str($def['type']);
        $required = (bool)$def['is_required'];

        // Boolean: checkbox presence = true, absence = false; required does not apply
        if ($type === 'boolean') {
            $result[$key] = isset($payload[$key]) && $payload[$key] !== '' && $payload[$key] !== '0';
            continue;
        }

        $raw = isset($payload[$key]) ? to_str($payload[$key]) : '';

        if ($raw === '') {
            if ($required) {
                throw new \InvalidArgumentException($key . ': this field is required');
            }
            $result[$key] = null;
            continue;
        }

        switch ($type) {
            case 'text':
                $result[$key] = $raw;
                break;
            case 'number':
                if (!is_numeric($raw)) {
                    throw new \InvalidArgumentException($key . ': expected a number');
                }
                $result[$key] = str_contains($raw, '.') ? (float)$raw : (int)$raw;
                break;
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                    throw new \InvalidArgumentException($key . ': expected YYYY-MM-DD format');
                }
                $result[$key] = $raw;
                break;
            case 'select':
                $options = json_decode(to_str($def['options'] ?? '[]'), true);
                $options = is_array($options) ? $options : [];
                if (!in_array($raw, $options, true)) {
                    throw new \InvalidArgumentException($key . ': not a valid option');
                }
                $result[$key] = $raw;
                break;
        }
    }
    return $result;
}

/**
 * Validate a custom_fields payload arriving from the JSON API.
 * Values are already typed (int, float, bool, string, null) — no coercion is done.
 * Unknown keys are rejected. Required fields must be non-null.
 *
 * @param list<array<string,mixed>> $defs     Output of custom_field_def_list()
 * @param array<mixed,mixed>        $payload  Decoded JSON object (key→typed value)
 * @return array<string,mixed>
 * @throws \InvalidArgumentException on the first type mismatch, unknown key, or missing required field
 */
function validate_custom_fields_api_payload(array $defs, array $payload): array
{
    $defKeys = [];
    foreach ($defs as $def) {
        $defKeys[to_str($def['key'])] = $def;
    }

    // Reject unknown keys
    foreach (array_keys($payload) as $k) {
        if (!isset($defKeys[$k])) {
            throw new \InvalidArgumentException($k . ': unknown custom field key');
        }
    }

    $result = [];
    foreach ($defs as $def) {
        $key      = to_str($def['key']);
        $type     = to_str($def['type']);
        $required = (bool)$def['is_required'];

        $present = array_key_exists($key, $payload);
        $val     = $present ? $payload[$key] : null;

        if ($val === null) {
            if ($required) {
                throw new \InvalidArgumentException($key . ': this field is required');
            }
            $result[$key] = null;
            continue;
        }

        switch ($type) {
            case 'text':
                if (!is_string($val)) {
                    throw new \InvalidArgumentException($key . ': expected string, got ' . gettype($val));
                }
                $result[$key] = $val;
                break;
            case 'number':
                if (!is_int($val) && !is_float($val)) {
                    throw new \InvalidArgumentException($key . ': expected number, got ' . gettype($val));
                }
                $result[$key] = $val;
                break;
            case 'date':
                if (!is_string($val) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    throw new \InvalidArgumentException($key . ': expected YYYY-MM-DD string');
                }
                $result[$key] = $val;
                break;
            case 'boolean':
                if (!is_bool($val)) {
                    throw new \InvalidArgumentException($key . ': expected boolean, got ' . gettype($val));
                }
                $result[$key] = $val;
                break;
            case 'select':
                if (!is_string($val)) {
                    throw new \InvalidArgumentException($key . ': expected string, got ' . gettype($val));
                }
                $options = json_decode(to_str($def['options'] ?? '[]'), true);
                $options = is_array($options) ? $options : [];
                if (!in_array($val, $options, true)) {
                    throw new \InvalidArgumentException($key . ': not a valid option');
                }
                $result[$key] = $val;
                break;
        }
    }
    return $result;
}

// ============================================================
// TOTP 2FA helpers (v3.6.0, #418)
// ============================================================

function ipam_totp_tfa(): \RobThree\Auth\TwoFactorAuth {
    static $tfa = null;
    if ($tfa === null) {
        $tfa = new \RobThree\Auth\TwoFactorAuth(null, 6, 30, \RobThree\Auth\Algorithm::Sha1);
    }
    return $tfa;
}

function ipam_totp_generate_secret(): string {
    return ipam_totp_tfa()->createSecret(160);
}

function ipam_totp_get_uri(string $secret, string $issuer, string $accountName): string {
    return ipam_totp_tfa()->getQRText($issuer . ':' . $accountName, $secret);
}

function ipam_totp_verify(string $secret, string $code, int $discrepancy = 1): bool {
    return ipam_totp_tfa()->verifyCode($secret, $code, $discrepancy);
}

function ipam_totp_encrypt_secret(string $secret, string $key): string {
    if ($key === '') {
        throw new \RuntimeException('TOTP encryption requires a non-empty app_secret');
    }
    $iv  = random_bytes(12);
    $tag = '';
    $enc = openssl_encrypt($secret, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($enc === false) {
        throw new \RuntimeException('TOTP secret encryption failed');
    }
    // Prefix '$2$' distinguishes GCM (authenticated) from legacy CBC blobs.
    return '$2$' . base64_encode($iv . $tag . $enc);
}

function ipam_totp_decrypt_secret(string $encSecret, string $key): string {
    if ($key === '') {
        throw new \RuntimeException('TOTP decryption requires a non-empty app_secret');
    }
    if (str_starts_with($encSecret, '$2$')) {
        // GCM path (v3.6.0+)
        $raw = base64_decode(substr($encSecret, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $iv      = substr($raw, 0, 12);
        $tag     = substr($raw, 12, 16);
        $payload = substr($raw, 28);
        $decrypted = openssl_decrypt($payload, 'aes-256-gcm', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($decrypted === false) {
            return '';
        }
        return $decrypted;
    }
    // Legacy CBC path — decrypt secrets enrolled before the GCM migration.
    $raw = base64_decode($encSecret, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }
    $iv        = substr($raw, 0, 16);
    $payload   = substr($raw, 16);
    $decrypted = openssl_decrypt($payload, 'aes-256-cbc', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
        return '';
    }
    return $decrypted;
}

/** @return list<string> */
function ipam_totp_generate_backup_codes(int $count = 8): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

/** @param list<string> $codes */
function ipam_totp_save_backup_codes(PDO $db, int $userId, array $codes): void {
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM totp_backup_codes WHERE user_id = :uid")->execute([':uid' => $userId]);
        $stmt = $db->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (:uid, :hash)");
        foreach ($codes as $code) {
            $stmt->execute([':uid' => $userId, ':hash' => password_hash($code, PASSWORD_DEFAULT)]);
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function ipam_totp_verify_backup_code(PDO $db, int $userId, string $code): bool {
    $stmt = $db->prepare(
        "SELECT id, code_hash FROM totp_backup_codes WHERE user_id = :uid AND used_at IS NULL"
    );
    $stmt->execute([':uid' => $userId]);
    $nowExpr = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : "NOW()";
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (password_verify($code, (string)$row['code_hash'])) {
            $consume = $db->prepare(
                "UPDATE totp_backup_codes SET used_at = {$nowExpr} WHERE id = :id AND used_at IS NULL"
            );
            $consume->execute([':id' => $row['id']]);
            return $consume->rowCount() === 1;
        }
    }
    return false;
}

// ============================================================
// API per-key rate limiting (v3.6.0, #419)
//   ipam_api_key_rate_limit_check() moved to lib/auth_rate_limit.php
//   in v3.30.0 (ADR-004 Phase 6 Task 6.3, #907).
// ============================================================

// ============================================================
// Session absolute lifetime moved to lib/auth.php in v3.30.0
// (ADR-004 Phase 6 Task 6.1, #907).
// ============================================================

// ============================================================
// Persistent account lockout helpers (v3.6.0, #421) (ipam_clear_persistent_lockout moved to lib/auth_rate_limit.php — Task 6.3)
// ============================================================

function ipam_is_persistently_locked(PDO $db, int $uid): bool {
    $stmt = $db->prepare("SELECT locked_until FROM users WHERE id = :id");
    $stmt->execute([':id' => $uid]);
    $lockedUntil = $stmt->fetchColumn();
    return $lockedUntil !== false && $lockedUntil !== null && strtotime((string)$lockedUntil . ' UTC') > time();
}

/** @param IpamConfig $config */
function ipam_record_2fa_failure(PDO $db, int $uid, array $config): void {
    $threshold   = (int)($config['auth']['lockout_after_failures'] ?? 10);
    $lockMinutes = (int)($config['auth']['lockout_duration_minutes'] ?? 30);

    $db->prepare("UPDATE users SET failed_auth_count = failed_auth_count + 1 WHERE id = :id")
       ->execute([':id' => $uid]);

    $stmt = $db->prepare("SELECT failed_auth_count FROM users WHERE id = :id");
    $stmt->execute([':id' => $uid]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $threshold) {
        $until = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
        $db->prepare("UPDATE users SET locked_until = :until, lock_reason = 'failed_2fa' WHERE id = :id")
           ->execute([':until' => $until, ':id' => $uid]);
    }
}

/* ipam_clear_persistent_lockout() moved to lib/auth_rate_limit.php in     */
/* v3.30.0 (ADR-004 Phase 6 Task 6.3, #907).                              */

// ============================================================
// Email OTP helpers (#684)
// ============================================================

/**
 * Generate a 6-digit email OTP for the given user, store a bcrypt hash,
 * set a TTL-based expiry, reset the attempt counter, and return the
 * plaintext code for delivery via email.
 *
 * Expiry is computed in PHP (date('Y-m-d H:i:s', time() + $ttlMinutes * 60))
 * so no dialect-specific SQL expression is needed.
 */
function ipam_email_otp_generate(PDO $db, int $userId, int $ttlMinutes = 10): string
{
    $code    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash    = password_hash($code, PASSWORD_DEFAULT);
    $expires = gmdate('Y-m-d H:i:s', time() + $ttlMinutes * 60);

    $db->prepare(
        "UPDATE users
            SET email_otp_hash       = :hash,
                email_otp_expires_at = :expires,
                email_otp_attempts   = 0
          WHERE id = :id"
    )->execute([':hash' => $hash, ':expires' => $expires, ':id' => $userId]);

    audit($db, 'mfa.otp.generate', 'user', $userId, "expires={$expires}");
    return $code;
}

/**
 * Verify a submitted email OTP code for the given user.
 *
 * Returns true only when:
 *   - a hash and expiry exist in the DB
 *   - fewer than 5 failed attempts have been recorded
 *   - the OTP has not expired
 *   - the code matches the stored bcrypt hash
 *
 * On success the OTP columns are cleared.
 * On failure the attempt counter is incremented.
 */
function ipam_email_otp_verify(PDO $db, int $userId, string $code): bool
{
    $stmt = $db->prepare(
        "SELECT email_otp_hash, email_otp_expires_at, email_otp_attempts
           FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $userId]);
    /** @var array<string,mixed>|false $row */
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    $hash     = is_string($row['email_otp_hash']       ?? null) ? $row['email_otp_hash']       : '';
    $expires  = is_string($row['email_otp_expires_at'] ?? null) ? $row['email_otp_expires_at'] : '';
    $attempts = to_int($row['email_otp_attempts'] ?? 0);

    if ($hash === '' || $expires === '') {
        return false;
    }

    if ($attempts >= 5) {
        ipam_email_otp_clear($db, $userId);
        audit($db, 'mfa.otp.locked', 'user', $userId, 'OTP locked: max attempts exceeded');
        return false;
    }

    // ISO datetime strings sort correctly as plain string comparison (both UTC)
    if ($expires < gmdate('Y-m-d H:i:s')) {
        ipam_email_otp_clear($db, $userId);
        audit($db, 'mfa.otp.expired', 'user', $userId, 'OTP expired');
        return false;
    }

    if (!password_verify($code, $hash)) {
        // #874 + CR #1100: increment unconditionally and use the
        // post-increment value via RETURNING (pgsql) or a follow-up
        // SELECT (sqlite/mysql) so EVERY concurrent failed attempt is
        // counted. The previous compare-and-swap version dropped
        // increments when two requests raced from the same baseline:
        // only one CAS won and the loser merely re-read the counter
        // without recording its own failure, so an attacker could
        // stretch the 5-strike lockout by firing N concurrent bad
        // codes against a single OTP. UPDATE … SET col = col + 1
        // is atomic at the row level on every supported engine.
        $upd = $db->prepare(
            "UPDATE users
                SET email_otp_attempts = email_otp_attempts + 1
              WHERE id = :id"
        );
        $upd->execute([':id' => $userId]);
        $reSt = $db->prepare("SELECT email_otp_attempts FROM users WHERE id = :id");
        $reSt->execute([':id' => $userId]);
        $current = to_int($reSt->fetchColumn() ?: 0);
        if ($current >= 5) {
            ipam_email_otp_clear($db, $userId);
            audit($db, 'mfa.otp.locked', 'user', $userId, 'OTP locked: max attempts exceeded');
        } else {
            audit($db, 'mfa.otp.fail', 'user', $userId, "attempt={$current}");
        }
        return false;
    }

    // Success — consume the OTP
    $db->prepare(
        "UPDATE users
            SET email_otp_hash       = NULL,
                email_otp_expires_at = NULL,
                email_otp_attempts   = 0
          WHERE id = :id"
    )->execute([':id' => $userId]);

    audit($db, 'mfa.otp.verify_ok', 'user', $userId, 'Email OTP verified successfully');
    return true;
}

/**
 * Clear all email OTP state for the given user (used on logout, password
 * change, or administrative reset). Pass a non-empty $reason to emit an
 * audit entry for the clear event (omit when the caller already audits).
 */
function ipam_email_otp_clear(PDO $db, int $userId, string $reason = ''): void
{
    $db->prepare(
        "UPDATE users
            SET email_otp_hash       = NULL,
                email_otp_expires_at = NULL,
                email_otp_attempts   = 0
          WHERE id = :id"
    )->execute([':id' => $userId]);
    if ($reason !== '') {
        audit($db, 'mfa.otp.clear', 'user', $userId, $reason);
    }
}

/**
 * Send a plaintext email OTP code to the given user's email address via ipam_send_mail().
 * Call this immediately after ipam_email_otp_generate() with the returned plaintext code.
 * Returns true on success, false on failure (SMTP not configured, no email set, etc.).
 */
function ipam_email_otp_send(PDO $db, int $userId, string $code, int $ttlMinutes = 10): bool
{
    $stmt = $db->prepare("SELECT email, username FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    /** @var array<string, mixed>|false $user */
    $user = $stmt->fetch();
    if (!$user || to_str($user['email'] ?? '') === '') {
        return false;
    }

    $appName = str_replace(["\r", "\n"], '', trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM');
    $to      = to_str($user['email']);
    $subject = '[' . $appName . '] Your verification code';
    $body    = "Your one-time verification code is:\n\n    {$code}\n\nThis code expires in {$ttlMinutes} minutes. Do not share it with anyone.\n\nIf you did not request this, please contact your administrator.";

    $result = ipam_send_mail($to, $subject, $body);
    if (!$result['success']) {
        $errMsg = $result['error'] ?? 'unknown';
        error_log('ipam_email_otp_send: failed to send OTP to user ' . $userId . ': ' . $errMsg);
        audit($db, 'mfa.otp.send_fail', 'user', $userId, substr(strip_tags($errMsg), 0, 200));
        return false;
    }
    $masked = preg_replace('/^(.).*(@.+)$/', '$1***$2', $to) ?: '***';
    audit($db, 'mfa.otp.send', 'user', $userId, "to={$masked}");
    return true;
}

// ── Passkeys (WebAuthn) ───────────────────────────────────────────────────────

function ipam_passkey_webauthn(?string $rpName = null): \lbuchs\WebAuthn\WebAuthn
{
    // Default rpName to the configured site name so password managers
    // (LastPass, 1Password, Bitwarden) display the install's friendly name
    // rather than the generic "Simple PHP IPAM" or the bare hostname.
    if ($rpName === null || $rpName === '') {
        $rpName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
    }
    // Load Composer autoloader if lbuchs\WebAuthn is not already available.
    // Primary: vendor/ bundled inside the web root (release tarball installs).
    // Fallback: vendor/ at the project root (dev/Docker setups, e.g. playwright matrix).
    if (!class_exists('lbuchs\\WebAuthn\\WebAuthn')) {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        }
        if (file_exists($autoload)) require_once $autoload;
        // PHPStan can't see that require_once may have loaded the class.
        // @phpstan-ignore-next-line booleanNot.alwaysTrue
        if (!class_exists('lbuchs\\WebAuthn\\WebAuthn')) {
            throw new \RuntimeException(
                'Passkeys are unavailable because the WebAuthn library is not installed. Run "composer install" or deploy the release tarball which bundles vendor/.'
            );
        }
    }
    // Strip port from HTTP_HOST — rpId must be hostname only, no port.
    $host = to_str($_SERVER['HTTP_HOST'] ?? 'localhost');
    $rpId = (string)preg_replace('/:\d+$/', '', $host) ?: 'localhost';
    // Strip brackets from IPv6 literals: "[::1]" → "::1", "[::1]:8443" → "::1".
    $rpId = (string)preg_replace('/^\[(.+)\]$/', '$1', $rpId);
    // IP addresses are not valid WebAuthn RP IDs per the W3C spec; only the
    // loopback addresses are permitted (mapped to 'localhost' for dev/test).
    // All other IP literals are rejected early — the browser would raise a
    // SecurityError anyway, and this surfaces a clear config error.
    if (filter_var($rpId, FILTER_VALIDATE_IP) !== false) {
        if ($rpId === '127.0.0.1' || $rpId === '::1') {
            $rpId = 'localhost';
        } else {
            throw new \RuntimeException(
                'Passkeys require a hostname; IP addresses are not valid WebAuthn RP IDs.'
            );
        }
    }
    return new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, allowedFormats: ['none', 'packed', 'apple', 'fido-u2f', 'android-key', 'android-safetynet', 'tpm']);
}

/**
 * Generate a WebAuthn assertion challenge for $userId, store it in the
 * session, and return true. Used by login.php for the initial dispatch and
 * by the MFA-method-switch links on totp_verify.php / email_otp_verify.php.
 * Returns false if the user has no registered credentials.
 */
function ipam_passkey_dispatch_challenge(PDO $db, int $userId): bool
{
    if (!ipam_passkey_has_credentials($db, $userId)) return false;

    $creds         = ipam_passkey_get_credentials($db, $userId);
    $credentialIds = array_map(
        static function (array $c): \lbuchs\WebAuthn\Binary\ByteBuffer {
            return new \lbuchs\WebAuthn\Binary\ByteBuffer(to_str($c['credential_id']));
        },
        $creds
    );

    $webAuthn     = ipam_passkey_webauthn();
    $assertArgs   = $webAuthn->getGetArgs($credentialIds, 60);
    $challengeBin = $webAuthn->getChallenge()->getBinaryString();

    $pk            = $assertArgs->publicKey;
    $pk->challenge = rtrim(strtr(base64_encode($challengeBin), '+/', '-_'), '=');
    if (!empty($pk->allowCredentials)) {
        foreach ($pk->allowCredentials as &$ac) {
            if (isset($ac->id) && ($ac->id instanceof \lbuchs\WebAuthn\Binary\ByteBuffer)) {
                $ac->id = rtrim(strtr(base64_encode($ac->id->getBinaryString()), '+/', '-_'), '=');
            }
        }
        unset($ac);
    }

    $_SESSION['passkey_pending_uid']         = $userId;
    $_SESSION['passkey_challenge']           = $challengeBin;
    $_SESSION['passkey_challenge_issued_at'] = time();
    $_SESSION['passkey_assertion_options']   = json_encode($pk);
    return true;
}

/** @return list<array<string,mixed>> */
function ipam_passkey_get_credentials(PDO $db, int $userId): array
{
    $st = $db->prepare(
        "SELECT id, credential_id, public_key, sign_count, name, created_at, last_used_at
           FROM webauthn_credentials
          WHERE user_id = :uid
          ORDER BY created_at"
    );
    $st->execute([':uid' => $userId]);
    /** @var list<array<string,mixed>> $rows */
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return $rows;
}

function ipam_passkey_has_credentials(PDO $db, int $userId): bool
{
    $st = $db->prepare("SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = :uid");
    $st->execute([':uid' => $userId]);
    return (int)$st->fetchColumn() > 0;
}

function ipam_passkey_count(PDO $db, int $userId): int
{
    $st = $db->prepare("SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = :uid");
    $st->execute([':uid' => $userId]);
    return (int)$st->fetchColumn();
}

function ipam_passkey_delete(PDO $db, int $credentialDbId, int $userId): bool
{
    $st = $db->prepare("DELETE FROM webauthn_credentials WHERE id = :id AND user_id = :uid");
    $st->execute([':id' => $credentialDbId, ':uid' => $userId]);
    return $st->rowCount() > 0;
}

function ipam_passkey_delete_all(PDO $db, int $userId): void
{
    $db->prepare("DELETE FROM webauthn_credentials WHERE user_id = :uid")->execute([':uid' => $userId]);
}

/**
 * Look up a credential by its binary credential_id; returns DB row or null.
 * @return array<string,mixed>|null
 */
function ipam_passkey_find_by_credential_id(PDO $db, string $credentialIdBin): ?array
{
    $st = $db->prepare(
        "SELECT id, user_id, credential_id, public_key, sign_count, name
           FROM webauthn_credentials
          WHERE credential_id = :cid"
    );
    $st->bindValue(':cid', $credentialIdBin, PDO::PARAM_LOB);
    $st->execute();
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ipam_passkey_update_sign_count(PDO $db, int $credentialDbId, int $signCount): void
{
    /** @var string $driver */
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowExpr = match($driver) {
        'sqlite' => "datetime('now')",
        'mysql'  => "UTC_TIMESTAMP()",
        default  => "(NOW() AT TIME ZONE 'utc')",
    };
    $db->prepare("UPDATE webauthn_credentials SET sign_count = :sc, last_used_at = $nowExpr WHERE id = :id")
       ->execute([':sc' => $signCount, ':id' => $credentialDbId]);
}

// ============================================================
// Preferred MFA method dispatch (v3.16.0, #746)
// ============================================================

/**
 * Return the user's currently usable MFA methods. A method is "usable" only
 * if the user has it enrolled AND the admin has it globally enabled.
 *
 * Ordering matches the legacy v3.x dispatch chain (TOTP → Email OTP →
 * Passkey) so that existing installs see no behaviour change when no
 * preferred_mfa_method is set. The user-facing "most-recently-enrolled
 * default" semantics live in change_password.php's picker UI, where we
 * have signal to make a smart default; the dispatch helper itself is a
 * deterministic, stable fallback.
 *
 * The returned list is the canonical fallback chain for login dispatch.
 *
 * @return list<string>  Subset of {'totp','email_otp','passkey'}.
 */
function ipam_user_available_mfa_methods(PDO $db, int $userId): array
{
    $totpGlobal      = (bool)to_int(ipam_setting('mfa.totp_enabled', true));
    $emailOtpGlobal  = (bool)to_int(ipam_setting('mfa.email_otp_enabled', false));
    $passkeysGlobal  = (bool)to_int(ipam_setting('mfa.passkeys_enabled', false));

    $st = $db->prepare("SELECT totp_enabled, email_otp_enabled FROM users WHERE id = :id");
    $st->execute([':id' => $userId]);
    /** @var array<string,mixed>|false $row */
    $row = $st->fetch();
    if (!$row) {
        return [];
    }

    $totpEnrolled     = to_int($row['totp_enabled'] ?? 0) === 1;
    $emailOtpEnrolled = to_int($row['email_otp_enabled'] ?? 0) === 1;
    $passkeyEnrolled  = ipam_passkey_has_credentials($db, $userId);

    $methods = [];
    if ($totpGlobal && $totpEnrolled) {
        $methods[] = 'totp';
    }
    if ($emailOtpGlobal && $emailOtpEnrolled) {
        $methods[] = 'email_otp';
    }
    if ($passkeysGlobal && $passkeyEnrolled) {
        $methods[] = 'passkey';
    }
    return $methods;
}

/**
 * Resolve the user's effective preferred MFA method for login dispatch.
 *
 * Returns the value stored in users.preferred_mfa_method when that method
 * is currently usable (enrolled by the user AND globally enabled). Returns
 * null otherwise — the caller should then fall back to
 * ipam_user_available_mfa_methods()[0].
 *
 * Tolerates absence of the preferred_mfa_method column (e.g. on partial
 * test DBs that pre-date the 3.16.0 migration) by returning null.
 */
function ipam_user_preferred_mfa(PDO $db, int $userId): ?string
{
    try {
        $st = $db->prepare("SELECT preferred_mfa_method FROM users WHERE id = :id");
        $st->execute([':id' => $userId]);
    } catch (\PDOException $e) {
        // Tolerate ONLY "column does not exist" — partial test DBs that pre-date
        // the v3.16.0 migration. SQLSTATE codes per driver:
        //   MySQL:      42S22 (column not found)
        //   PostgreSQL: 42703 (undefined_column)
        //   SQLite:     HY000 with errorInfo[2] containing "no such column"
        // Any other PDO error (transient connection, lock timeout, etc.) is a
        // real problem and must propagate — silently coercing every DB error
        // to "no preference" hides outages.
        $sqlstate = (string)($e->errorInfo[0] ?? '');
        $msg      = (string)($e->errorInfo[2] ?? '');
        if ($sqlstate === '42S22'
            || $sqlstate === '42703'
            || ($sqlstate === 'HY000' && str_contains($msg, 'no such column'))) {
            return null;
        }
        throw $e;
    }
    $val = $st->fetchColumn();
    if (!is_string($val) || $val === '') {
        return null;
    }
    if (!in_array($val, ['totp', 'email_otp', 'passkey'], true)) {
        return null;
    }
    $available = ipam_user_available_mfa_methods($db, $userId);
    if (!in_array($val, $available, true)) {
        return null;
    }
    return $val;
}

// ============================================================
// Dashboard KPI helpers (v3.8.0, #514)
// ============================================================

/**
 * @return array{subnets:int,addresses:int,used:int,pct_used:float,alerts:int}
 */
function ipam_dashboard_kpis(PDO $db): array {
    $stmtTotals = $db->query(
        "SELECT COUNT(*) AS subnets,
                (SELECT COUNT(*) FROM addresses) AS addresses,
                (SELECT COUNT(*) FROM addresses WHERE status='used') AS used
         FROM subnets"
    );
    /** @var array<string, mixed>|false $totals */
    $totals = $stmtTotals ? $stmtTotals->fetch() : false;
    $stmtAlerts = $db->query(
        "SELECT COUNT(*) AS cnt FROM alert_state WHERE level='crit'"
    );
    /** @var array<string, mixed>|false $alerts */
    $alerts = $stmtAlerts ? $stmtAlerts->fetch() : false;
    $subnets   = is_array($totals) ? to_int($totals['subnets'])   : 0;
    $addresses = is_array($totals) ? to_int($totals['addresses']) : 0;
    $used      = is_array($totals) ? to_int($totals['used'])      : 0;
    return [
        'subnets'   => $subnets,
        'addresses' => $addresses,
        'used'      => $used,
        'pct_used'  => $addresses > 0 ? round($used / $addresses * 100, 1) : 0.0,
        'alerts'    => is_array($alerts) ? to_int($alerts['cnt']) : 0,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function ipam_dashboard_growth(PDO $db, int $days = 30): array {
    $days  = max(1, $days);
    $now   = time();
    $start = gmdate('Y-m-d', $now - ($days - 1) * 86400) . ' 00:00:00';
    $st = $db->prepare(
        "SELECT DATE(created_at) AS d, COUNT(*) AS n
         FROM addresses
         WHERE created_at >= :start
         GROUP BY DATE(created_at)
         ORDER BY d"
    );
    $st->execute([':start' => $start]);

    $counts = [];
    foreach ($st->fetchAll() as $row) {
        $counts[to_str($row['d'])] = to_int($row['n']);
    }

    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day      = gmdate('Y-m-d', $now - $i * 86400);
        $series[] = ['d' => $day, 'n' => $counts[$day] ?? 0];
    }
    return $series;
}

/**
 * Renders the drawer body partial for a single backup_runs row.
 * Used by backup_run_detail.php (#803). Returns null when the id does
 * not resolve so the endpoint can return 404.
 *
 * Disabled-state matrix is the source of truth for both the rendered
 * UI and the BackupRunDetailTest contract:
 *   - status='running'                         → all three actions disabled
 *   - status!='success' OR filename empty      → verify/download disabled
 *   - is_protected=1                           → delete disabled
 *
 * @return string|null
 */
function ipam_render_backup_run_detail(\PDO $db, int $id): ?string
{
    $st = $db->prepare(
        "SELECT r.*, d.name AS dest_name, d.type AS dest_type
           FROM backup_runs r
           LEFT JOIN backup_destinations d ON d.id = r.destination_id
          WHERE r.id = :id"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $status      = to_str($row['status'] ?? '');
    $filename    = to_str($row['filename'] ?? '');
    $destId      = to_int($row['destination_id'] ?? 0);
    $hasArtifact = ($status === 'success') && $filename !== '';
    $isRunning   = ($status === 'running');
    $isProtected = to_int($row['is_protected'] ?? 0) === 1;
    // backup_runs.destination_id is ON DELETE SET NULL — an orphan row with a
    // success status still has a filename but no destination to fetch from,
    // so Download must be disabled (the POST endpoint rejects destId <= 0).
    $hasDest     = $destId > 0;

    $disabled = [
        'verify'   => !$hasArtifact || $isRunning || !$hasDest,
        'download' => !$hasArtifact || $isRunning || !$hasDest,
        'delete'   => $isRunning || $isProtected,
    ];
    $tooltip = [
        'verify'   => $isRunning ? 'Run not finished' : (!$hasArtifact ? 'No artifact at destination' : (!$hasDest ? 'Destination deleted' : '')),
        'download' => $isRunning ? 'Run not finished' : (!$hasArtifact ? 'No artifact at destination' : (!$hasDest ? 'Destination deleted' : '')),
        'delete'   => $isRunning
            ? 'Cannot delete a run in progress'
            : ($isProtected ? 'This run is protected. Unprotect it from the schedule\'s retention settings before deleting.' : ''),
    ];

    ob_start();
    require __DIR__ . '/views/_backup_run_detail_body.php';
    return (string) ob_get_clean();
}
