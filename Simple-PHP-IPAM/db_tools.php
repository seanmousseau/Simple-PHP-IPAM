<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

$err = '';
$msg = '';

/* ------------------------------------------------------------------ *
 * POST: export                                                         *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    csrf_require();
    audit($db, 'db.export', 'system', null, 'Manual database export initiated');

    $sql = ipam_db_dump($db);

    $filename = 'ipam-export-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo $sql;
    exit;
}

/* ------------------------------------------------------------------ *
 * POST: import                                                         *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_require();

    $confirmed = !empty($_POST['confirmed']);
    $upload    = $_FILES['sql_file'] ?? null;

    if (!$confirmed) {
        $err = 'You must check the confirmation box before importing.';
    } elseif (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
        $errCode = $upload['error'] ?? -1;
        $err = match ((int)$errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the allowed size limit.',
            UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
            default                                   => "Upload error (code {$errCode}).",
        };
    } else {
        $tmpPath  = (string)$upload['tmp_name'];
        $fileSize = filesize($tmpPath);
        $maxBytes = 50 * 1024 * 1024; // 50 MB hard cap

        if ($fileSize === false || $fileSize > $maxBytes) {
            $err = 'Import file must be under 50 MB.';
        } else {
            $sql = file_get_contents($tmpPath);
            if ($sql === false || trim($sql) === '') {
                $err = 'Uploaded file is empty or unreadable.';
            } else {
                // Basic sanity check: must look like a SQL dump
                if (!str_contains($sql, 'BEGIN TRANSACTION') && !str_contains($sql, 'CREATE TABLE')) {
                    $err = 'File does not appear to be a valid SQL dump (missing BEGIN TRANSACTION or CREATE TABLE).';
                }
            }
        }

        if (!$err) {
            // Back up the current database before import
            $dbPath = (string)($config['db_path'] ?? (__DIR__ . '/data/ipam.sqlite'));
            $backupPath = $dbPath . '.pre-import-' . date('YmdHis') . '.bak';
            try { $db->exec("PRAGMA wal_checkpoint(FULL)"); } catch (Throwable) {}
            if (!@copy($dbPath, $backupPath)) {
                $err = 'Could not create a pre-import backup of the database. Import aborted for safety.';
            }
        }

        if (!$err) {
            // Execute import inside a transaction; rollback on any error
            try {
                // Close current connection cleanly, re-open after replacing DB content
                $db->exec("PRAGMA foreign_keys=OFF");
                $db->beginTransaction();

                // Drop all user tables (except sqlite_sequence which is auto-managed)
                $tables = $db->query(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
                )->fetchAll(PDO::FETCH_COLUMN);

                foreach ($tables as $tbl) {
                    $db->exec('DROP TABLE IF EXISTS "' . addslashes((string)$tbl) . '"');
                }

                // Execute the dump SQL (split on statement boundaries)
                // Strip comments and split on semicolons cautiously
                $statements = preg_split('/;\s*\n/', $sql ?? '');
                foreach ($statements as $stmt) {
                    $stmt = trim($stmt);
                    if ($stmt === '' || str_starts_with($stmt, '--')) continue;
                    // Skip PRAGMA foreign_keys lines from the dump; we manage that ourselves
                    if (preg_match('/^PRAGMA\s+foreign_keys\s*=/i', $stmt)) continue;
                    $db->exec($stmt);
                }

                $db->exec("PRAGMA foreign_keys=ON");
                $db->commit();

                audit($db, 'db.import', 'system', null,
                    'Database import completed; pre-import backup: ' . basename($backupPath));
                $msg = 'Import successful. A pre-import backup was saved to: ' . e(basename($backupPath));
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                $db->exec("PRAGMA foreign_keys=ON");
                $err = 'Import failed: ' . e($ex->getMessage())
                     . ' The database has been restored from the pre-import state.';
                audit($db, 'db.import_failed', 'system', null,
                    'Import rolled back: ' . $ex->getMessage());
            }
        }
    }
}

/* ------------------------------------------------------------------ *
 * GET: manual backup trigger                                           *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now') {
    csrf_require();
    // Force a backup regardless of schedule
    $dbPath = (string)($config['db_path'] ?? (__DIR__ . '/data/ipam.sqlite'));
    $bdir   = backup_dir($config);
    if (!is_dir($bdir)) @mkdir($bdir, 0700, true);

    try { $db->exec("PRAGMA wal_checkpoint(FULL)"); } catch (Throwable) {}

    $ts   = date('Y-m-d-His');
    $dest = $bdir . '/ipam-' . $ts . '.sqlite';

    if (@copy($dbPath, $dest)) {
        @chmod($dest, 0600);
        $retention = max(1, (int)($config['backup']['retention'] ?? 7));
        $files = glob($bdir . '/ipam-*.sqlite');
        if (is_array($files)) {
            rsort($files);
            foreach (array_slice($files, $retention) as $old) @unlink($old);
        }
        $state = ['last_backup' => time(), 'last_file' => basename($dest)];
        @file_put_contents(__DIR__ . '/data/backup-state.json', json_encode($state));
        audit($db, 'db.backup', 'system', null, 'Manual backup: ' . basename($dest));
        $msg = 'Backup created: ' . e(basename($dest));
    } else {
        $err = 'Backup failed: could not write to ' . e($bdir);
    }
}

/* ------------------------------------------------------------------ *
 * Gather backup info for display                                       *
 * ------------------------------------------------------------------ */
$backupEnabled = !empty($config['backup']['enabled']);
$bInfo         = backup_info($config);

page_header('Database Tools');
?>
<h1>🗄 Database Tools</h1>

<?php if ($err): ?>
  <p class='danger'><?= $err ?></p>
<?php endif; ?>
<?php if ($msg): ?>
  <p class='success'><?= $msg ?></p>
<?php endif; ?>

<div class='grid cols-2' style='margin-top:16px'>

  <!-- Export -->
  <div class='card'>
    <h2>Export Database</h2>
    <p class='muted'>Download a full SQL dump of the database. This file can be used to restore or migrate the IPAM instance.</p>
    <form method='post'>
      <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
      <input type='hidden' name='action' value='export'>
      <button type='submit'>⬇ Download SQL Dump</button>
    </form>
  </div>

  <!-- Import -->
  <div class='card'>
    <h2>Import Database</h2>
    <p class='danger' style='font-weight:600'>⚠ This will <strong>replace</strong> all current data. A pre-import backup is created automatically.</p>
    <form method='post' enctype='multipart/form-data'>
      <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
      <input type='hidden' name='action' value='import'>
      <div class='row' style='flex-direction:column;gap:10px'>
        <label>SQL file (.sql)
          <input type='file' name='sql_file' accept='.sql,text/plain' required>
        </label>
        <label style='flex-direction:row;align-items:center;gap:8px;cursor:pointer'>
          <input type='checkbox' name='confirmed' value='1' required>
          I understand this will overwrite all existing data
        </label>
        <div>
          <button type='submit' class='button-danger'>⬆ Import &amp; Replace</button>
        </div>
      </div>
    </form>
  </div>

</div>

<!-- Backups -->
<div class='card' style='margin-top:16px'>
  <h2>Automatic Backups</h2>
  <?php if (!$backupEnabled): ?>
    <p class='muted'>Automatic backups are <strong>disabled</strong>. Enable them by setting <code>'backup' => ['enabled' => true, ...]</code> in config.php.</p>
  <?php else: ?>
    <p>
      Frequency: <strong><?= e(ucfirst((string)($config['backup']['frequency'] ?? 'daily'))) ?></strong>
      &nbsp;|&nbsp; Retention: <strong><?= e((string)($config['backup']['retention'] ?? 7)) ?> backups</strong>
      &nbsp;|&nbsp; Directory: <code><?= e($bInfo['dir']) ?></code>
    </p>
  <?php endif; ?>

  <table style='margin-top:10px'>
    <tr><th>Stat</th><th>Value</th></tr>
    <tr>
      <td>Last backup</td>
      <td><?= $bInfo['last_backup'] ? e(date('Y-m-d H:i:s', $bInfo['last_backup'])) : '<span class=\'muted\'>Never</span>' ?></td>
    </tr>
    <tr>
      <td>Last backup file</td>
      <td><?= $bInfo['last_file'] ? e((string)$bInfo['last_file']) : '<span class=\'muted\'>—</span>' ?></td>
    </tr>
    <tr>
      <td>Backup count</td>
      <td><?= (int)$bInfo['count'] ?></td>
    </tr>
  </table>

  <form method='post' style='margin-top:14px'>
    <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
    <input type='hidden' name='action' value='backup_now'>
    <button type='submit' class='button-secondary'>💾 Run Backup Now</button>
  </form>
</div>

<?php page_footer(); ?>
