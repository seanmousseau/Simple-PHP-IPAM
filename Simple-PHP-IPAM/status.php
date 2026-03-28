<?php
declare(strict_types=1);

/**
 * Health check endpoint — no authentication required.
 * Returns HTTP 200 with JSON {"status":"ok"} when the app and database are healthy.
 * Returns HTTP 503 with JSON {"status":"error"} on failure.
 *
 * Suitable for use with load balancers, uptime monitors, and container health checks.
 * Example: GET /status.php
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/version.php';

$dbOk = false;
try {
    $pdo = ipam_db((string)$config['db_path']);
    $pdo->query('SELECT 1')->fetch();
    $dbOk = true;
} catch (Throwable) {
    // DB unavailable
}

$status = $dbOk ? 'ok' : 'error';
http_response_code($dbOk ? 200 : 503);

echo json_encode([
    'status'  => $status,
    'version' => IPAM_VERSION,
    'db'      => $dbOk ? 'ok' : 'error',
], JSON_UNESCAPED_SLASHES);
