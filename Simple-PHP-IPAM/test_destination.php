<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
csrf_require();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

$id = to_int($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid destination id']);
    exit;
}

$stmt = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    echo json_encode(['ok' => false, 'message' => 'Destination not found']);
    exit;
}

$type = is_string($row['type'] ?? null) ? $row['type'] : '';
$cfgJson = is_string($row['config'] ?? null) ? $row['config'] : '{}';
$cfg = json_decode($cfgJson, true);
if (!is_array($cfg)) {
    echo json_encode(['ok' => false, 'message' => 'Destination config invalid']);
    exit;
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
    audit($db, 'destination.test', 'destination', $id, $result['ok'] ? 'ok' : 'fail');
    echo json_encode($result);
} catch (Throwable $e) {
    audit($db, 'destination.test', 'destination', $id, 'fail: ' . substr($e->getMessage(), 0, 200));
    echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'latency_ms' => null]);
}
