<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function request_is_https(array $server, bool $trustProxyHeaders): bool
{
    if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') return true;

    if ($trustProxyHeaders) {
        if (!empty($server['HTTP_X_FORWARDED_PROTO']) && strtolower($server['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
        if (!empty($server['HTTP_X_FORWARDED_SSL']) && strtolower($server['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    }
    return false;
}

$isHttps = request_is_https($_SERVER, (bool)($config['proxy_trust'] ?? false));
if (!$isHttps) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 301);
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

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
