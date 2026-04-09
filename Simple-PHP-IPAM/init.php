<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

/** @param array<string, mixed> $server */
function request_is_https(array $server, bool $trustProxyHeaders): bool
{
    if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') return true;

    if ($trustProxyHeaders) {
        if (!empty($server['HTTP_X_FORWARDED_PROTO']) && strtolower($server['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        if (!empty($server['HTTP_X_FORWARDED_SSL']) && strtolower($server['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    }
    return false;
}

// Skip HTTPS redirect for CLI (migrate.php, tmp_cleanup.php)
$isHttps = php_sapi_name() === 'cli' || request_is_https($_SERVER, (bool)($config['proxy_trust'] ?? false));
if (!$isHttps) {
    $base = rtrim((string)($config['base_url'] ?? ''), '/');
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    if ($base !== '') {
        header('Location: ' . $base . $uri, true, 301);
    } else {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' || !preg_match('/^(\[[:0-9a-fA-F]+\]|[a-zA-Z0-9._\-]+)(:\d+)?$/', $host)) {
            http_response_code(400);
            echo 'Invalid request: base_url is not configured and Host header is not valid.';
            exit(1);
        }
        header('Location: https://' . $host . $uri, true, 301);
    }
    exit;
}

session_name((string)$config['session_name']);
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

$db = ipam_db((string)$config['db_path']);
ipam_db_init($db);

// Auto-populate any missing config keys with their defaults
$_addedConfigKeys = ipam_config_sync(__DIR__ . '/config.php', $config);
if ($_addedConfigKeys && isset($_SESSION) && ($_SESSION['role'] ?? '') === 'admin') {
    $_SESSION['config_notice'] = 'New configuration keys were automatically added to config.php: '
        . implode(', ', array_map(fn($k) => "'{$k}'", $_addedConfigKeys)) . '.';
}
unset($_addedConfigKeys);

// Run best-effort housekeeping at most once/day (configurable)
run_housekeeping_if_due($config, $db);

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
    $thisScript = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
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
