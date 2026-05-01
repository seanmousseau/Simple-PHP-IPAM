<?php
declare(strict_types=1);

// Early guard: if this is a POST whose Content-Length exceeds php.ini's
// post_max_size, PHP silently discards $_POST / $_FILES and emits a
// "Request Startup" warning to stdout before any script runs. That warning
// commits the HTTP response body, so every subsequent header()/session_start()
// call from init.php cascades into "headers already sent" errors. Detect the
// condition here before requiring init.php and emit a clean 413 with
// actionable guidance (the PHP warning is suppressed at the server level via
// `php_flag display_startup_errors Off` in .htaccess).
$_contentLenRaw = $_SERVER['CONTENT_LENGTH'] ?? '0';
$_contentLen    = is_numeric($_contentLenRaw) ? (int) $_contentLenRaw : 0;
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && $_contentLen > 0
    && $_POST === []
    && $_FILES === []
) {
    /** @return int bytes — parses '512M', '2G', '4096k', '1024' */
    $parseIniBytes = static function (string $raw): int {
        $raw = trim($raw);
        if ($raw === '') return 0;
        $last = strtolower($raw[strlen($raw) - 1]);
        $n = (int) $raw;
        return match ($last) {
            'g'     => $n * 1024 * 1024 * 1024,
            'm'     => $n * 1024 * 1024,
            'k'     => $n * 1024,
            default => $n,
        };
    };
    $postMaxBytes = $parseIniBytes((string) ini_get('post_max_size'));
    if ($postMaxBytes > 0 && $_contentLen > $postMaxBytes) {
        http_response_code(413);
        header('Content-Type: text/html; charset=utf-8');
        $mbLimit = (int) round($postMaxBytes / 1024 / 1024);
        $mbSent  = (int) round($_contentLen / 1024 / 1024);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Upload too large</title>';
        echo '<style>body{font:16px system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#222}h1{font-size:24px}code{background:#f4f4f4;padding:2px 6px;border-radius:3px}</style>';
        echo '<h1>413 — Uploaded file too large</h1>';
        echo "<p>The upload was approximately <strong>{$mbSent}&nbsp;MB</strong>, which exceeds this server's <code>post_max_size</code> limit of <strong>{$mbLimit}&nbsp;MB</strong>.</p>";
        echo '<p>To import a larger SQL dump, raise both <code>post_max_size</code> and <code>upload_max_filesize</code> in one of the following places and try again:</p>';
        echo '<ul>';
        echo '  <li><code>Simple-PHP-IPAM/.htaccess</code> (Apache + mod_php)</li>';
        echo '  <li>PHP-FPM pool config (<code>/etc/php/*/fpm/pool.d/*.conf</code>)</li>';
        echo '  <li><code>php.ini</code> (CGI / other SAPIs)</li>';
        echo '</ul>';
        echo '<p><a href="db_tools.php">&larr; Back to Database Tools</a></p>';
        exit;
    }
}

require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();
require_role('admin');

$err = '';
$msg = '';
$backupPath = '';

/* ------------------------------------------------------------------ *
 * POST: export                                                         *
 * ------------------------------------------------------------------ */
// ipam_db_dump_stream() and the import path below both hardcode
// sqlite_master queries. SQL-format dump/restore on MySQL lands in v3.0.0's
// migrate_db.php (#392). Until then, gate both actions with a clear
// user-facing message on non-SQLite drivers instead of letting the request
// reach the hardcoded SQLite queries and 500.
$sqlDumpSupported = ipam_dialect()->driver_name() === 'sqlite';
$sqlDumpUnsupportedMsg = 'SQL export/import is only supported on the SQLite driver. '
    . 'The MySQL driver uses engine-native tools (mysqldump / mysql); '
    . 'cross-engine dump/restore will land in v3.0.0 via migrate_db.php.';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export' && $sqlDumpSupported) {
    csrf_require();
    audit($db, 'db.export', 'system', null, 'Manual database export initiated');

    $filename = 'ipam-export-' . date('Y-m-d-His') . '.sql';
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    ipam_db_dump_stream($db, function(string $chunk) { echo $chunk; });
    exit;
}

/* ------------------------------------------------------------------ *
 * POST: import                                                         *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['export', 'import'], true)
    && !$sqlDumpSupported
) {
    csrf_require();
    // Non-2xx status + machine-readable header so a scripted backup
    // client cannot mistake the warning HTML for a successful dump.
    // The HTML still renders inline below so interactive users see the
    // notice the same way every other db_tools error is surfaced.
    http_response_code(400);
    header('X-IPAM-Sql-Dump-Unsupported: 1');
    $err = $sqlDumpUnsupportedMsg;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    csrf_require();

    $confirmed = !empty($_POST['confirmed']);
    $uploadRaw = $_FILES['sql_file'] ?? null;
    $upload    = is_array($uploadRaw) ? $uploadRaw : null;

    if (!$confirmed) {
        $err = 'You must check the confirmation box before importing.';
    } elseif ($upload === null || to_int($upload['error']) !== UPLOAD_ERR_OK) {
        $errCode = $upload !== null ? to_int($upload['error']) : UPLOAD_ERR_NO_FILE;
        $err = match ($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the allowed size limit.',
            UPLOAD_ERR_NO_FILE                        => 'No file was uploaded.',
            default                                   => "Upload error (code {$errCode}).",
        };
    } else {
        $tmpPath  = to_str($upload['tmp_name']);
        $fileSize = filesize($tmpPath);
        // Null-coalesce to match ipam_config_defaults() default. Upgrades from
        // older installs may not yet have this key in config.php if the file
        // is not writable by the web server user (so ipam_config_sync() could
        // not auto-populate it on boot). Fallback prevents a "headers already
        // sent" cascade even if the global error handler is not yet installed.
        $maxMb    = to_int(ipam_setting('limits.import_sql_max_mb'));
        $maxBytes = $maxMb * 1024 * 1024;

        if ($fileSize === false || $fileSize > $maxBytes) {
            $err = "Import file must be under {$maxMb} MB.";
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
            $dbPath = to_str($config['db_path']);
            $backupPath = $dbPath . '.pre-import-' . date('YmdHis') . '.bak';
            try { $db->exec("PRAGMA wal_checkpoint(FULL)"); } catch (Throwable) {}
            if (!@copy($dbPath, $backupPath)) {
                $err = 'Could not create a pre-import backup of the database. Import aborted for safety.';
            }
        }

        if (!$err) {
            // Execute import inside a transaction; rollback on any error
            try {
                // Close current connection cleanly, re-open after replacing DB content.
                // #380: route FK toggle through the dialect so future engines
                // swap in their own connection-level override.
                $d = ipam_dialect();
                $fkOff = $d->pragma_foreign_keys(false);
                if ($fkOff !== null) $db->exec($fkOff);
                $db->beginTransaction();

                // Drop all user tables (except sqlite_sequence which is auto-managed)
                $tablesSt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                $tables = $tablesSt !== false ? $tablesSt->fetchAll(PDO::FETCH_COLUMN) : [];

                foreach ($tables as $tbl) {
                    $db->exec('DROP TABLE IF EXISTS "' . str_replace('"', '""', (string)$tbl) . '"');
                }

                // Split the dump into individual statements.
                // Simple preg_split on ";\n" is insufficient because:
                //   1. Trigger bodies contain ";\n" inside BEGIN...END blocks.
                //   2. Text values encoded as CAST(X'hex' AS TEXT) are single-line,
                //      but a plain-text dump could have ";\n" inside string literals.
                // We use a line-based parser that tracks BEGIN...END depth so that
                // semicolons inside trigger bodies are never treated as boundaries.
                $statements = [];
                $current    = '';
                $depth      = 0;
                foreach (explode("\n", $sql ?? '') as $line) {
                    $trimLine = trim($line);
                    // A bare BEGIN (no TRANSACTION keyword) opens a trigger body.
                    if (preg_match('/^BEGIN\s*$/i', $trimLine)) {
                        $depth++;
                    }
                    $current .= $line . "\n";
                    // END; closes a trigger body.
                    if ($depth > 0 && preg_match('/^END\s*;\s*$/i', $trimLine)) {
                        $depth--;
                    }
                    // A line ending with ; at depth 0 terminates a complete statement.
                    if ($depth === 0 && str_ends_with(rtrim($line), ';')) {
                        $statements[] = $current;
                        $current = '';
                    }
                }
                if (trim($current) !== '') {
                    $statements[] = $current;
                }

                $importStart = microtime(true);
                $stmtCount = 0;
                foreach ($statements as $stmt) {
                    // Strip SQL comment lines, then trim
                    $exec = trim((string)preg_replace('/^--[^\n]*\n?/m', '', $stmt));
                    if ($exec === '') continue;
                    // Skip transaction/pragma statements we manage ourselves
                    if (preg_match('/^PRAGMA\s+foreign_keys\s*=/i', $exec)) continue;
                    if (preg_match('/^BEGIN(\s+TRANSACTION)?\s*;?\s*$/i', $exec)) continue;
                    if (preg_match('/^COMMIT\s*;?\s*$/i', $exec)) continue;
                    // Whitelist: only allow safe DDL/DML statements
                    $firstWord = strtoupper((string)strtok(ltrim($exec), " \t\r\n("));
                    $allowed = ['INSERT', 'CREATE', 'DROP', 'DELETE'];
                    if (!in_array($firstWord, $allowed, true)) {
                        throw new RuntimeException(
                            'Blocked disallowed SQL statement type: ' . $firstWord
                        );
                    }
                    // Block dangerous SQLite constructs even inside allowed statements
                    $upper = strtoupper($exec);
                    if (str_contains($upper, 'LOAD_EXTENSION') || str_contains($upper, 'ATTACH') || str_contains($upper, 'DETACH')) {
                        throw new RuntimeException(
                            'Blocked dangerous SQL construct in: ' . substr($exec, 0, 80)
                        );
                    }
                    // CREATE TRIGGER bodies can contain arbitrary SQL. Only allow
                    // triggers that match one of three known-safe patterns:
                    //   1. RAISE(ABORT, ...) — append-only guards on audit_log
                    //   2. UPDATE <table> SET updated_at = datetime('now') WHERE id = OLD.id
                    //      — the timestamp maintenance triggers on users/subnets/addresses
                    //   3. UPDATE <table> SET <col> = NULL WHERE <col> = OLD.id
                    //      — FK cleanup triggers (e.g. vlans_before_delete_cleanup_subnets)
                    // Patterns 2 and 3 are matched strictly: the entire trigger body must be
                    // that single UPDATE statement with no other statements before or after it.
                    if (preg_match('/^CREATE\s+TRIGGER/i', $exec)) {
                        $isRaise     = preg_match('/RAISE\s*\(\s*ABORT/i', $exec);
                        $isTimestamp = preg_match(
                            '/\bBEGIN\s+UPDATE\s+\w+\s+SET\s+updated_at\s*=\s*datetime\s*\(\s*\'now\'\s*\)\s+WHERE\s+id\s*=\s*OLD\.id\s*;\s*END\b/is',
                            $exec
                        );
                        $isFkCleanup = preg_match(
                            '/\bBEGIN\s+UPDATE\s+\w+\s+SET\s+\w+\s*=\s*NULL\s+WHERE\s+\w+\s*=\s*OLD\.id\s*;\s*END\b/is',
                            $exec
                        );
                        if (!$isRaise && !$isTimestamp && !$isFkCleanup) {
                            throw new RuntimeException(
                                'Blocked CREATE TRIGGER with non-RAISE body: ' . substr($exec, 0, 80)
                            );
                        }
                    }
                    $db->exec($exec);
                    $stmtCount++;
                }

                $db->commit();
                // SQLite ignores PRAGMA foreign_keys while a transaction is
                // open, so the re-enable must run *after* commit(), not before.
                $fkOn = $d->pragma_foreign_keys(true);
                if ($fkOn !== null) $db->exec($fkOn);

                $elapsed = round(microtime(true) - $importStart, 1);
                audit($db, 'db.import', 'system', null,
                    "Database import completed ({$stmtCount} statements, {$elapsed}s); pre-import backup: " . basename($backupPath));
                $msg = "Import successful — {$stmtCount} statements executed in {$elapsed}s. Pre-import backup: " . basename($backupPath);
                // Invalidate db_initialized sentinel so ipam_db_init re-checks on next request
                @unlink(__DIR__ . '/data/.db_initialized'); // nosemgrep: php.lang.security.unlink-use.unlink-use
            } catch (Throwable $ex) {
                if ($db->inTransaction()) $db->rollBack();
                $fkOnRecover = ipam_dialect()->pragma_foreign_keys(true);
                if ($fkOnRecover !== null) $db->exec($fkOnRecover);
                $err = 'Import failed: ' . $ex->getMessage()
                     . ' The database has been restored from the pre-import state.';
                audit($db, 'db.import_failed', 'system', null,
                    'Import rolled back: ' . $ex->getMessage());
            }
        }
    }
}

/* ------------------------------------------------------------------ *
 * POST: backup_runs delete (CLI runner rows; v3.21.0 §A1 / #799)      *
 *                                                                     *
 * The legacy backup_history table stored a full target_path; backup_runs
 * stores only filename. The on-disk path is reconstructed from
 * backup_dir($config) and the filename is constrained to a single path
 * component (basename) before joining, so a user-supplied filename
 * cannot escape the backup directory.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_delete') {
    csrf_require();
    $delId = to_int($_POST['id'] ?? 0);
    if ($delId > 0) {
        $st = $db->prepare("SELECT * FROM backup_runs WHERE id=:id AND triggered_by='cli'");
        $st->execute([':id' => $delId]);
        $bkRow = $st->fetch();
        if (is_array($bkRow)) {
            $allowedDir = realpath(backup_dir($config));
            $rowFile    = basename(to_str($bkRow['filename'] ?? ''));
            $delPath    = ($allowedDir !== false && $rowFile !== '') ? ($allowedDir . DIRECTORY_SEPARATOR . $rowFile) : '';
            $realDel    = $delPath !== '' ? (realpath($delPath) ?: '') : '';
            $fileOk     = true; // assume no file to remove unless we try
            // Only unlink if the file is inside the configured backup directory
            if ($allowedDir !== false && $realDel !== ''
                && str_starts_with($realDel, $allowedDir . DIRECTORY_SEPARATOR)
                && is_file($realDel)) {
                $fileOk = unlink($realDel); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
            if ($fileOk) {
                $db->prepare("DELETE FROM backup_runs WHERE id=:id")->execute([':id' => $delId]);
                audit($db, 'backup.deleted', 'backup_runs', $delId,
                    'Deleted backup record: ' . $rowFile);
                $msg = 'Backup record deleted.';
            } else {
                audit($db, 'backup.delete_failed', 'backup_runs', $delId,
                    'Failed to delete backup file: ' . $rowFile);
                $err = 'Failed to delete the backup file — record kept.';
            }
        } else {
            $err = 'Backup record not found.';
        }
    }
}

/* ------------------------------------------------------------------ *
 * POST: backup_runs verify                                            *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_verify') {
    csrf_require();
    $verifyId = to_int($_POST['id'] ?? 0);
    if ($verifyId > 0) {
        $st = $db->prepare("SELECT * FROM backup_runs WHERE id=:id AND triggered_by='cli'");
        $st->execute([':id' => $verifyId]);
        $bkRow = $st->fetch();
        if (is_array($bkRow)) {
            $allowedVerDir = realpath(backup_dir($config));
            $rowFile      = basename(to_str($bkRow['filename'] ?? ''));
            $verPath      = ($allowedVerDir !== false && $rowFile !== '') ? ($allowedVerDir . DIRECTORY_SEPARATOR . $rowFile) : '';
            $expected     = to_str($bkRow['checksum'] ?? '');
            $realVer      = $verPath !== '' ? (realpath($verPath) ?: false) : false;
            if ($realVer === false || $allowedVerDir === false
                || !str_starts_with($realVer, $allowedVerDir . DIRECTORY_SEPARATOR)) {
                $err = 'Backup path is outside the allowed backup directory.';
            } elseif (!is_file($realVer)) {
                $err = 'Backup file not found on disk.';
            } elseif ($expected === '') {
                $err = 'No SHA-256 hash recorded for this backup — cannot verify.';
            } else {
                $actual = hash_file('sha256', $realVer) ?: '';
                if (hash_equals($expected, $actual)) {
                    $msg = 'SHA-256 verified OK. File integrity confirmed.';
                    audit($db, 'backup.verified', 'backup_runs', $verifyId,
                        'SHA-256 verified OK for: ' . basename($realVer));
                } else {
                    $err = 'SHA-256 MISMATCH — backup file may be corrupted.';
                    audit($db, 'backup.verify_failed', 'backup_runs', $verifyId,
                        'SHA-256 mismatch for: ' . basename($realVer));
                }
            }
        } else {
            $err = 'Backup record not found.';
        }
    }
}

/* ------------------------------------------------------------------ *
 * GET: backup_runs download                                           *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'backup_download') {
    $dlId = to_int($_GET['id'] ?? 0);
    if ($dlId > 0) {
        $st = $db->prepare("SELECT * FROM backup_runs WHERE id=:id AND triggered_by='cli'");
        $st->execute([':id' => $dlId]);
        $bkRow = $st->fetch();
        if (is_array($bkRow)) {
            $allowedDlDir = realpath(backup_dir($config));
            $rowFile    = basename(to_str($bkRow['filename'] ?? ''));
            $dlPath     = ($allowedDlDir !== false && $rowFile !== '') ? ($allowedDlDir . DIRECTORY_SEPARATOR . $rowFile) : '';
            $realDl     = $dlPath !== '' ? (realpath($dlPath) ?: false) : false;
            if ($realDl === false || $allowedDlDir === false
                || !str_starts_with($realDl, $allowedDlDir . DIRECTORY_SEPARATOR)) {
                audit($db, 'backup.download_denied', 'backup_runs', $dlId,
                    'Path confinement check failed for backup id ' . $dlId);
                http_response_code(403);
                exit('Access denied.');
            }
            if (is_file($realDl)) {
                $dlFilename = basename($realDl);
                $dlSize     = (int)filesize($realDl);
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $dlFilename . '"');
                header('Content-Length: ' . $dlSize);
                header('X-Content-Type-Options: nosniff');
                readfile($realDl);
                audit($db, 'backup.downloaded', 'backup_runs', $dlId,
                    'Downloaded backup: ' . $dlFilename);
                exit;
            }
        }
    }
    $err = 'Backup file not found.';
}

/* ------------------------------------------------------------------ *
 * GET: manual backup trigger                                           *
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now' && !$sqlDumpSupported) {
    csrf_require();
    // Manual backup is SQLite-only for the same reason SQL dump is:
    // wal_checkpoint + file-copy of a .sqlite file is engine-specific.
    // Return 400 + the same notice as export/import so scripted clients
    // cannot mistake the warning page for a successful backup trigger.
    http_response_code(400);
    header('X-IPAM-Sql-Dump-Unsupported: 1');
    $err = $sqlDumpUnsupportedMsg;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now') {
    csrf_require();
    // Force a backup regardless of schedule
    $dbPath = to_str($config['db_path']);
    $bdir   = backup_dir($config);
    if (!is_dir($bdir)) @mkdir($bdir, 0700, true);

    try { $db->exec("PRAGMA wal_checkpoint(FULL)"); } catch (Throwable) {}

    $ts   = date('Y-m-d-His');
    $dest = $bdir . '/ipam-' . $ts . '.sqlite';

    if (@copy($dbPath, $dest)) {
        @chmod($dest, 0600);
        $retention = max(1, to_int(ipam_setting('backup.retention')));
        $files = glob($bdir . '/ipam-*.sqlite');
        if (is_array($files)) {
            rsort($files);
            foreach (array_slice($files, $retention) as $old) @unlink($old); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
        $state = ['last_backup' => time(), 'last_file' => basename($dest)];
        @file_put_contents(__DIR__ . '/data/backup-state.json', json_encode($state));
        audit($db, 'db.backup', 'system', null, 'Manual backup: ' . basename($dest));
        $msg = 'Backup created: ' . basename($dest);
    } else {
        $err = 'Backup failed: could not write to ' . $bdir;
    }
}

/* ------------------------------------------------------------------ *
 * Gather backup info for display                                       *
 * ------------------------------------------------------------------ */
$backupEnabled = (bool)ipam_setting('backup.enabled');
$bInfo         = backup_info($config);

/* ------------------------------------------------------------------ *
 * Load backup history for the history section                         *
 * ------------------------------------------------------------------ */
$bkpRows = [];
try {
    // v3.21.0 §A1 (#799): backup_history collapsed into backup_runs.
    // Filter to triggered_by='cli' for parity with the legacy CLI-runner UI.
    $bkpSt = $db->query("SELECT * FROM backup_runs WHERE triggered_by='cli' ORDER BY started_at DESC LIMIT 50");
    /** @var list<array<string, mixed>> $bkpRows */
    $bkpRows = $bkpSt ? $bkpSt->fetchAll() : [];
} catch (Throwable) {
    // table may not exist yet on a fresh install before migration runs
}
$backupDir     = backup_dir($config);
$diskFreeBytes = @disk_free_space($backupDir);
$diskFree      = ($diskFreeBytes !== false)
    ? format_bytes((int)$diskFreeBytes) . ' free'
    : 'unknown';

page_header('Database');
render_security_banner('db_tools', 'Database import will overwrite all existing data. Only import files from trusted sources.');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Database</span>
</div>
<h1>Database</h1>

<?php if ($err): ?>
  <p class='danger'><?= e($err) ?></p>
<?php endif; ?>
<?php if ($msg): ?>
  <p class='success'><?= e($msg) ?></p>
<?php endif; ?>

<?php if (!$sqlDumpSupported): ?>
  <div class='admin-notice admin-notice--warning mt-16' role='status'>
    <strong>SQL export/import is SQLite-only.</strong>
    The MySQL driver uses engine-native tools (<code>mysqldump</code> / <code>mysql</code>).
    Cross-engine dump/restore will land in v3.0.0 via <code>migrate_db.php</code> (#392).
  </div>
<?php endif; ?>

<div class='grid cols-2 mt-16'>

  <!-- Export -->
  <div class='card'>
    <h2>Export Database</h2>
    <p class='muted'>Download a full SQL dump of the database. This file can be used to restore or migrate the IPAM instance.</p>
    <form method='post'>
      <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
      <input type='hidden' name='action' value='export'>
      <button type='submit'<?= $sqlDumpSupported ? '' : ' disabled' ?>><?= icon('download') ?> Download SQL Dump</button>
    </form>
  </div>

  <!-- Import -->
  <div class='card'>
    <h2>Import Database</h2>
    <p class='danger fw-600'>⚠ This will <strong>replace</strong> all current data. A pre-import backup is created automatically.</p>
    <form method='post' enctype='multipart/form-data' data-import-form>
      <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
      <input type='hidden' name='action' value='import'>
      <div class='flex-col gap-10'>
        <label>SQL file (.sql)
          <input type='file' name='sql_file' accept='.sql,text/plain' required>
        </label>
        <label class='d-flex align-center gap-8 cursor-pointer'>
          <input type='checkbox' name='confirmed' value='1' required>
          I understand this will overwrite all existing data
        </label>
        <div>
          <button type='submit' class='button-danger'<?= $sqlDumpSupported ? '' : ' disabled' ?>><?= icon('upload') ?> Import &amp; Replace</button>
        </div>
      </div>
    </form>
  </div>

</div>

<!-- Backups -->
<div class='card mt-16'>
  <h2>Automatic Backups</h2>
  <?php if (!$backupEnabled): ?>
    <p class='muted'>Automatic backups are <strong>disabled</strong>. Enable them in <strong>Admin &rarr; Settings &rarr; Database backup</strong>.</p>
  <?php else: ?>
    <p>
      Frequency: <strong><?= e(ucfirst(to_str(ipam_setting('backup.frequency')))) ?></strong>
      &nbsp;|&nbsp; Retention: <strong><?= e(to_str(ipam_setting('backup.retention'))) ?> backups</strong>
      &nbsp;|&nbsp; Directory: <code><?= e(to_str($bInfo['dir'])) ?></code>
    </p>
  <?php endif; ?>

  <table class='mt-10'>
    <tr><th>Stat</th><th>Value</th></tr>
    <tr>
      <td>Last backup</td>
      <td><?= $bInfo['last_backup'] ? e(ipam_format_datetime(to_int($bInfo['last_backup']))) : '<span class=\'muted\'>Never</span>' ?></td>
    </tr>
    <tr>
      <td>Last backup file</td>
      <td><?= $bInfo['last_file'] ? e(to_str($bInfo['last_file'])) : '<span class=\'muted\'>—</span>' ?></td>
    </tr>
    <tr>
      <td>Backup count</td>
      <td><?= to_int($bInfo['count']) ?></td>
    </tr>
  </table>

  <form method='post' class='mt-14'>
    <input type='hidden' name='csrf' value='<?= e(csrf_token()) ?>'>
    <input type='hidden' name='action' value='backup_now'>
    <button type='submit' class='button-secondary'<?= $sqlDumpSupported ? '' : ' disabled' ?>><?= icon('backup') ?> Run Backup Now</button>
  </form>
</div>

<!-- ================================================================ -->
<!-- Backup History                                                    -->
<!-- ================================================================ -->
<div id="backup-history" class="card mt-16" style="padding:0;overflow:hidden">
  <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem">
    <div>
      <strong>Backup History</strong>
      <span class="muted" style="font-size:.85em;margin-left:.75rem"><?= count($bkpRows) ?> record<?= count($bkpRows) !== 1 ? 's' : '' ?></span>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;font-size:.85em">
      <?php if ($backupEnabled): ?>
        <span class="badge" style="background:var(--success);color:#fff">Backups enabled</span>
      <?php else: ?>
        <span class="badge" style="background:var(--muted);color:#fff">Backups disabled</span>
      <?php endif; ?>
      <span class="muted">Dir: <code style="font-size:.9em"><?= e($backupDir) ?></code></span>
      <span class="muted">Disk: <?= e($diskFree) ?></span>
      <button type="button" class="button-secondary" style="font-size:.85em" data-action="restore-info">
        Restore instructions
      </button>
    </div>
  </div>
  <?php if (empty($bkpRows)): ?>
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
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">Source version</th>
          <th style="padding:.6rem 1rem;text-align:right;font-weight:600;white-space:nowrap">Size</th>
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">SHA-256</th>
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">Started</th>
          <th style="padding:.6rem 1rem;text-align:left;font-weight:600;white-space:nowrap">Completed</th>
          <th style="padding:.6rem 1rem;text-align:center;font-weight:600;white-space:nowrap">Status</th>
          <th style="padding:.6rem 1rem;text-align:right;font-weight:600;white-space:nowrap">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $bkpDirAbs = realpath(backup_dir($config));
        foreach ($bkpRows as $bk):
            $bkId    = to_int($bk['id'] ?? 0);
            $bkStatus = to_str($bk['status'] ?? 'unknown');
            // v3.21.0 §A1 (#799): backup_runs replaces backup_history.
            // sha256 → checksum; target_path is gone (reconstructed from
            // backup_dir + filename); duration_ms is gone (replaced by
            // showing started_at + completed_at).
            $bkSha    = to_str($bk['checksum'] ?? '');
            $bkFile   = basename(to_str($bk['filename'] ?? ''));
            $bkPath   = ($bkpDirAbs !== false && $bkFile !== '') ? ($bkpDirAbs . DIRECTORY_SEPARATOR . $bkFile) : '';
            $bkFileOk = ($bkPath !== '' && is_file($bkPath));
            $bkSrcVer = to_str($bk['source_version'] ?? '');
            $bkComp   = to_str($bk['completed_at'] ?? '');

            if ($bkStatus === 'success') {
                $bkBadgeBg = 'var(--success)'; $bkBadgeFg = '#fff';
            } elseif ($bkStatus === 'failed') {
                $bkBadgeBg = 'var(--danger)';  $bkBadgeFg = '#fff';
            } else {
                $bkBadgeBg = 'var(--warn)';    $bkBadgeFg = '#000';
            }
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:.6rem 1rem;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($bkPath) ?>">
            <?php if ($bkFileOk): ?>
              <span style="font-family:monospace;font-size:.85em"><?= e(to_str($bk['filename'] ?? '')) ?></span>
            <?php else: ?>
              <span style="font-family:monospace;font-size:.85em;color:var(--muted)"><?= e(to_str($bk['filename'] ?? '')) ?></span>
              <span class="muted" style="font-size:.8em"> (missing)</span>
            <?php endif; ?>
          </td>
          <td style="padding:.6rem 1rem">
            <code style="font-size:.85em"><?= e($bkSrcVer) ?></code>
          </td>
          <td style="padding:.6rem 1rem;text-align:right;white-space:nowrap">
            <?= e(format_bytes(to_int($bk['size_bytes'] ?? 0))) ?>
          </td>
          <td style="padding:.6rem 1rem">
            <?php if ($bkSha !== ''): ?>
              <code style="font-size:.8em;word-break:break-all" title="<?= e($bkSha) ?>"><?= e(substr($bkSha, 0, 12)) ?>…</code>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:.6rem 1rem;white-space:nowrap">
            <?= e(to_str($bk['started_at'] ?? '')) ?>
          </td>
          <td style="padding:.6rem 1rem;white-space:nowrap">
            <?= $bkComp !== '' ? e($bkComp) : '<span class="muted">—</span>' ?>
          </td>
          <td style="padding:.6rem 1rem;text-align:center">
            <span class="badge" style="background:<?= $bkBadgeBg ?>;color:<?= $bkBadgeFg ?>"><?= e($bkStatus) ?></span>
          </td>
          <td style="padding:.6rem 1rem;text-align:right;white-space:nowrap">
            <!-- Download -->
            <?php if ($bkFileOk): ?>
            <a href="db_tools.php?action=backup_download&id=<?= $bkId ?>"
               class="action-pill" style="text-decoration:none;cursor:pointer" title="Download backup file">
              Download
            </a>
            <?php endif; ?>
            <!-- Verify -->
            <?php if ($bkSha !== '' && $bkFileOk): ?>
            <form method="post" action="db_tools.php" style="display:inline">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="backup_verify">
              <input type="hidden" name="id"     value="<?= $bkId ?>">
              <button type="submit" class="action-pill" style="cursor:pointer" title="Verify SHA-256 integrity">
                Verify
              </button>
            </form>
            <?php endif; ?>
            <!-- Restore instructions -->
            <button type="button" class="action-pill" style="cursor:pointer"
                    data-action="restore-info"
                    data-id="<?= $bkId ?>"
                    data-filename="<?= e(to_str($bk['filename'] ?? '')) ?>"
                    data-path="<?= e($bkPath) ?>">
              Restore…
            </button>
            <!-- Delete -->
            <button type="button" class="action-pill button-danger" style="cursor:pointer"
                    data-action="backup-delete"
                    data-id="<?= $bkId ?>"
                    data-filename="<?= e(to_str($bk['filename'] ?? '')) ?>">
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
    <button data-action="close-modal" data-target="restore-modal" style="position:absolute;top:.75rem;right:.75rem;background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--muted)" aria-label="Close">&times;</button>
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
      <button type="button" class="button-secondary" data-action="close-modal" data-target="restore-modal">Close</button>
    </div>
  </div>
</div>

<!-- Delete confirmation modal -->
<div id="delete-modal" role="dialog" aria-modal="true" aria-labelledby="delete-modal-title"
     style="display:none;position:fixed;inset:0;z-index:var(--z-page-overlay);background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;max-width:420px;width:calc(100% - 2rem)">
    <h2 id="delete-modal-title" style="margin:0 0 .75rem">Delete Backup?</h2>
    <p id="delete-modal-body" style="margin:0 0 1.25rem;word-break:break-all"></p>
    <form id="delete-form" method="post" action="db_tools.php">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="backup_delete">
      <input type="hidden" name="id"     id="delete-id">
      <div style="display:flex;gap:.75rem;justify-content:flex-end">
        <button type="button" class="button-secondary" data-action="close-modal" data-target="delete-modal">Cancel</button>
        <button type="submit" class="button-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<?php page_footer(); ?>
