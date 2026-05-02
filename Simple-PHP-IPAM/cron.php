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

$now       = date('c');
$tickEpoch = time(); // Pinned UTC epoch for the entire tick — passed through
                     // to backup retention so prune timing is aligned to the
                     // tick rather than drifting with time() across long runs (#762).
$exitCode  = 0;

// ---------------------------------------------------------------------------
// Soft time budget for the scanner (v3.22.0 #817).
//
// The scanner is the only task in this runner whose work is unbounded — a
// /22 sweep with slow-responding hosts can easily exceed the cron interval.
// Earlier tasks (backups, webhook delivery, etc.) are time-sensitive and
// must not be starved. If the tick has already spent more than this many
// seconds on prior work by the time the scanner's turn comes, defer the
// scanner to the next tick. This is a soft budget — no in-flight scan is
// killed; the scan block is simply skipped for this tick.
// ---------------------------------------------------------------------------
const IPAM_CRON_SCANNER_BUDGET_SECS = 600;

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
// Task 6: Backup stale-run reaper (v3.22.0 #815)
//
// Reordered ahead of the schedule loop in v3.22.0 (#817): the backup
// schedule loop relies on the "active run" guard, which depends on the
// reaper having cleared any stale 'running' rows from a crashed prior tick.
// Running the reaper first makes the schedule loop's claim path reliable.
//
// Marks any backup_runs row stuck in 'running' past the reap threshold as
// 'failed' so a crashed/killed orchestrator can't permanently block fresh
// runs. The orchestrator also calls this inline as a defensive sweep, but
// the cron task ensures liveness on systems that have scheduled backups
// disabled and only run manual orchestrators.
// ---------------------------------------------------------------------------
try {
    $reaped = ipam_backup_reap_stale_runs($db);
    $emit(['task' => 'backup_reaper', 'reaped' => $reaped, 'ts' => $now]);
} catch (Throwable $e) {
    $fail('backup_reaper', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 6b: backup_runs row purge (v3.22.0 #1053)
//
// Time-based purge of old backup_runs rows. Pairs with the reaper above —
// the reaper handles in-flight rows that got stuck, this handles rows that
// completed long enough ago that they're no longer useful for forensics.
// Protected rows (is_protected=1) and 'running' rows are never auto-purged.
// Remote-blob retention is the GFS path's concern; this is row housekeeping.
// ---------------------------------------------------------------------------
try {
    $retentionDays = to_int(ipam_setting('backup_runs.retention_days'));
    if ($retentionDays > 0) {
        $batchSize = to_int(ipam_setting('backup_runs.prune_batch_size'));
        if ($batchSize <= 0) $batchSize = 500;
        $purged = ipam_backup_runs_purge($db, $retentionDays, $batchSize);
        $emit([
            'task'           => 'backup_runs_purge',
            'purged'         => $purged,
            'retention_days' => $retentionDays,
            'batch_size'     => $batchSize,
            'ts'             => $now,
        ]);
    } else {
        $emit(['task' => 'backup_runs_purge', 'skipped' => true, 'reason' => 'retention_days=0', 'ts' => $now]);
    }
} catch (Throwable $e) {
    $fail('backup_runs_purge', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 6c: Destination connection re-test (v3.22.0 §2.4 Notifications)
//
// Runs ipam_destination_test_now() against every active destination once per
// cron tick. Per-destination state lives in the JSON setting
// `backup.destination_health` keyed by destination id; the alert fires only
// on a healthy → failing transition (delta-only). A 6h cooldown on
// last_alerted_at prevents re-alerting on persistent failures every tick.
// Recovery (failing → healthy) is tracked but does not emit an email.
// ---------------------------------------------------------------------------
try {
    $connFailureNotifyEnabled = (bool) ipam_setting('backup.notify_destination_conn_failure');
    /** @var list<array<string, mixed>> $destRows */
    $destStmt = $db->query("SELECT id, name FROM backup_destinations WHERE is_active = 1 ORDER BY id");
    $destRows = $destStmt !== false ? $destStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $stateRaw = to_str(ipam_setting('backup.destination_health', '{}'));
    $stateDecoded = json_decode($stateRaw, true);
    /** @var array<string, array<string, mixed>> $healthMap */
    $healthMap = is_array($stateDecoded) ? $stateDecoded : [];

    $cooldownSecs = 6 * 3600;
    $nowTs = time();
    $tested = 0;
    $newlyFailing = 0;
    $recovered = 0;
    $errored = 0;

    foreach ($destRows as $destRow) {
        $destId = to_int($destRow['id'] ?? 0);
        if ($destId <= 0) continue;
        $destName = to_str($destRow['name'] ?? ('destination ' . $destId));
        $tested++;
        $key = (string) $destId;
        $prev = $healthMap[$key] ?? [];
        $prevStatus = is_string($prev['status'] ?? null) ? $prev['status'] : 'unknown';

        try {
            $result = ipam_destination_test_now($db, $destId, 'cron-recheck');
            $ok = $result['ok'] === true;
            $resultMessage = $result['message'];

            $entry = [
                'last_ok_at'      => is_string($prev['last_ok_at'] ?? null) ? $prev['last_ok_at'] : null,
                'last_failed_at'  => is_string($prev['last_failed_at'] ?? null) ? $prev['last_failed_at'] : null,
                'last_alerted_at' => to_int($prev['last_alerted_at'] ?? 0),
                'status'          => $ok ? 'ok' : 'failing',
            ];
            if ($ok) {
                $entry['last_ok_at'] = date('c', $nowTs);
                if ($prevStatus === 'failing') {
                    $recovered++;
                    $entry['last_alerted_at'] = 0; // reset cooldown so next failure alerts immediately
                }
            } else {
                $entry['last_failed_at'] = date('c', $nowTs);
                $shouldAlert = ($prevStatus !== 'failing')
                    || (($nowTs - to_int($entry['last_alerted_at'])) >= $cooldownSecs && to_int($entry['last_alerted_at']) > 0);
                if ($prevStatus !== 'failing') {
                    $newlyFailing++;
                }
                // Only the healthy→failing transition triggers the alert per §2.4
                // (delta-only). The cooldown applies if state has been "failing"
                // for so long that a re-alert is justified, but the spec says
                // "don't alert every tick once it's known-broken" — keep the
                // shouldAlert restricted to transitions for ship-1 simplicity.
                if ($connFailureNotifyEnabled && $prevStatus !== 'failing') {
                    audit($db, 'backup.connection_test_failed', 'destination', $destId,
                          'name=' . $destName . ' message=' . substr($resultMessage, 0, 200));
                    try {
                        ipam_backup_notify($db, 'destination_conn_failure', [
                            'dest'    => ['name' => $destName],
                            'message' => $resultMessage !== '' ? $resultMessage : 'unknown',
                        ]);
                        $entry['last_alerted_at'] = $nowTs;
                    } catch (Throwable $ne) {
                        error_log('[backup] conn-failure notify dispatch failed: ' . $ne->getMessage());
                    }
                }
            }
            $healthMap[$key] = $entry;
        } catch (Throwable $te) {
            // One bad destination must not abort the whole loop or block
            // persistence of $healthMap for the destinations that did
            // complete. Mark this entry as unknown so we don't mistake
            // an iteration error for a healthy state on the next tick.
            error_log('[backup] destination_health iteration failed for dest ' . $destId
                . ' (' . $destName . '): ' . $te->getMessage());
            $errored++;
            $healthMap[$key] = [
                'last_ok_at'      => is_string($prev['last_ok_at'] ?? null) ? $prev['last_ok_at'] : null,
                'last_failed_at'  => is_string($prev['last_failed_at'] ?? null) ? $prev['last_failed_at'] : null,
                'last_alerted_at' => to_int($prev['last_alerted_at'] ?? 0),
                'status'          => 'unknown',
            ];
            continue;
        }
    }

    // Drop entries for destinations that no longer exist or are inactive so
    // the map doesn't grow unbounded.
    $aliveKeys = [];
    foreach ($destRows as $destRow) {
        $aliveKeys[(string) to_int($destRow['id'] ?? 0)] = true;
    }
    foreach (array_keys($healthMap) as $k) {
        if (!isset($aliveKeys[$k])) unset($healthMap[$k]);
    }

    $encoded = json_encode($healthMap, JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
        ipam_setting_set($db, 'backup.destination_health', $encoded);
    }

    $emit([
        'task'           => 'destination_health',
        'tested'         => $tested,
        'newly_failing'  => $newlyFailing,
        'recovered'      => $recovered,
        'ts'             => $now,
    ]);
} catch (Throwable $e) {
    $fail('destination_health', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 6d: Schedule-overdue detector (v3.22.0 §2.4 Notifications)
//
// Reads `backup.notify_overdue_grace_minutes` and computes a cutoff. Any
// active schedule whose next_run_at is older than the cutoff is overdue.
// Per-schedule cooldown lives in the JSON setting
// `backup.schedule_overdue_state` keyed by schedule id; once an alert has
// fired for a given (schedule_id, expected_at) pair, no further alerts are
// sent until the schedule actually fires (advancing next_run_at) and goes
// overdue again.
// ---------------------------------------------------------------------------
try {
    $detectResult = ipam_backup_detect_overdue_schedules($db);
    $emit([
        'task'           => 'schedule_overdue',
        'overdue'        => $detectResult['overdue'],
        'alerted'        => count($detectResult['alerted']),
        'grace_minutes'  => $detectResult['grace_minutes'],
        'ts'             => $now,
    ]);
} catch (Throwable $e) {
    $fail('schedule_overdue', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 7: Backup schedules (v3.17.0 — fire any backup_schedules rows that are due)
//
// Reordered ahead of the scanner in v3.22.0 (#817): scheduled backups are
// time-sensitive ("did last night's backup run?") and must not be starved
// by a slow scanner sweep monopolising the cron tick.
//
// v3.22.0 (#816): per-row pessimistic claim closes the SELECT-then-UPDATE race
// where two cron processes could fetch the same due schedule and both fire it.
// next_run_at is advanced inside the claim transaction; the backup runs OUTSIDE
// the transaction so a long dump+upload doesn't hold a row lock. Failed runs
// no longer auto-retry on the next tick (Run-now is the recovery path).
// ---------------------------------------------------------------------------
try {
    $totalSched = 0;
    $okSched    = 0;
    $failSched  = 0;
    while (($sched = ipam_backup_claim_due_schedule($db)) !== null) {
        $totalSched++;
        $destId  = isset($sched['destination_id']) && is_numeric($sched['destination_id'])
            ? (int) $sched['destination_id'] : 0;
        $schedId = isset($sched['id']) && is_numeric($sched['id']) ? (int) $sched['id'] : 0;
        if ($destId <= 0 || $schedId <= 0) continue;

        try {
            // Pass the cron-tick epoch through to retention so prune timing
            // is aligned to the tick rather than drifting with time() (#762).
            // Pass schedule_id so the backup_runs row is linked back to the
            // schedule that triggered it (#821) — UI joins on this for the
            // "Triggered by" column on the backup history view.
            ipam_backup_run_for_destination($db, $config, $destId, 'schedule', $tickEpoch, $schedId);
            $okSched++;
        } catch (Throwable $e) {
            $failSched++;
            $fail('backup_schedule', 'schedule_id=' . $schedId . ' ' . $e->getMessage());
        }

        // Always record last_run_at so admins see the attempt regardless of
        // success/failure. next_run_at was advanced at claim time.
        try {
            ipam_backup_finalize_schedule_run($db, $schedId);
        } catch (Throwable $e) {
            $fail('backup_schedule', 'finalize schedule_id=' . $schedId . ' ' . $e->getMessage());
        }
    }
    $emit(['task' => 'backup_schedules', 'due' => $totalSched, 'ok' => $okSched, 'failed' => $failSched, 'ts' => $now]);
} catch (Throwable $e) {
    $fail('backup_schedules', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 8: Webhook delivery retry (attempt pending deliveries up to 3 times)
// ---------------------------------------------------------------------------
try {
    $retried = ipam_webhook_retry_pending($db, $config);
    $emit(['task' => 'webhook_retry', 'retried' => $retried, 'ts' => $now]);
} catch (Throwable $e) {
    $fail('webhook_retry', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 9: Webhook delivery log prune (per webhook.retention_days setting)
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
// Task 10: Network scanning — all active schedules that are due
//
// Reordered to run last among the heavy tasks in v3.22.0 (#817). Scanner
// work is unbounded (a /22 sweep can take many minutes) so it must not
// block backups, webhook delivery, or other time-sensitive work.
//
// Soft time budget: if the cron tick has already spent more than
// IPAM_CRON_SCANNER_BUDGET_SECS on prior tasks, defer scanner to the next
// tick rather than risk blowing past the cron interval. No in-flight scan
// is killed — the entire scan block is simply skipped this tick.
// ---------------------------------------------------------------------------
try {
    $elapsedSecs = time() - $tickEpoch;
    if ($elapsedSecs > IPAM_CRON_SCANNER_BUDGET_SECS) {
        $emit([
            'task'         => 'scan',
            'skipped'      => true,
            'reason'       => 'time_budget_exhausted',
            'budget_secs'  => IPAM_CRON_SCANNER_BUDGET_SECS,
            'elapsed_secs' => $elapsedSecs,
            'ts'           => $now,
        ]);
    } else {
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
    }
} catch (Throwable $e) {
    $fail('scan', $e->getMessage());
}

// ---------------------------------------------------------------------------
// Task 11: Demo mode database reset (was Task 7 pre-v3.3.0, Task 9 pre-v3.17.0,
// Task 10 pre-v3.22.0). Kept last because it is irreversible-on-misuse.
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
