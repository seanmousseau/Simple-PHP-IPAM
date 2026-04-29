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
    $engine = new BackupEngine($db, $config);
    $result = $engine->runForDestination($destId, 'manual');
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
