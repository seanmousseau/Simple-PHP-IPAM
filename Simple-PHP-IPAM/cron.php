<?php
declare(strict_types=1);
/**
 * cron.php — unified housekeeping runner
 *
 * Consolidates all periodic maintenance tasks into a single cron entry:
 *   - Temp file cleanup
 *   - Audit log pruning
 *   - Address history pruning
 *   - Subnet utilisation alerts
 *   - Database backup
 *
 * Network scanning (scan_run.php) remains a separate script due to its
 * per-subnet options and dry-run mode. Schedule both:
 *
 *   # Housekeeping — once per hour is fine; tasks throttle themselves internally
 *   0 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /var/log/ipam-cron.log 2>&1
 *
 *   # Network scanning — every 15 minutes; scan_run.php respects per-subnet schedules
 *   *\/15 * * * * php /path/to/Simple-PHP-IPAM/scan_run.php --all >> /var/log/ipam-scan.log 2>&1
 *
 * Outputs one JSON object per task to stdout (JSONL format). Errors go to stderr.
 * Exit code: 0 on success, 1 if any task raised an exception.
 */

// Reject web requests
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden — this script must be run from the command line.\n";
    exit(1);
}

$scriptDir = __DIR__;
/** @var IpamConfig $config */
$config = require $scriptDir . '/config.php';

// Apply configured timezone before any date/time operations
date_default_timezone_set(is_string($config['timezone'] ?? null) && $config['timezone'] !== ''
    ? $config['timezone']
    : 'UTC');

require $scriptDir . '/lib.php';

$db       = ipam_db(to_str($config['db_path']));
$now      = date('c');
$exitCode = 0;

/** @param array<string, mixed> $data */
$emit = function (array $data): void {
    echo json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
};

/** Log an error to stderr and flag the run as failed. */
$fail = function (string $task, string $msg) use (&$exitCode): void {
    $exitCode = 1;
    fwrite(STDERR, "[$task] ERROR: $msg\n");
};

// ---------------------------------------------------------------------------
// Task 1: Temp file cleanup
// ---------------------------------------------------------------------------
try {
    $ttl = to_int($config['tmp_cleanup_ttl_seconds']);
    if ($ttl < 3600) $ttl = 3600;

    $filesRemoved = cleanup_tmp_import_files($ttl);
    $plansRemoved = cleanup_tmp_import_plans($ttl);

    $emit([
        'task'          => 'tmp_cleanup',
        'files_removed' => $filesRemoved,
        'plans_removed' => $plansRemoved,
        'ts'            => $now,
    ]);
} catch (Throwable $e) {
    $fail('tmp_cleanup', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 2: Audit log pruning
// ---------------------------------------------------------------------------
try {
    $retentionDays = to_int($config['audit_log_retention_days']);
    if ($retentionDays > 0) {
        $pruned = prune_audit_log($db, $retentionDays);
        $emit(['task' => 'prune_audit_log', 'pruned' => $pruned, 'ts' => $now]);
    } else {
        $emit(['task' => 'prune_audit_log', 'skipped' => true, 'reason' => 'retention_days=0', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('prune_audit_log', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 3: Address history pruning
// ---------------------------------------------------------------------------
try {
    $histRetention = to_int($config['address_history_retention_days']);
    if ($histRetention > 0) {
        $pruned = prune_address_history($db, $histRetention);
        $emit(['task' => 'prune_address_history', 'pruned' => $pruned, 'ts' => $now]);
    } else {
        $emit(['task' => 'prune_address_history', 'skipped' => true, 'reason' => 'retention_days=0', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('prune_address_history', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 4: Subnet utilisation alerts
// ---------------------------------------------------------------------------
try {
    $alertEmail = to_str($config['alert_email'] ?? '');
    if ($alertEmail !== '') {
        check_utilization_alerts($db, $config);
        $emit(['task' => 'utilisation_alerts', 'ran' => true, 'ts' => $now]);
    } else {
        $emit(['task' => 'utilisation_alerts', 'skipped' => true, 'reason' => 'alert_email not configured', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('utilisation_alerts', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 5: Database backup
// ---------------------------------------------------------------------------
try {
    $backupEnabled = !empty($config['backup']['enabled']);
    if ($backupEnabled) {
        $ran = run_db_backup_if_due($db, $config);
        $emit(['task' => 'db_backup', 'ran' => $ran, 'ts' => $now]);
    } else {
        $emit(['task' => 'db_backup', 'skipped' => true, 'reason' => 'backup.enabled=false', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('db_backup', $e->getMessage());
}

exit($exitCode);
