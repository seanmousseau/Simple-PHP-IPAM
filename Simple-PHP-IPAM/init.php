<?php
declare(strict_types=1);

// Defensive error handler: route runtime warnings and notices to error_log()
// only, never to stdout. PHP's default behaviour (display_errors=on) writes
// E_WARNING / E_NOTICE / E_DEPRECATED messages into the HTTP response body,
// which commits the response headers and causes every subsequent header() /
// session_start() call to cascade with "headers already sent".
//
// This has bitten v2.9.x twice already: once on oversized uploads exceeding
// post_max_size (fixed in v2.9.1), and once on an undefined array key in
// $config. Rather than hunt every unguarded read, install a global handler
// that swallows these non-fatal errors and logs them. Fatal errors
// (E_ERROR / parse errors) still exit because they bypass the user handler.
//
// Operators should monitor the PHP error log — any missing config key still
// surfaces there, just not in the user's browser.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Respect the error_reporting() setting (e.g. @-suppressed calls).
    if ((error_reporting() & $severity) === 0) return true;
    error_log(sprintf('Simple-PHP-IPAM [%d]: %s in %s:%d', $severity, $message, $file, $line));
    return true;
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

// Global exception handler (v2.10.0 #536). Any Throwable that escapes a
// page script — most commonly a RuntimeException out of ipam_db() because
// the MySQL version is wrong, the DSN points at MariaDB, the credentials
// are invalid, or the server is unreachable — used to bubble up to PHP's
// default fatal handler. The default handler:
//
//   1. Does NOT set an HTTP status code → Apache returns 200 OK with the
//      fatal error in the response body. Uptime monitors see "success"
//      and never alert.
//   2. Emits the full Throwable::__toString() including absolute server
//      paths, line numbers, and stack traces. Information disclosure on
//      every misconfigured install.
//
// This handler routes every uncaught Throwable to:
//   - error_log() with the FULL trace (operator keeps the debug info)
//   - An HTTP 500 response body that contains ONLY the exception message
//     (which is already operator-written and actionable — "MariaDB is not
//     supported in v2.10.0", "MySQL 8.0.29+ is required", etc.) plus a
//     small user-facing shell. No paths, no trace, no PHP fatal markup.
//
// Note: parse errors and some fatal errors (memory exhaustion, max
// execution time) still bypass user handlers — the handler catches what
// PHP lets us catch, which is every Throwable thrown from PHP code.
set_exception_handler(static function (\Throwable $e): void {
    // Log the full exception for operators — absolute paths, line numbers,
    // full stack trace, previous exceptions. This is the ONLY place those
    // end up; the HTTP response body never contains them.
    error_log(sprintf(
        "Simple-PHP-IPAM uncaught %s: %s in %s:%d\n%s",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));

    // HTTP 500 + user-safe body. Use htmlspecialchars() directly rather
    // than e() because lib.php may not have loaded yet when this handler
    // fires (e.g. an exception thrown from ipam_db() before lib.php's
    // helpers are in scope).
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Simple PHP IPAM — Configuration error</title>';
    echo '<style>body{font:16px system-ui,-apple-system,sans-serif;max-width:720px;margin:80px auto;padding:0 24px;color:#1e293b;background:#f8fafc}h1{font-size:24px;margin:0 0 16px;color:#991b1b}.msg{background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;padding:16px 20px;border-radius:6px;margin:20px 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:14px;word-break:break-word}.hint{color:#64748b;font-size:14px;margin-top:24px}</style>';
    echo '</head><body>';
    echo '<h1>Configuration error</h1>';
    echo '<p>Simple PHP IPAM could not start. The server reports:</p>';
    echo '<div class="msg">' . $msg . '</div>';
    echo '<p class="hint">An administrator should check the PHP error log for full diagnostic details.</p>';
    echo '</body></html>';
    exit(1);
});

/** @var IpamConfig $config */
$config = require __DIR__ . '/config.php';

// Pure utility helpers (e(), to_int(), to_str(), q_int(), format_bytes(),
// base64url_*, ipam_normalise_version()). Loaded before lib.php so the
// early HTTPS-redirect / session-setup code below can call to_str() without
// pulling in the rest of lib.php. v3.30.0 ADR-004 Phase 2.
require_once __DIR__ . '/lib/utils.php';

// Pure IP/CIDR math + ipam_bind_binary() (invariant #1 from CLAUDE.md).
// Loaded after utils (depends on to_int/to_str) and before lib.php so any
// extracted helper can be called from early bootstrap if ever needed.
// v3.30.0 ADR-004 Phase 2 Task 2.2.
require_once __DIR__ . '/lib/ip.php';

// ipam_config() / ipam_config_nested() / ipam_config_invalidate_cache() —
// ADR-003 Option D accessor. Must load AFTER config.php has populated
// $GLOBALS['config'] (line 86 above) and BEFORE lib.php so that any module
// extracted in v3.30.0+ (db, audit, settings, user_preferences, presentation,
// auth ×4) can call ipam_config() at top-of-function instead of `global $config;`.
// v3.30.0 ADR-004 Phase 2 Task 2.3.
require_once __DIR__ . '/lib/config.php';

// DB layer (#378, ADR-004 Phase 2 Task 4.1) — ipam_db() / ipam_dialect() /
// apply_migrations() / audit_log + schema_migrations self-heal / SQLite dump
// tooling. Loaded AFTER lib/config.php (its bootstrap-admin path reads via
// ipam_config_nested()) and BEFORE lib.php so any lib.php function can call
// these without a separate bootstrap step. The Dialect classes themselves are
// required below; db.php's type hints resolve lazily at call time, which is
// always after init.php has finished loading. v3.30.0.
require_once __DIR__ . '/lib/db.php';

// Demo-data fixture seeder (#909, ADR-004, A14) — demo_seed_data() populates
// an empty schema with the canonical demo dataset. Loaded AFTER lib/db.php
// (demo_seed_data() calls ipam_dialect()) and BEFORE lib.php so the fresh-
// install / test-harness reset path can seed without a separate bootstrap
// step. v3.31.0.
require_once __DIR__ . '/lib/demo_seed.php';

// Audit layer (#912, ADR-004 Phase 4 Task 4.2) — audit() / audit_export() /
// prune_audit_log() / audit_filter_validate_* and the AUDIT_FILTER_PREFIXES
// const. Loaded AFTER lib/db.php (prune_audit_log() calls ipam_dialect() and
// the new Dialect::with_append_only_bypass() helper) and BEFORE lib.php so any
// lib.php function can call audit() without a separate bootstrap step.
// current_user() / client_ip() are still in lib.php; they resolve lazily at
// call time, always after init.php has finished loading. v3.30.0.
require_once __DIR__ . '/lib/audit.php';

// Presentation layer (#910, ADR-004 Phase 5 Task 5.1) — page_header() /
// page_footer(), the ipam_render() view-partial helpers, icon(), the flash
// store, sortable-<th> / pagination / badge / banner / custom-field-input
// renderers. Loaded AFTER lib/config.php (page_header() reads config via
// ipam_config()) and BEFORE lib.php so any lib.php function can call these
// without a separate bootstrap step. The DB-backed renderers and the helpers
// they depend on (ipam_setting(), csrf_token(), recovery_mode_enabled(),
// ipam_update_check(), ipam_install_key_banner_handle_dismiss(), …) still
// live in lib.php; they resolve lazily at call time, always after init.php
// has finished loading. v3.30.0.
require_once __DIR__ . '/lib/presentation.php';

// Settings layer (#907 / #915, ADR-004 Phase 5 Task 5.2b) — the setting
// registry (ipam_setting_definitions / _seed / _groups), the encode/decode/
// infer codec, ipam_setting() / ipam_setting_set() with their per-request
// cache, the config.php back-compat fallback, ipam_setting_deprecated_keys(),
// and the ADR-001 11-value logical-type dispatch layer
// (ipam_setting_storage_type / ipam_setting_validate). Loaded AFTER
// lib/config.php (ipam_setting() reads config.php via ipam_config()) and
// BEFORE ipam_db_init() runs migrations below, because some migration closures
// call ipam_setting_definitions() to resolve registry defaults (e.g. the
// config-import settings seeding and the MFA-default seeding migrations).
// ipam_key_col() /
// ipam_dialect() / audit() resolve lazily at call time. v3.30.0.
require_once __DIR__ . '/lib/settings.php';

// Per-user preference read/write layer (ADR-002, Task 5.3 Chunk 3). Loaded
// immediately after settings.php; deps: db (ipam_dialect, ipam_key_col). v3.30.0.
require_once __DIR__ . '/lib/user_preferences.php';

// Core session + CSRF + login layer (ADR-004 Phase 6 Task 6.1). Deps: db,
// audit, utils, presentation, config, settings, user_preferences. v3.30.0.
require_once __DIR__ . '/lib/auth.php';

// Password policy + password-reset token/email layer (ADR-004 Phase 6
// Task 6.2). Deps: auth, utils, db. v3.30.0.
require_once __DIR__ . '/lib/auth_password.php';

// Login/IP rate-limiting + account-lockout layer (ADR-004 Phase 6
// Task 6.3). Deps: auth, db, audit. v3.30.0.
require_once __DIR__ . '/lib/auth_rate_limit.php';

// reCAPTCHA + login-form protection layer (ADR-004 Phase 6 Task 6.4).
// Deps: auth, utils, settings, config. v3.30.0.
require_once __DIR__ . '/lib/auth_recaptcha.php';

// Composer autoloader (#416). Conditional because v2.9.0 ships with an empty
// require {} — vendor/autoload.php only exists in release tarballs (built by
// releases/make_releases.sh) and in dev environments where the tester has run
// `composer install`. A fresh git clone without composer install must still
// boot, so we skip silently if the file is absent.
$_ipam_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($_ipam_autoload)) {
    // Fallback: vendor/ mounted one level above the web root (dev/Docker setups).
    $_ipam_autoload = dirname(__DIR__) . '/vendor/autoload.php';
}
if (is_file($_ipam_autoload)) {
    require_once $_ipam_autoload;
}
unset($_ipam_autoload);

// Dialect abstraction (#378). Loaded before lib.php so any function in lib.php
// can call ipam_dialect() without needing a separate bootstrap step. v2.9.0
// ships SqliteDialect only; v2.10.0 adds Mysql, v2.11.0 adds Pgsql. The driver
// is selected from $config['db_driver'] (default 'sqlite' for back-compat).
//
// Error reporting routes through error_log() / echo rather than fwrite(STDERR,
// ...) because STDIN/STDOUT/STDERR are only defined under CLI and phpdbg SAPIs
// — referencing them under Apache or PHP-FPM would throw a fatal error.
require_once __DIR__ . '/dialects/Dialect.php';
require_once __DIR__ . '/dialects/DialectValidator.php';
// Supported drivers: 'sqlite' (default), 'mysql', and 'pgsql' (all stable
// as of v3.0.0). Unknown values are rejected here before any DB code runs.
$_ipam_db_driver = (string)($config['db_driver'] ?? 'sqlite');
$_ipam_driver_error = match ($_ipam_db_driver) {
    'sqlite', 'mysql', 'pgsql' => null,
    default  => "Unknown db_driver: {$_ipam_db_driver}",
};
if ($_ipam_driver_error !== null) {
    error_log('Simple-PHP-IPAM: ' . $_ipam_driver_error);
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        http_response_code(500);
        echo 'Internal configuration error. See server log for details.';
    } else {
        echo $_ipam_driver_error . "\n";
    }
    exit(2);
}
// Load the concrete dialect class and stash an instance under
// $GLOBALS['ipam_dialect'] so any code that calls ipam_dialect() before
// ipam_db($config) runs (HTTPS redirect, session setup, early helpers) sees
// the right driver rather than the SqliteDialect fallback.
require_once __DIR__ . '/dialects/SqliteDialect.php';
if ($_ipam_db_driver === 'mysql') {
    require_once __DIR__ . '/dialects/MysqlDialect.php';
    $GLOBALS['ipam_dialect'] = new MysqlDialect();
} elseif ($_ipam_db_driver === 'pgsql') {
    require_once __DIR__ . '/dialects/PgsqlDialect.php';
    $GLOBALS['ipam_dialect'] = new PgsqlDialect();
} else {
    $GLOBALS['ipam_dialect'] = new SqliteDialect();
}
unset($_ipam_db_driver, $_ipam_driver_error);

// Seed a UTC default so any pre-DB date/time operations (HTTPS redirect, session
// setup) are deterministic. The real timezone is applied from the DB settings
// (branding.timezone) once $db is open and lib.php is loaded — see below.
date_default_timezone_set('UTC');

/** @param array<string, mixed> $server */
function request_is_https(array $server, bool $trustProxyHeaders): bool
{
    if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') return true;

    if ($trustProxyHeaders) {
        if (!empty($server['HTTP_X_FORWARDED_PROTO']) && strtolower(to_str($server['HTTP_X_FORWARDED_PROTO'])) === 'https') return true;
        if (!empty($server['HTTP_X_FORWARDED_SSL']) && strtolower(to_str($server['HTTP_X_FORWARDED_SSL'])) === 'on') return true;
    }
    return false;
}

// Skip HTTPS redirect for CLI (migrate.php, tmp_cleanup.php)
$isHttps = php_sapi_name() === 'cli' || request_is_https($_SERVER, (bool)$config['proxy_trust']);
if (!$isHttps) {
    $base = rtrim(to_str($config['base_url'] ?? ''), '/');
    $uri  = to_str($_SERVER['REQUEST_URI'] ?? '/');
    if ($base !== '') {
        header('Location: ' . $base . $uri, true, 301);
    } else {
        $host = to_str($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^(\[[:0-9a-fA-F]+\]|[a-zA-Z0-9._\-]+)(:\d+)?$/', $host)) {
            http_response_code(400);
            echo 'Invalid request: base_url is not configured and Host header is not valid.';
            exit(1);
        }
        header('Location: https://' . $host . $uri, true, 301);
    }
    exit;
}

// -------------------------------------------------------------------------
// Session isolation (v2.10.0 #532 security fix)
// -------------------------------------------------------------------------
// Two IPAM installs under the same hostname used to share a session cookie
// because the default session name ('IPAMSESSID') and path ('/') were
// identical on every deploy. A user logged into /ipam-a/ would be
// authenticated on /ipam-b/ without ever entering credentials, because the
// browser sent the same cookie, PHP loaded the same $_SESSION record, and
// /ipam-b/'s require_login() trusted the $_SESSION['user_id'] (which
// resolved against its own users table to its own admin).
//
// Three layers of isolation close this:
//
// 1. Cookie NAME derived from the install directory hash, so two installs
//    at different filesystem paths never collide. Users who set
//    config.session_name explicitly keep full control and get their
//    exact value (operators who front-proxy multiple hostnames may need
//    this for SSO-style shared sessions).
//
// 2. Cookie PATH scoped to the install's URL directory, so even if two
//    installs somehow wound up with the same cookie name, the browser
//    would not send /ipam-a/'s cookie to /ipam-b/ requests. Operators
//    behind a reverse proxy that rewrites paths can override via
//    config.session_cookie_path.
//
// 3. Session STORAGE path under each install's data/sessions/ with 0700
//    perms, so PHP's session files are physically separated per install.
//    Defense in depth: prevents cross-read even if both cookies ever
//    collided via session fixation.
//
// The default for every layer is "derive from __DIR__", which makes
// isolation automatic on every install without requiring any config edit.
$configuredSessionName = to_str($config['session_name']);
if ($configuredSessionName === '' || $configuredSessionName === 'IPAMSESSID') {
    // Default path: suffix with 8 hex chars of the install-dir hash so
    // two installs at different filesystem paths never collide.
    $configuredSessionName = 'IPAMSESSID_' . substr(hash('sha256', __DIR__), 0, 8);
}
session_name($configuredSessionName);

// Derive the cookie path from the running script so an install at
// /claude/ipam-mysql/login.php gets path=/claude/ipam-mysql/. CLI mode
// has no SCRIPT_NAME and doesn't issue cookies, so the fallback is '/'.
$configuredCookiePath = to_str($config['session_cookie_path'] ?? '');
if ($configuredCookiePath === '') {
    $scriptName = to_str($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName !== '') {
        $dir = str_replace('\\', '/', dirname($scriptName));
        $configuredCookiePath = ($dir === '' || $dir === '.' || $dir === '/') ? '/' : $dir . '/';
    } else {
        $configuredCookiePath = '/';
    }
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $configuredCookiePath,
    'domain'   => '',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);

// Session storage isolation: each install keeps its own session files
// under data/sessions/ so two installs that somehow ended up with the
// same session ID would still look up different records. Created with
// 0700 perms so other local users on a shared host cannot list or read
// the session files.
$sessionSaveDir = __DIR__ . '/data/sessions';
if (!is_dir($sessionSaveDir)) {
    @mkdir($sessionSaveDir, 0700, true);
}
if (is_dir($sessionSaveDir) && is_writable($sessionSaveDir)) {
    ini_set('session.save_path', $sessionSaveDir);
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_start();

// LiteSpeed Server-Side Cache bypass: LiteSpeed ignores the standard
// Cache-Control: no-store header for its own ESI/full-page cache. All PHP
// pages in this app carry session state and CSRF tokens so they must never
// be served from cache. X-LiteSpeed-Cache-Control is the authoritative
// opt-out that LiteSpeed actually respects.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    header('X-LiteSpeed-Cache-Control: no-cache');
}

require __DIR__ . '/lib.php';

// Enforce absolute session lifetime (#420); no-op in CLI context where there is no session
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    ipam_session_enforce_absolute_lifetime($config);
}

$db = ipam_db($config);
ipam_db_init($db);

// Now that settings are available, apply the admin-configured timezone. All DB
// timestamps are stored as UTC; display_datetime() in lib.php converts them
// for UI output using the effective timezone set here.
$tz = to_str(ipam_setting('branding.timezone'));
if ($tz === '' || !@date_default_timezone_set($tz)) {
    date_default_timezone_set('UTC');
}
unset($tz);

// Validate config values on every boot; surface warnings to admins in the UI
$_configWarnings = ipam_validate_config($config);
if ($_configWarnings) {
    $GLOBALS['config_warnings'] = $_configWarnings;
}
unset($_configWarnings);

// v3.0.0: detect non-bootstrap keys left in config.php after migration
$_staleConfigKeys = ipam_config_stale_keys($config);
if ($_staleConfigKeys) {
    $GLOBALS['config_stale_keys'] = $_staleConfigKeys;
}
unset($_staleConfigKeys);

// Run best-effort housekeeping at most once/day (configurable)
run_housekeeping_if_due($config, $db);

// Utilization alerts — independent interval (default 1 h); no-op if alert_email is empty
alerts_check_if_due($config, $db);

// Demo nightly reset — independent of housekeeping schedule; never crashes the page
if (!empty($config['demo_mode']['enabled'])) {
    run_demo_reset_if_due($db);
}

// Demo gate (#125): redirect to challenge page if gate is configured and not yet passed
if (!empty($config['demo_mode']['enabled'])
    && !empty($config['demo_mode']['gate'])
    && empty($_SESSION['demo_gate_passed'])
) {
    $gateExempt = ['demo_gate.php', 'status.php', 'api.php'];
    $thisScript = basename(to_str($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (!in_array($thisScript, $gateExempt, true)) {
        header('Location: demo_gate.php');
        exit;
    }
}

// v3.26.0 (#1059): the legacy v3.7 filesystem-only backup runner and its
// init-time conversion helper were retired. The unified cron.php scheduler
// now drives every backup destination from the backup_destinations +
// backup_schedules tables. Operators upgrading from a pre-v3.23 install
// must pass through v3.23.0–v3.25.x first; the 3.26.0-retire-legacy-backup
// migration enforces this with a hard-fail check on its sentinel.

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
