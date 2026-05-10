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
    $staged = ipam_restore_prepare_for_restore($db, $config, $destId, $name);
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
    // #1127 (v3.27.3): server-side session stash replaces the legacy
    // HMAC-signed token. The path/meta are kept in $_SESSION so the
    // apply step can read them without trusting the client to round-trip
    // a signed reference. Removes the hard `app_secret` dependency that
    // blocked restore on every install that took the v3.26.0 vault-key
    // relocation path. The path is still returned in the JSON for UI
    // display purposes ("Staged: /tmp/foo.gz"), but the apply step does
    // NOT read it from the client — it consumes the session slot.
    // CR PR #1141: capture the per-wizard opaque ID so any consumer of
    // this JSON (today: none in the bundled UI, but a curl-driven
    // automation may exist) can thread it into the backup_admin_restore
    // form's `staged_sig` field. The wizard's session slot is keyed by
    // this ID so concurrent restore tabs don't clobber each other.
    require_once __DIR__ . '/lib/restore_wizard.php';
    $stagedSig = ipam_restore_wizard_stage_pending(
        RESTORE_WIZARD_PHASE_STAGED,
        $staged['path'],
        [
            'filename'       => $staged['filename'],
            'destination_id' => $destId,
            'size'           => $staged['size'],
        ]
    );
    audit($db, 'remote_backup.download', 'destination', $destId, "name=$name as=staged");
    header('Content-Type: application/json');
    // nosemgrep: php.lang.security.xss.echoed-request -- Content-Type is application/json; json_encode provides structural escaping; no HTML rendering
    echo json_encode([
        'ok'         => true,
        'path'       => $staged['path'],   // display only — apply reads from session
        'staged_sig' => $stagedSig,        // round-trip via wizard form's hidden field
        'size'       => $staged['size'],
        'filename'   => $staged['filename'],
        'encrypted'  => $staged['encrypted'],
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
