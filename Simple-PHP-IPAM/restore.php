<?php
declare(strict_types=1);
/**
 * restore.php — CLI-only database restore runner.
 *
 * Usage:
 *   php restore.php --from=<path> [--dry-run] [--force]
 *
 *   --from=<path>  Path to backup file produced by backup.php
 *   --dry-run      Validate and report the restore plan without writing anything
 *   --force        Allow overwriting a non-empty target database
 *
 * Web requests receive a 403 Forbidden response.
 * There is intentionally no one-click web restore — this is a security boundary.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain');
    echo "403 Forbidden\n";
    exit(1);
}

require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

/* -------------------------------------------------------------------------
 * Parse arguments
 * ---------------------------------------------------------------------- */
$opts   = getopt('', ['from:', 'dry-run', 'force']);
$from   = isset($opts['from'])    ? (string)$opts['from'] : '';
$dryRun = array_key_exists('dry-run', $opts);
$force  = array_key_exists('force', $opts);

function restore_die(string $msg): never
{
    fwrite(STDERR, "ERROR: {$msg}\n");
    exit(1);
}

function restore_info(string $msg): void
{
    fwrite(STDOUT, $msg . "\n");
}

if ($from === '') {
    restore_die("--from=<path> is required.\nUsage: php restore.php --from=<backup-file> [--dry-run] [--force]");
}

/* -------------------------------------------------------------------------
 * Safety checks
 * ---------------------------------------------------------------------- */
if (!is_file($from)) {
    restore_die("File not found: {$from}");
}

$fromAbs  = realpath($from);
$fileSize = filesize($from);
$sha256   = hash_file('sha256', $from) ?: '';

restore_info("Backup file : {$fromAbs}");
restore_info("Size        : " . format_bytes((int)$fileSize));
restore_info("SHA-256     : {$sha256}");

// Try to find a matching backup_runs record for integrity check.
// v3.21.0 §A1 (#799): backup_history was collapsed into backup_runs;
// sha256 column → checksum.
$histRow = false;
try {
    $st = $db->prepare("SELECT * FROM backup_runs WHERE checksum=:h ORDER BY started_at DESC LIMIT 1");
    $st->execute([':h' => $sha256]);
    $histRow = $st->fetch();
} catch (Throwable) {}

if ($histRow) {
    restore_info("History     : found — recorded on " . to_str($histRow['started_at'] ?? ''));
} else {
    restore_info("History     : not found in backup_runs (untracked or different install)");
}

/** @var IpamConfig $gConf */
$gConf  = $GLOBALS['config'];
$driver = ipam_dialect()->driver_name();

// Extension-based format detection
$ext = strtolower(pathinfo($from, PATHINFO_EXTENSION));
if ($ext === 'sqlite' && $driver !== 'sqlite') {
    restore_die("Backup is SQLite (.sqlite) but configured driver is '{$driver}'. Cross-driver restore is not supported by this script.");
}
if ($ext === 'sql' && $driver === 'sqlite') {
    restore_die("Backup is SQL dump (.sql) but configured driver is 'sqlite'. Cross-driver restore is not supported by this script.");
}

restore_info("Driver      : {$driver}");
restore_info("Dry-run     : " . ($dryRun ? 'YES (no changes will be written)' : 'NO'));
restore_info('');

/* -------------------------------------------------------------------------
 * Driver-specific restore
 * ---------------------------------------------------------------------- */
if ($driver === 'sqlite') {
    $dbPath = $gConf['db_path'] !== '' ? $gConf['db_path'] : (__DIR__ . '/data/ipam.sqlite');

    // Safety: refuse to overwrite a non-empty DB unless --force
    if (!$force && is_file($dbPath) && filesize($dbPath) > 0) {
        restore_die(
            "Target database {$dbPath} already exists and is non-empty.\n" .
            "Use --force to overwrite, or remove the file manually first."
        );
    }

    restore_info("Target      : {$dbPath}");

    if ($dryRun) {
        restore_info("[DRY-RUN] Would copy {$fromAbs} → {$dbPath}");
        restore_info("[DRY-RUN] Would run apply_migrations() on restored database.");
        restore_info("Dry-run complete. No changes written.");
        exit(0);
    }

    // WAL check — ensure no active writer
    $walPath = $dbPath . '-wal';
    if (is_file($walPath) && filesize($walPath) > 0) {
        restore_info("Warning: WAL file exists ({$walPath}) — flushing before restore.");
        // WAL truncate is a best-effort cleanup before overwriting the DB file;
        // if it fails we surface the error via audit + error_log but DO NOT abort
        // (the upcoming copy() will replace the file regardless — checkpoint is
        // an optimization, not a correctness requirement).
        try {
            $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
        } catch (Throwable $e) {
            audit($db, 'backup.wal_checkpoint_failed', 'system', null,
                'context=restore error=' . substr($e->getMessage(), 0, 200));
            error_log("[backup] wal_checkpoint failed at restore: " . $e->getMessage());
        }
    }

    // Close existing connection before overwriting the file
    unset($db);

    $bakPath = $dbPath . '.pre-restore-' . date('YmdHis') . '.bak';
    if (is_file($dbPath)) {
        if (!@copy($dbPath, $bakPath)) {
            restore_die("Could not create safety backup at {$bakPath}.");
        }
        restore_info("Safety backup: {$bakPath}");
    }

    if (!@copy($fromAbs, $dbPath)) {
        restore_die("Failed to copy {$fromAbs} → {$dbPath}.");
    }
    restore_info("Restored: {$dbPath}");

    // Re-open and run migrations to bring to current schema
    restore_info("Applying migrations...");
    $db = ipam_db($config);
    $applied = apply_migrations($db);
    restore_info("Migrations applied: " . (empty($applied) ? '(none)' : implode(', ', $applied)));

    audit($db, 'restore.run', 'system', null,
        "SQLite restore from: " . basename($fromAbs) . " sha256={$sha256}");

} elseif ($driver === 'mysql') {
    $host   = to_str($gConf['db_host'] ?? '127.0.0.1');
    $port   = to_str($gConf['db_port'] ?? '3306');
    $dbName = to_str($gConf['db_name'] ?? 'ipam');
    $user   = to_str($gConf['db_user'] ?? 'root');
    $pass   = to_str($gConf['db_pass'] ?? '');

    restore_info("Target      : {$user}@{$host}:{$port}/{$dbName}");

    if ($dryRun) {
        restore_info("[DRY-RUN] Would run: mysql -h {$host} -P {$port} -u {$user} {$dbName} < {$fromAbs}");
        restore_info("Dry-run complete. No changes written.");
        exit(0);
    }

    // Route the password through a 0600 --defaults-extra-file so it never
    // appears in /proc/<pid>/environ or `ps eww` (#820). The file is unlinked
    // in the finally block below regardless of how the proc_open block exits.
    $credFile = ipam_backup_write_mysql_defaults_file($pass);
    // --defaults-extra-file MUST be the first mysql argument.
    // --no-login-paths + --ssl-verify-server-cert toggling per
    // lib/backup.php::ipam_backup_native_cmd rationale (PR #1080 CR / #1075).
    $verifySsl = (bool) ipam_setting('backup.dump_ssl_verify');
    $cmd = ['mysql', '--defaults-extra-file=' . $credFile,
            '--no-login-paths',
            $verifySsl ? '--ssl-verify-server-cert=on' : '--ssl-verify-server-cert=off',
            '-h', $host, '-P', $port, '-u', $user, $dbName];
    $env = getenv() ?: [];
    // Strip any inherited DB password env vars so a parent shell that
    // already has these set can't leak the secret into the child via
    // /proc/<pid>/environ even though we route the real cred via
    // --defaults-extra-file (#820 PR #1074 CR).
    unset($env['MYSQL_PWD'], $env['PGPASSWORD']);

    // Capture schema_migrations count before the restore so the sigchild
    // post-condition check compares pre vs post (CR feedback PR #1054).
    $preMigCount = 0;
    try {
        $preMigStmt = $db->query("SELECT COUNT(*) FROM schema_migrations");
        if ($preMigStmt !== false) {
            $preMigCount = (int) $preMigStmt->fetchColumn();
        }
    } catch (Throwable $_e) {
        // schema_migrations might not exist yet on a fresh target; treat as 0.
    }

    $stderr    = '';
    $finalExit = -1;
    $procOk    = false;
    try {
        $pipes = [];
        // nosemgrep
        $proc = proc_open( // nosemgrep
            $cmd,
            [
                0 => ['file', $fromAbs, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            // credFile is cleaned up by the outer finally before restore_die exits.
            $procOk = false;
        } else {
            $procOk = true;
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            // Capture exit code via proc_get_status BEFORE proc_close — on PHP
            // builds with --enable-sigchild proc_close reaps SIGCHLD itself and
            // returns -1 unconditionally. proc_get_status returns the real code
            // on glibc and -1 on sigchild builds; we treat -1 as "unreliable,
            // fall back to checking target DB post-conditions" (#805 / B-P0-3).
            $status    = proc_get_status($proc);
            $finalExit = $status['exitcode'];
            proc_close($proc);
        }
    } finally {
        @unlink($credFile); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
    if (!$procOk) {
        restore_die("Failed to start mysql process.");
    }
    $check = ipam_restore_proc_check($finalExit, 'mysql', (string)$stderr, $db, $preMigCount);
    if (!$check['ok']) {
        restore_die($check['message']);
    }
    restore_info("Restored to {$dbName} on {$host}. (proc verdict: {$check['verdict']})");

    restore_info("Applying migrations...");
    $applied = apply_migrations($db);
    restore_info("Migrations applied: " . (empty($applied) ? '(none)' : implode(', ', $applied)));

    audit($db, 'restore.run', 'system', null,
        "MySQL restore from: " . basename($fromAbs) . " sha256={$sha256} ({$check['verdict']})");

} elseif ($driver === 'pgsql') {
    $host   = to_str($gConf['db_host'] ?? '127.0.0.1');
    $port   = to_str($gConf['db_port'] ?? '5432');
    $dbName = to_str($gConf['db_name'] ?? 'ipam');
    $user   = to_str($gConf['db_user'] ?? 'postgres');
    $pass   = to_str($gConf['db_pass'] ?? '');

    restore_info("Target      : {$user}@{$host}:{$port}/{$dbName}");

    if ($dryRun) {
        restore_info("[DRY-RUN] Would run: psql -h {$host} -p {$port} -U {$user} {$dbName} < {$fromAbs}");
        restore_info("Dry-run complete. No changes written.");
        exit(0);
    }

    // Route the password through a 0600 PGPASSFILE so it never appears in
    // /proc/<pid>/environ or `ps eww` (#820). PGPASSFILE itself is an env var
    // carrying a *path*, not the secret — libpq's documented pattern for
    // non-interactive scripts. The file is unlinked in the finally block below.
    $credFile = ipam_backup_write_pgpass_file($pass);
    $cmd = ['psql', '-h', $host, '-p', $port, '-U', $user, $dbName];
    $env = getenv() ?: [];
    // Strip any inherited DB password env vars before merging in PGPASSFILE
    // so the parent shell can't leak a secret into the child even though
    // we route the real cred via PGPASSFILE (#820 PR #1074 CR).
    unset($env['MYSQL_PWD'], $env['PGPASSWORD']);
    $env['PGPASSFILE'] = $credFile;

    // Capture schema_migrations count before the restore so the sigchild
    // post-condition check compares pre vs post (CR feedback PR #1054).
    $preMigCount = 0;
    try {
        $preMigStmt = $db->query("SELECT COUNT(*) FROM schema_migrations");
        if ($preMigStmt !== false) {
            $preMigCount = (int) $preMigStmt->fetchColumn();
        }
    } catch (Throwable $_e) {
        // schema_migrations might not exist yet on a fresh target.
    }

    $stderr    = '';
    $finalExit = -1;
    $procOk    = false;
    try {
        $pipes = [];
        $proc = proc_open( // nosemgrep
            $cmd,
            [
                0 => ['file', $fromAbs, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            $procOk = false;
        } else {
            $procOk = true;
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            // See mysql branch above for the sigchild-fallback rationale
            // (#805 / B-P0-3).
            $status    = proc_get_status($proc);
            $finalExit = $status['exitcode'];
            proc_close($proc);
        }
    } finally {
        @unlink($credFile); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
    if (!$procOk) {
        restore_die("Failed to start psql process.");
    }
    $check = ipam_restore_proc_check($finalExit, 'psql', (string)$stderr, $db, $preMigCount);
    if (!$check['ok']) {
        restore_die($check['message']);
    }
    restore_info("Restored to {$dbName} on {$host}. (proc verdict: {$check['verdict']})");

    restore_info("Applying migrations...");
    $applied = apply_migrations($db);
    restore_info("Migrations applied: " . (empty($applied) ? '(none)' : implode(', ', $applied)));

    audit($db, 'restore.run', 'system', null,
        "PostgreSQL restore from: " . basename($fromAbs) . " sha256={$sha256} ({$check['verdict']})");

} else {
    restore_die("Unsupported driver: {$driver}");
}

restore_info('');
restore_info("Restore complete.");
exit(0);
