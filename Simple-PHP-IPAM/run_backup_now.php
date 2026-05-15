<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');
csrf_require();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

if (demo_mode_enabled()) {
    echo json_encode(['ok' => false, 'message' => 'Disabled in demo mode']);
    exit;
}

$destId = to_int($_POST['destination_id'] ?? 0);
if ($destId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid destination_id']);
    exit;
}

try {
    $result = ipam_backup_run_for_destination($db, $config, $destId, 'manual');
    // #1166 v3.29.0: response is application/json (Content-Type set above).
    // json_encode does structural escaping; no HTML context.
    // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- application/json response, structural escape via json_encode
    echo json_encode([
        'ok' => true,
        'log_id' => $result['log_id'],
        'filename' => $result['filename'],
        'size' => $result['size'],
        'pruned' => $result['pruned'],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
