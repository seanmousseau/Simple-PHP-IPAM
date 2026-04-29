<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$flash = '';
$selectedId = to_int($_GET['destination_id'] ?? 0);

// ── POST handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');
    $postDestId = to_int($_POST['destination_id'] ?? 0);
    $postName = to_str($_POST['name'] ?? '');

    if (demo_mode_enabled()) {
        $err = 'This action is disabled in demo mode.';
    } elseif ($postDestId <= 0 || $postName === '') {
        $err = 'Invalid request.';
    } elseif (str_contains($postName, '/') || str_contains($postName, '\\')
              || str_contains($postName, "\0") || str_starts_with($postName, '.')) {
        // Reject path-like remote names before they reach any backup client.
        $err = 'Invalid filename.';
    } else {
        $client = ipam_remote_backups_client_for($db, $postDestId);
        if ($client === null) {
            $err = 'Destination not found or invalid.';
        } else {
            try {
                if ($action === 'delete') {
                    if (!$client->delete($postName)) {
                        audit($db, 'remote_backup.delete_failed', 'destination', $postDestId, "name=$postName");
                        $err = 'Delete failed: file not found on remote (or destination rejected the request).';
                    } else {
                        audit($db, 'remote_backup.delete', 'destination', $postDestId, "name=$postName");
                        $flash = 'File deleted from remote.';
                    }
                } elseif ($action === 'verify') {
                    // Download to a tmp file, recompute SHA-256, compare with backup_log if entry exists
                    $tmp = tempnam(sys_get_temp_dir(), 'rbverify_');
                    if ($tmp === false) throw new RuntimeException('tempnam failed');
                    try {
                        if (!$client->download($postName, $tmp)) {
                            $err = 'File not found on remote.';
                        } else {
                            $observed = hash_file('sha256', $tmp);
                            if ($observed === false) {
                                $err = 'Checksum failed.';
                            } else {
                                // Compare with stored checksum if available
                                $stmt = $db->prepare("SELECT checksum FROM backup_log
                                    WHERE destination_id = :d AND filename = :f AND status = 'success'
                                    ORDER BY started_at DESC LIMIT 1");
                                $stmt->execute([':d' => $postDestId, ':f' => $postName]);
                                $stored = $stmt->fetchColumn();
                                if (is_string($stored) && $stored !== '') {
                                    if (hash_equals($stored, $observed)) {
                                        $flash = "Verified: SHA-256 matches stored checksum.";
                                    } else {
                                        $err = "Checksum MISMATCH! stored=$stored observed=$observed";
                                    }
                                } else {
                                    $flash = "No stored checksum for this file. Observed SHA-256: $observed";
                                }
                                audit($db, 'remote_backup.verify', 'destination', $postDestId,
                                      "name=$postName ok=" . ($err === '' ? '1' : '0'));
                            }
                        }
                    } finally {
                        if (is_file($tmp)) {
                            // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmp is tempnam()-generated, no user input
                            @unlink($tmp);
                        }
                    }
                } else {
                    $err = 'Unknown action.';
                }
                if ($err === '' && $action === 'delete') {
                    header("Location: remote_backups.php?destination_id=$postDestId&flash=deleted"); exit;
                }
                $selectedId = $postDestId;
            } catch (Throwable $e) {
                $err = 'Operation failed: ' . $e->getMessage();
            }
        }
    }
}

// ── Helper: build a client for a destination id ──
function ipam_remote_backups_client_for(PDO $db, int $id): ?BackupClientInterface
{
    $stmt = $db->prepare("SELECT * FROM backup_destinations WHERE id = :id AND is_active = 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;
    $type = is_string($row['type'] ?? null) ? $row['type'] : '';
    $cfgJson = is_string($row['config'] ?? null) ? $row['config'] : '{}';
    $cfg = json_decode($cfgJson, true);
    if (!is_array($cfg)) return null;
    /** @var array<string,mixed> $typedCfg */
    $typedCfg = [];
    foreach ($cfg as $k => $v) {
        if (is_string($k)) $typedCfg[$k] = $v;
    }
    try {
        return match ($type) {
            's3'    => new S3Client($typedCfg),
            'sftp'  => new SftpClient($typedCfg),
            'local' => new LocalBackupClient($typedCfg),
            default => null,
        };
    } catch (Throwable) {
        return null;
    }
}

// ── data fetch ──
$destStmt = $db->query("SELECT id, name, type FROM backup_destinations WHERE is_active = 1 ORDER BY name");
$destinations = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$entries = [];
$listError = '';
if ($selectedId > 0) {
    $client = ipam_remote_backups_client_for($db, $selectedId);
    if ($client === null) {
        $listError = 'Selected destination is invalid or inactive.';
    } else {
        try {
            $entries = $client->list();
        } catch (Throwable $e) {
            $listError = 'Could not list remote files: ' . $e->getMessage();
        }
    }
}

if ($flash === '' && to_str($_GET['flash'] ?? '') === 'deleted') $flash = 'File deleted.';

page_header('Remote Backups');
?>
<main class="container">
  <h1>Remote Backups</h1>
  <p class="muted">Browse files on remote destinations. <a href="destinations.php">Manage destinations →</a></p>
  <?php if ($err !== ''): ?><div class="card danger"><?= e($err) ?></div><?php endif; ?>
  <?php if ($flash !== ''): ?><div class="card success"><?= e($flash) ?></div><?php endif; ?>

  <section class="card">
    <h2>Choose a destination</h2>
    <form method="get" class="filter-bar">
      <label>Destination
        <select name="destination_id" data-auto-submit>
          <option value="0">— Select —</option>
          <?php foreach ($destinations as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= $selectedId === (int)$d['id'] ? 'selected' : '' ?>>
              <?= e(to_str($d['name'])) ?> (<?= e(to_str($d['type'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    </form>
  </section>

  <?php if ($selectedId > 0): ?>
    <section class="card">
      <h2>Files</h2>
      <?php if ($listError !== ''): ?>
        <div class="danger"><?= e($listError) ?></div>
      <?php elseif (count($entries) === 0): ?>
        <p class="muted">No files on this destination.</p>
      <?php else: ?>
        <table class="data-table">
          <thead><tr><th>Filename</th><th>Size</th><th>Last modified</th><th>Checksum</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($entries as $entry):
            $name = to_str($entry['name']);
            $size = $entry['size'];
            $modified = $entry['last_modified'];
            $checksum = to_str($entry['checksum']);
            $csShort = $checksum !== '' ? substr($checksum, 0, 12) . '…' : '—';
            $isEnc = str_ends_with($name, '.enc');
          ?>
            <tr>
              <td><?= e($name) ?> <?php if ($isEnc): ?><span class="badge">encrypted</span><?php endif; ?></td>
              <td><?= number_format($size) ?> bytes</td>
              <td><?= e($modified) ?></td>
              <td title="<?= e($checksum) ?>"><?= e($csShort) ?></td>
              <td class="actions">
                <form method="post" action="download_remote_backup.php" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="destination_id" value="<?= $selectedId ?>">
                  <input type="hidden" name="name" value="<?= e($name) ?>">
                  <input type="hidden" name="as" value="file">
                  <button class="action-pill" type="submit">Download</button>
                </form>
                <form method="post" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="verify">
                  <input type="hidden" name="destination_id" value="<?= $selectedId ?>">
                  <input type="hidden" name="name" value="<?= e($name) ?>">
                  <button class="action-pill" type="submit">Verify</button>
                </form>
                <form method="post" style="display:inline" data-confirm-delete="<?= e($name) ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="destination_id" value="<?= $selectedId ?>">
                  <input type="hidden" name="name" value="<?= e($name) ?>">
                  <button class="action-pill button-danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<?php page_footer();
