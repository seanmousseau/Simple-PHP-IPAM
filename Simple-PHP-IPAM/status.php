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
$schemaVersion = null;
try {
    $db = ipam_db($config);
    $GLOBALS['db'] = $db;
    ($db->query('SELECT 1') ?: throw new \RuntimeException('Query failed'))->fetch();
    $dbOk = true;
    /** @var array<string, mixed>|false $row */
    $row = ($db->query("SELECT MAX(version) AS v FROM schema_migrations")
        ?: throw new \RuntimeException('Query failed'))->fetch();
    if ($row && $row['v'] !== null) {
        $schemaVersion = to_str($row['v']);
    }
} catch (Throwable) {
    // DB unavailable
}

$status = $dbOk ? 'ok' : 'error';
http_response_code($dbOk ? 200 : 503);

$response = [
    'status' => $status,
    'db'     => $dbOk ? 'ok' : 'error',
];
if (!(bool)ipam_setting('display.status_hide_version')) {
    $response['version'] = IPAM_VERSION;
    if ($schemaVersion !== null) {
        $response['schema_version'] = $schemaVersion;
    }
}
echo json_encode($response, JSON_UNESCAPED_SLASHES);
