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
        echo '<style>body{font:16px system-ui,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#222}h1{font-size:var(--text-xl)}code{background:#f4f4f4;padding:2px 6px;border-radius:3px}</style>';
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

    // v3.27.0 (#1113) — gate the import behind ipam_sudo_require(). Replaces
    // the v3.26.0 bare 'confirmed' checkbox per plan §4 row 6: a typed
    // confirmation alone is not strong enough authorisation for an action
    // that wipes and replaces every row in the database. If the gate is not
    // satisfied, render the step-up prompt as a full page and exit. The
    // uploaded file does not round-trip through the prompt (browsers will
    // not re-attach <input type="file"> after a redirect), so the prompt
    // explicitly tells the operator to return to this page and re-pick the
    // SQL file after authenticating.
    $cur    = current_user();
    $userId = to_int($cur['id'] ?? 0);
    if (!ipam_sudo_require($db, $userId)) {
        page_header('Confirm your identity');
        $stepUpUserId       = $userId;
        $stepUpFormAction   = 'db_tools.php';
        // Carry action=import so the proof submission re-enters the same
        // branch (line 104) and ipam_sudo_require() actually mints the
        // grant. Without it the resumed POST has no `action`, the entire
        // import branch is skipped, the proof goes unverified, and the
        // user then has no warm grant when they re-upload the SQL file.
        // CR PR #1117 #4. The browser still won't re-attach <input
        // type="file"> through a redirect; the user re-selects the file
        // on the page they land on after the proof succeeds, and the
        // warm grant carries that second submit through.
        $stepUpHiddenFields = ['action' => 'import'];
        $stepUpDescription  = 'Re-authenticate to import a SQL dump. This will overwrite every row in the database. After verifying, return to Database Tools and re-select your SQL file.';
        $stepUpReturnPath   = 'db_tools.php';
        $stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. Import refused.' : '';
        include __DIR__ . '/views/_step_up_prompt.php';
        page_footer();
        exit;
    }
    ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.


    $uploadRaw = $_FILES['sql_file'] ?? null;
    $upload    = is_array($uploadRaw) ? $uploadRaw : null;

    if ($upload === null || to_int($upload['error']) !== UPLOAD_ERR_OK) {
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
            // safety copy before import; failure sets $err and aborts the import transaction
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
 * v3.26.0 (#1059): the legacy single-directory backup admin lived here
 * (manual backup_now, backup_verify, backup_download, backup_delete,
 * the Automatic Backups card, the Backup History table, and two modals).
 * Backups are now driven entirely by the unified surface in
 * backup_admin.php which iterates every backup_destinations row. This
 * page is now the SQL export/import + status surface only.
 * ------------------------------------------------------------------ */

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
        <p class='muted fs-09'>
          You will be prompted to re-authenticate before the import runs.
          The import wipes every existing row and replaces it with the
          uploaded dump &mdash; a pre-import backup is created automatically.
        </p>
        <div>
          <button type='submit' class='button-danger'<?= $sqlDumpSupported ? '' : ' disabled' ?>><?= icon('upload') ?> Import &amp; Replace</button>
        </div>
      </div>
    </form>
  </div>

</div>

<!-- v3.26.0 (#1059): Automatic Backups card + Backup History table moved to backup_admin.php -->
<div class='card mt-16'>
  <h2>Automatic Backups</h2>
  <p class='muted'>
    Database backups are managed on the unified
    <a href="backup_admin.php">Backups admin</a> surface, which configures
    destinations (local / SFTP / S3), schedules, retention, and history.
  </p>
</div>

<?php page_footer(); ?>
