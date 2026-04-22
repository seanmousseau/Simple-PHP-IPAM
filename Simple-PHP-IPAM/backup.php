<?php
declare(strict_types=1);
/**
 * backup.php — CLI-only on-demand database backup runner.
 *
 * Usage:
 *   php backup.php [--force]
 *
 *   --force   Run even if a backup is not yet due per the schedule.
 *
 * Web requests receive a 403 Forbidden response.
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

$force = in_array('--force', $argv ?? [], true);

if (!(bool) ipam_setting('backup.enabled')) {
    fwrite(STDOUT, "Backup is disabled (backup.enabled=false). Use admin settings to enable.\n");
    exit(0);
}

if (!$force && !backup_is_due($config)) {
    fwrite(STDOUT, "No backup due yet. Use --force to run immediately.\n");
    exit(0);
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
    exit(2);
}
