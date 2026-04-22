<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();
require_role('admin');

$msg    = '';
$errors = [];
$action = to_str($_REQUEST['action'] ?? '');
$id     = to_int($_REQUEST['id'] ?? 0);

/* -------------------------------------------------------------------------
 * POST handlers (CSRF required for all mutations)
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    if ($action === 'delete' && $id > 0) {
        $st = $db->prepare("SELECT * FROM backup_history WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if ($row) {
            $path = to_str($row['target_path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
            $db->prepare("DELETE FROM backup_history WHERE id=:id")->execute([':id' => $id]);
            audit($db, 'backup.deleted', 'backup_history', $id,
                "Deleted backup record: " . e(basename($path)));
            $msg = 'Backup record deleted.';
        } else {
            $errors[] = 'Backup record not found.';
        }
    } elseif ($action === 'verify' && $id > 0) {
        $st = $db->prepare("SELECT * FROM backup_history WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        if ($row) {
            $path     = to_str($row['target_path'] ?? '');
            $expected = to_str($row['sha256'] ?? '');
            if ($path === '' || !is_file($path)) {
                $errors[] = 'Backup file not found on disk.';
            } elseif ($expected === '') {
                $errors[] = 'No SHA-256 hash recorded for this backup — cannot verify.';
            } else {
                $actual = hash_file('sha256', $path) ?: '';
                if (hash_equals($expected, $actual)) {
                    $msg = 'SHA-256 verified OK. File integrity confirmed.';
                    audit($db, 'backup.verified', 'backup_history', $id,
                        "SHA-256 verified OK for: " . e(basename($path)));
                } else {
                    $errors[] = 'SHA-256 MISMATCH — backup file may be corrupted.';
                    audit($db, 'backup.verify_failed', 'backup_history', $id,
                        "SHA-256 mismatch for: " . e(basename($path)));
                }
            }
        } else {
            $errors[] = 'Backup record not found.';
        }
    }
}

/* -------------------------------------------------------------------------
 * GET: file download (served inline — no CSRF needed, admin-only auth sufficient)
 * ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'download' && $id > 0) {
    $st = $db->prepare("SELECT * FROM backup_history WHERE id=:id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if ($row) {
        $path = to_str($row['target_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            $filename = basename($path);
            $size     = (int)filesize($path);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . $size);
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            audit($db, 'backup.downloaded', 'backup_history', $id,
                "Downloaded backup: {$filename}");
            exit;
        }
    }
    $errors[] = 'Backup file not found.';
}

/* -------------------------------------------------------------------------
 * Load backup list
 * ---------------------------------------------------------------------- */
$backups = [];
try {
    $st = $db->query(
        "SELECT * FROM backup_history ORDER BY started_at DESC LIMIT 100"
    );
    if ($st !== false) {
        $backups = $st->fetchAll();
    }
} catch (Throwable) {
    // table may not exist yet on a fresh install before migration runs
}

$backupDir     = backup_dir($config);
$diskFreeBytes = @disk_free_space($backupDir);
$diskFree      = ($diskFreeBytes !== false)
    ? format_bytes((int)$diskFreeBytes) . ' free'
    : 'unknown';

$retentionCount = max(1, to_int(ipam_setting('backup.retention')));
$backupEnabled  = (bool) ipam_setting('backup.enabled');

page_header('Backups');
?>

<div class="page-header">
  <div>
    <h1>Database Backups</h1>
    <p class="muted">Backup history and integrity verification. Use the CLI to run or restore.</p>
  </div>
</div>

<?php if ($msg !== ''): ?>
<div class="alert alert-success" role="alert"><?= e($msg) ?></div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
<div class="alert alert-danger" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if (!$backupEnabled): ?>
<div class="alert alert-warn" role="alert">
  Backups are <strong>disabled</strong>.
  Enable them in <a href="settings.php#backup">Settings → Backup</a>.
</div>
<?php endif; ?>

<!-- Summary card -->
<div class="card" style="margin-bottom:1.25rem;display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center">
  <div>
    <div class="muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Status</div>
    <div>
      <?php if ($backupEnabled): ?>
        <span class="badge" style="background:var(--success);color:#fff">Enabled</span>
      <?php else: ?>
        <span class="badge" style="background:var(--muted);color:#fff">Disabled</span>
      <?php endif; ?>
    </div>
  </div>
  <div>
    <div class="muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Retention</div>
    <div><?= e((string)$retentionCount) ?> backup<?= $retentionCount !== 1 ? 's' : '' ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Backup dir</div>
    <div style="font-family:monospace;font-size:.85em"><?= e($backupDir) ?></div>
  </div>
  <div>
    <div class="muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em">Disk space</div>
    <div><?= e($diskFree) ?></div>
  </div>
  <div style="margin-left:auto">
    <button type="button" class="button-secondary" onclick="showRestoreModal()">
      Restore instructions
    </button>
  </div>
</div>

<!-- Backup history table -->
<div class="card" style="padding:0;overflow:hidden">
  <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <strong>Backup History</strong>
    <span class="muted" style="font-size:.85em"><?= count($backups) ?> record<?= count($backups) !== 1 ? 's' : '' ?></span>
  </div>
  <?php if (empty($backups)): ?>
    <div style="padding:2rem;text-align:center;color:var(--muted)">
      No backups recorded yet.
      <?php if ($backupEnabled): ?>
        Run <code>php backup.php --force</code> to create the first backup.
      <?php endif; ?>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.9em">
      <thead>
        <tr style="background:var(--bg);border-bottom:1px solid var(--border)">
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">File</th>
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">Driver</th>
          <th style="padding:.6rem 1rem;text-align:right;font-weight:600;white-space:nowrap">Size</th>
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">SHA-256</th>
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">Started</th>
          <th style="padding:.6rem 1rem;text-align:right;font-weight:600;white-space:nowrap">Duration</th>
          <th style="padding:.6rem 1rem;text-align:center;font-weight:600;white-space:nowrap">Status</th>
          <th style="padding:.6rem 1rem;text-align:right;font-weight:600;white-space:nowrap">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($backups as $bk):
            $bkId     = (int)$bk['id'];
            $status   = to_str($bk['status'] ?? 'unknown');
            $sha256   = to_str($bk['sha256'] ?? '');
            $path     = to_str($bk['target_path'] ?? '');
            $fileOk   = ($path !== '' && is_file($path));
            $dur      = to_int($bk['duration_ms'] ?? 0);
            $durStr   = $dur > 0 ? ($dur >= 1000 ? round($dur / 1000, 1) . 's' : $dur . 'ms') : '—';

            if ($status === 'success') {
                $badgeBg  = 'var(--success)';
                $badgeFg  = '#fff';
            } elseif ($status === 'failed') {
                $badgeBg  = 'var(--danger)';
                $badgeFg  = '#fff';
            } else {
                $badgeBg  = 'var(--warn)';
                $badgeFg  = '#000';
            }
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:.6rem 1rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($path) ?>">
            <?php if ($fileOk): ?>
              <span style="font-family:monospace;font-size:.85em"><?= e(to_str($bk['filename'] ?? '')) ?></span>
            <?php else: ?>
              <span style="font-family:monospace;font-size:.85em;color:var(--muted)"><?= e(to_str($bk['filename'] ?? '')) ?></span>
              <span class="muted" style="font-size:.8em"> (missing)</span>
            <?php endif; ?>
          </td>
          <td style="padding:.6rem 1rem">
            <code style="font-size:.85em"><?= e(to_str($bk['db_driver'] ?? '')) ?></code>
          </td>
          <td style="padding:.6rem 1rem;text-align:right;white-space:nowrap">
            <?= e(format_bytes(to_int($bk['size_bytes'] ?? 0))) ?>
          </td>
          <td style="padding:.6rem 1rem">
            <?php if ($sha256 !== ''): ?>
              <code style="font-size:.8em;word-break:break-all" title="<?= e($sha256) ?>"><?= e(substr($sha256, 0, 12)) ?>…</code>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:.6rem 1rem;white-space:nowrap">
            <?= e(to_str($bk['started_at'] ?? '')) ?>
          </td>
          <td style="padding:.6rem 1rem;text-align:right;white-space:nowrap">
            <?= e($durStr) ?>
          </td>
          <td style="padding:.6rem 1rem;text-align:center">
            <span class="badge" style="background:<?= $badgeBg ?>;color:<?= $badgeFg ?>"><?= e($status) ?></span>
          </td>
          <td style="padding:.6rem 1rem;text-align:right;white-space:nowrap">
            <!-- Download -->
            <?php if ($fileOk): ?>
            <a href="backups.php?action=download&id=<?= $bkId ?>&csrf=<?= e(csrf_token()) ?>"
               class="action-pill" style="text-decoration:none;cursor:pointer" title="Download backup file">
              Download
            </a>
            <?php endif; ?>
            <!-- Verify -->
            <?php if ($sha256 !== '' && $fileOk): ?>
            <form method="post" action="backups.php" style="display:inline">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="verify">
              <input type="hidden" name="id"     value="<?= $bkId ?>">
              <button type="submit" class="action-pill" style="cursor:pointer" title="Verify SHA-256 integrity">
                Verify
              </button>
            </form>
            <?php endif; ?>
            <!-- Restore instructions -->
            <button type="button" class="action-pill" style="cursor:pointer"
                    onclick="showRestoreModal(<?= $bkId ?>, '<?= e(addslashes(to_str($bk['filename'] ?? ''))) ?>', '<?= e(addslashes($path)) ?>')">
              Restore…
            </button>
            <!-- Delete -->
            <button type="button" class="action-pill button-danger" style="cursor:pointer"
                    onclick="confirmDelete(<?= $bkId ?>, '<?= e(addslashes(to_str($bk['filename'] ?? ''))) ?>')">
              Delete
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Restore instructions modal -->
<div id="restore-modal" role="dialog" aria-modal="true" aria-labelledby="restore-modal-title"
     style="display:none;position:fixed;inset:0;z-index:var(--z-page-overlay);background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;max-width:640px;width:calc(100% - 2rem);max-height:90vh;overflow-y:auto;position:relative">
    <button onclick="closeModal('restore-modal')" style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--muted)" aria-label="Close">&times;</button>
    <h2 id="restore-modal-title" style="margin:0 0 1rem">Restore Instructions</h2>
    <p>Restores are performed via the CLI only. There is no one-click web restore — this is intentional to prevent accidental data loss.</p>
    <p><strong>Dry-run first (safe, no changes):</strong></p>
    <pre id="restore-cmd-dry" style="background:var(--bg);padding:.75rem;border-radius:var(--radius-sm);overflow-x:auto;font-size:.85em;white-space:pre-wrap;word-break:break-all"></pre>
    <p><strong>Apply restore:</strong></p>
    <pre id="restore-cmd-apply" style="background:var(--bg);padding:.75rem;border-radius:var(--radius-sm);overflow-x:auto;font-size:.85em;white-space:pre-wrap;word-break:break-all"></pre>
    <p class="muted" style="font-size:.85em;margin-top:.75rem">
      The restore script verifies the SHA-256 hash before writing. Use <code>--force</code> to overwrite a non-empty target.
    </p>
    <div style="text-align:right;margin-top:1rem">
      <button type="button" class="button-secondary" onclick="closeModal('restore-modal')">Close</button>
    </div>
  </div>
</div>

<!-- Delete confirmation modal -->
<div id="delete-modal" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title"
     style="display:none;position:fixed;inset:0;z-index:var(--z-page-overlay);background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;max-width:420px;width:calc(100% - 2rem)">
    <h2 id="delete-modal-title" style="margin:0 0 .75rem">Delete Backup?</h2>
    <p id="delete-modal-body" style="margin:0 0 1.25rem;word-break:break-all"></p>
    <form id="delete-form" method="post" action="backups.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id"     id="delete-id">
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" class="button-secondary" onclick="closeModal('delete-modal')">Cancel</button>
        <button type="submit" class="button-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  const phpRoot = <?= json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/')) ?>;

  function showModal(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'flex';
    el.addEventListener('click', function onBg(e) {
      if (e.target === el) { closeModal(id); el.removeEventListener('click', onBg); }
    });
    const first = el.querySelector('button, [href], [tabindex]');
    if (first) first.focus();
  }

  window.closeModal = function (id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
  };

  window.confirmDelete = function (id, filename) {
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-modal-body').textContent =
      'Delete backup record and file "' + filename + '"? This cannot be undone.';
    showModal('delete-modal');
  };

  window.showRestoreModal = function (id, filename, path) {
    const phpPath = phpRoot + '/restore.php';
    const fileArg = path || filename || '<path/to/backup>';
    document.getElementById('restore-cmd-dry').textContent =
      'php ' + phpPath + ' --from=' + fileArg + ' --dry-run';
    document.getElementById('restore-cmd-apply').textContent =
      'php ' + phpPath + ' --from=' + fileArg + ' --force';
    showModal('restore-modal');
  };

  // Close modals on Escape key
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeModal('restore-modal');
      closeModal('delete-modal');
    }
  });
}());
</script>

<?php page_footer(); ?>
