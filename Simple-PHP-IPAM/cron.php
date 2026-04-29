<?php
declare(strict_types=1);
/**
 * cron.php — unified housekeeping + scanning runner
 *
 * Consolidates all periodic maintenance and network scanning into a single cron entry:
 *   - Temp file cleanup
 *   - Audit log pruning
 *   - Address history pruning
 *   - Subnet utilisation alerts
 *   - Database backup
 *   - Network scanning (all active scheduled subnets that are due)
 *   - Demo mode database reset (no-op when demo_mode.enabled is false)
 *
 * A single cron entry covers everything:
 *
 *   # Run every 15 minutes — housekeeping tasks throttle themselves internally;
 *   # scanning honours the per-subnet interval set in the Scan Schedule UI.
 *   *\/15 * * * * php /path/to/Simple-PHP-IPAM/cron.php >> /var/log/ipam-cron.log 2>&1
 *
 * scan_run.php is still available for one-off or per-subnet scans from the CLI.
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

// Seed a UTC default so any pre-DB date operations in lib.php bootstrap are
// deterministic. Real timezone is applied from the DB settings below.
date_default_timezone_set('UTC');

require $scriptDir . '/lib.php';

// ---------------------------------------------------------------------------
// Self-lock — refuse to run if another cron.php is still in progress.
//
// This prevents the runaway-stacking scenario where a misconfigured `* * * * *`
// crontab (or a scan that takes longer than the cron interval) starts a new
// invocation before the previous one has finished. Concurrent runs all hammer
// the same scan_results table and inevitably exhaust the SQLite busy_timeout,
// failing user-facing writes elsewhere with "database is locked".
//
// Uses LOCK_EX | LOCK_NB so the second invocation exits cleanly instead of
// blocking. A separate lock file (data/cron.lock) keeps this independent of
// the demo_reset stamp lock used in Task 7.
// ---------------------------------------------------------------------------
$cronLockFile = $scriptDir . '/data/cron.lock';
$cronLockHandle = fopen($cronLockFile, 'c');
if ($cronLockHandle === false) {
    fwrite(STDERR, "[cron] ERROR: unable to open cron lock file: $cronLockFile\n");
    exit(1);
}
if (!flock($cronLockHandle, LOCK_EX | LOCK_NB)) {
    // Another cron.php instance already holds the lock — exit cleanly. This
    // is normal/expected, not an error, so exit code 0.
    echo json_encode([
        'task'    => 'cron',
        'skipped' => true,
        'reason'  => 'another cron.php instance is still running',
        'ts'      => date('c'),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    fclose($cronLockHandle);
    exit(0);
}
// Release on shutdown — covers normal exit, fatal errors, and uncaught throws.
register_shutdown_function(static function () use ($cronLockHandle): void {
    if (is_resource($cronLockHandle)) {
        @flock($cronLockHandle, LOCK_UN);
        @fclose($cronLockHandle);
    }
});

$db       = ipam_db($config);

// Apply admin-configured timezone from DB settings now that $db is open. All
// downstream date() calls in this cron run pick up the new zone.
$tz = to_str(ipam_setting('branding.timezone'));
if ($tz === '' || !@date_default_timezone_set($tz)) {
    date_default_timezone_set('UTC');
}
unset($tz);

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
    $ttl = to_int(ipam_setting('housekeeping.tmp_cleanup_ttl_seconds'));
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
    $retentionDays = to_int(ipam_setting('housekeeping.audit_log_retention_days'));
    if ($retentionDays > 0) {
        $pruned = prune_audit_log($db, $retentionDays);
        if ($pruned > 0) {
            audit($db, 'audit.pruned', 'system', null,
                "Pruned {$pruned} audit log " . ($pruned === 1 ? 'entry' : 'entries')
                . " older than {$retentionDays} days (scheduled).");
        }
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
    $histRetention = to_int(ipam_setting('housekeeping.address_history_retention_days'));
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
    $alertEmail = to_str(ipam_setting('alert.email'));
    if ($alertEmail !== '') {
        check_utilization_alerts($db, $config);
        $emit(['task' => 'utilisation_alerts', 'ran' => true, 'ts' => $now]);
    } else {
        $emit(['task' => 'utilisation_alerts', 'skipped' => true, 'reason' => 'alert.email not configured', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('utilisation_alerts', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 5: Database backup
// ---------------------------------------------------------------------------
try {
    $backupEnabled = (bool)ipam_setting('backup.enabled');
    if ($backupEnabled) {
        $ran = run_db_backup_if_due($db, $config);
        $emit(['task' => 'db_backup', 'ran' => $ran, 'ts' => $now]);
    } else {
        $emit(['task' => 'db_backup', 'skipped' => true, 'reason' => 'backup.enabled=false', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('db_backup', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 6: Network scanning — all active schedules that are due
// ---------------------------------------------------------------------------
try {
    // Fetch every active schedule and filter "is due" in PHP so the query
    // stays engine-agnostic — SQLite's `datetime(col, '+N minutes')` and
    // `||` string concatenation are not portable to MySQL / Postgres. The
    // filter is cheap: scan_schedules is bounded by subnet count and each
    // row is a simple strtotime + integer comparison.
    $dueStmt = $db->query("
        SELECT s.id, s.cidr, ss.method, ss.tcp_port, ss.interval_minutes, ss.last_run_at
        FROM subnets s
        JOIN scan_schedules ss ON ss.subnet_id = s.id
        WHERE ss.is_active = 1
        ORDER BY ss.last_run_at ASC
    ");
    if ($dueStmt === false) throw new \RuntimeException('Scan schedule query failed');

    /** @var list<array<string, mixed>> $candidates */
    $candidates = $dueStmt->fetchAll();
    $nowTs = time();
    $dueSubnets = [];
    foreach ($candidates as $row) {
        $lastRun = $row['last_run_at'] ?? null;
        if ($lastRun === null || $lastRun === '') {
            $dueSubnets[] = $row;
            continue;
        }
        $lastTs = strtotime(to_str($lastRun) . ' UTC');
        if ($lastTs === false) continue;
        $intervalMinutes = to_int($row["interval_minutes"] ?? 0);
        if (($nowTs - $lastTs) >= ($intervalMinutes * 60)) {
            $dueSubnets[] = $row;
        }
    }

    if (count($dueSubnets) === 0) {
        $emit(['task' => 'scan', 'skipped' => true, 'reason' => 'no schedules due', 'ts' => $now]);
    } else {
        $updateLastRun = $db->prepare(
            "UPDATE scan_schedules SET last_run_at = " . ipam_dialect()->now() . ", updated_at = " . ipam_dialect()->now() . " WHERE subnet_id = :sid"
        );
        $scanSummary = [
            'scanned_subnets' => 0,
            'total_hosts'     => 0,
            'total_up'        => 0,
            'total_down'      => 0,
            'total_stale_marked' => 0,
        ];

        foreach ($dueSubnets as $sub) {
            $subnetId = to_int($sub['id']);
            $cidr     = to_str($sub['cidr'] ?? '');
            $method   = to_str($sub['method'] ?? 'icmp');
            $tcpPort  = isset($sub['tcp_port']) ? to_int($sub['tcp_port']) : null;
            if (!in_array($method, ['icmp', 'tcp', 'both'], true)) $method = 'icmp';

            $start   = microtime(true);
            $stats   = ipam_scan_subnet($db, $subnetId, $method, $tcpPort);
            $elapsed = round(microtime(true) - $start, 2);

            $updateLastRun->execute([':sid' => $subnetId]);

            $emit([
                'task'         => 'scan',
                'subnet_id'    => $subnetId,
                'cidr'         => $cidr,
                'method'       => $method,
                'tcp_port'     => $tcpPort,
                'scanned'      => $stats['scanned'],
                'up'           => $stats['up'],
                'down'         => $stats['down'],
                'stale_marked' => $stats['stale_marked'],
                'elapsed_sec'  => $elapsed,
                'ts'           => $now,
            ]);

            $scanSummary['scanned_subnets']++;
            $scanSummary['total_hosts']        += $stats['scanned'];
            $scanSummary['total_up']           += $stats['up'];
            $scanSummary['total_down']         += $stats['down'];
            $scanSummary['total_stale_marked'] += $stats['stale_marked'];
        }

        $emit(array_merge(['task' => 'scan_summary', 'ts' => $now], $scanSummary));
    }
} catch (Throwable $e) {
    $fail('scan', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 7: Webhook delivery retry (attempt pending deliveries up to 3 times)
// ---------------------------------------------------------------------------
try {
    $retried = ipam_webhook_retry_pending($db, $config);
    $emit(['task' => 'webhook_retry', 'retried' => $retried, 'ts' => $now]);
} catch (Throwable $e) {
    $fail('webhook_retry', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 8: Webhook delivery log prune (per webhook.retention_days setting)
// ---------------------------------------------------------------------------
try {
    $retentionDays = to_int(ipam_setting('webhook.retention_days'));
    if ($retentionDays > 0) {
        $pruned = ipam_webhook_prune($db, $retentionDays);
        $emit(['task' => 'webhook_prune', 'pruned' => $pruned, 'retention_days' => $retentionDays, 'ts' => $now]);
    } else {
        $emit(['task' => 'webhook_prune', 'skipped' => true, 'reason' => 'retention_days=0', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('webhook_prune', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 9: Backup schedules (v3.17.0 — fire any backup_schedules rows that are due)
// ---------------------------------------------------------------------------
try {
    $stmt = $db->query(
        "SELECT s.*, d.name AS dest_name
         FROM backup_schedules s
         JOIN backup_destinations d ON d.id = s.destination_id
         WHERE s.is_active = 1 AND d.is_active = 1
           AND (s.next_run_at IS NULL OR s.next_run_at <= " . ipam_dialect()->now() . ")"
    );
    $due = ($stmt !== false) ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $totalSched = count($due);
    $okSched = 0;
    $failSched = 0;
    foreach ($due as $sched) {
        if (!is_array($sched)) continue;
        $destId = isset($sched['destination_id']) && is_numeric($sched['destination_id'])
            ? (int) $sched['destination_id'] : 0;
        $schedId = isset($sched['id']) && is_numeric($sched['id']) ? (int) $sched['id'] : 0;
        if ($destId <= 0 || $schedId <= 0) continue;

        $runOk = false;
        try {
            $engine = new BackupEngine($db, $config);
            $engine->runForDestination($destId, 'schedule');
            $runOk = true;
            $okSched++;
        } catch (Throwable $e) {
            $failSched++;
            $fail('backup_schedule', 'schedule_id=' . $schedId . ' ' . $e->getMessage());
        }

        // Always record last_run_at (so admins see the attempt); advance next_run_at
        // only on success so failed runs are retried on the next cron tick.
        $sNorm = [];
        foreach ($sched as $k => $v) {
            if (is_string($k)) $sNorm[$k] = $v;
        }
        try {
            if ($runOk) {
                $next = ipam_backup_next_run_at($sNorm);
                $upd = $db->prepare(
                    "UPDATE backup_schedules
                       SET last_run_at = " . ipam_dialect()->now() . ",
                           next_run_at = :next
                     WHERE id = :id"
                );
                $upd->execute([
                    ':next' => gmdate('Y-m-d H:i:s', $next),
                    ':id'   => $schedId,
                ]);
            } else {
                $upd = $db->prepare(
                    "UPDATE backup_schedules
                       SET last_run_at = " . ipam_dialect()->now() . "
                     WHERE id = :id"
                );
                $upd->execute([':id' => $schedId]);
            }
        } catch (Throwable $e) {
            $fail('backup_schedule', 'next_run_update schedule_id=' . $schedId . ' ' . $e->getMessage());
        }
    }
    $emit(['task' => 'backup_schedules', 'due' => $totalSched, 'ok' => $okSched, 'failed' => $failSched, 'ts' => $now]);
} catch (Throwable $e) {
    $fail('backup_schedules', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 10: Demo mode database reset (was Task 7 pre-v3.3.0, Task 9 pre-v3.17.0)
// ---------------------------------------------------------------------------
try {
    $demoEnabled = !empty($config['demo_mode']['enabled']);
    if (!$demoEnabled) {
        $emit(['task' => 'demo_reset', 'skipped' => true, 'reason' => 'demo_mode.enabled=false', 'ts' => $now]);
    } else {
        // Throttle to once per 24h so the /15min cron cadence doesn't wipe the DB
        // every tick. demo_reset.php (run from its own cron entry) bypasses this.
        // The flock + ftruncate + fwrite sequence is atomic across overlapping
        // cron invocations: only one process holds the exclusive lock, and the
        // stamp write is verified — a failed write throws and the task fails
        // loudly instead of silently re-running on the next tick.
        $stampFile = $scriptDir . '/data/demo_last_reset.txt';
        $stamp = fopen($stampFile, 'c+');
        if ($stamp === false) {
            throw new RuntimeException("Unable to open demo reset stamp: $stampFile");
        }
        if (!flock($stamp, LOCK_EX)) {
            fclose($stamp);
            throw new RuntimeException('Unable to lock demo reset stamp');
        }
        try {
            rewind($stamp);
            $raw = stream_get_contents($stamp);
            $lastRun = (is_string($raw) && ctype_digit(trim($raw))) ? (int) trim($raw) : 0;

            $throttleSeconds = 24 * 3600;
            if ((time() - $lastRun) < $throttleSeconds) {
                $emit([
                    'task'        => 'demo_reset',
                    'skipped'     => true,
                    'reason'      => 'throttled',
                    'last_run_at' => $lastRun > 0 ? date('c', $lastRun) : null,
                    'next_due_at' => $lastRun > 0 ? date('c', $lastRun + $throttleSeconds) : null,
                    'ts'          => $now,
                ]);
            } else {
                demo_reset_db($db);
                if (ftruncate($stamp, 0) === false
                    || rewind($stamp) === false
                    || fwrite($stamp, (string) time()) === false
                    || fflush($stamp) === false) {
                    throw new RuntimeException('Unable to persist demo reset timestamp');
                }
                $emit(['task' => 'demo_reset', 'ran' => true, 'ts' => $now]);
            }
        } finally {
            flock($stamp, LOCK_UN);
            fclose($stamp);
        }
    }
} catch (Throwable $e) {
    $fail('demo_reset', $e->getMessage());
}

exit($exitCode);
