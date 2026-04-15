<?php
declare(strict_types=1);

// Defensive error handler: route runtime warnings and notices to error_log()
// only, never to stdout. PHP's default behaviour (display_errors=on) writes
// E_WARNING / E_NOTICE / E_DEPRECATED messages into the HTTP response body,
// which commits the response headers and causes every subsequent header() /
// session_start() call to cascade with "headers already sent".
//
// This has bitten v2.9.x twice already: once on oversized uploads exceeding
// post_max_size (fixed in v2.9.1), and once on a config.php missing a newer
// registry key like `import_sql_max_mb` on an install where `config.php`
// is not writable by the web server user (so `ipam_config_sync()` cannot
// auto-populate the missing key). Rather than hunt every unguarded
// $config[...] read, install a global handler that swallows these non-fatal
// errors and logs them. Fatal errors (E_ERROR / parse errors) still exit
// because they bypass the user handler.
//
// Operators should monitor the PHP error log — any missing config key still
// surfaces there, just not in the user's browser.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    // Respect the error_reporting() setting (e.g. @-suppressed calls).
    if ((error_reporting() & $severity) === 0) return true;
    error_log(sprintf('Simple-PHP-IPAM [%d]: %s in %s:%d', $severity, $message, $file, $line));
    return true;
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

/** @var IpamConfig $config */
$config = require __DIR__ . '/config.php';

// Composer autoloader (#416). Conditional because v2.9.0 ships with an empty
// require {} — vendor/autoload.php only exists in release tarballs (built by
// releases/make_releases.sh) and in dev environments where the tester has run
// `composer install`. A fresh git clone without composer install must still
// boot, so we skip silently if the file is absent.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Dialect abstraction (#378). Loaded before lib.php so any function in lib.php
// can call ipam_dialect() without needing a separate bootstrap step. v2.9.0
// ships SqliteDialect only; v2.10.0 adds Mysql, v2.11.0 adds Pgsql. The driver
// is selected from $config['db_driver'] (default 'sqlite' for back-compat).
//
// Error reporting routes through error_log() / echo rather than fwrite(STDERR,
// ...) because STDIN/STDOUT/STDERR are only defined under CLI and phpdbg SAPIs
// — referencing them under Apache or PHP-FPM would throw a fatal error.
require_once __DIR__ . '/dialects/Dialect.php';
// v2.10.0 (#382) — supported drivers: 'sqlite' (default) and 'mysql'
// (experimental beta). Postgres lands in v2.11.0 (#386). Unknown values are
// rejected here before the request touches any DB code.
$_ipam_db_driver = (string)($config['db_driver'] ?? 'sqlite');
$_ipam_driver_error = match ($_ipam_db_driver) {
    'sqlite', 'mysql' => null,
    'pgsql'  => 'db_driver=pgsql is experimental and lands in v2.11.0 (#388)',
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
} else {
    $GLOBALS['ipam_dialect'] = new SqliteDialect();
}
unset($_ipam_db_driver, $_ipam_driver_error);

// Seed a UTC default so any pre-DB date/time operations (HTTPS redirect, session
// setup) are deterministic. The real timezone is applied from the DB settings
// (branding.timezone) once $db is open and lib.php is loaded — see below.
date_default_timezone_set('UTC');

/**
 * Convert a mixed value to string. Defined early in init.php so it is available
 * before lib.php is loaded; lib.php guards against redefinition with function_exists().
 */
function to_str(mixed $value): string
{
    if (is_string($value)) return $value;
    if (is_int($value) || is_float($value)) return (string)$value;
    if (is_bool($value)) return $value ? '1' : '';
    if ($value === null) return '';
    return '';
}

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

require __DIR__ . '/lib.php';

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

// Auto-populate any missing config keys with their defaults
$_addedConfigKeys = ipam_config_sync(__DIR__ . '/config.php', $config);
if ($_addedConfigKeys && isset($_SESSION) && ($_SESSION['role'] ?? '') === 'admin') {
    $_SESSION['config_notice'] = 'New configuration keys were automatically added to config.php: '
        . implode(', ', array_map(fn($k) => "'{$k}'", $_addedConfigKeys)) . '.';
}
unset($_addedConfigKeys);

// Validate config values on every boot; surface warnings to admins in the UI
$_configWarnings = ipam_validate_config($config);
if ($_configWarnings) {
    $GLOBALS['config_warnings'] = $_configWarnings;
}
unset($_configWarnings);

// #376: surface a rate-limited server-log warning listing any registered
// settings still being served from config.php. Touch a marker file so we
// emit at most one line per hour regardless of traffic. Missing/unwritable
// data/tmp/ silently falls through to the "no marker" path so the warning
// still fires once per request — preferable to failing silently.
$_deprecated = ipam_setting_deprecated_keys();
if ($_deprecated) {
    $_markerPath = __DIR__ . '/data/tmp/deprecation_warning.txt';
    $_shouldLog  = true;
    if (is_file($_markerPath) && (time() - (int)@filemtime($_markerPath)) < 3600) {
        $_shouldLog = false;
    }
    if ($_shouldLog) {
        $_keyList = implode(', ', array_map(fn($d) => $d['key'], $_deprecated));
        error_log(
            'Simple-PHP-IPAM: ' . count($_deprecated)
            . ' registered setting(s) still served from config.php — will break at v3.0.0. Keys: '
            . $_keyList
            . '. Migrate via Admin → Settings.'
        );
        @ensure_tmp_dir();
        @touch($_markerPath);
        @chmod($_markerPath, 0600);
    }
    unset($_markerPath, $_shouldLog, $_keyList);
}
unset($_deprecated);

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

// Run database backup if due (configurable frequency)
if (!empty($config['backup']['enabled'])) {
    run_db_backup_if_due($db, $config);
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
