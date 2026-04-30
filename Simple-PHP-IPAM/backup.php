<?php
declare(strict_types=1);
/**
 * backup.php — CLI-only on-demand database backup runner.
 *
 * Usage:
 *   php backup.php [-f] [--force]
 *
 *   -f, --force   Run even if a backup is not yet due per the schedule.
 *
 * Web requests receive a 403 Forbidden response.
 *
 * Exit codes (stable contract for wrapper scripts):
 *
 *   0  success            — backup ran and completed
 *   1  error              — DB / config / dump failure, OR another
 *                           process holds the backup lock. The orchestrator
 *                           collapses both into a single false return; the
 *                           v3.21.0 unified surface (#797) introduces a
 *                           structured result that lets us split lock-held
 *                           into its own exit code 2.
 *   2  reserved           — already-running (split from 1 in v3.21.0)
 *   3  not-due            — backup.enabled=false, OR no schedule is due
 *                           and --force was not given.
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

// Accept -f, --force, and --force=1 (getopt sets the long-form key when
// either spelling is used; --force=1 also sets it because '1' is truthy
// for isset). Behaviour for existing wrapper scripts that pass `--force`
// alone is unchanged.
$opts  = getopt('f', ['force']);
$force = isset($opts['f']) || isset($opts['force']);

if (!(bool) ipam_setting('backup.enabled')) {
    fwrite(STDOUT, "Backup is disabled (backup.enabled=false). Use admin settings to enable.\n");
    exit(3);
}

if (!$force && !backup_is_due($config)) {
    fwrite(STDOUT, "No backup due yet. Use --force to run immediately.\n");
    exit(3);
}

fwrite(STDOUT, "Starting backup...\n");

$ok = run_db_backup_if_due($db, $config);

if ($ok) {
    $state = @json_decode((string)@file_get_contents(backup_state_path()), true);
    $file  = is_array($state) && isset($state['last_file']) ? (string)$state['last_file'] : '(unknown)';
    fwrite(STDOUT, "Backup completed: {$file}\n");
    audit($db, 'backup.run', 'system', null, "CLI backup completed: {$file}");
    exit(0);
} else {
    fwrite(STDERR, "Backup failed or was skipped (another process may hold the lock).\n");
    audit($db, 'backup.failed', 'system', null, "CLI backup failed or could not acquire lock.");
    exit(1);
}
