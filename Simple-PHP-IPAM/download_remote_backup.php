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
    // Log full error server-side; return generic message to the client.
    error_log('[download_remote_backup] dest=' . $destId . ' name=' . $name . ' error=' . $e->getMessage());
    audit($db, 'remote_backup.download_failed', 'destination', $destId,
          'name=' . $name . ' error=' . substr($e->getMessage(), 0, 200));
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "500 Download failed (see server log for details)\n";
    exit;
}

if ($as === 'staged') {
    // Generate signature BEFORE auditing or returning JSON — sign() can throw
    // on empty app_secret, and we'd rather fail noisily without an audit row
    // claiming the download succeeded.
    try {
        $signature = $engine->sign($staged['path'], [
            'filename' => $staged['filename'],
            'destination_id' => $destId,
            'size' => $staged['size'],
        ]);
    } catch (Throwable $e) {
        error_log('[download_remote_backup] sign failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain');
        echo "500 Cannot sign staged token (see server log for details)\n";
        exit;
    }
    audit($db, 'remote_backup.download', 'destination', $destId, "name=$name as=staged");
    header('Content-Type: application/json');
    // nosemgrep: php.lang.security.xss.echoed-request -- Content-Type is application/json; json_encode provides structural escaping; no HTML rendering
    echo json_encode([
        'ok'        => true,
        'path'      => $staged['path'],
        'signature' => $signature,
        'size'      => $staged['size'],
        'filename'  => $staged['filename'],
        'encrypted' => $staged['encrypted'],
    ]);
    // Note: $staged['path'] persists in data/tmp/ for the apply step.
    // Phase 13's restore_web.php cleans it up after dry-run/apply.
    exit;
}

// File-streaming branch: audit after we've committed to streaming.
audit($db, 'remote_backup.download', 'destination', $destId, "name=$name as=file");

// Default: stream the decrypted staged file as a download.
// basename() strips path components but does not remove quotes or CR/LF.
// Sanitize aggressively — strip control chars and collapse to a safe ASCII charset.
$safeFilename = basename($staged['filename'], '.enc');
$safeFilename = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '_', $safeFilename) ?? 'backup';
$safeFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $safeFilename) ?? 'backup';
if ($safeFilename === '' || $safeFilename === '.' || $safeFilename === '..') {
    $safeFilename = 'backup';
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . $staged['size']);
// Resolve the canonical staged path BEFORE streaming; sign() can throw on
// missing app_secret and we'd rather fail before the response body starts.
$cleanupReal = realpath($staged['path']);
$tmpDir = __DIR__ . '/data/tmp';
$tmpReal = realpath($tmpDir);
$canCleanup = ($cleanupReal !== false && $tmpReal !== false
               && str_starts_with($cleanupReal . '/', rtrim($tmpReal, '/') . '/'));

readfile($staged['path']);

if ($canCleanup && is_file($cleanupReal)) {
    @unlink($cleanupReal); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath() under data/tmp/
}
