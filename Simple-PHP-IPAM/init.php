<?php
declare(strict_types=1);

/** @var IpamConfig $config */
$config = require __DIR__ . '/config.php';

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

session_name(to_str($config['session_name']));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_start();

require __DIR__ . '/lib.php';

$db = ipam_db(to_str($config['db_path']));
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
