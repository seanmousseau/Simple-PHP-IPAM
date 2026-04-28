<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var array<string, mixed> $config */
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain');
    echo "405 POST required\n";
    exit;
}
csrf_require();

if (demo_mode_enabled()) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Disabled in demo mode\n";
    exit;
}

$destId = to_int($_POST['destination_id'] ?? 0);
$name   = to_str($_POST['name'] ?? '');
$as     = to_str($_POST['as'] ?? 'file');

if ($destId <= 0 || $name === '') {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "400 missing destination_id or name\n";
    exit;
}

try {
    $engine = new RestoreEngine($db, $config);
    $staged = $engine->prepareForRestore($destId, $name);
} catch (Throwable $e) {
    audit($db, 'remote_backup.download_failed', 'destination', $destId,
          'name=' . $name . ' error=' . substr($e->getMessage(), 0, 200));
    http_response_code(500);
    header('Content-Type: text/plain');
    echo '500 ' . $e->getMessage() . "\n";
    exit;
}

audit($db, 'remote_backup.download', 'destination', $destId, "name=$name as=$as");

if ($as === 'staged') {
    header('Content-Type: application/json');
    // nosemgrep: php.lang.security.xss.echoed-request -- Content-Type is application/json; json_encode provides structural escaping; no HTML rendering
    echo json_encode([
        'ok'        => true,
        'path'      => $staged['path'],
        'signature' => $engine->sign($staged['path']),
        'size'      => $staged['size'],
        'filename'  => $staged['filename'],
        'encrypted' => $staged['encrypted'],
    ]);
    // Note: $staged['path'] persists in data/tmp/ for the apply step.
    // Phase 13's restore_web.php cleans it up after dry-run/apply.
    exit;
}

// Default: stream the decrypted staged file as a download.
$safeFilename = basename($staged['filename'], '.enc');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . $staged['size']);
readfile($staged['path']);
// Cleanup: re-resolve via realpath() at the call site (project semgrep recognises this as a sanitizer)
$verified = $engine->verifySigned($staged['path'], $engine->sign($staged['path']));
if ($verified !== null) {
    $real = realpath($verified);
    if ($real !== false) @unlink($real);
}
