<?php
declare(strict_types=1);

/**
 * Backup + restore orchestration — procedural counterpart to v3.17's
 * BackupEngine / RestoreEngine classes. Refactored to plain functions in
 * v3.18.0 (#762) per CLAUDE.md "When in doubt, write a function" — the
 * engines were single-implementation, no polymorphism, no per-instance
 * state beyond constructor-injected $db and $config.
 *
 * Pipeline (single backup run for one destination):
 *   1. Dump the database to a gzipped tmp file (SQLite-only as of v3.17.0).
 *   2. Optionally encrypt with AES-256-GCM via backup_encrypt().
 *   3. Upload via the destination-specific BackupClientInterface.
 *   4. Insert/update a row in backup_runs.
 *   5. Apply GFS retention via ipam_backup_apply_retention().
 *
 * Pipeline (restore from a remote backup):
 *   1. Download the remote blob to data/tmp/ via the destination client.
 *   2. Verify SHA-256 against backup_runs.checksum (#762 item 4).
 *   3. Decrypt if encrypted, stage as .sql.gz under data/tmp/.
 *   4. Sign the staged path so the wizard can hand it back safely.
 *   5. Dry-run, then apply with PRAGMA foreign_keys = OFF inside a transaction.
 *
 * v3.21.0 (#799 §A1): backup_history (v3.7 CLI) and backup_log (v3.17
 * destination runner) were collapsed into a single backup_runs table.
 * Both the CLI runner in lib.php and this destination runner now write
 * to backup_runs.
 */

// ────────────────────────────────────────────────────────────────────────────
// Schedule claim (v3.22.0 #816)
// ────────────────────────────────────────────────────────────────────────────

/**
 * Atomically claim one due backup_schedules row for execution and advance
 * its next_run_at to the next scheduled time. Returns the claimed row or
 * null when nothing is due.
 *
 * Closes the SELECT-then-UPDATE race in cron.php where two ticks could
 * fetch the same schedule and both fire it. The advance happens inside a
 * short transaction; the actual backup runs OUTSIDE the transaction so we
 * don't hold a row lock for the duration of the dump+upload.
 *
 *   SQLite     — BEGIN IMMEDIATE acquires the database write lock; a
 *                concurrent BEGIN IMMEDIATE blocks (or returns SQLITE_BUSY,
 *                which we retry with backoff).
 *   MySQL/PG   — SELECT ... FOR UPDATE SKIP LOCKED returns the next
 *                unclaimed row instead of blocking on one another tick is
 *                already advancing.
 *
 * Failed runs do NOT auto-retry on the next cron tick (next_run_at is
 * advanced on claim, before the run starts). This is the documented
 * v3.22.0 behaviour change — Run-now is the recovery path. The previous
 * "retry on every tick until success" behaviour interacted poorly with
 * destinations that fail predictably (e.g. expired credentials), keeping
 * cron busy and producing one alert per tick.
 *
 * @return array<string,mixed>|null
 */
function ipam_backup_claim_due_schedule(PDO $db): ?array {
    $dialect     = ipam_dialect();
    $nowSql      = $dialect->now();
    $isSqlite    = $dialect->driver_name() === 'sqlite';
    $maxAttempts = 5;
    $lastBusy    = null;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $started = false;
        try {
            if ($isSqlite) {
                // BEGIN IMMEDIATE acquires the SQLite write lock right away
                // so concurrent claims serialize at the database, not in PHP.
                // PDO does not track manual BEGIN, so commit/rollback must
                // also go through exec() rather than the PDO methods.
                $db->exec("BEGIN IMMEDIATE");
            } else {
                $db->beginTransaction();
            }
            $started = true;

            $sql = "SELECT s.*, d.name AS dest_name
                      FROM backup_schedules s
                      JOIN backup_destinations d ON d.id = s.destination_id
                     WHERE s.is_active = 1 AND d.is_active = 1
                       AND (s.next_run_at IS NULL OR s.next_run_at <= $nowSql)
                     ORDER BY s.next_run_at ASC, s.id ASC
                     LIMIT 1";
            if (!$isSqlite) {
                // SKIP LOCKED so a concurrent cron sees the next unclaimed
                // row instead of blocking on a row another tick is advancing.
                $sql .= " FOR UPDATE SKIP LOCKED";
            }

            $stmt = $db->query($sql);
            $raw  = ($stmt !== false) ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if (!is_array($raw) || !isset($raw['id']) || !is_numeric($raw['id'])) {
                if ($isSqlite) $db->exec("COMMIT");
                else $db->commit();
                return null;
            }
            // is_numeric guard above ensures $raw['id'] is string|int|float.
            $rawId = $raw['id'];
            $scheduleId = is_string($rawId) ? (int) $rawId : (int) (is_int($rawId) ? $rawId : (float) $rawId);
            // Normalize PDO's array<int|string,mixed> down to the contract we
            // expose, dropping any non-string keys defensively.
            /** @var array<string,mixed> $row */
            $row = [];
            foreach ($raw as $k => $v) {
                if (is_string($k)) $row[$k] = $v;
            }

            $next = ipam_backup_next_run_at($row);
            $upd  = $db->prepare(
                "UPDATE backup_schedules SET next_run_at = :next WHERE id = :id"
            );
            $upd->execute([
                ':next' => gmdate('Y-m-d H:i:s', $next),
                ':id'   => $scheduleId,
            ]);

            if ($isSqlite) $db->exec("COMMIT");
            else $db->commit();
            return $row;
        } catch (Throwable $e) {
            if ($started) {
                try {
                    if ($isSqlite) {
                        $db->exec("ROLLBACK");
                    } elseif ($db->inTransaction()) {
                        $db->rollBack();
                    }
                } catch (Throwable) {}
            }
            // SQLite BEGIN IMMEDIATE may fail with SQLITE_BUSY when another
            // process holds the write lock — back off and retry.
            $msg = $e->getMessage();
            $busy = $isSqlite && (
                str_contains($msg, 'database is locked')
                || str_contains($msg, 'SQLITE_BUSY')
                || str_contains($msg, 'database table is locked')
            );
            if ($busy) {
                $lastBusy = $e;
                usleep(50_000 * ($attempt + 1));
                continue;
            }
            throw $e;
        }
    }
    // Exhausted retries on SQLITE_BUSY — surface the failure rather than
    // returning null, which the caller would interpret as "nothing due"
    // and silently skip a backup that was actually contended (#816 CR).
    if ($lastBusy instanceof Throwable) throw $lastBusy;
    return null;
}

/**
 * Record the outcome of a scheduled backup run. Always sets last_run_at so
 * admins see the attempt regardless of success/failure. next_run_at is left
 * at the value claim() set — failed runs wait for the next scheduled tick.
 */
function ipam_backup_finalize_schedule_run(PDO $db, int $scheduleId): void {
    $upd = $db->prepare(
        "UPDATE backup_schedules SET last_run_at = " . ipam_dialect()->now() . " WHERE id = :id"
    );
    $upd->execute([':id' => $scheduleId]);
}

// ────────────────────────────────────────────────────────────────────────────
// Concurrency guard + stale-row reaper (v3.22.0 #815)
// ────────────────────────────────────────────────────────────────────────────

/**
 * Default reaper threshold in seconds. A 'running' row older than this is
 * presumed dead (orchestrator OOM-killed, server rebooted mid-dump, kill -9,
 * deploy restart, etc.) and gets force-marked 'failed' so subsequent runs
 * aren't blocked forever. Covers a generous 1h dump+upload window with a 2×
 * safety factor; small/medium databases finish in well under that.
 */
const IPAM_BACKUP_REAP_THRESHOLD_SECS = 7200;

/**
 * Mark stuck 'running' backup_runs rows as 'failed' and audit each one. Used
 * by the cron reaper task and as a defensive sweep at the top of
 * ipam_backup_run_for_destination(). Returns the number of rows reaped.
 *
 * Race-safe: the UPDATE re-asserts status='running', so two ticks reaping
 * the same row produce one audit entry, not two.
 */
function ipam_backup_reap_stale_runs(PDO $db, int $thresholdSecs = IPAM_BACKUP_REAP_THRESHOLD_SECS): int {
    if ($thresholdSecs < 60) $thresholdSecs = 60;
    $cutoff = gmdate('Y-m-d H:i:s', time() - $thresholdSecs);

    $sel = $db->prepare(
        "SELECT id, destination_id FROM backup_runs
          WHERE status = 'running' AND started_at < :cutoff"
    );
    $sel->execute([':cutoff' => $cutoff]);
    /** @var list<array<string,mixed>> $rows */
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 0) return 0;

    $reaped = 0;
    $upd = $db->prepare(
        "UPDATE backup_runs
            SET status = 'failed',
                completed_at = " . ipam_dialect()->now() . ",
                error_message = :msg
          WHERE id = :id AND status = 'running'"
    );
    foreach ($rows as $row) {
        $rowId  = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
        $destId = isset($row['destination_id']) && is_numeric($row['destination_id'])
            ? (int) $row['destination_id'] : 0;
        if ($rowId <= 0) continue;

        $upd->execute([
            ':msg' => 'reaper: stuck running past ' . $thresholdSecs . 's threshold, presumed dead',
            ':id'  => $rowId,
        ]);
        if ($upd->rowCount() > 0) {
            audit($db, 'backup.reaped', 'backup_run', $rowId,
                  'destination_id=' . $destId . ' threshold_secs=' . $thresholdSecs);
            $reaped++;
        }
    }
    return $reaped;
}

/**
 * Returns the id of any non-stale 'running' backup_runs row for the given
 * destination, or null if none. Used as a concurrency guard so two runs
 * (manual + scheduled, or two ticks racing) cannot both proceed against the
 * same destination — the second sees the first's row and aborts cleanly.
 */
function ipam_backup_active_run_id(PDO $db, int $destId, int $thresholdSecs = IPAM_BACKUP_REAP_THRESHOLD_SECS): ?int {
    $cutoff = gmdate('Y-m-d H:i:s', time() - $thresholdSecs);
    $stmt = $db->prepare(
        "SELECT id FROM backup_runs
          WHERE destination_id = :dest AND status = 'running' AND started_at >= :cutoff
          ORDER BY started_at DESC LIMIT 1"
    );
    $stmt->execute([':dest' => $destId, ':cutoff' => $cutoff]);
    $id = $stmt->fetchColumn();
    return is_numeric($id) ? (int) $id : null;
}

/**
 * Delete backup_runs rows older than $retentionDays in batches of $batchSize.
 * Returns the number of rows actually deleted on this call.
 *
 * Skips rows whose status is 'running' (let the reaper handle those — #815)
 * and rows where is_protected = 1 (operator-marked keep).
 *
 * Uses a portable SELECT-id-then-DELETE-by-id-list pattern rather than
 * `DELETE ... LIMIT N` because the latter is non-portable: SQLite only
 * supports it when compiled with SQLITE_ENABLE_UPDATE_DELETE_LIMIT (not the
 * default in many distros), MySQL supports it natively, and PostgreSQL has
 * no equivalent.
 *
 * One audit entry per call (not per row) — see backup_run.purge in
 * docs/internal/audit-actions.md.
 */
function ipam_backup_runs_purge(PDO $db, int $retentionDays, int $batchSize): int {
    if ($retentionDays <= 0) return 0;
    if ($batchSize <= 0) return 0;

    $cutoff = gmdate('Y-m-d H:i:s', time() - $retentionDays * 86400);

    $sel = $db->prepare(
        "SELECT id FROM backup_runs
          WHERE started_at < :cutoff
            AND status != 'running'
            AND (is_protected IS NULL OR is_protected = 0)
          ORDER BY id ASC
          LIMIT " . $batchSize
    );
    $sel->execute([':cutoff' => $cutoff]);
    /** @var list<int|string> $ids */
    $ids = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if (count($ids) === 0) return 0;

    $intIds = [];
    foreach ($ids as $raw) {
        if (is_numeric($raw)) $intIds[] = (int) $raw;
    }
    if (count($intIds) === 0) return 0;

    $placeholders = implode(',', array_fill(0, count($intIds), '?'));
    $del = $db->prepare(
        "DELETE FROM backup_runs WHERE id IN ($placeholders)"
    );
    $del->execute($intIds);
    $count = $del->rowCount();

    if ($count > 0) {
        audit($db, 'backup_run.purge', 'system', null,
              'deleted=' . $count
              . ' retention_days=' . $retentionDays
              . ' batch_size=' . $batchSize);
    }
    return $count;
}

// ────────────────────────────────────────────────────────────────────────────
// Backup
// ────────────────────────────────────────────────────────────────────────────

/**
 * Run backup for one destination.
 *
 * @param array<string,mixed> $config  global $config
 * @return array{log_id:int,filename:string,size:int,checksum:string,pruned:int}
 */
function ipam_backup_run_for_destination(
    PDO $db,
    array $config,
    int $destId,
    string $triggeredBy = 'manual',
    ?int $scheduleId = null
): array {
    // v3.22.0 #815: concurrency guard. Reap stuck rows first (so a row that
    // *looks* active but is past the threshold doesn't permanently block
    // legitimate runs), then refuse to start if a non-stale running row
    // exists for this destination.
    ipam_backup_reap_stale_runs($db);
    $activeId = ipam_backup_active_run_id($db, $destId);
    if ($activeId !== null) {
        audit($db, 'backup.skipped_concurrent', 'destination', $destId,
              'active_run_id=' . $activeId . ' triggered_by=' . $triggeredBy);
        throw new RuntimeException(
            'ipam_backup: another run is already in progress for destination=' . $destId
            . ' (run id=' . $activeId . '). Concurrent runs are blocked; wait for the active'
            . ' run to finish or use the reaper if it is stuck.'
        );
    }

    // O3+O4 (Pass A 2026-05-08, v3.27.1): wrap the pre-INSERT region in a
    // try/catch so any throw between dest_load and the encrypt-resolve
    // produces a visible failure trace (backup_runs row + audit row)
    // rather than silently disappearing. Before this fix the orchestrator
    // INSERTed the backup_runs row only AFTER dump+encrypt — every
    // pre-INSERT failure (missing key, missing path, dump error, etc.)
    // produced zero forensic trace.
    //
    // On throw we INSERT a synthetic backup_runs row tagged with
    // triggered_by + schedule_id, status='failed', a recognisable
    // synthetic filename `(preflight-failed-<8hex>)`, and the truncated
    // exception message in error_message. Then audit
    // `backup.preflight_failed` and re-throw so the caller's failure
    // path (cron $fail, UI error display) still runs.
    $tmpSql     = null;
    $dumpExt    = '.sql.gz';
    $backupType = 'database';
    $encMode    = 'stored';
    try {
        $dest = ipam_backup_dest_load($db, $destId);
        $dest['triggered_by'] = $triggeredBy;
        $dest['schedule_id']  = $scheduleId;
        $client = ipam_backup_dest_client($dest);

        // v3.25.0 #1076 #849 #851: resolve backup format and encryption mode
        // from the destination's per-destination defaults.
        $backupType = is_string($dest['default_backup_type'] ?? null)
            ? (string) $dest['default_backup_type']
            : 'database';
        if ($backupType !== 'database' && $backupType !== 'logical') {
            $backupType = 'database';
        }

        $encMode = is_string($dest['default_encryption_mode'] ?? null)
            ? (string) $dest['default_encryption_mode']
            : 'stored';
        if (!in_array($encMode, ['stored', 'transitory', 'unencrypted'], true)) {
            $encMode = 'stored';
        }
        // Server-side guard: 'unencrypted' is only allowed for Local
        // destinations (#851). Remote destinations always force 'stored'.
        $destType = is_string($dest['type'] ?? null) ? (string) $dest['type'] : '';
        if ($encMode === 'unencrypted' && $destType !== 'local') {
            $encMode = 'stored';
        }

        // Dispatch on backup_type (#849).
        if ($backupType === 'logical') {
            $tmpSqlFh = tempnam(sys_get_temp_dir(), 'ipam-backup-logical-');
            if ($tmpSqlFh === false) {
                throw new RuntimeException('ipam_backup: cannot create temp file for logical dump');
            }
            try {
                ipam_backup_logical_dump($db, $tmpSqlFh);
            } catch (Throwable $e) {
                @unlink($tmpSqlFh); // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-generated
                throw $e;
            }
            $tmpSql = $tmpSqlFh;
            $dumpExt = '.ipambkl1.gz';
        } else {
            $tmpSql = ipam_backup_dump_to_tmp($db);
            $dumpExt = '.sql.gz';
        }

        // Encrypt-write-path dispatch (v3.27.1 fix). vault_key first,
        // app_secret legacy fallback, throw if neither.
        $appSecret = is_string($config['app_secret'] ?? null) ? $config['app_secret'] : '';
        $vaultKey  = ipam_backup_vault_key_get_raw();
        $encResult = ipam_backup_resolve_encrypt_to_tmp($tmpSql, $encMode, $vaultKey, $appSecret, $dumpExt);
        $tmpFile   = $encResult['tmpFile'];
        $extension = $encResult['extension'];
        // resolve_encrypt_to_tmp consumed $tmpSql when it produced a new
        // encrypted output; mark consumed so the catch path doesn't try
        // to re-unlink it.
        if ($tmpFile !== $tmpSql) {
            $tmpSql = null;
        }
    } catch (Throwable $preflightError) {
        // Clean up any tmp dump that was produced but not encrypted.
        if (is_string($tmpSql) && is_file($tmpSql)) {
            @unlink($tmpSql); // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-generated
        }

        // Synthetic filename so History UI can distinguish preflight from
        // real uploads at a glance. The 8-hex suffix prevents collisions
        // when two preflight failures happen in the same second.
        $syntheticName = '(preflight-failed-' . bin2hex(random_bytes(4)) . ')';
        try {
            $logId = ipam_backup_insert_log(
                $db,
                $destId,
                $triggeredBy,
                'failed',
                $syntheticName,
                $scheduleId,
                $backupType,
                $encMode
            );
            $errMsg = substr($preflightError->getMessage(), 0, 1024);
            $upd = $db->prepare("UPDATE backup_runs SET error_message = :em WHERE id = :id");
            $upd->execute([':em' => $errMsg, ':id' => $logId]);

            audit($db, 'backup.preflight_failed', 'destination', $destId,
                  'run_id=' . $logId
                  . ' triggered_by=' . $triggeredBy
                  . ($scheduleId !== null ? ' schedule_id=' . $scheduleId : '')
                  . ' error=' . substr($preflightError->getMessage(), 0, 200));
        } catch (Throwable $audErr) {
            // If the failed-row INSERT or audit also fails (e.g. DB is
            // gone), don't mask the original cause — log to SAPI error
            // log so the SAPI log captures something even when stderr
            // is /dev/null'd, and re-throw the ORIGINAL exception.
            error_log('[backup] preflight_failed insert/audit failed: ' . $audErr->getMessage());
        }

        throw $preflightError;
    }

    // Random 8-hex-char suffix prevents filename collisions when two
    // runs land in the same second (e.g. manual + scheduled overlap).
    $remoteName = sprintf('ipam-backup-%s-%s%s', gmdate('Ymd-His'), bin2hex(random_bytes(4)), $extension);
    $logId = ipam_backup_insert_log(
        $db,
        $destId,
        $triggeredBy,
        'running',
        $remoteName,
        $scheduleId,
        $backupType,
        $encMode
    );

    // v3.27.8 (#1171, Bug D investigation): emit an audit row at the
    // moment the backup_runs INSERT succeeds. Bug D surfaced as "remote
    // file present, no backup_runs row" — confirmed structurally caused
    // by DB restore wiping the metadata row while the remote file
    // persists. A future *genuine* orchestrator orphan (insert failed,
    // upload still happened somehow) would now show 'backup.run' without
    // a paired 'backup.run_recorded' for the same filename, in any
    // audit_log snapshot taken via filesystem backup of the audit table
    // (e.g. logical backup of audit_log itself). Survives the DB-restore
    // scenario only when audit_log is captured out-of-band.
    audit($db, 'backup.run_recorded', 'destination', $destId,
          'run_id=' . $logId
          . ' filename=' . $remoteName
          . ' triggered_by=' . $triggeredBy
          . ($scheduleId !== null ? ' schedule_id=' . $scheduleId : ''));

    // v3.25.0 #856 cancel poll: cancel between dump+encrypt and upload.
    // v3.26.0 #859: ipam_backup_cancel_reason() also fires on
    // backup_destinations.is_active=0 — i.e. an admin disabled the
    // destination while this backup was in flight. The audit detail
    // carries the discriminator so an investigator can tell operator-
    // cancel from destination-disabled.
    $cancelReason = ipam_backup_cancel_reason($db, $logId);
    if ($cancelReason !== '') {
        ipam_backup_mark_canceled($db, $logId, 'before-upload reason=' . $cancelReason);
        audit($db, 'backup.cancel', 'destination', $destId,
              'run_id=' . $logId . ' phase=before-upload reason=' . $cancelReason);
        @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-generated
        throw new RuntimeException(
            'ipam_backup: canceled before upload (reason=' . $cancelReason . ')'
        );
    }

    try {
        $meta = $client->upload($tmpFile, $remoteName);
        ipam_backup_update_log_success($db, $logId, $meta);
        $size = $meta['size'];
        $checksum = $meta['checksum'];
    } catch (Throwable $e) {
        // If a cancel was requested mid-upload, prefer the canceled
        // status over a generic 'failed' so audit and the History row
        // surface the operator action.
        $midCancelReason = ipam_backup_cancel_reason($db, $logId);
        if ($midCancelReason !== '') {
            ipam_backup_mark_canceled($db, $logId, 'mid-upload reason=' . $midCancelReason);
            audit($db, 'backup.cancel', 'destination', $destId,
                  'run_id=' . $logId . ' phase=mid-upload reason=' . $midCancelReason
                  . ' error=' . substr($e->getMessage(), 0, 80));
        } else {
            ipam_backup_update_log_failure($db, $logId, $e->getMessage());
            audit($db, 'backup.failed', 'destination', $destId,
                  'remote=' . $remoteName . ' error=' . substr($e->getMessage(), 0, 200));
        }
        try {
            ipam_backup_notify($db, $dest, 'failure',
                'remote=' . $remoteName . ' error=' . $e->getMessage());
        } catch (Throwable $ne) {
            error_log('[backup] notify dispatch failed: ' . $ne->getMessage());
        }
        @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpFile is tempnam()-generated, no user input
        throw $e;
    }

    @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpFile is tempnam()-generated, no user input

    $pruned = 0;
    try {
        $pruned = ipam_backup_apply_retention($db, $destId);
    } catch (Throwable $e) {
        error_log('[backup] retention failed for destination ' . $destId . ': ' . $e->getMessage());
    }

    if ($pruned > 0) {
        try {
            ipam_backup_notify($db, 'retention_prune', ['dest' => $dest, 'pruned' => $pruned]);
        } catch (Throwable $ne) {
            error_log('[backup] retention notify dispatch failed: ' . $ne->getMessage());
        }
    }

    audit($db, 'backup.run', 'destination', $destId,
          'remote=' . $remoteName . ' size=' . $size . ' pruned=' . $pruned);

    try {
        ipam_backup_notify($db, $dest, 'success',
            'remote=' . $remoteName . ' size=' . $size . ' pruned=' . $pruned);
    } catch (Throwable $ne) {
        error_log('[backup] notify dispatch failed: ' . $ne->getMessage());
    }

    return [
        'log_id' => $logId,
        'filename' => $remoteName,
        'size' => $size,
        'checksum' => $checksum,
        'pruned' => $pruned,
    ];
}

/** @return array<string,mixed> */
function ipam_backup_dest_load(PDO $db, int $id): array
{
    $stmt = $db->prepare(
        "SELECT * FROM backup_destinations WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('ipam_backup: destination not found or inactive');
    }
    /** @var array<string,mixed> $typed */
    $typed = [];
    foreach ($row as $k => $v) {
        if (is_string($k)) {
            $typed[$k] = $v;
        }
    }
    return $typed;
}

/** @param array<string,mixed> $dest */
function ipam_backup_dest_client(array $dest): BackupClientInterface
{
    $type = is_string($dest['type'] ?? null) ? $dest['type'] : '';
    $configJson = is_string($dest['config'] ?? null) ? $dest['config'] : '{}';
    $cfg = json_decode($configJson, true);
    if (!is_array($cfg)) {
        throw new RuntimeException('ipam_backup: destination config is not valid JSON');
    }
    /** @var array<string,mixed> $typedCfg */
    $typedCfg = [];
    foreach ($cfg as $k => $v) {
        if (is_string($k)) {
            $typedCfg[$k] = $v;
        }
    }
    return match ($type) {
        's3'    => new S3Client($typedCfg),
        'sftp'  => new SftpClient($typedCfg),
        'local' => new LocalBackupClient($typedCfg),
        default => throw new RuntimeException('ipam_backup: unknown destination type ' . $type),
    };
}

/**
 * Detect the flavor of the locally-installed `mysqldump` / `mysql` client.
 *
 * Returns one of `'mariadb'`, `'mysql'`, or `'unknown'`. Result is cached for
 * the lifetime of the request because the probe shells out to `mysqldump
 * --version` and we don't want to repeat that for every dump invocation.
 *
 * Why we need this (v3.22.2): MariaDB and Oracle MySQL clients diverged on
 * SSL/TLS option spelling around the MariaDB 11.x / Oracle MySQL 8.4
 * timeframe. The dialects are mutually incompatible:
 *
 *   - MariaDB 11.x: `--ssl-verify-server-cert` (bare = ON), `--ssl-verify-server-cert=on/off`,
 *     `--skip-ssl-verify-server-cert` (= OFF). Rejects `--ssl-mode=*`.
 *   - Oracle MySQL 8.4: `--ssl-mode=DISABLED|PREFERRED|VERIFY_*` is the
 *     canonical replacement. Rejects `--ssl-verify-server-cert=on/off` and
 *     `--skip-ssl-verify-server-cert` as unknown options.
 *
 * Without flavor-aware emission a single hard-coded SSL flag bricks half the
 * field. The deferred follow-up #1081 (`--no-login-paths` and `--ssl-mode`
 * adoption) was supposed to introduce this probe; the v3.22.1 SSL flag
 * regression on Oracle MySQL prod made it a hotfix item instead.
 *
 * The probe is best-effort: any `proc_open` failure or missing binary
 * returns 'unknown' and call sites omit the SSL flag entirely.
 *
 * Cached per-binary so a host with split-vendor `mysql` / `mysqldump`
 * (e.g. distro-package mysqldump + custom-installed mysql client) gets the
 * correct dialect for each tool independently.
 */
function ipam_mysql_client_flavor(string $binary = 'mysqldump'): string
{
    /** @var array<string,string> $cache */
    static $cache = [];
    // Restrict to the two binaries we ever invoke; an unexpected value would
    // otherwise become an attacker-influenced argv if a caller forwarded user
    // input. Both are constant strings in our codebase today, but pin the
    // contract anyway.
    if ($binary !== 'mysqldump' && $binary !== 'mysql') {
        return 'unknown';
    }
    if (isset($cache[$binary])) {
        return $cache[$binary];
    }
    $pipes = [];
    // Constant array-form invocation; bypasses the shell entirely so no
    // injection surface exists. PHP 8+ raises Error (not returns false) when
    // proc_open is in disable_functions, so we treat both failure modes as
    // "unknown flavor" — call sites then omit the SSL flag and the operator
    // sees the real cause via the surfaced stderr from backup_run_dump.
    try {
        $proc = proc_open( // nosemgrep
            [$binary, '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
    } catch (Throwable $e) {
        return $cache[$binary] = 'unknown';
    }
    if (!is_resource($proc)) {
        return $cache[$binary] = 'unknown';
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $haystack = $stdout . "\n" . $stderr;
    if (stripos($haystack, 'MariaDB') !== false) {
        return $cache[$binary] = 'mariadb';
    }
    if (stripos($haystack, 'MySQL') !== false) {
        return $cache[$binary] = 'mysql';
    }
    return $cache[$binary] = 'unknown';
}

/**
 * Detect whether the locally-installed `mysqldump` / `mysql` accepts the
 * `--no-login-paths` flag (#1081). The flag, added in MariaDB 11.4 and
 * present in Oracle MySQL 8.x, makes the client skip `~/.mylogin.cnf`
 * regardless of `--defaults-extra-file` ordering. Without it, an operator
 * with a matching login-path entry on the app server can substitute the
 * password we route through `--defaults-extra-file`.
 *
 * Probed via `<binary> --help` (the `--version` output reliably names the
 * vendor; the help screen is the canonical place that lists supported
 * flags). Cached per-binary for the lifetime of the request.
 *
 * MariaDB <11.4 (Debian 12 default = MariaDB 10.11) rejects the flag
 * with "unknown option" and the dump fails immediately — see PR #1080
 * v3.22.1 hotfix CR thread for the failure mode that drove this probe.
 */
function ipam_mysql_client_supports_no_login_paths(string $binary = 'mysqldump'): bool
{
    /** @var array<string,bool> $cache */
    static $cache = [];
    if ($binary !== 'mysqldump' && $binary !== 'mysql') {
        return false;
    }
    if (isset($cache[$binary])) {
        return $cache[$binary];
    }
    $pipes = [];
    try {
        $proc = proc_open( // nosemgrep
            [$binary, '--help'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
    } catch (Throwable) {
        return $cache[$binary] = false;
    }
    if (!is_resource($proc)) {
        return $cache[$binary] = false;
    }
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $haystack = $stdout . "\n" . $stderr;
    return $cache[$binary] = str_contains($haystack, '--no-login-paths');
}

/**
 * Build the SSL-related arguments for `mysqldump` / `mysql` based on the
 * detected client flavor and the operator's `backup.dump_ssl_verify` setting.
 * Returns an empty list when the safe behaviour is "emit nothing" (Oracle
 * MySQL with verify off — the client default `--ssl-mode=PREFERRED` does not
 * verify the server certificate, which is what we want).
 *
 * The probe is run against the binary actually being invoked so a host with a
 * MariaDB `mysqldump` and an Oracle `mysql` (or vice versa) gets the right
 * dialect for each call site.
 *
 * @return list<string>
 */
function ipam_mysql_ssl_verify_args(bool $verify, string $binary = 'mysqldump'): array
{
    $flavor = ipam_mysql_client_flavor($binary);
    if ($flavor === 'mariadb') {
        // MariaDB 11.x defaults to verify-on, so we MUST emit something
        // explicit when verify is off, otherwise self-signed servers break.
        return $verify ? ['--ssl-verify-server-cert'] : ['--skip-ssl-verify-server-cert'];
    }
    if ($flavor === 'mysql') {
        // Oracle MySQL 8.x defaults to ssl-mode=PREFERRED (TLS if available,
        // no cert verification). For verify-off we omit the flag — emitting
        // --ssl-mode=DISABLED would gratuitously turn off TLS encryption too.
        return $verify ? ['--ssl-mode=VERIFY_IDENTITY'] : [];
    }
    // Unknown flavor: emit nothing and hope the client defaults to a sane
    // non-verifying mode. Worst case the operator sees a clear error in the
    // surfaced stderr (v3.22.2) and can pin a flavor manually.
    return [];
}

/**
 * Build the proc_open command + env for the configured DB driver's native
 * dump tool. Shared by the v3.17 remote-destination pipeline and the
 * legacy CLI backup runner so both stay in sync if a flag changes.
 *
 * Password is routed via a 0600 temp credential file (`--defaults-extra-file`
 * for mysql, `PGPASSFILE` for pgsql) so it never appears in the process
 * environment or on the command line. The returned `cred_file` path MUST
 * be unlink()ed by the caller after the dump completes (use try/finally).
 * Inherited `MYSQL_PWD` / `PGPASSWORD` from the parent shell are stripped
 * defensively (#820 PR #1074 CR / completes #1075).
 *
 * @param array<string,mixed> $config global $config
 * @return array{cmd: list<string>, env: array<string,string>, cred_file: string}
 */
function ipam_backup_native_cmd(string $driver, array $config): array
{
    $dsn  = is_string($config['db_dsn']  ?? null) ? $config['db_dsn']  : '';
    $user = is_string($config['db_user'] ?? null) ? $config['db_user'] : '';
    $pass = is_string($config['db_pass'] ?? null) ? $config['db_pass'] : '';
    $existingEnv = getenv(); // returns array<string,string> when called without args
    // Strip any inherited DB password env vars before passing to the child.
    // If the runner was started by a shell/service that already has these
    // set, they'd otherwise still leak into /proc/<pid>/environ even though
    // we route the real cred via a temp file.
    unset($existingEnv['MYSQL_PWD'], $existingEnv['PGPASSWORD']);

    if ($driver === 'mysql') {
        if (!preg_match('/dbname=([^;]+)/i', $dsn, $m)) {
            throw new RuntimeException('ipam_backup_native_cmd: dbname missing from db_dsn');
        }
        $name = $m[1];
        // Resolve setting BEFORE writing the temp cred file so that an
        // ipam_setting() throw doesn't leak a 0600 password file in /tmp
        // (PR #1080 CR round 2). --ssl-verify-server-cert is opt-in via
        // backup.dump_ssl_verify; default false matches PDO_MYSQL
        // (PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT) so v3.22.0 operators on
        // internal/on-prem MySQL with self-signed certs are not regressed.
        $verifySsl = (bool) ipam_setting('backup.dump_ssl_verify');
        $credFile  = ipam_backup_write_mysql_defaults_file($pass);
        // --defaults-extra-file MUST be the FIRST argument; mysql/mysqldump
        // ignore it otherwise (libmysql parses it before any other option).
        //
        // SSL verify flag is flavor-aware (see ipam_mysql_ssl_verify_args).
        // MariaDB and Oracle MySQL clients diverged on the option spelling
        // around the 11.x/8.4 timeframe — a hard-coded form bricks half the
        // field in either direction.
        $cmd = ['mysqldump', '--defaults-extra-file=' . $credFile];
        // #1081: prevent ~/.mylogin.cnf from overriding our 0600 defaults
        // file when the client supports it (MariaDB 11.4+ / Oracle MySQL 8.x).
        // Older MariaDB rejects the flag — probe + skip silently.
        if (ipam_mysql_client_supports_no_login_paths('mysqldump')) {
            $cmd[] = '--no-login-paths';
        }
        foreach (ipam_mysql_ssl_verify_args($verifySsl) as $sslArg) {
            $cmd[] = $sslArg;
        }
        $cmd[] = '--single-transaction';
        $cmd[] = '--routines';
        if (preg_match('/unix_socket=([^;]+)/i', $dsn, $m)) {
            $cmd[] = '--socket';
            $cmd[] = $m[1];
        } elseif (preg_match('/host=([^;]+)/i', $dsn, $m)) {
            $cmd[] = '-h';
            $cmd[] = $m[1];
        }
        if (preg_match('/port=([^;]+)/i', $dsn, $m)) {
            $cmd[] = '-P';
            $cmd[] = $m[1];
        }
        $cmd[] = '-u';
        $cmd[] = $user;
        $cmd[] = $name;
        return [
            'cmd'       => $cmd,
            'env'       => $existingEnv,
            'cred_file' => $credFile,
        ];
    }
    if ($driver === 'pgsql') {
        if (!preg_match('/dbname=([^;]+)/i', $dsn, $m)) {
            throw new RuntimeException('ipam_backup_native_cmd: dbname missing from db_dsn');
        }
        $name = $m[1];
        $credFile = ipam_backup_write_pgpass_file($pass);
        $cmd  = ['pg_dump'];
        if (preg_match('/host=([^;]+)/i', $dsn, $m)) {
            $cmd[] = '-h';
            $cmd[] = $m[1];
        }
        if (preg_match('/port=([^;]+)/i', $dsn, $m)) {
            $cmd[] = '-p';
            $cmd[] = $m[1];
        }
        $cmd[] = '-U';
        $cmd[] = $user;
        $cmd[] = $name;
        return [
            'cmd'       => $cmd,
            'env'       => array_merge($existingEnv, ['PGPASSFILE' => $credFile]),
            'cred_file' => $credFile,
        ];
    }
    throw new RuntimeException('ipam_backup_native_cmd: unsupported driver ' . $driver);
}

/**
 * Dump the configured DB to a gzipped tmp file, returning its path.
 *
 * - SQLite: streams `ipam_db_dump_stream()` directly into a gzip writer.
 * - MySQL:  shells out to `mysqldump` (password via MYSQL_PWD env), gzips the
 *           resulting SQL, deletes the intermediate plain file.
 * - Postgres: same pattern with `pg_dump` (PGPASSWORD env).
 *
 * @throws RuntimeException on unsupported driver, dump-tool failure, or I/O error.
 */
function ipam_backup_dump_to_tmp(PDO $db): string
{
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';

    $tmp = tempnam(sys_get_temp_dir(), 'ipambk_');
    if ($tmp === false) {
        throw new RuntimeException('ipam_backup: tempnam failed');
    }
    $tmpGz = $tmp . '.sql.gz';
    @rename($tmp, $tmpGz);

    // Outer try ensures $tmpGz is unlinked on every failure path, including
    // partial-write conditions. Otherwise a recurring scheduled-dump failure
    // would orphan ipambk_*.sql.gz files in sys_get_temp_dir() until disk
    // fills (CR feedback PR #786, #788 sibling).
    try {
        if ($driver === 'sqlite') {
            $fh = @gzopen($tmpGz, 'wb9');
            if ($fh === false) {
                throw new RuntimeException('ipam_backup: gzopen failed');
            }
            try {
                ipam_db_dump_stream($db, function (string $chunk) use ($fh): void {
                    $written = gzwrite($fh, $chunk);
                    if ($written === false || $written !== strlen($chunk)) {
                        throw new RuntimeException(
                            'ipam_backup: gzwrite stopped accepting bytes (disk full or compression error)'
                        );
                    }
                });
            } finally {
                gzclose($fh);
            }
            return $tmpGz;
        }

        if ($driver === 'mysql' || $driver === 'pgsql') {
            global $config;
            $cfg = is_array($config ?? null) ? $config : [];
            $tmpSql = $tmp . '.sql';
            $native = ipam_backup_native_cmd($driver, $cfg);
            try {
                // 10-minute deadline matches what an interactive admin would tolerate;
                // larger DBs that legitimately need more should run via cron instead.
                $dumpErr = '';
                if (!backup_run_dump($native['cmd'], $native['env'], $tmpSql, 600, $dumpErr)) {
                    // v3.22.2: surface stderr in the exception so the cause lands
                    // in backup_runs.error_message and the UI; previously the diagnostic
                    // was only available in the PHP error_log, which on LSWS/FPM
                    // installs is operationally hard to find. Truncate to 500 chars
                    // so a verbose stderr doesn't blow the row's column width.
                    $detail = $dumpErr !== '' ? $dumpErr : 'see error_log';
                    if (strlen($detail) > 500) {
                        $detail = substr($detail, 0, 497) . '...';
                    }
                    throw new RuntimeException('ipam_backup: ' . $driver . ' dump failed: ' . $detail);
                }
                $in = @fopen($tmpSql, 'rb');
                if ($in === false) {
                    throw new RuntimeException('ipam_backup: cannot read dump output');
                }
                $out = @gzopen($tmpGz, 'wb9');
                if ($out === false) {
                    fclose($in);
                    throw new RuntimeException('ipam_backup: gzopen failed for compressed output');
                }
                try {
                    while (!feof($in)) {
                        $chunk = fread($in, 65536);
                        if ($chunk === false) {
                            throw new RuntimeException('ipam_backup: read failed during compression');
                        }
                        if ($chunk === '') continue;
                        $written = gzwrite($out, $chunk);
                        if ($written === false || $written !== strlen($chunk)) {
                            throw new RuntimeException(
                                'ipam_backup: gzwrite stopped accepting bytes (disk full or compression error)'
                            );
                        }
                    }
                } finally {
                    fclose($in);
                    gzclose($out);
                }
            } finally {
                if (is_file($tmpSql)) {
                    @unlink($tmpSql); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSql is tempnam()-generated, no user input
                }
                // Unlink the 0600 password file regardless of dump outcome.
                if (is_file($native['cred_file'])) {
                    @unlink($native['cred_file']); // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-generated
                }
            }
            return $tmpGz;
        }

        throw new RuntimeException('ipam_backup: unsupported driver ' . $driver);
    } catch (Throwable $e) {
        if (is_file($tmpGz)) {
            @unlink($tmpGz); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpGz derives from tempnam(); no user input
        }
        throw $e;
    }
}

function ipam_backup_encrypt_to_tmp(string $srcPath, string $appSecret): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ipambkE_');
    if ($tmp === false) {
        throw new RuntimeException('ipam_backup: tempnam failed');
    }
    try {
        backup_encrypt_stream($srcPath, $tmp, $appSecret);
    } catch (Throwable $e) {
        @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmp is tempnam()-generated, no user input
        throw $e;
    }
    return $tmp;
}

/**
 * Encrypt $srcPath with the install's backup vault key (IPAMBKP3 stored
 * mode) into a fresh tempnam. Returns the new tmp path; caller is
 * responsible for unlinking. v3.27.1 — wraps backup_encrypt_stream_v3
 * with the same tempnam/throw-pattern as ipam_backup_encrypt_to_tmp so
 * ipam_backup_resolve_encrypt_to_tmp can pick a codec without caring
 * about file plumbing.
 */
function ipam_backup_encrypt_v3_stored_to_tmp(string $srcPath, string $vaultKey): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ipambkE3_');
    if ($tmp === false) {
        throw new RuntimeException('ipam_backup: tempnam failed');
    }
    try {
        backup_encrypt_stream_v3($srcPath, $tmp, BACKUP_V3_MODE_STORED, null, $vaultKey);
    } catch (Throwable $e) {
        @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmp is tempnam()-generated, no user input
        throw $e;
    }
    return $tmp;
}

/**
 * Decide which encryption codec to apply to a freshly-produced dump and
 * return both the resulting tmp file path and the operator-facing
 * filename suffix. v3.27.1 — extracted from `ipam_backup_run_for_destination`
 * so the encrypt-decision is one testable site (Bug from Pass A 2026-05-08:
 * the orchestrator's encrypt block was never wired to read the vault key
 * shipped in v3.26.0; on every install with no `app_secret`, encrypted
 * scheduled backups silently failed).
 *
 * Resolution order, mirroring the restore-side dispatcher
 * (`lib/backup.php:1241-1264`):
 *   1. encMode == 'unencrypted' → pass-through.
 *   2. backup_vault_key configured → IPAMBKP3 stored, suffix `.ipambkp3`.
 *   3. app_secret configured → IPAMBKP2 (legacy), suffix `.enc`.
 *   4. neither → throw with actionable error message.
 *
 * On encryption-path entry, the source $srcPath is unlinked after the
 * codec produces the encrypted output. On the throw path it is also
 * unlinked so the orchestrator never leaks plaintext under sys_get_temp_dir().
 *
 * @return array{tmpFile:string,extension:string}
 */
function ipam_backup_resolve_encrypt_to_tmp(
    string $srcPath,
    string $encMode,
    ?string $vaultKey,
    string $appSecret,
    string $dumpExt
): array {
    if ($encMode === 'unencrypted') {
        return ['tmpFile' => $srcPath, 'extension' => $dumpExt];
    }

    if ($vaultKey !== null) {
        $tmpFile = ipam_backup_encrypt_v3_stored_to_tmp($srcPath, $vaultKey);
        @unlink($srcPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $srcPath is tempnam()-generated by caller
        return ['tmpFile' => $tmpFile, 'extension' => '.ipambkp3'];
    }

    if ($appSecret !== '') {
        $tmpFile = ipam_backup_encrypt_to_tmp($srcPath, $appSecret);
        @unlink($srcPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $srcPath is tempnam()-generated by caller
        return ['tmpFile' => $tmpFile, 'extension' => '.enc'];
    }

    @unlink($srcPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $srcPath is tempnam()-generated by caller
    throw new RuntimeException(
        'ipam_backup: encryption requested but neither backup_vault_key '
        . 'nor app_secret is configured. Set up the backup vault key in '
        . 'Backups → Destinations, or restore app_secret in config.php.'
    );
}

/**
 * Insert a backup_runs row for a destination-driven backup. Status starts as
 * 'running' (v3.21.0 enum) and gets promoted to 'success' or 'failed' on
 * completion.
 *
 * v3.25.0 #849 #1076 #851: $backupType and $encryptionMode are now passed
 * explicitly by the orchestrator, sourced from the destination's
 * `default_backup_type` / `default_encryption_mode` columns. Defaults
 * preserve pre-v3.25 behaviour (database / derive-from-encrypt-flag) so
 * legacy callers still work.
 *
 * The encryption_mode column vocabulary is ('stored','transitory',
 * 'unencrypted'). The destination-driven path emits 'stored' or
 * 'unencrypted'; 'transitory' is emitted only by the manual
 * upload-and-restore path (#837) which has its own insert site.
 */
function ipam_backup_insert_log(
    PDO $db,
    int $destId,
    string $triggeredBy,
    string $status,
    string $filename,
    ?int $scheduleId = null,
    string $backupType = 'database',
    ?string $encryptionMode = null
): int {
    $now = ipam_dialect()->now();

    if ($encryptionMode === null) {
        // Back-compat: derive from the destination's encrypt flag, matching
        // pre-v3.25 behaviour.
        $encryptionMode = 'unencrypted';
        try {
            $eStmt = $db->prepare("SELECT encrypt FROM backup_destinations WHERE id = :id");
            $eStmt->execute([':id' => $destId]);
            $enc = $eStmt->fetchColumn();
            if ($enc !== false && (int) $enc === 1) {
                $encryptionMode = 'stored';
            }
        } catch (Throwable) {
            // best-effort; falls back to 'unencrypted' if the lookup fails
        }
    }

    if ($backupType !== 'database' && $backupType !== 'logical') {
        $backupType = 'database';
    }
    if (!in_array($encryptionMode, ['stored', 'transitory', 'unencrypted'], true)) {
        $encryptionMode = 'unencrypted';
    }

    $stmt = $db->prepare(
        "INSERT INTO backup_runs " .
        "(destination_id, schedule_id, backup_type, encryption_mode, triggered_by, status, filename, source_version, started_at) " .
        "VALUES (:d, :sid, :bt, :em, :t, :s, :f, :sv, $now)"
    );
    $stmt->bindValue(':d',   $destId, PDO::PARAM_INT);
    if ($scheduleId === null) {
        $stmt->bindValue(':sid', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':sid', $scheduleId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':bt',  $backupType);
    $stmt->bindValue(':em',  $encryptionMode);
    $stmt->bindValue(':t',   $triggeredBy);
    $stmt->bindValue(':s',   $status);
    $stmt->bindValue(':f',   $filename);
    $stmt->bindValue(':sv',  IPAM_VERSION);
    $stmt->execute();
    return (int) $db->lastInsertId();
}

/**
 * v3.25.0 #856 cancel-in-flight: poll the cancel_requested flag on a
 * backup_runs row. Returns true iff the operator has clicked Cancel since
 * the run started. Best-effort: a DB hiccup returns false (better to
 * complete the backup than to abort it on a transient lookup failure).
 *
 * Call this between major orchestrator boundaries (post-dump,
 * post-encrypt, before-upload) and at any chunk boundary inside a long
 * upload. The corresponding cleanup happens in
 * ipam_backup_handle_cancel().
 */
/**
 * Return the cancel discriminator for an in-flight backup run, or '' if
 * the run should continue:
 *
 *   'cancel_requested'    — operator clicked Cancel in the UI; backup_runs.cancel_requested=1
 *   'destination_disabled' — admin flipped backup_destinations.is_active=0 mid-run (#859)
 *   ''                    — no cancel signal active
 *
 * The two-signal model lets the orchestrator emit a distinct audit detail
 * for each path so an incident response can tell "the operator pressed
 * Cancel" from "an admin disabled the destination while a run was active".
 *
 * Tolerates a missing destination row (LEFT JOIN), missing schema columns
 * (is_active or cancel_requested absent on a partial fixture), and any
 * other PDO failure — all return '' so the cancel poll never fabricates
 * a cancel signal from an infrastructure error.
 */
function ipam_backup_cancel_reason(PDO $db, int $runId): string
{
    try {
        $stmt = $db->prepare(
            "SELECT br.cancel_requested, bd.is_active AS dest_active
               FROM backup_runs br
               LEFT JOIN backup_destinations bd ON bd.id = br.destination_id
              WHERE br.id = :id"
        );
        $stmt->execute([':id' => $runId]);
        $row = $stmt->fetch();
        if (!is_array($row)) return '';
        $cancelRaw = $row['cancel_requested'] ?? 0;
        if (is_numeric($cancelRaw) && (int) $cancelRaw === 1) {
            return 'cancel_requested';
        }
        // dest_active=NULL means no destination row joined (missing FK or
        // pre-migration schema with no is_active column). Treat NULL as
        // "still live" so a destination-row glitch does not abort an
        // in-flight backup.
        $destActiveRaw = $row['dest_active'] ?? null;
        if ($destActiveRaw !== null && is_numeric($destActiveRaw) && (int) $destActiveRaw === 0) {
            return 'destination_disabled';
        }
        return '';
    } catch (Throwable) {
        return '';
    }
}

function ipam_backup_should_cancel(PDO $db, int $runId): bool
{
    return ipam_backup_cancel_reason($db, $runId) !== '';
}

/**
 * v3.25.0 #856: mark a canceled run as failed with the canonical
 * 'canceled-by-operator' error_message. Status stays 'failed' (rather than
 * a new 'canceled' enum value) so we don't have to evolve the
 * backup_runs.status CHECK constraint across all three engines — see the
 * comment in lib/backup.php where this status is checked. Audit and
 * tmpfile cleanup are the orchestrator's responsibility.
 */
function ipam_backup_mark_canceled(PDO $db, int $runId, string $detail = ''): void
{
    $now = ipam_dialect()->now();
    $msg = 'canceled-by-operator' . ($detail !== '' ? ': ' . $detail : '');
    $stmt = $db->prepare(
        "UPDATE backup_runs SET status='failed', error_message=:e, completed_at=$now WHERE id=:id"
    );
    $stmt->execute([':e' => $msg, ':id' => $runId]);
}

/** @param array{size:int,checksum:string} $meta */
function ipam_backup_update_log_success(PDO $db, int $logId, array $meta): void
{
    $now = ipam_dialect()->now();
    $stmt = $db->prepare(
        "UPDATE backup_runs SET status='success', size_bytes=:sz, checksum=:cs, completed_at=$now WHERE id=:id"
    );
    $stmt->execute([':sz' => $meta['size'], ':cs' => $meta['checksum'], ':id' => $logId]);
}

function ipam_backup_update_log_failure(PDO $db, int $logId, string $error): void
{
    $now = ipam_dialect()->now();
    $stmt = $db->prepare(
        "UPDATE backup_runs SET status='failed', error_message=:e, completed_at=$now WHERE id=:id"
    );
    $stmt->execute([':e' => substr($error, 0, 1000), ':id' => $logId]);
}

// ────────────────────────────────────────────────────────────────────────────
// Restore — staging-dir guards (#762 item 3)
// ────────────────────────────────────────────────────────────────────────────

/**
 * Throw RuntimeException unless the candidate path resolves to something
 * under Simple-PHP-IPAM/data/tmp/. Defence-in-depth: every restore code
 * path that writes a file MUST call this on the target before writing,
 * so a future refactor that introduces user input into the path
 * construction fails closed instead of silently writing outside the
 * staging area.
 *
 * The target file may not exist yet (writes go to fresh paths), so the
 * containment check is anchored on the parent directory's realpath plus
 * the candidate's basename.
 */
function ipam_restore_assert_staged_path(string $path): void
{
    if ($path === '') {
        throw new RuntimeException('ipam_restore: empty staged path');
    }
    $tmpDir = realpath(dirname(__DIR__) . '/data/tmp');
    if ($tmpDir === false) {
        throw new RuntimeException('ipam_restore: data/tmp/ does not exist');
    }
    // The file itself may not exist yet — anchor on the parent directory.
    $parent = realpath(dirname($path));
    if ($parent === false) {
        throw new RuntimeException('ipam_restore: parent of staged path does not resolve');
    }
    if (!str_starts_with($parent . '/', rtrim($tmpDir, '/') . '/')) {
        throw new RuntimeException('ipam_restore: staged path is not under data/tmp/');
    }
}

/**
 * Resolve a candidate path to its canonical realpath, returning null if
 * the file does not exist or does not resolve to something under
 * Simple-PHP-IPAM/data/tmp/. Used by read-side callers (verify-signed,
 * read-staged-sql) that need the canonical form to operate on.
 */
function ipam_restore_canonicalize_staged(string $path): ?string
{
    if ($path === '') return null;
    $tmpDir = realpath(dirname(__DIR__) . '/data/tmp');
    if ($tmpDir === false) return null;
    $real = realpath($path);
    if ($real === false) return null;
    if (!str_starts_with($real . '/', rtrim($tmpDir, '/') . '/')) return null;
    return $real;
}

// ────────────────────────────────────────────────────────────────────────────
// Restore
// ────────────────────────────────────────────────────────────────────────────

/**
 * Download a remote backup, decrypt if encrypted, verify checksum,
 * and stage the plain .sql.gz file in data/tmp/. Returns absolute path.
 *
 * @param array<string,mixed> $config  global $config
 * @return array{path:string,size:int,filename:string,encrypted:bool}
 */
function ipam_restore_prepare_for_restore(PDO $db, array $config, int $destinationId, string $remoteName): array
{
    $dest = ipam_backup_dest_load($db, $destinationId);
    $client = ipam_backup_dest_client($dest);

    // Sanity: reject any name with traversal characters before passing to client.
    // Backslash rejection is defence-in-depth: direct POSTs to download_remote_backup.php
    // can reach this method without first going through the remote_backups.php name-guard.
    if ($remoteName === ''
        || str_contains($remoteName, '/')
        || str_contains($remoteName, '\\')
        || str_contains($remoteName, "\0")
        || str_starts_with($remoteName, '.')) {
        throw new InvalidArgumentException('ipam_restore: invalid remote name');
    }

    $tmpDir = dirname(__DIR__) . '/data/tmp';
    if (!is_dir($tmpDir)) {
        if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('ipam_restore: cannot create tmp dir');
        }
    }
    $rand = bin2hex(random_bytes(8));
    $isEnc = str_ends_with($remoteName, '.enc');
    $stagedExt = $isEnc ? '.sql.gz' : (str_ends_with($remoteName, '.sql.gz') ? '.sql.gz' : '.bin');
    $downloadPath = $tmpDir . '/restore_dl_' . $rand;
    $stagedPath   = $tmpDir . '/restore_staged_' . $rand . $stagedExt;

    if (!$client->download($remoteName, $downloadPath)) {
        throw new RuntimeException('ipam_restore: file not found on remote');
    }

    // Compute checksum of the on-the-wire blob BEFORE decryption,
    // to match how ipam_backup_run_for_destination() recorded it during upload.
    $observedHash = hash_file('sha256', $downloadPath);
    if ($observedHash === false) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- random local path
        @unlink($downloadPath);
        throw new RuntimeException('ipam_restore: cannot hash downloaded file');
    }

    // Verify against backup_runs row if one exists for this filename.
    // Mismatch is fatal — never apply a backup whose stored checksum disagrees.
    // backup_runs only tracks backup runs (no type=restore rows since v3.21.0
    // §A1), so no type filter is needed.
    $stmt = $db->prepare(
        "SELECT checksum FROM backup_runs
         WHERE destination_id = :d AND filename = :f AND status = 'success'
         ORDER BY started_at DESC LIMIT 1"
    );
    $stmt->execute([':d' => $destinationId, ':f' => $remoteName]);
    $stored = $stmt->fetchColumn();
    if (is_string($stored) && $stored !== '' && !hash_equals($stored, $observedHash)) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- random local path
        @unlink($downloadPath);
        throw new RuntimeException('ipam_restore: checksum mismatch — refusing to stage file');
    }

    try {
        if ($isEnc) {
            $appSecret = is_string($config['app_secret'] ?? null) ? $config['app_secret'] : '';
            // v3.24+: forward the vault key so the dispatcher can decrypt
            // any IPAMBKP3 stored-mode archives that have been written into
            // this destination by the manual upload-restore path or by a
            // future encrypt-side rollout. Transitory archives cannot be
            // restored on the destination-driven path (no operator
            // passphrase available); the dispatcher throws
            // IpamBackupKeyRequiredException which we surface as-is so the
            // operator sees an actionable message in the UI.
            $vaultKey = ipam_backup_vault_key_get_raw();
            // Pre-flight credential check: we don't yet know the on-disk
            // format (the dispatcher peeks the magic), but we can refuse
            // up-front when neither credential is available — every
            // encrypted format needs at least one. Allowing only one of
            // the two means an install with vault_key but no app_secret
            // can still restore a v3 stored archive (and vice versa for
            // v1/v2). The dispatcher will raise the format-specific
            // error if the actual archive needs the credential we don't
            // have.
            if ($appSecret === '' && $vaultKey === null) {
                throw new RuntimeException(
                    'ipam_restore: encrypted backup but neither app_secret nor backup_vault_key is set'
                );
            }
            ipam_restore_assert_staged_path($stagedPath); // #762 item 3 — defence-in-depth before write
            backup_decrypt_to_path($downloadPath, $stagedPath, $appSecret, null, $vaultKey);
        } else {
            ipam_restore_assert_staged_path($stagedPath); // #762 item 3 — defence-in-depth before write
            if (!@copy($downloadPath, $stagedPath)) {
                throw new RuntimeException('ipam_restore: cannot stage downloaded file');
            }
        }
    } finally {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- $downloadPath generated locally from random hex; tmpDir is project-controlled
        if (is_file($downloadPath)) @unlink($downloadPath);
    }

    $size = filesize($stagedPath);
    if ($size === false) {
        throw new RuntimeException('ipam_restore: staged file size unreadable');
    }

    return [
        'path'      => $stagedPath,
        'size'      => $size,
        'filename'  => $remoteName,
        'encrypted' => $isEnc,
    ];
}

/**
 * Stage an operator-uploaded backup file (#837, v3.24.0).
 *
 * Mirrors ipam_restore_prepare_for_restore()'s output contract so the
 * existing wizard machinery (sign/dryrun/apply) consumes upload-staged
 * files identically to destination-staged files.
 *
 * Detects the on-disk format by peeking the magic bytes:
 *   IPAMBKP3 stored      → backup_vault_key from config (no operator prompt)
 *   IPAMBKP3 transitory  → operator-supplied $passphrase
 *   IPAMBKP2 / IPAMBKP1  → app_secret (legacy)
 *   IPAMBKU1             → integrity-only unwrap (no key)
 *   plain SQL / IPAMBKL1 → straight copy (no decrypt)
 *
 * For IPAMBKP3 transitory archives, throws IpamBackupKeyRequiredException
 * when $passphrase is null/empty so the caller can render a passphrase
 * prompt. The same exception fires when a stored archive lands but the
 * vault key is unset on this install (operator must restore config.php
 * before they can decrypt the archive).
 *
 * Reads $_FILES['restore_upload']. Caller is responsible for csrf, role,
 * and demo-mode checks before calling.
 *
 * No PDO dependency: unlike the destination-driven path, an uploaded
 * archive has no matching backup_runs row to checksum-verify against
 * (the file did not originate from any known destination this install
 * tracks). Integrity is enforced by the codec's HMAC / SHA-256 instead.
 *
 * @param array<string,mixed> $config  global $config
 * @return array{path:string,size:int,filename:string,encrypted:bool}
 * @throws IpamBackupKeyRequiredException when an IPAMBKP3 archive needs
 *         a credential the caller did not supply.
 * @throws RuntimeException on upload, format, or I/O failure.
 */
function ipam_restore_prepare_for_upload(array $config, ?string $passphrase = null): array
{
    // dirname(__DIR__) here is the Simple-PHP-IPAM/ web root (this file lives
    // under lib/), matching the convention used by sibling helpers.
    $tmpDir = dirname(__DIR__) . '/data/tmp';
    if (!is_dir($tmpDir)) {
        if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('ipam_restore_upload: cannot create tmp dir');
        }
    }

    if (!isset($_FILES['restore_upload']) || !is_array($_FILES['restore_upload'])) {
        throw new RuntimeException('ipam_restore_upload: no file uploaded');
    }
    $f = $_FILES['restore_upload'];
    $err  = is_numeric($f['error'] ?? null) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE;
    $tmp  = is_string($f['tmp_name'] ?? null) ? $f['tmp_name'] : '';
    $name = is_string($f['name'] ?? null)     ? $f['name']     : '';
    $size = is_numeric($f['size'] ?? null)    ? (int) $f['size'] : 0;

    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException(ipam_restore_upload_error_message($err));
    }
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('ipam_restore_upload: invalid uploaded file');
    }
    if ($size <= 0) {
        throw new RuntimeException('ipam_restore_upload: empty upload');
    }

    $maxMb = to_int(ipam_setting('backup_max_upload_size_mb', 2048));
    // The setting registry advertises a min of 1; if a corrupted /
    // pre-min database row sneaks through, clamp to that minimum rather
    // than silently expanding to the 2048 default (which would raise the
    // cap above what the operator intended). Tightening to the smaller
    // bound is the safer failure mode.
    if ($maxMb < 1) {
        $maxMb = 1;
    }
    $maxBytes = $maxMb * 1024 * 1024;
    if ($size > $maxBytes) {
        throw new RuntimeException(sprintf(
            'ipam_restore_upload: file is %d bytes; limit is %d MiB. ' .
            'Adjust backup_max_upload_size_mb (and PHP upload_max_filesize / post_max_size) if needed.',
            $size,
            $maxMb
        ));
    }

    // Sanitise the filename for display / signing — strip path components,
    // refuse traversal characters. The on-disk staged path uses a random
    // suffix; this name is recorded for the operator's reference only.
    $displayName = basename($name);
    if ($displayName === '' || str_contains($displayName, "\0")) {
        throw new RuntimeException('ipam_restore_upload: invalid filename');
    }

    $rand         = bin2hex(random_bytes(8));
    $downloadPath = $tmpDir . '/restore_dl_' . $rand;

    if (!@move_uploaded_file($tmp, $downloadPath)) {
        throw new RuntimeException('ipam_restore_upload: cannot move uploaded file into tmp');
    }

    try {
        // Peek 8 bytes to dispatch.
        $fh = @fopen($downloadPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('ipam_restore_upload: cannot open uploaded file');
        }
        $magic = (string) fread($fh, 8);
        fclose($fh);

        $isEnc = ($magic === BACKUP_MAGIC_V3
               || $magic === BACKUP_MAGIC_V2
               || $magic === BACKUP_MAGIC);
        $isWrapped = ($magic === BACKUP_MAGIC_UNENC);
        $isLogical = ($magic === 'IPAMBKL1');
        // Gzip-backed plain dumps (e.g. uploaded mysqldump | gzip output
        // or raw .sql.gz) carry the standard gzip magic 0x1f 0x8b in the
        // first two bytes. Without this branch they fall through to .bin
        // and ipam_restore_read_staged_sql() reads the compressed bytes
        // as plaintext during dry-run, rejecting otherwise valid uploads.
        $isGz = (strlen($magic) >= 2 && substr($magic, 0, 2) === "\x1f\x8b");

        // Gzip is just framing; an arbitrary gzip blob proves nothing
        // about the payload. Open a stream-only decompress and sniff the
        // first ~128 bytes of plaintext for the same SQL-prelude / IPAMBKL1
        // markers we accept on the uncompressed path. This rejects
        // gzipped random garbage at the upload step rather than letting
        // it stage and only fail at dryrun.
        if ($isGz) {
            $gz = @gzopen($downloadPath, 'rb');
            if ($gz === false) {
                throw new RuntimeException('ipam_restore_upload: cannot read gzip upload');
            }
            try {
                $gzHead = strtoupper(ltrim((string) gzgets($gz, 128)));
            } finally {
                gzclose($gz);
            }
            $gzPayloadOk = (
                str_starts_with($gzHead, 'IPAMBKL1')
                || str_starts_with($gzHead, '--')
                || str_starts_with($gzHead, 'BEGIN')
                || str_starts_with($gzHead, 'CREATE')
                || str_starts_with($gzHead, 'INSERT')
                || str_starts_with($gzHead, 'PRAGMA')
                || str_starts_with($gzHead, 'SET ')
                || str_starts_with($gzHead, '/*')
            );
            if (!$gzPayloadOk) {
                throw new RuntimeException(
                    'ipam_restore_upload: unrecognised backup format ' .
                    '(gzip payload does not look like SQL or IPAMBKL1)'
                );
            }
        }

        // Positive plain-SQL sniff: anything that is NOT IPAM magic, NOT
        // IPAMBKL1, NOT IPAMBKU1, and NOT gzip must look like SQL text
        // before we accept it. Random binary garbage previously fell
        // through to the plain-copy branch and only failed later at
        // dryrun/apply with a confusing error; reject up-front instead
        // so the operator gets an immediate "unrecognised backup format"
        // message at the upload step.
        $isPlainSql = false;
        if (!$isEnc && !$isWrapped && !$isLogical && !$isGz) {
            $head = strtoupper(ltrim((string) substr($magic, 0, 8)));
            // Common dump preludes: SQL comment, BEGIN [TRANSACTION],
            // CREATE TABLE/INDEX/...,  PRAGMA (sqlite), INSERT, SET
            // (mysqldump session vars), -- (comment).
            $isPlainSql = (
                str_starts_with($head, '--')
                || str_starts_with($head, 'BEGIN')
                || str_starts_with($head, 'CREATE')
                || str_starts_with($head, 'INSERT')
                || str_starts_with($head, 'PRAGMA')
                || str_starts_with($head, 'SET ')
                || str_starts_with($head, '/*')
            );
            if (!$isPlainSql) {
                throw new RuntimeException(
                    'ipam_restore_upload: unrecognised backup format ' .
                    '(expected IPAMBKP1/2/3, IPAMBKU1, IPAMBKL1, gzip, or SQL text)'
                );
            }
        }

        // Choose the staged extension. The decrypted body for v1/v2/v3 is
        // historically gzipped SQL — match the destination-driven path's
        // convention so dryrun/apply don't care which staging route fed
        // them. Same .sql.gz extension applies to plain gzipped uploads
        // so the restore reader's gz-aware path picks them up. For
        // wrapped (IPAMBKU1), IPAMBKL1, and plain-uncompressed SQL we
        // use .bin since the body's content type is determined by its
        // own magic (IPAMBKL1 vs SQL) at dryrun time.
        $stagedExt  = ($isEnc || $isGz) ? '.sql.gz' : '.bin';
        $stagedPath = $tmpDir . '/restore_staged_' . $rand . $stagedExt;
        ipam_restore_assert_staged_path($stagedPath);

        if ($isEnc) {
            $appSecret = is_string($config['app_secret'] ?? null) ? $config['app_secret'] : '';
            // app_secret is required for legacy v1/v2; v3 ignores it. Pass
            // empty string when missing so the dispatcher's branch-specific
            // requirement check fires the appropriate error.
            $vaultKey = ipam_backup_vault_key_get_raw();
            backup_decrypt_to_path($downloadPath, $stagedPath, $appSecret, $passphrase, $vaultKey);
        } elseif ($isWrapped) {
            backup_unencrypted_unwrap_stream($downloadPath, $stagedPath);
        } else {
            // Plain SQL / IPAMBKL1 — stage atomically via tempfile + rename.
            // copy() can leave a partial destination if it fails part-way
            // through; without atomic staging the helper would throw with
            // an orphaned partial file at $stagedPath that the caller has
            // no handle on (the function exits before returning the path).
            $copyTmp = $stagedPath . '.copying.' . bin2hex(random_bytes(4));
            try {
                if (!@copy($downloadPath, $copyTmp)) {
                    throw new RuntimeException('ipam_restore_upload: cannot copy plain upload to staged tmp');
                }
                if (!@rename($copyTmp, $stagedPath)) {
                    throw new RuntimeException('ipam_restore_upload: cannot finalise staged upload');
                }
                $copyTmp = null;
            } finally {
                if ($copyTmp !== null && is_file($copyTmp)) {
                    // nosemgrep: php.lang.security.unlink-use.unlink-use -- $copyTmp built from $stagedPath + random suffix; no user input
                    @unlink($copyTmp);
                }
            }
        }
    } finally {
        if (is_file($downloadPath)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- $downloadPath generated locally from random hex; tmpDir is project-controlled
            @unlink($downloadPath);
        }
    }

    $stagedSize = @filesize($stagedPath);
    if ($stagedSize === false) {
        throw new RuntimeException('ipam_restore_upload: staged file size unreadable');
    }

    return [
        'path'      => $stagedPath,
        'size'      => $stagedSize,
        'filename'  => $displayName,
        'encrypted' => $isEnc, // wrapped (IPAMBKU1) reports as not-encrypted: integrity wrapper, no key was needed
    ];
}

/**
 * Translate a $_FILES['*']['error'] code into an operator-readable
 * sentence. Keeps the ipam_restore_prepare_for_upload() body focused
 * on flow rather than message tables.
 */
function ipam_restore_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'ipam_restore_upload: file exceeds PHP upload_max_filesize';
        case UPLOAD_ERR_FORM_SIZE:
            return 'ipam_restore_upload: file exceeds the form-declared MAX_FILE_SIZE';
        case UPLOAD_ERR_PARTIAL:
            return 'ipam_restore_upload: file upload was incomplete';
        case UPLOAD_ERR_NO_FILE:
            return 'ipam_restore_upload: no file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'ipam_restore_upload: PHP has no upload_tmp_dir configured';
        case UPLOAD_ERR_CANT_WRITE:
            return 'ipam_restore_upload: PHP could not write the uploaded file to disk';
        case UPLOAD_ERR_EXTENSION:
            return 'ipam_restore_upload: a PHP extension halted the upload';
        default:
            return 'ipam_restore_upload: unknown upload error (code ' . $code . ')';
    }
}

/**
 * Sign a staged file path so caller can pass it back to apply()/dryRun()
 * via a query parameter without an attacker forging arbitrary paths.
 *
 * The signature binds the path AND any metadata (filename, destination_id)
 * the caller will hand back later. An attacker who flips the destination
 * or filename POST field will produce a signature mismatch.
 *
 * @param array<string,mixed> $config  global $config
 * @param array{filename?:string,destination_id?:int,size?:int} $meta
 */
function ipam_restore_sign(array $config, string $stagedPath, array $meta = []): string
{
    $appSecret = is_string($config['app_secret'] ?? null) ? $config['app_secret'] : '';
    if ($appSecret === '') {
        throw new RuntimeException('ipam_restore: cannot sign without app_secret');
    }
    $key = ipam_hkdf_sha256($appSecret, 'ipam-v3:restore-stage', 32);
    $message = $stagedPath
        . "\0filename=" . (isset($meta['filename']) ? (string) $meta['filename'] : '')
        . "\0destination_id=" . (isset($meta['destination_id']) ? (string) (int) $meta['destination_id'] : '')
        . "\0size=" . (isset($meta['size']) ? (string) (int) $meta['size'] : '');
    return hash_hmac('sha256', $message, $key);
}

/**
 * Verify a signed staged-file token. Returns the path on success or null.
 *
 * @param array<string,mixed> $config  global $config
 * @param array{filename?:string,destination_id?:int,size?:int} $meta
 */
function ipam_restore_verify_signed(array $config, string $stagedPath, string $signature, array $meta = []): ?string
{
    try {
        $expected = ipam_restore_sign($config, $stagedPath, $meta);
    } catch (Throwable) {
        return null;
    }
    if (!hash_equals($expected, $signature)) return null;
    // Containment guard via centralized helper (#762 item 3): symlinked
    // deployments (release dir → /opt/ipam-current → /opt/ipam-X.Y.Z/) get
    // canonicalised on both sides, so otherwise-valid staged files resolve.
    return ipam_restore_canonicalize_staged($stagedPath);
}

/**
 * Parse a staged .sql.gz dump and report what restoring it would do,
 * without actually modifying the database. SQLite-only (matches backup).
 *
 * Pre-validates the dump by streaming it through ipam_restore_split_sql_statements
 * (#830, v3.23.0). A truncated or corrupt dump trips the splitter's
 * unterminated-state detection and throws RuntimeException — surfaced to
 * the operator BEFORE the apply path commits to a transaction. Previously
 * dry-run only ran a per-line regex scan that silently passed truncated
 * dumps; the apply step then failed mid-restore with a less specific
 * PDO::exec error and an indeterminate database state.
 *
 * @return array{
 *   tables: list<array{name:string,current_rows:int,backup_rows:int,delta:int}>,
 *   schema_diff: list<string>,
 *   total_statements: int,
 *   warnings: list<string>,
 * }
 * @throws RuntimeException  on driver mismatch, missing file, gzgets corruption,
 *                            or splitter-detected truncation (unterminated string,
 *                            identifier, comment, dollar-quote, or BEGIN block).
 */
function ipam_restore_dry_run(PDO $db, string $stagedPath): array
{
    // Bug S (Pass A 2026-05-08) — magic-byte dispatch must happen BEFORE
    // the SQL splitter touches the file. The apply path at
    // ipam_restore_apply() already does this; dry-run was missed when
    // IPAMBKL1 landed in v3.23.0. Without this sniff the dry-run path
    // pipes JSON-shaped IPAMBKL1 content through ipam_restore_split_sql_statements,
    // which interprets the JSON's `"`/`$tag$` tokens as SQL identifier
    // openers and dollar-quote openers and throws an "unterminated …"
    // error at EOF — making every IPAMBKL1 archive un-restorable from
    // the wizard's Step 2 dry-run preview.
    $magic = ipam_restore_sniff_magic($stagedPath);
    if ($magic === 'IPAMBKL1') {
        return ipam_restore_logical_dry_run($db, $stagedPath);
    }

    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    if ($driver !== 'sqlite') {
        throw new RuntimeException('ipam_restore: dry-run only supports sqlite in v3.17.0');
    }

    // Stream chunks → splitter → count INSERT/CREATE per statement. The
    // splitter throws on unterminated state at EOF (#830 1/2) so a corrupt
    // dump fails dry-run loudly instead of silently passing the line scan.
    $tableInsertCounts = [];
    $createdTables = [];
    $warnings = [];

    $chunks = ipam_restore_read_staged_sql($stagedPath);
    foreach (ipam_restore_split_sql_statements($chunks) as $stmt) {
        $trim = ltrim($stmt);
        if ($trim === '' || str_starts_with($trim, '--')) continue;

        if (preg_match('/^INSERT INTO ["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/', $trim, $m)) {
            $t = $m[1];
            $tableInsertCounts[$t] = ($tableInsertCounts[$t] ?? 0) + 1;
        } elseif (preg_match('/^CREATE TABLE\s+(?:IF NOT EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i', $trim, $m)) {
            $createdTables[$m[1]] = true;
        }
    }

    // Tally currents
    $tables = [];
    foreach ($tableInsertCounts as $name => $backupRows) {
        $current = 0;
        try {
            $r = $db->query("SELECT COUNT(*) FROM \"$name\"");
            if ($r !== false) {
                $val = $r->fetchColumn();
                if (is_numeric($val)) $current = (int) $val;
            }
        } catch (Throwable) {
            $warnings[] = "Table '$name' does not currently exist; will be created.";
        }
        $tables[] = [
            'name' => $name,
            'current_rows' => $current,
            'backup_rows' => $backupRows,
            'delta' => $backupRows - $current,
        ];
    }

    // Sort by name
    usort($tables, fn($a, $b) => strcmp($a['name'], $b['name']));

    // Schema diff (very lightweight — list any tables in backup not in current schema)
    $schemaDiff = [];
    $existingStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
    $existing = is_object($existingStmt) ? $existingStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $existingSet = [];
    foreach ($existing as $v) {
        if (is_string($v)) $existingSet[$v] = true;
    }
    foreach (array_keys($createdTables) as $bt) {
        if (!isset($existingSet[$bt])) {
            $schemaDiff[] = "Backup creates table '$bt' which is not in the current schema.";
        }
    }

    return [
        'tables' => $tables,
        'schema_diff' => $schemaDiff,
        'total_statements' => array_sum($tableInsertCounts),
        'warnings' => $warnings,
    ];
}

/**
 * Sniff the magic line of a staged backup file to dispatch between
 * Database-format (IPAMBKP1/2/3 wrapping mysqldump/pg_dump/SQLite SQL)
 * and Logical-format (IPAMBKL1 wrapping NDJSON).
 *
 * Reads at most the first ~64 bytes through gzopen so it's safe on
 * arbitrarily-large dumps. Returns the trimmed first line, or '' if
 * the file isn't gzip-readable or has no recognizable magic.
 */
function ipam_restore_sniff_magic(string $stagedPath): string
{
    if (!is_file($stagedPath)) {
        return '';
    }
    $gz = @gzopen($stagedPath, 'rb');
    if ($gz === false) {
        return '';
    }
    try {
        $line = gzgets($gz, 64);
        if ($line === false) {
            return '';
        }
        return rtrim($line, "\r\n");
    } finally {
        gzclose($gz);
    }
}

/**
 * Apply a staged backup to the database. Wraps in a transaction;
 * on any failure rolls back and throws.
 *
 * @return array{tables_restored?:int,statements:int,format?:string,logical?:array<string,mixed>}
 */
function ipam_restore_apply(PDO $db, string $stagedPath, string $realFilename = '', ?int $destinationId = null): array
{
    // Magic-byte dispatch (v3.23.0 #824). IPAMBKL1 → engine-agnostic
    // PDO replay. Anything else → existing Database-format SQL-text path
    // (sqlite-only until #1042's multi-engine work).
    $magic = ipam_restore_sniff_magic($stagedPath);
    if ($magic === 'IPAMBKL1') {
        $logicalMeta = ipam_restore_logical_apply($db, $stagedPath);
        // v3.27.8 (#1171, Bug D investigation): the SQL-text path below
        // emits 'db.restore' on success; the IPAMBKL1 early-return path
        // previously did not, leaving logical restores invisible in the
        // audit log. The new row carries format=logical so post-mortems
        // can distinguish path-A from path-B without grepping into
        // details.
        $filename = $realFilename !== '' ? $realFilename : basename($stagedPath);
        try {
            audit(
                $db,
                'db.restore',
                'system',
                null,
                'file=' . $filename
                    . ' format=logical'
                    . ' rows=' . $logicalMeta['total_rows']
                    . ' size=' . (filesize($stagedPath) ?: 0)
            );
        } catch (Throwable $e) {
            error_log('[restore] audit failed: ' . $e->getMessage());
        }
        return [
            'format'           => 'logical',
            'tables_restored'  => 0, // not tracked in logical path; see logical meta
            'statements'       => $logicalMeta['total_rows'],
            'logical'          => $logicalMeta,
        ];
    }

    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    if ($driver !== 'sqlite') {
        throw new RuntimeException('ipam_restore: apply only supports sqlite in v3.17.0');
    }

    // v3.21.0 §A1 (#799/#808): backup_runs only tracks backup runs (no
    // type='restore' rows). Restore activity is recorded via audit_log
    // below. Proper restore-runs tracking lands with the restore wizard
    // rewrite in #807 (Wave 3).
    $filename = $realFilename !== '' ? $realFilename : basename($stagedPath);

    $tablesSeen = [];
    $statements = 0;

    // SQLite ignores PRAGMA foreign_keys = OFF inside an active transaction.
    // Set it BEFORE beginTransaction(); restore (defensively) afterwards even
    // on the failure path so the connection is left in the expected state.
    $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        // Stream chunks → splitter → exec. Bounded memory regardless of
        // dump size — the splitter GCs its buffer after each yielded
        // statement (#829, v3.23.0).
        $chunks = ipam_restore_read_staged_sql($stagedPath);
        foreach (ipam_restore_split_sql_statements($chunks) as $stmt) {
            if ($stmt === '' || str_starts_with(ltrim($stmt), '--')) continue;
            $db->exec($stmt);
            $statements++;
            if (preg_match('/^INSERT INTO ["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/', ltrim($stmt), $m)) {
                $tablesSeen[$m[1]] = true;
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw new RuntimeException('ipam_restore: apply failed — ' . $e->getMessage(), 0, $e);
    } finally {
        $db->exec('PRAGMA foreign_keys = ON');
    }

    // Bring schema up to date if backup is from older version
    try {
        apply_migrations($db);
    } catch (Throwable $e) {
        // Don't fail the restore for migration issues; surface as warning via audit
        error_log('[restore] post-restore migrations failed: ' . $e->getMessage());
    }

    // Audit: significant destructive action. Records into audit_log so
    // operators can see who restored what and when. (Pre-v3.21.0 the
    // restore also wrote a type='restore' row to backup_log; that path
    // is gone now per §A1 / #808.)
    try {
        audit(
            $db,
            'db.restore',
            'system',
            null,
            'file=' . $filename
                . ' tables=' . count($tablesSeen)
                . ' statements=' . $statements
                . ' size=' . (filesize($stagedPath) ?: 0)
        );
    } catch (Throwable $e) {
        error_log('[restore] audit failed: ' . $e->getMessage());
    }

    return [
        'tables_restored' => count($tablesSeen),
        'statements' => $statements,
    ];
}

/**
 * Stream the staged backup file as line-aligned chunks.
 *
 * Yields chunks of plaintext SQL — typically one line per yield via
 * `gzgets` / `fgets`, occasionally larger if a single line exceeds the
 * stream-buffer size — so callers (the splitter, the dry-run statement
 * scan) never need to hold the full plaintext in memory. Memory is
 * bounded by the longest single line plus one stream buffer (typically
 * a few KB), independent of total input size.
 *
 * #829 (v3.23.0): replaces the prior `: string` form which read the
 * entire decompressed dump into a single buffer and OOM'd on multi-GB
 * backups. The streaming model is the prerequisite for #824 PDO restore
 * engine landing in the same release.
 *
 * @return \Generator<string>  Plaintext SQL chunks in source order.
 * @throws RuntimeException    if the staged path is invalid, the file is
 *                             missing, gzopen fails, or gzread reports
 *                             corruption (truncation distinguished from
 *                             clean EOF via `gzeof`).
 */
function ipam_restore_read_staged_sql(string $stagedPath): \Generator
{
    // Containment guard via centralized helper (#762 item 3): defence-in-depth
    // in case an upstream signature/validation step is bypassed or refactored.
    $real = ipam_restore_canonicalize_staged($stagedPath);
    if ($real === null) {
        throw new RuntimeException('ipam_restore: staged file is not under data/tmp/');
    }
    if (!is_file($real)) {
        throw new RuntimeException('ipam_restore: staged file not found');
    }

    if (str_ends_with($real, '.sql.gz')) {
        // S-003 (#1149): a gzip bomb passes the compressed-upload cap
        // (backup_max_upload_size_mb) and only blows up on decompression —
        // a few-KB blob expanding to GB exhausts data/tmp/ and takes the
        // app + cron + scanner down with it. Cap the decompressed stream.
        // Headroom scales with the upload (legit SQL dumps compress ~5–10×,
        // so 10× the on-disk size is generous) with a 64 MiB floor for tiny
        // uploads. A gzip bomb is a few KB compressed, so the cap stays at
        // the floor and it never gets near GB scale; a real large-install
        // dump uploaded compressed at, say, 50 MiB gets a ~500 MiB cap.
        $compressedBytes = (int) (@filesize($real) ?: 0);
        $maxDecompressed = max(10 * $compressedBytes, 64 * 1024 * 1024);

        $fh = @gzopen($real, 'rb');
        if ($fh === false) {
            throw new RuntimeException('ipam_restore: gzopen failed');
        }
        try {
            // Read until gzgets returns false. gzgets returns up to 64KB or
            // to the next \n, whichever is smaller; lines in IPAM dumps are
            // typically < 1KB (one INSERT per line). After the loop, gzeof
            // distinguishes clean end-of-file from corruption (truncation).
            $decompressed = 0;
            while (true) {
                $line = gzgets($fh, 65536);
                if ($line === false) {
                    break;
                }
                $decompressed += strlen($line);
                if ($decompressed > $maxDecompressed) {
                    throw new RuntimeException(
                        'ipam_restore: refusing to stage — decompressed size exceeds the cap ' .
                        '(' . $maxDecompressed . ' bytes); the upload may be a gzip bomb.'
                    );
                }
                yield $line;
            }
            if (!gzeof($fh)) {
                throw new RuntimeException('ipam_restore: gzgets error — backup may be truncated');
            }
        } finally {
            gzclose($fh);
        }
        return;
    }

    $fh = @fopen($real, 'rb');
    if ($fh === false) {
        throw new RuntimeException('ipam_restore: cannot open staged file');
    }
    try {
        while (true) {
            $line = fgets($fh, 65536);
            if ($line === false) {
                break;
            }
            yield $line;
        }
        if (!feof($fh)) {
            throw new RuntimeException('ipam_restore: fgets error');
        }
    } finally {
        fclose($fh);
    }
}

/**
 * Validate the verdict of a restore proc_open run, accounting for sigchild
 * PHP builds where proc_close returns -1 unconditionally.
 *
 * Why (rewrite under #805 / B-P0-3): v3.19.1 #783 added a file-size fallback
 * for sigchild ambiguity in `backup_run_dump`. The same proc_close
 * unreliability exists in the mysql/psql restore paths in `restore.php`,
 * but a "file size" post-condition makes no sense for a restore — the
 * tool produces no output file. The right post-condition is that the
 * target DB is in the expected state: at minimum, `schema_migrations`
 * is populated. A successful restore from any IPAM dump always lands at
 * least one migration row.
 *
 * Pure: takes the captured exit code (caller must read it via
 * proc_get_status BEFORE proc_close, since proc_close on sigchild
 * builds reaps the SIGCHLD itself), returns a verdict struct. Never
 * exits / dies — the caller decides how to surface a failure.
 *
 * @return array{ok: bool, verdict: string, message: string}
 */
function ipam_restore_proc_check(int $exitCode, string $tool, string $stderr, PDO $db, int $preMigCount = 0): array
{
    if ($exitCode > 0) {
        return [
            'ok'      => false,
            'verdict' => "exit={$exitCode}",
            'message' => "{$tool} restore failed (exit={$exitCode}): " . trim($stderr),
        ];
    }
    if ($exitCode === 0) {
        return ['ok' => true, 'verdict' => 'exit=0', 'message' => ''];
    }
    // exitCode === -1 → sigchild build: exit code is unreliable.
    // Fall back to confirming the target DB has the expected post-state.
    // CR feedback PR #1054: an absolute count is dangerous — if the target
    // already had `schema_migrations` populated and the restore tool died
    // before touching the DB, the count is still > 0 and we'd report success.
    // Compare against the pre-restore count instead.
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM schema_migrations");
        if ($stmt === false) {
            throw new RuntimeException('schema_migrations COUNT query returned false');
        }
        $migCount = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return [
            'ok'      => false,
            'verdict' => 'exit=-1 (sigchild); schema_migrations check failed',
            'message' => "{$tool} restore: exit code unreliable AND schema_migrations check failed: " . $e->getMessage(),
        ];
    }
    // CR feedback PR #1054: a same-version restore can legitimately leave
    // the count unchanged — the dump contains the same schema_migrations
    // rows already present on the target. Only fail on a genuine regression
    // (`post < pre`) and on the "pre=0 and post=0" case (no rows at all,
    // means the restore tool never wrote anything).
    if ($migCount < $preMigCount) {
        return [
            'ok'      => false,
            'verdict' => "exit=-1 (sigchild); schema_migrations regressed (pre={$preMigCount}, post={$migCount})",
            'message' => "{$tool} restore: exit code unreliable AND schema_migrations regressed (pre={$preMigCount}, post={$migCount}) — restore lost rows. stderr: " . trim($stderr),
        ];
    }
    if ($migCount === 0) {
        return [
            'ok'      => false,
            'verdict' => 'exit=-1 (sigchild); schema_migrations empty',
            'message' => "{$tool} restore: exit code unreliable AND schema_migrations is empty — restore did not produce expected post-state. stderr: " . trim($stderr),
        ];
    }
    return [
        'ok'      => true,
        'verdict' => "exit=-1 (sigchild) → post-condition OK (pre={$preMigCount}, post={$migCount})",
        'message' => '',
    ];
}

/**
 * Split a SQL dump into top-level statements via a character-level lexer.
 *
 * Why a real lexer (rewrite under #806 / B-P0-4): the prior line-oriented
 * splitter mis-split any non-trivial dump — semicolons inside string
 * literals, identifier quotes, line/block comments, PostgreSQL
 * dollar-quoted bodies, or compound BEGIN…END blocks would all bleed
 * across statement boundaries (or split mid-statement). T13 in
 * tests/RestoreSplitterTest.php encodes the failure modes.
 *
 * The lexer streams the source once and tracks state for: single-quoted
 * strings (with `''` escape), double-quoted identifiers, MySQL backtick
 * identifiers, PostgreSQL dollar-quoted strings (`$tag$...$tag$`),
 * `--` line comments, `/* … *\/` block comments, and BEGIN…END depth.
 * `BEGIN TRANSACTION` / `BEGIN;` / `BEGIN WORK` are recognised as
 * transaction starts, not compound blocks. A statement boundary is a
 * top-level `;` outside every quoted/commented/depth context.
 *
 * Streaming model (#829, v3.23.0): consumes an iterable of input chunks
 * and yields complete statements as a generator. Internal buffer is
 * GC'd after each yielded statement so memory stays bounded by the
 * largest single statement plus one chunk — never by total input size.
 * Pass `[$sql]` to feed a single string.
 *
 * @param  iterable<string> $chunks  Stream of dump bytes (lines or larger blocks).
 * @return \Generator<string>        Trimmed top-level statements in source order.
 */
function ipam_restore_split_sql_statements(iterable $chunks): \Generator
{
    // Normalize iterable to Iterator so we can pull on demand.
    if (is_array($chunks)) {
        $chunks = new \ArrayIterator($chunks);
    }
    while ($chunks instanceof \IteratorAggregate) {
        $chunks = $chunks->getIterator();
    }
    /** @var \Iterator $chunks */
    $chunks->rewind();

    $buf = '';
    $i = 0;
    $stmtStart = 0;
    $depth = 0;

    // Pull the next non-empty chunk into $buf. Returns false at EOF.
    $pull = function () use (&$buf, $chunks): bool {
        while ($chunks->valid()) {
            $c = $chunks->current();
            $chunks->next();
            if (is_string($c) && $c !== '') {
                $buf .= $c;
                return true;
            }
        }
        return false;
    };

    // Ensure at least $n bytes are addressable at $i. Returns false at EOF.
    $ensure = function (int $n) use (&$buf, &$i, $pull): bool {
        while (strlen($buf) - $i < $n) {
            if (!$pull()) {
                return false;
            }
        }
        return true;
    };

    // Drop bytes before $stmtStart from the buffer so $buf doesn't grow
    // without bound across many statements. Threshold trades GC frequency
    // against substr cost; 16KB amortises well for IPAM-shape dumps.
    // GC runs inline at yield points (see below) so PHPStan can trace the
    // mutation; the closure form would obscure the by-reference writes
    // and trip the greaterOrEqual.alwaysFalse check.
    $gcThreshold = 16384;

    while ($ensure(1)) {
        $c = $buf[$i];
        $next = $ensure(2) ? $buf[$i + 1] : '';

        // ── line comment ── -- ... \n (or end of input)
        if ($c === '-' && $next === '-') {
            while ($ensure(1) && $buf[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        // ── block comment ── /* ... */ (does not nest in standard SQL)
        if ($c === '/' && $next === '*') {
            $i += 2;
            $closed = false;
            while ($ensure(2)) {
                if ($buf[$i] === '*' && $buf[$i + 1] === '/') {
                    $i += 2;
                    $closed = true;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                throw new RuntimeException(
                    'ipam_restore_split: unterminated /* block comment at end of input — backup may be truncated'
                );
            }
            continue;
        }

        // ── single-quoted string ── '...' with '' or \' escape
        if ($c === "'") {
            $i++;
            $closed = false;
            while ($ensure(1)) {
                // Backslash escape (MySQL default `\'`, also `\\`, `\n`, etc.).
                // Consume the backslash and the following character verbatim
                // so an escaped quote can't terminate the string.
                if ($buf[$i] === "\\" && $ensure(2)) {
                    $i += 2;
                    continue;
                }
                if ($buf[$i] === "'") {
                    if ($ensure(2) && $buf[$i + 1] === "'") {
                        // Escaped quote: consume both as part of the literal.
                        $i += 2;
                        continue;
                    }
                    $i++;
                    $closed = true;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                throw new RuntimeException(
                    "ipam_restore_split: unterminated single-quoted string at end of input — backup may be truncated"
                );
            }
            continue;
        }

        // ── double-quoted identifier ── "..."  (ANSI / PostgreSQL)
        if ($c === '"') {
            $i++;
            $closed = false;
            while ($ensure(1)) {
                if ($buf[$i] === '"') {
                    $i++;
                    $closed = true;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                throw new RuntimeException(
                    'ipam_restore_split: unterminated double-quoted identifier at end of input — backup may be truncated'
                );
            }
            continue;
        }

        // ── backtick-quoted identifier ── `...`  (MySQL)
        if ($c === '`') {
            $i++;
            $closed = false;
            while ($ensure(1)) {
                if ($buf[$i] === '`') {
                    $i++;
                    $closed = true;
                    break;
                }
                $i++;
            }
            if (!$closed) {
                throw new RuntimeException(
                    'ipam_restore_split: unterminated backtick identifier at end of input — backup may be truncated'
                );
            }
            continue;
        }

        // ── PostgreSQL dollar-quoted string ── $tag$ ... $tag$
        if ($c === '$') {
            // Look for the closing $ of the opening tag.
            $j = $i + 1;
            while (true) {
                if (!$ensure($j - $i + 1)) {
                    break;
                }
                $cj = $buf[$j];
                if ($cj === '$') {
                    break;
                }
                // Tag may only be identifier chars (letters, digits, underscore).
                if (!(($cj >= 'a' && $cj <= 'z') || ($cj >= 'A' && $cj <= 'Z') || ($cj >= '0' && $cj <= '9') || $cj === '_')) {
                    break;
                }
                $j++;
            }
            if ($ensure($j - $i + 1) && $buf[$j] === '$') {
                $tag = substr($buf, $i, $j - $i + 1); // includes the two $
                $tagLen = strlen($tag);
                $i = $j + 1;
                $closed = false;
                while ($ensure($tagLen)) {
                    if ($buf[$i] === '$' && substr($buf, $i, $tagLen) === $tag) {
                        $i += $tagLen;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                if (!$closed) {
                    throw new RuntimeException(
                        "ipam_restore_split: unterminated dollar-quoted string {$tag} at end of input — backup may be truncated"
                    );
                }
                continue;
            }
            // Lone $ — fall through and treat as ordinary char.
        }

        // ── BEGIN ... END block depth (only at top level / NORMAL state) ──
        // Detect a BEGIN word boundary that is not BEGIN TRANSACTION/WORK/;.
        if (($c === 'B' || $c === 'b')
            && ($i === 0 || (!ctype_alnum($buf[$i - 1]) && $buf[$i - 1] !== '_'))
            && $ensure(5)
            && strcasecmp(substr($buf, $i, 5), 'BEGIN') === 0
            && (!$ensure(6) || !(ctype_alnum($buf[$i + 5]) || $buf[$i + 5] === '_'))) {
            // Look past whitespace for the next significant token.
            $k = $i + 5;
            while ($ensure($k - $i + 1) && ctype_space($buf[$k])) {
                $k++;
            }
            $isTxn = false;
            if ($ensure($k - $i + 1)) {
                $nextCh = $buf[$k];
                if ($nextCh === ';') {
                    $isTxn = true; // BEGIN; → transaction start
                } elseif ($ensure($k - $i + 12)
                          && strcasecmp(substr($buf, $k, 11), 'TRANSACTION') === 0
                          && (!$ensure($k - $i + 12) || !(ctype_alnum($buf[$k + 11]) || $buf[$k + 11] === '_'))) {
                    $isTxn = true;
                } elseif ($ensure($k - $i + 5)
                          && strcasecmp(substr($buf, $k, 4), 'WORK') === 0
                          && (!$ensure($k - $i + 5) || !(ctype_alnum($buf[$k + 4]) || $buf[$k + 4] === '_'))) {
                    $isTxn = true;
                } elseif ($ensure($k - $i + 9)
                          && strcasecmp(substr($buf, $k, 8), 'IMMEDIATE') === 0) {
                    $isTxn = true; // SQLite: BEGIN IMMEDIATE
                } elseif ($ensure($k - $i + 9)
                          && strcasecmp(substr($buf, $k, 8), 'DEFERRED') === 0) {
                    $isTxn = true; // SQLite: BEGIN DEFERRED
                } elseif ($ensure($k - $i + 10)
                          && strcasecmp(substr($buf, $k, 9), 'EXCLUSIVE') === 0) {
                    $isTxn = true; // SQLite: BEGIN EXCLUSIVE
                }
            } else {
                $isTxn = true; // bare BEGIN at end of input
            }
            if (!$isTxn) {
                $depth++;
            }
            $i += 5;
            continue;
        }

        // ── END decrements depth ──
        if (($c === 'E' || $c === 'e')
            && ($i === 0 || (!ctype_alnum($buf[$i - 1]) && $buf[$i - 1] !== '_'))
            && $ensure(3)
            && strcasecmp(substr($buf, $i, 3), 'END') === 0
            && (!$ensure(4) || !(ctype_alnum($buf[$i + 3]) || $buf[$i + 3] === '_'))
            && $depth > 0) {
            $depth--;
            $i += 3;
            continue;
        }

        // ── statement terminator ──
        if ($c === ';' && $depth === 0) {
            $i++;
            $stmt = trim(substr($buf, $stmtStart, $i - $stmtStart));
            if ($stmt !== '') {
                yield $stmt;
            }
            $stmtStart = $i;
            if ($stmtStart >= $gcThreshold) {
                $buf = substr($buf, $stmtStart);
                $i -= $stmtStart;
                $stmtStart = 0;
            }
            continue;
        }

        // Default: advance by one byte.
        $i++;
    }

    // EOF reached at top level. An open BEGIN ... END block means the dump
    // ends mid-procedure body — almost certainly truncation.
    if ($depth > 0) {
        throw new RuntimeException(
            "ipam_restore_split: unterminated BEGIN…END block (depth={$depth}) at end of input — backup may be truncated"
        );
    }

    // Final flush — any unterminated tail (no trailing ';') is a complete
    // statement. Line-comment-at-EOF is legitimate and lands here as empty
    // tail after trim.
    $tail = trim(substr($buf, $stmtStart));
    if ($tail !== '') {
        yield $tail;
    }
}

// =========================================================================
// backup_state — per-(scope, key) cooldown state for backup notifications
// (v3.28.0 #1159)
//
// Replaces the whole-blob read-modify-write of the
// backup.destination_health / backup.schedule_overdue_state JSON settings.
// Two concurrent writers (cron tick + UI action) used to serialize the
// entire map, so one clobbered the other's update of an unrelated entry
// (Pass C F-S5-02). One row per (scope, key) lets each writer touch only
// its own entry; same-key races degrade to harmless last-writer-wins.
//
// scope: 'destination_health' (k = destination id) | 'schedule_overdue'
//        (k = schedule id). payload = arbitrary JSON-encodable assoc array.
// =========================================================================

/**
 * Fetch one backup_state entry, decoded. Returns [] when absent or unparsable.
 *
 * @return array<string, mixed>
 */
function ipam_backup_state_get(PDO $db, string $scope, string $k): array
{
    $st = $db->prepare("SELECT payload_json FROM backup_state WHERE scope = :s AND k = :k");
    $st->execute([':s' => $scope, ':k' => $k]);
    $raw = $st->fetchColumn();
    if (!is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Fetch every backup_state entry for a scope as a map of k => decoded payload.
 *
 * @return array<string, array<string, mixed>>
 */
function ipam_backup_state_get_all(PDO $db, string $scope): array
{
    $st = $db->prepare("SELECT k, payload_json FROM backup_state WHERE scope = :s");
    $st->execute([':s' => $scope]);
    $out = [];
    /** @var array<string, mixed> $row */
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $k   = to_str($row['k'] ?? '');
        $raw = is_string($row['payload_json'] ?? null) ? $row['payload_json'] : '';
        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $out[$k] = is_array($decoded) ? $decoded : [];
    }
    return $out;
}

/**
 * Atomic upsert of a single backup_state row. SELECT-then-UPDATE-or-INSERT;
 * a concurrent INSERT loser swallows the UNIQUE violation and re-applies its
 * UPDATE (last-writer-wins on the same key — the bug being fixed was losing
 * *unrelated* keys, not racing on one). updated_at is set from the dialect's
 * "now" expression on UPDATE and from the column default on INSERT.
 *
 * @param array<string, mixed> $payload
 */
function ipam_backup_state_put(PDO $db, string $scope, string $k, array $payload): void
{
    $enc = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($enc)) return;

    $nowExpr = ipam_dialect()->now();
    $applyUpdate = static function () use ($db, $scope, $k, $enc, $nowExpr): int {
        $st = $db->prepare(
            "UPDATE backup_state SET payload_json = :p, updated_at = {$nowExpr} WHERE scope = :s AND k = :k"
        );
        $st->execute([':p' => $enc, ':s' => $scope, ':k' => $k]);
        return $st->rowCount();
    };

    $exists = $db->prepare("SELECT 1 FROM backup_state WHERE scope = :s AND k = :k");
    $exists->execute([':s' => $scope, ':k' => $k]);
    if ($exists->fetchColumn() !== false) {
        $applyUpdate();
        return;
    }
    try {
        $db->prepare("INSERT INTO backup_state (scope, k, payload_json) VALUES (:s, :k, :p)")
           ->execute([':s' => $scope, ':k' => $k, ':p' => $enc]);
    } catch (\PDOException $e) {
        $sqlstate = (string) ($e->errorInfo[0] ?? '');
        $isUniqueViolation = $sqlstate === '23000' || $sqlstate === '23505'
            || stripos($e->getMessage(), 'unique') !== false
            || stripos($e->getMessage(), 'duplicate') !== false;
        if (!$isUniqueViolation) throw $e;
        $applyUpdate(); // concurrent insert won — last writer wins
    }
}

/**
 * Drop backup_state rows for a scope whose key is not in $liveKeys, keeping
 * the table from growing unbounded as destinations / schedules come and go.
 * An empty $liveKeys clears the whole scope.
 *
 * @param list<string> $liveKeys
 */
function ipam_backup_state_prune(PDO $db, string $scope, array $liveKeys): void
{
    if ($liveKeys === []) {
        $db->prepare("DELETE FROM backup_state WHERE scope = :s")->execute([':s' => $scope]);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($liveKeys), '?'));
    $st = $db->prepare("DELETE FROM backup_state WHERE scope = ? AND k NOT IN ({$placeholders})");
    $st->execute(array_merge([$scope], $liveKeys));
}

/**
 * Schedule-overdue detector (cron Task 6d, v3.22.0 §2.4).
 *
 * Walks `backup_schedules` JOIN `backup_destinations` for active
 * (schedule + destination) rows, computes a cutoff at `now - graceMinutes`,
 * and treats any schedule whose `next_run_at` predates the cutoff as
 * overdue. For each newly-overdue schedule, writes a `backup.schedule_overdue`
 * audit row and (when `$notifyEnabled`) dispatches an email via
 * `ipam_backup_notify('schedule_overdue', ...)`.
 *
 * Per-schedule cooldown is keyed by the `next_run_at` value at the time the
 * alert fires: once a schedule has been alerted for a given expected_at, no
 * further alert is emitted until the schedule successfully fires (which moves
 * `next_run_at` forward) and goes overdue again. State persists in the
 * `backup_state` table (scope `schedule_overdue`, one atomic row per
 * schedule id — v3.28.0 #1159; was a JSON setting before that).
 *
 * The function isolates the cron logic so it can be unit-tested without
 * spinning up the cron pipeline. Behaviour matches the inline version that
 * shipped in commit 5a26a95 byte-for-byte.
 *
 * @param int|null $nowTs Override "now" timestamp for tests; pass null in prod.
 * @return array{
 *     overdue:       int,
 *     alerted:       list<int>,
 *     grace_minutes: int,
 * } overdue = total overdue schedules detected; alerted = schedule_ids that
 *   were freshly alerted on this call (i.e. cooldown did not suppress).
 */
function ipam_backup_detect_overdue_schedules(PDO $db, ?int $nowTs = null): array
{
    $notifyEnabled = (bool) ipam_setting('backup.notify_schedule_overdue');
    $graceMinutes  = to_int(ipam_setting('backup.notify_overdue_grace_minutes'));
    if ($graceMinutes < 5) $graceMinutes = 5;

    // #1159: per-schedule cooldown lives in backup_state (scope
    // 'schedule_overdue'), one row per schedule id. We load the whole
    // scope once for the in-loop "already alerted?" reads, then write each
    // mutated entry back atomically via ipam_backup_state_put() so a
    // concurrent cron tick / UI action can't clobber an unrelated entry.
    /** @var array<string, array<string, mixed>> $overdueState */
    $overdueState = ipam_backup_state_get_all($db, 'schedule_overdue');

    $stmt = $db->query("
        SELECT s.id AS schedule_id, s.destination_id, s.next_run_at,
               d.name AS destination_name, d.is_active AS dest_active
        FROM backup_schedules s
        JOIN backup_destinations d ON d.id = s.destination_id
        WHERE s.is_active = 1
          AND d.is_active = 1
          AND s.next_run_at IS NOT NULL
    ");
    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $nowTs    = $nowTs ?? time();
    $cutoffTs = $nowTs - ($graceMinutes * 60);
    $overdueCount  = 0;
    /** @var list<int> $alertedIds */
    $alertedIds    = [];
    $aliveSchedKeys = [];

    foreach ($rows as $r) {
        $schedId = to_int($r['schedule_id'] ?? 0);
        if ($schedId <= 0) continue;
        $aliveSchedKeys[(string) $schedId] = true;
        $nextRunAt = to_str($r['next_run_at'] ?? '');
        if ($nextRunAt === '') continue;
        $nextRunTs = strtotime($nextRunAt . ' UTC');
        if ($nextRunTs === false) continue;
        if ($nextRunTs >= $cutoffTs) continue;

        $overdueCount++;
        $key = (string) $schedId;
        $prev = $overdueState[$key] ?? [];
        $alertedFor = is_string($prev['alerted_for'] ?? null) ? $prev['alerted_for'] : '';

        if ($alertedFor === $nextRunAt) {
            // Already alerted on this exact expected-fire-time; skip until the
            // schedule fires and moves next_run_at forward.
            continue;
        }

        $overdueMinutes = (int) floor(($nowTs - $nextRunTs) / 60);
        $destName = to_str($r['destination_name'] ?? 'unknown');
        audit($db, 'backup.schedule_overdue', 'schedule', $schedId,
              "destination=$destName expected_at=$nextRunAt overdue_minutes=$overdueMinutes");
        if ($notifyEnabled) {
            try {
                ipam_backup_notify($db, 'schedule_overdue', [
                    'schedule_id'      => $schedId,
                    'destination_name' => $destName,
                    'expected_at'      => $nextRunAt,
                    'overdue_minutes'  => $overdueMinutes,
                ]);
            } catch (Throwable $ne) {
                error_log('[backup] schedule-overdue notify dispatch failed: ' . $ne->getMessage());
            }
        }
        $overdueState[$key] = [
            'alerted_for'     => $nextRunAt,
            'last_alerted_at' => date('c', $nowTs),
        ];
        ipam_backup_state_put($db, 'schedule_overdue', $key, $overdueState[$key]);
        $alertedIds[] = $schedId;
    }

    // O5 (Pass A 2026-05-08, v3.27.1): second pass — flag schedules whose
    // most-recent backup_runs row is `status='failed'` regardless of
    // next_run_at. The original logic missed the "every fire fails"
    // pattern entirely because finalize_schedule_run advances last_run_at
    // AND next_run_at on every tick (success or fail), so a schedule
    // failing every fire kept its next_run_at fresh and never crossed
    // the grace window. Without O5 the encrypt-write-path bug stayed
    // invisible to the very alert designed to catch "schedule isn't
    // running" cases.
    //
    // For each schedule joined to its most-recent run, "failed last run"
    // means: latest run by started_at has status='failed', and there is
    // NO success row newer than that failure. The query below returns
    // each schedule's last run; we filter status in PHP.
    $lastRunStmt = $db->query("
        SELECT br.schedule_id, br.status, br.started_at
        FROM backup_runs br
        INNER JOIN (
            SELECT schedule_id, MAX(started_at) AS max_started
            FROM backup_runs
            WHERE schedule_id IS NOT NULL
            GROUP BY schedule_id
        ) latest
        ON latest.schedule_id = br.schedule_id
        AND latest.max_started = br.started_at
        WHERE br.schedule_id IS NOT NULL
    ");
    /** @var list<array<string, mixed>> $lastRunRows */
    $lastRunRows = $lastRunStmt !== false ? $lastRunStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($lastRunRows as $lr) {
        $schedId = to_int($lr['schedule_id'] ?? 0);
        if ($schedId <= 0) continue;
        if (!isset($aliveSchedKeys[(string) $schedId])) continue;
        $status = to_str($lr['status'] ?? '');
        if ($status !== 'failed') continue;

        // Already alerted in the next_run_at-based pass? Skip.
        if (in_array($schedId, $alertedIds, true)) continue;

        $overdueCount++;
        // For the failed-last-run path, key the cooldown on the
        // started_at timestamp of the failure rather than next_run_at.
        // A new failure (= new started_at) re-alerts; repeated alerts
        // for the same failure row are suppressed.
        $startedAt = to_str($lr['started_at'] ?? '');
        $key = (string) $schedId;
        $prev = $overdueState[$key] ?? [];
        $alertedFor = is_string($prev['alerted_for'] ?? null) ? $prev['alerted_for'] : '';
        if ($alertedFor === 'failed_run_at:' . $startedAt) {
            continue; // already alerted for this exact failure
        }

        // Look up destination name for the audit/notify payload.
        $destNameStmt = $db->prepare(
            "SELECT d.name FROM backup_schedules s "
            . "JOIN backup_destinations d ON d.id = s.destination_id "
            . "WHERE s.id = :id"
        );
        $destNameStmt->execute([':id' => $schedId]);
        $destName = to_str((string) $destNameStmt->fetchColumn());

        audit($db, 'backup.schedule_overdue', 'schedule', $schedId,
              "destination=$destName cause=last_run_failed last_failed_at=$startedAt");
        if ($notifyEnabled) {
            try {
                ipam_backup_notify($db, 'schedule_overdue', [
                    'schedule_id'      => $schedId,
                    'destination_name' => $destName,
                    'expected_at'      => $startedAt,
                    'overdue_minutes'  => 0,
                    'cause'            => 'last_run_failed',
                ]);
            } catch (Throwable $ne) {
                error_log('[backup] schedule-overdue (O5) notify dispatch failed: ' . $ne->getMessage());
            }
        }
        $overdueState[$key] = [
            'alerted_for'     => 'failed_run_at:' . $startedAt,
            'last_alerted_at' => date('c', $nowTs),
        ];
        ipam_backup_state_put($db, 'schedule_overdue', $key, $overdueState[$key]);
        $alertedIds[] = $schedId;
    }

    // Drop cooldown rows for schedules that no longer exist or are inactive.
    ipam_backup_state_prune($db, 'schedule_overdue', array_map('strval', array_keys($aliveSchedKeys)));

    return [
        'overdue'       => $overdueCount,
        'alerted'       => $alertedIds,
        'grace_minutes' => $graceMinutes,
    ];
}

// =========================================================================
// IPAMBKL1 — Logical-format codec
// =========================================================================
//
// Spec: docs/internal/ipambkl1-format.md
//
// The codec is the bottom of the IPAMBKL1 stack. Every body row's column
// values pass through ipam_logical_encode_value() on dump and through
// ipam_logical_decode_value() on restore. The pair preserves PHP's value
// types verbatim across the JSON round-trip and is engine-agnostic — driver
// differences are handled at the binding layer (ipam_bind_binary()), not
// here.
//
// Three column kinds need explicit handling:
//   - Binary blobs (ip_bin, network_bin)  → {"$bin": "<base64>"} envelope
//   - Timestamps (engine-native datetime) → ISO-8601 UTC normalised
//   - Everything else (int/string/bool/null) → pass through; JSON handles natively

/**
 * Normalise an engine-native datetime string to canonical ISO-8601 UTC.
 *
 * Accepts both 'YYYY-MM-DD HH:MM:SS' (sqlite / mysql fetch) and
 * 'YYYY-MM-DDTHH:MM:SSZ' (already canonical). Throws on anything else.
 *
 * Engines store TIMESTAMP / DATETIME columns in UTC by IPAM convention
 * (see init.php session config and the migration history). This function
 * does not perform timezone conversion — it assumes the input is already
 * UTC and only reformats the separator and trailing 'Z'.
 */
function ipam_logical_normalise_timestamp(string $ts): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $ts)) {
        return $ts;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $ts, $m)) {
        return $m[1] . 'T' . $m[2] . 'Z';
    }
    throw new InvalidArgumentException('ipam_logical_normalise_timestamp: unrecognised format: ' . $ts);
}

/**
 * Encode a single column value into its IPAMBKL1 wire form.
 *
 * @param mixed $value      The raw column value as fetched from PDO.
 * @param bool  $isBinary   True when the column is a binary blob (BLOB / VARBINARY / BYTEA).
 * @param bool  $isTimestamp True when the column is a TIMESTAMP / DATETIME.
 * @return mixed            JSON-serialisable scalar or {"$bin": "<base64>"} envelope. Null in → null out.
 */
function ipam_logical_encode_value(mixed $value, bool $isBinary = false, bool $isTimestamp = false): mixed
{
    if ($value === null) {
        return null;
    }
    if ($isBinary) {
        if (!is_string($value)) {
            throw new InvalidArgumentException('ipam_logical_encode_value: binary value must be string, got ' . gettype($value));
        }
        return ['$bin' => base64_encode($value)];
    }
    if ($isTimestamp) {
        if (!is_string($value)) {
            throw new InvalidArgumentException('ipam_logical_encode_value: timestamp value must be string, got ' . gettype($value));
        }
        return ipam_logical_normalise_timestamp($value);
    }
    return $value;
}

/**
 * Decode a single IPAMBKL1 wire-form value back into its PHP/SQL form.
 *
 * Inverse of ipam_logical_encode_value(). The {"$bin": "..."} envelope
 * is unwrapped via base64_decode; everything else passes through. Note
 * that timestamps remain ISO-8601 strings — every supported engine
 * (sqlite, mysql, pg) accepts ISO timestamps in INSERT, so no conversion
 * back to engine-native format is required.
 *
 * @param mixed $encoded  The decoded JSON value from a body row.
 * @return mixed
 */
function ipam_logical_decode_value(mixed $encoded): mixed
{
    if ($encoded === null) {
        return null;
    }
    if (is_array($encoded) && array_key_exists('$bin', $encoded)) {
        $payload = $encoded['$bin'];
        if (!is_string($payload)) {
            throw new InvalidArgumentException('ipam_logical_decode_value: $bin payload must be string');
        }
        $decoded = base64_decode($payload, /* strict */ true);
        if ($decoded === false) {
            throw new RuntimeException('ipam_logical_decode_value: invalid base64 in $bin envelope');
        }
        return $decoded;
    }
    return $encoded;
}

/**
 * Return the canonical IPAMBKL1 table_order list — parents-first FK-safe
 * topological sort of every user table in the live schema.
 *
 * Hand-coded rather than computed via PRAGMA introspection because:
 *   - The schema is small (38 tables) and stable; FK graph changes are rare
 *     and always intentional (a migration that adds a table or FK).
 *   - A hand-coded list is auditable in code review and survives engine
 *     introspection differences (sqlite PRAGMA vs mysql information_schema
 *     vs pg pg_constraint).
 *   - IPAMBKL1TableOrderTest validates the list against the live FK graph
 *     on every test run — if a schema change desyncs the list, the test
 *     fails loudly during the gate, not silently in the dump.
 *
 * The PDO arg is currently unused — the function returns the same list on
 * every engine — but is kept in the signature for forward-compat with a
 * possible v4.0.0 tenancy-aware variant that filters per tenant_id.
 *
 * Self-referential tables (currently only `sites` via `parent_id`) appear
 * once at their natural position; the restorer handles them via two-pass
 * replay per the format spec.
 *
 * @return string[] Parents-first table-order list.
 */
function ipam_logical_table_order(PDO $db): array
{
    // Layout (left → right = earliest to latest replay):
    //
    //   Layer 0 (no incoming FKs from any other table):
    //     schema_migrations users tags contacts vrfs webhooks api_keys
    //     login_attempts rate_limit_buckets rate_limit_dampener aggregates
    //     custom_field_defs audit_log address_history backup_destinations
    //     backup_state
    //
    //   Layer 1 (depend only on layer 0):
    //     sites (self-ref, two-pass)
    //
    //   Layer 2:
    //     devices (→ sites) → device_interfaces (→ devices)
    //     vlans (→ sites) → vlan_ranges (→ sites)
    //     subnets (→ vrfs, vlans, sites)
    //
    //   Layer 3:
    //     pd_pools (→ sites, subnets)
    //     scan_schedules (→ subnets), alert_state, utilization_snapshots,
    //     subnet_tags, subnet_contacts
    //
    //   Layer 4:
    //     addresses (→ subnets, device_interfaces, devices, contacts)
    //
    //   Layer 5:
    //     scan_results (→ addresses, subnets), address_tags, pd_delegations
    //
    //   Layer 6 (depend on layer-1+ peers):
    //     backup_schedules (→ backup_destinations) → backup_runs
    //     site_contacts (→ contacts, sites)
    //     settings (→ users), password_reset_tokens (→ users),
    //     totp_backup_codes (→ users), webauthn_credentials (→ users),
    //     webhook_deliveries (→ webhooks)
    unset($db); // forward-compat placeholder (tenancy filter in v4.0.0)
    return [
        // -- Layer 0 — no FKs, replayable in any order ---------------------
        'schema_migrations',
        'users',
        'tags',
        'contacts',
        'vrfs',
        'webhooks',
        'api_keys',
        'login_attempts',
        'rate_limit_buckets',
        'rate_limit_dampener',
        'aggregates',
        'custom_field_defs',
        'audit_log',
        'address_history',
        'backup_destinations',
        'backup_state',
        // -- Layer 1 — sites is self-referential (two-pass replay) ---------
        'sites',
        // -- Layer 2 — site/vlan/vrf-rooted -------------------------------
        'devices',
        'device_interfaces',
        'vlans',
        'vlan_ranges',
        'subnets',
        // -- Layer 3 — subnet-rooted children -----------------------------
        'pd_pools',
        'scan_schedules',
        'alert_state',
        'utilization_snapshots',
        'subnet_tags',
        'subnet_contacts',
        // -- Layer 4 — addresses depends on subnets + device_interfaces ---
        'addresses',
        // -- Layer 5 — address-rooted children ----------------------------
        'scan_results',
        'address_tags',
        'pd_delegations',
        // -- Layer 6 — user / backup / webhook tails -----------------------
        'backup_schedules',
        'backup_runs',
        'site_contacts',
        'settings',
        'password_reset_tokens',
        'totp_backup_codes',
        'webauthn_credentials',
        'webhook_deliveries',
    ];
}

// =========================================================================
// IPAMBKL1 — Logical-format dump (writer)
// =========================================================================

/**
 * Classify a column's encoding kind based on its name. IPAM follows a
 * consistent convention: binary blobs end in `_bin` (ip_bin, network_bin),
 * timestamps end in `_at` (created_at, updated_at, started_at, …). Every
 * other column is a scalar (int, string, bool, null) and passes through
 * the codec unchanged.
 *
 * Suffix-based rather than runtime introspection because SQLite's
 * declared column types aren't strict (a column declared TEXT can hold a
 * BLOB, etc.) and the three engines disagree on type metadata syntax.
 * The naming convention is a stable cross-engine signal — and the
 * conformance tests (#1042) catch any column that defies it.
 *
 * Per-table overrides (`$table.$column` map) carry the cases where a
 * shipped table predates or otherwise violates the suffix convention. Add
 * sparingly: the override is a maintenance liability, not a feature.
 *
 * @return 'binary'|'timestamp'|'scalar'
 */
function ipam_logical_column_kind(string $columnName, ?string $tableName = null): string
{
    // Per-table overrides (#1124). Each entry exists because a shipped
    // table has a binary column that does not end in `_bin`. The IPAMBKL1
    // writer's pre-#1124 silent-drop on json_encode failure made these
    // invisible until reproduction surfaced #1124 on v3.27.1's deployed
    // sqlite test instance — a backup with passkeys enrolled produced an
    // off-by-N row count and a checksum mismatch. The override map ties
    // the fix to specific columns; the `?` parameter keeps callers that
    // don't have table context (eg. external tooling) backward compatible.
    static $overrides = [
        // WebAuthn credentials store raw binary in two columns. Schema
        // declares `credential_id` BLOB and `public_key` TEXT, but TEXT
        // here carries the COSE-encoded key (binary, often non-UTF-8).
        // Renaming the columns to `_bin` would be a schema migration on
        // a security-sensitive table; the override is the lower-risk
        // fix and exists for that reason.
        'webauthn_credentials.credential_id' => 'binary',
        'webauthn_credentials.public_key'    => 'binary',
        // rate_limit_dampener.unlock_at (v3.28.0 #1143) is a Unix epoch
        // INTEGER, not an ISO timestamp string — the `_at` suffix
        // predates that distinction. Pass it through as a scalar so the
        // codec doesn't try to normalise an int as a timestamp.
        'rate_limit_dampener.unlock_at'      => 'scalar',
    ];
    if ($tableName !== null) {
        $key = $tableName . '.' . $columnName;
        if (isset($overrides[$key])) {
            return $overrides[$key];
        }
    }

    if (str_ends_with($columnName, '_bin')) {
        return 'binary';
    }
    // v3.25.0 fix: addresses.expires_at is a DATE column (YYYY-MM-DD), not
    // a datetime. The _at suffix convention assumed datetime granularity but
    // expires_at predates that convention. Surfaced 2026-05-06 by the
    // dispatch wire-up (#849) routing demo data through the IPAMBKL1 writer.
    // Pass through as a scalar string so JSON encodes it verbatim.
    if ($columnName === 'expires_at') {
        return 'scalar';
    }
    if (str_ends_with($columnName, '_at')) {
        return 'timestamp';
    }
    return 'scalar';
}

/**
 * Encode a single fetched row into IPAMBKL1 wire form, applying the
 * per-column kind classifier.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function ipam_logical_encode_row(array $row, ?string $tableName = null): array
{
    $out = [];
    foreach ($row as $col => $val) {
        $kind = ipam_logical_column_kind($col, $tableName);
        $out[$col] = ipam_logical_encode_value(
            $val,
            $kind === 'binary',
            $kind === 'timestamp'
        );
    }
    return $out;
}

/**
 * Compute the integer schema_version high-water mark for the IPAMBKL1
 * header. Defined as `COUNT(*)` of `schema_migrations` (= `MAX(id)` under
 * apply_migrations() idempotency). Monotone over a single install's
 * lifetime; identical on two installs sharing the same migration set.
 *
 * Version *labels* (e.g. "3.22.0") are TEXT and don't sort meaningfully
 * across major releases, which is why the count is the canonical integer
 * compatibility axis. The label of the most recent migration is carried
 * separately as `header.last_migration_version` for human-readable
 * diagnostics; the restorer only consults `schema_version` for compat
 * decisions.
 */
function ipam_logical_schema_version(PDO $db): int
{
    $r = $db->query("SELECT COUNT(*) FROM schema_migrations");
    if ($r === false) {
        return 0;
    }
    $val = $r->fetchColumn();
    return is_numeric($val) ? (int) $val : 0;
}

/**
 * Read the most recently applied migration's version label, for
 * diagnostic purposes only. Empty string when no migrations applied.
 */
function ipam_logical_last_migration_version(PDO $db): string
{
    $r = $db->query("SELECT version FROM schema_migrations ORDER BY id DESC LIMIT 1");
    if ($r === false) {
        return '';
    }
    $val = $r->fetchColumn();
    return is_string($val) ? $val : '';
}

/**
 * Stream all rows of a single table through PDO, yielding each row as
 * an associative array. Forward-only cursor — bounded memory regardless
 * of table size.
 *
 * @return Generator<array<string,mixed>>
 */
function ipam_logical_iterate_table(PDO $db, string $table): Generator
{
    // Quoting: table names from ipam_logical_table_order() are a known
    // closed set, hand-curated in source. Double-quoting works on all
    // three engines for ANSI-quoted identifiers.
    $stmt = $db->query("SELECT * FROM " . ipam_logical_q($db, $table));
    if ($stmt === false) {
        return;
    }
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        // PDO::FETCH_ASSOC always yields string keys, but PHPStan's stub
        // can't see that. Rebuild explicitly so the generic narrows to
        // array<string, mixed> at the yield site.
        $narrow = [];
        foreach ($row as $k => $v) {
            $narrow[(string) $k] = $v;
        }
        yield $narrow;
    }
}

/**
 * Write an IPAMBKL1 Logical-format dump from a live PDO connection to
 * the given output path. Memory-bounded — table rows stream through a
 * forward cursor; the output is gzipped line-by-line.
 *
 * Spec: docs/internal/ipambkl1-format.md.
 *
 * @param ?int $tenantId Reserved for v4.0.0; ignored in v3.23.0 (always null
 *                       in the produced header).
 * @return array{
 *   total_rows:int,
 *   row_counts:array<string,int>,
 *   checksum_sha256:string,
 *   schema_version:int,
 *   exported_at:string
 * }
 */
function ipam_backup_logical_dump(PDO $db, string $outputPath, ?int $tenantId = null): array
{
    unset($tenantId); // v4.0.0 hook
    $tableOrder = ipam_logical_table_order($db);
    $schemaVersion = ipam_logical_schema_version($db);
    $lastMigration = ipam_logical_last_migration_version($db);
    $exportedAt    = gmdate('Y-m-d\TH:i:s\Z');

    // First pass: count rows per table so the header can carry row_counts
    // upfront. The two-pass approach keeps the streaming write pure (no
    // backpatch into the gzip stream).
    $rowCounts = [];
    foreach ($tableOrder as $table) {
        $stmt = $db->query("SELECT COUNT(*) FROM " . ipam_logical_q($db, $table));
        $val = $stmt !== false ? $stmt->fetchColumn() : 0;
        $rowCounts[$table] = is_numeric($val) ? (int) $val : 0;
    }

    $header = [
        'header'                   => true,
        'format_version'           => 1,
        'schema_version'           => $schemaVersion,
        'last_migration_version'   => $lastMigration,
        'exported_at'              => $exportedAt,
        'exported_by_ipam_version' => defined('IPAM_VERSION') ? IPAM_VERSION : 'unknown',
        'tenant_id'                => null,
        'table_order'              => $tableOrder,
        'row_counts'               => $rowCounts,
    ];

    $gz = gzopen($outputPath, 'wb9');
    if ($gz === false) {
        throw new RuntimeException('ipam_backup_logical_dump: cannot open output for writing: ' . $outputPath);
    }

    $hashCtx   = hash_init('sha256');
    $totalRows = 0;

    // Each gzwrite goes through here so disk-full / compression-error
    // surfaces as a thrown RuntimeException rather than a silently
    // truncated dump that the operator only discovers at restore time
    // (CR feedback PR #1090). Mirrors the $written !== strlen($chunk)
    // pattern in ipam_backup_dump_to_tmp().
    $write = static function ($gz, string $payload, string $what) use ($outputPath): void {
        $written = gzwrite($gz, $payload);
        if ($written === false || $written !== strlen($payload)) {
            $writtenStr = $written === false ? 'false' : (string) $written;
            throw new RuntimeException(
                'ipam_backup_logical_dump: gzwrite failed on ' . $what
                . ' — wrote ' . $writtenStr . ' of ' . strlen($payload) . ' bytes to ' . $outputPath
                . ' (likely disk-full or compression error)'
            );
        }
    };

    try {
        // Magic line.
        $write($gz, "IPAMBKL1\n", 'magic');

        // Header line. Not part of body checksum.
        $headerJson = (string) json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $write($gz, $headerJson . "\n", 'header');

        // Body — rows in topo-sorted table order.
        foreach ($tableOrder as $table) {
            foreach (ipam_logical_iterate_table($db, $table) as $row) {
                $encoded = ipam_logical_encode_row($row, $table);
                $envelope = ['table' => $table, 'row' => $encoded];
                $line = json_encode(
                    $envelope,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                // #1124: pre-fix `(string) json_encode(...)` silently became
                // "" on non-UTF-8 input, producing a blank body line that
                // the reader skipped while the writer still incremented
                // $totalRows and hashed "\n". Result: off-by-N row count and
                // a checksum mismatch that gated apply. The throw turns
                // "silent data loss" into "loud, attributed failure" so any
                // future column-kind override gap surfaces at write time
                // instead of at restore time.
                if ($line === false) {
                    $rawPk = $row['id'] ?? null;
                    $sourcePk = (is_int($rawPk) || is_string($rawPk)) ? (string) $rawPk : '?';
                    throw new RuntimeException(
                        'ipam_backup_logical_dump: json_encode failed for table=' . $table
                        . ' row id=' . $sourcePk
                        . ' — likely a non-UTF-8 binary column missing from ipam_logical_column_kind() override map'
                        . ' (json_last_error=' . json_last_error() . ': ' . json_last_error_msg() . ')'
                    );
                }
                $payload = $line . "\n";
                hash_update($hashCtx, $payload);
                $write($gz, $payload, 'body row in table ' . $table);
                $totalRows++;
            }
        }

        $checksum = hash_final($hashCtx);

        $footer = [
            'footer'          => true,
            'checksum_sha256' => $checksum,
            'total_rows'      => $totalRows,
        ];
        $footerJson = (string) json_encode($footer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $write($gz, $footerJson . "\n", 'footer');
    } finally {
        gzclose($gz);
    }

    return [
        'total_rows'      => $totalRows,
        'row_counts'      => $rowCounts,
        'checksum_sha256' => $checksum,
        'schema_version'  => $schemaVersion,
        'exported_at'     => $exportedAt,
    ];
}

// =========================================================================
// IPAMBKL1 — Logical-format restore (reader)
// =========================================================================

/**
 * Convert an IPAMBKL1-wire timestamp ('YYYY-MM-DDTHH:MM:SSZ') to MySQL's
 * accepted DATETIME literal form ('YYYY-MM-DD HH:MM:SS'). With the default
 * sql_mode MySQL rejects the ISO 'T'/'Z' separators with error 1292;
 * sqlite and pgsql accept either form. Any string that isn't shaped like
 * an ISO-Z timestamp passes through unchanged so non-timestamp values
 * accidentally suffixed `_at` (none today, but defensive) aren't mangled.
 */
function ipam_logical_timestamp_for_mysql(string $value): string
{
    if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})Z$/', $value, $m)) {
        return $m[1] . ' ' . $m[2];
    }
    return $value;
}

/**
 * Quote a SQL identifier for the active driver. MySQL needs backticks;
 * SQLite and Postgres take ANSI double-quotes. Without this branch the
 * IPAMBKL1 writer/reader emit `"foo"` which MySQL reads as a string
 * literal and rejects with a 1064 syntax error. Existing IPAM code
 * already follows this convention (see ipam_key_col() in lib.php).
 */
function ipam_logical_q(PDO $db, string $ident): string
{
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    if ($driver === 'mysql') {
        return '`' . str_replace('`', '``', $ident) . '`';
    }
    return '"' . str_replace('"', '""', $ident) . '"';
}

/**
 * Introspect a table's FK columns and their target tables/columns.
 *
 * @return list<array{from:string,table:string,to:string}>
 */
function ipam_logical_introspect_fks(PDO $db, string $table): array
{
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    $out = [];
    if ($driver === 'sqlite') {
        $stmt = $db->query("PRAGMA foreign_key_list(\"$table\")");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $from = $r['from'] ?? null;
            $tgt  = $r['table'] ?? null;
            $to   = $r['to'] ?? null;
            if (is_string($from) && is_string($tgt) && is_string($to)) {
                $out[] = ['from' => $from, 'table' => $tgt, 'to' => $to];
            }
        }
        return $out;
    }
    if ($driver === 'mysql') {
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME AS `from`, REFERENCED_TABLE_NAME AS `table`, REFERENCED_COLUMN_NAME AS `to` '
            . 'FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND REFERENCED_TABLE_NAME IS NOT NULL '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([':t' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $from = $r['from'] ?? null;
            $tgt  = $r['table'] ?? null;
            $to   = $r['to'] ?? null;
            if (is_string($from) && is_string($tgt) && is_string($to)) {
                $out[] = ['from' => $from, 'table' => $tgt, 'to' => $to];
            }
        }
        return $out;
    }
    if ($driver === 'pgsql') {
        $sql = <<<'SQL'
SELECT kcu.column_name AS "from",
       ccu.table_name  AS "table",
       ccu.column_name AS "to"
  FROM information_schema.table_constraints tc
  JOIN information_schema.key_column_usage kcu
    ON tc.constraint_name = kcu.constraint_name
   AND tc.table_schema    = kcu.table_schema
  JOIN information_schema.constraint_column_usage ccu
    ON ccu.constraint_name = tc.constraint_name
   AND ccu.table_schema    = tc.table_schema
 WHERE tc.constraint_type = 'FOREIGN KEY'
   AND tc.table_name      = :t
   AND tc.table_schema    = current_schema()
 ORDER BY kcu.ordinal_position
SQL;
        $stmt = $db->prepare($sql);
        $stmt->execute([':t' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $from = $r['from'] ?? null;
            $tgt  = $r['table'] ?? null;
            $to   = $r['to'] ?? null;
            if (is_string($from) && is_string($tgt) && is_string($to)) {
                $out[] = ['from' => $from, 'table' => $tgt, 'to' => $to];
            }
        }
        return $out;
    }
    return $out;
}

/**
 * Detect a table's primary-key column when it's a single auto-increment
 * integer (the IPAM convention — `id INTEGER PRIMARY KEY`). Returns the
 * column name, or null if the PK is composite, non-integer (e.g.
 * schema_migrations.version), or otherwise non-strip-on-insert.
 */
function ipam_logical_detect_autoincrement_pk(PDO $db, string $table): ?string
{
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    if ($driver === 'sqlite') {
        $stmt = $db->query("PRAGMA table_info(\"$table\")");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $pkCols = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $pkRank = $r['pk'] ?? 0;
            if (is_numeric($pkRank) && (int) $pkRank > 0) {
                $pkCols[] = $r;
            }
        }
        if (count($pkCols) !== 1) {
            return null; // composite PK (join tables) — no auto-increment to strip
        }
        $col  = $pkCols[0];
        $name = is_string($col['name'] ?? null) ? $col['name'] : '';
        $type = strtoupper(is_string($col['type'] ?? null) ? $col['type'] : '');
        if ($type !== 'INTEGER' || $name === '') {
            return null;
        }
        return $name;
    }
    if ($driver === 'mysql') {
        $stmt = $db->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND EXTRA LIKE '%auto_increment%'"
        );
        $stmt->execute([':t' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }
        $name = $rows[0]['COLUMN_NAME'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }
    if ($driver === 'pgsql') {
        // Identity columns OR serial columns (default = nextval(...sequence...)).
        $sql = <<<'SQL'
SELECT c.column_name
  FROM information_schema.columns c
 WHERE c.table_schema = current_schema()
   AND c.table_name   = :t
   AND (
        c.is_identity = 'YES'
     OR (c.column_default IS NOT NULL AND c.column_default LIKE 'nextval(%')
   )
SQL;
        $stmt = $db->prepare($sql);
        $stmt->execute([':t' => $table]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            return null;
        }
        $name = $rows[0]['column_name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }
    return null;
}

/**
 * Per-engine FK-bracket: temporarily disable FK enforcement so the dump
 * can be replayed in topo order without ordering-dependent constraint
 * violations. Returns a closure that restores the prior setting.
 *
 * SQLite requires the PRAGMA to be set BEFORE BEGIN TRANSACTION (CLAUDE.md);
 * the caller is responsible for that ordering.
 */
function ipam_logical_open_fk_bracket(PDO $db): callable
{
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    if ($driver === 'sqlite') {
        $priorStmt = $db->query('PRAGMA foreign_keys');
        $priorRaw  = $priorStmt !== false ? $priorStmt->fetchColumn() : 0;
        $priorOn   = is_numeric($priorRaw) && (int) $priorRaw === 1;
        $db->exec('PRAGMA foreign_keys = OFF');
        return function () use ($db, $priorOn): void {
            $db->exec('PRAGMA foreign_keys = ' . ($priorOn ? 'ON' : 'OFF'));
        };
    }
    if ($driver === 'mysql') {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        return function () use ($db): void {
            $db->exec('SET FOREIGN_KEY_CHECKS = 1');
        };
    }
    if ($driver === 'pgsql') {
        $db->exec("SET session_replication_role = 'replica'");
        return function () use ($db): void {
            $db->exec("SET session_replication_role = 'origin'");
        };
    }
    return function (): void {};
}

/**
 * Logical-format dry-run preview (Bug S, v3.27.1).
 *
 * Mirror of ipam_restore_dry_run() for IPAMBKL1 archives. The SQL-format
 * dry-run streams .sql.gz through the SQL splitter; that path cannot be
 * fed JSON-shaped IPAMBKL1 content (the splitter interprets `"` and
 * `$tag$` tokens by SQL semantics and throws unterminated-state errors
 * at EOF).
 *
 * This helper walks the IPAMBKL1 file:
 *   - verifies magic and header
 *   - counts rows per table from the body
 *   - re-computes SHA-256 over the body and verifies it matches the
 *     footer's `checksum_sha256` (catches truncation / tampering before
 *     the wizard's Step 3 apply commits to a transaction)
 *   - reports any schema_version delta as a warning
 *
 * Return shape matches the SQL dry-run for caller compatibility:
 * `{tables, schema_diff, total_statements, warnings}`. `total_statements`
 * carries the row count for logical archives (the field is the operator-
 * facing "how much will be replayed" number).
 *
 * @return array{
 *   tables: list<array{name:string,current_rows:int,backup_rows:int,delta:int}>,
 *   schema_diff: list<string>,
 *   total_statements: int,
 *   warnings: list<string>,
 * }
 */
function ipam_restore_logical_dry_run(PDO $db, string $inputPath): array
{
    if (!is_file($inputPath)) {
        throw new RuntimeException('ipam_restore_logical_dry_run: input file not found: ' . $inputPath);
    }
    $gz = gzopen($inputPath, 'rb');
    if ($gz === false) {
        throw new RuntimeException('ipam_restore_logical_dry_run: cannot open input for reading: ' . $inputPath);
    }

    $warnings = [];
    $rowsByTable = [];   // [tableName => count]
    $totalRows   = 0;
    $hashCtx     = hash_init('sha256');
    $footer      = null;

    try {
        $magic = rtrim((string) gzgets($gz), "\n");
        if ($magic !== 'IPAMBKL1') {
            throw new RuntimeException('ipam_restore_logical_dry_run: unrecognised magic: ' . var_export($magic, true));
        }

        $headerLine = (string) gzgets($gz);
        $header = json_decode($headerLine, true);
        if (!is_array($header) || ($header['header'] ?? false) !== true) {
            throw new RuntimeException('ipam_restore_logical_dry_run: missing or malformed header line');
        }

        $rawSchemaVersion = $header['schema_version'] ?? 0;
        $sourceSchemaVersion = is_numeric($rawSchemaVersion) ? (int) $rawSchemaVersion : 0;

        // Target schema_version may be unavailable (empty DB, missing
        // schema_migrations table). Treat that as "skip the version compare"
        // rather than fail the dry-run — operator wants to see WHAT would be
        // restored, even when the target is fresh.
        $targetSchemaVersion = null;
        try {
            $targetSchemaVersion = ipam_logical_schema_version($db);
        } catch (Throwable) {
            $warnings[] = 'Could not read target schema_version (schema_migrations table missing). Apply will refuse on a fresh target until init runs.';
        }
        if ($targetSchemaVersion !== null) {
            if ($sourceSchemaVersion > $targetSchemaVersion) {
                $warnings[] = "Backup schema_version $sourceSchemaVersion is newer than this install's $targetSchemaVersion. Apply will refuse — upgrade first.";
            } elseif ($sourceSchemaVersion < $targetSchemaVersion) {
                $warnings[] = "Backup schema_version $sourceSchemaVersion is older than this install's $targetSchemaVersion. Apply will succeed; rows from tables added after the backup remain empty.";
            }
        }

        // Body — JSONL, one row per line, plus an optional footer line.
        // Hash matches what ipam_restore_logical_apply hashes (#824 spec):
        // every row line (excluding the footer line) contributes to the
        // SHA-256 checksum that the writer recorded in the footer.
        while (!gzeof($gz)) {
            $line = gzgets($gz);
            if ($line === false || $line === '') break;
            $trim = rtrim($line, "\n");
            if ($trim === '') continue;

            $obj = json_decode($trim, true);
            if (!is_array($obj)) {
                throw new RuntimeException('ipam_restore_logical_dry_run: malformed body line');
            }

            if (($obj['footer'] ?? false) === true) {
                $footer = $obj;
                break;
            }

            $table = $obj['table'] ?? null;
            if (!is_string($table)) {
                throw new RuntimeException('ipam_restore_logical_dry_run: malformed row line (missing table)');
            }
            $rowsByTable[$table] = ($rowsByTable[$table] ?? 0) + 1;
            $totalRows++;

            // Match writer's checksum domain: every body row line as written.
            hash_update($hashCtx, $trim . "\n");
        }

        if (!is_array($footer)) {
            throw new RuntimeException('ipam_restore_logical_dry_run: archive missing footer — backup may be truncated');
        }

        $expectedHash = $footer['checksum_sha256'] ?? null;
        $actualHash = hash_final($hashCtx);
        if (is_string($expectedHash) && hash_equals($expectedHash, $actualHash) !== true) {
            $warnings[] = 'Body checksum does not match footer — apply will refuse to proceed (corruption or tampering).';
        }

        $expectedTotal = $footer['total_rows'] ?? null;
        if (is_numeric($expectedTotal) && (int) $expectedTotal !== $totalRows) {
            $warnings[] = "Body row count ($totalRows) disagrees with footer total_rows ($expectedTotal).";
        }
    } finally {
        gzclose($gz);
    }

    // Build the per-table summary the wizard renders. current_rows comes
    // from the live DB; backup_rows from what we just counted.
    $tables = [];
    foreach ($rowsByTable as $name => $backupRows) {
        $current = 0;
        try {
            $stmt = $db->query('SELECT COUNT(*) FROM ' . ipam_logical_q($db, $name));
            if ($stmt !== false) {
                $val = $stmt->fetchColumn();
                if (is_numeric($val)) $current = (int) $val;
            }
        } catch (Throwable) {
            // Table doesn't exist on target — treated as current=0.
        }
        $tables[] = [
            'name'          => $name,
            'current_rows'  => $current,
            'backup_rows'   => $backupRows,
            'delta'         => $backupRows - $current,
        ];
    }

    return [
        'tables'           => $tables,
        'schema_diff'      => [],
        'total_statements' => $totalRows,
        'warnings'         => $warnings,
    ];
}

/**
 * Apply an IPAMBKL1 Logical-format dump onto the given PDO connection
 * via re-emit-IDs replay with FK remapping.
 *
 * Spec: docs/internal/ipambkl1-format.md → "Replay strategy — re-emit IDs".
 *
 * Process:
 *   1. Read magic line, header line. Validate schema_version (refuse newer).
 *   2. Open per-engine FK bracket BEFORE transaction (sqlite ordering rule).
 *   3. Stream body. For each row:
 *      - Look up table's FK metadata.
 *      - Remap FK column values via idmap (set NULL for self-referential).
 *      - Strip auto-increment PK if the table has one.
 *      - INSERT. Capture lastInsertId. Record idmap[table][source_pk] = target_pk.
 *   4. Validate footer checksum + total_rows.
 *   5. Second pass over self-referential tables to UPDATE the self-FKs.
 *   6. Commit transaction.
 *   7. Restore FK enforcement (always — finally clause).
 *
 * @return array{total_rows:int,checksum_ok:bool,schema_version:int}
 */
function ipam_restore_logical_apply(PDO $db, string $inputPath): array
{
    if (!is_file($inputPath)) {
        throw new RuntimeException('ipam_restore_logical_apply: input file not found: ' . $inputPath);
    }
    $gz = gzopen($inputPath, 'rb');
    if ($gz === false) {
        throw new RuntimeException('ipam_restore_logical_apply: cannot open input for reading: ' . $inputPath);
    }

    try {
        // Magic.
        $magic = rtrim((string) gzgets($gz), "\n");
        if ($magic !== 'IPAMBKL1') {
            throw new RuntimeException('ipam_restore_logical_apply: unrecognised magic: ' . var_export($magic, true));
        }

        // Header.
        $headerLine = (string) gzgets($gz);
        $header = json_decode($headerLine, true);
        if (!is_array($header) || ($header['header'] ?? false) !== true) {
            throw new RuntimeException('ipam_restore_logical_apply: missing or malformed header line');
        }
        $rawSchemaVersion    = $header['schema_version'] ?? 0;
        $sourceSchemaVersion = is_numeric($rawSchemaVersion) ? (int) $rawSchemaVersion : 0;
        $targetSchemaVersion = ipam_logical_schema_version($db);
        if ($sourceSchemaVersion > $targetSchemaVersion) {
            throw new RuntimeException(
                'ipam_restore_logical_apply: backup schema is newer than install — ' .
                "source=$sourceSchemaVersion, target=$targetSchemaVersion. Upgrade the install first."
            );
        }

        // FK bracket BEFORE transaction (sqlite rule).
        $closeBracket = ipam_logical_open_fk_bracket($db);
        $db->beginTransaction();

        // Wipe every replayable table so the dump's data fully replaces the
        // target's prior contents.
        //
        // Two exceptions:
        //   - schema_migrations: preserved because the target already ran
        //     apply_migrations() to reach a compatible schema_version, and
        //     that history must survive restore.
        //   - audit_log: append-only by trigger (audit_log_no_delete);
        //     DELETE would abort the transaction. Source's audit_log rows
        //     append to whatever the target carries — which is informative,
        //     not a corruption. user_id columns reference source IDs that
        //     no longer exist post-re-emit, but audit_log.user_id has no FK
        //     constraint and the username TEXT column preserves the
        //     human-readable identity.
        //
        // FK enforcement is off (bracketed above) so wipe order doesn't
        // matter; iterating in reverse topo order is still a polite gesture
        // for any DB engine that benefits from child-before-parent deletes.
        $tableOrder = ipam_logical_table_order($db);
        $skipWipe   = ['schema_migrations', 'audit_log'];
        foreach (array_reverse($tableOrder) as $t) {
            if (in_array($t, $skipWipe, true)) continue;
            $db->exec("DELETE FROM " . ipam_logical_q($db, $t));
        }

        $idmap = [];        // idmap[table][source_id] = target_id
        $selfFkPending = []; // [table => list of {source_pk, source_self_fk}]
        $totalRows = 0;
        $hashCtx   = hash_init('sha256');
        $footer    = null;

        try {
            while (!gzeof($gz)) {
                $line = gzgets($gz);
                if ($line === false || $line === '') break;
                $trim = rtrim($line, "\n");
                if ($trim === '') continue;

                $obj = json_decode($trim, true);
                if (!is_array($obj)) {
                    throw new RuntimeException('ipam_restore_logical_apply: malformed body line');
                }

                if (($obj['footer'] ?? false) === true) {
                    $footer = $obj;
                    break;
                }

                $table = $obj['table'] ?? null;
                $row   = $obj['row']   ?? null;
                if (!is_string($table) || !is_array($row)) {
                    throw new RuntimeException('ipam_restore_logical_apply: malformed row line (missing table/row)');
                }

                // Body checksum + total_rows count every body line, regardless of
                // whether the row is replayed — so the file's footer integrity is
                // independent of restore-side filter rules.
                hash_update($hashCtx, $line);
                $totalRows++;

                // schema_migrations rows are skipped on restore: the target install
                // ran apply_migrations() before this function was called, so it
                // already carries an equivalent migration history. The source's
                // schema_version (consulted earlier for compat) is the meaningful
                // signal; the row contents are redundant.
                if ($table === 'schema_migrations') {
                    continue;
                }

                // Narrow row keys to string for replay_row's array<string,mixed> signature.
                $rowNarrow = [];
                foreach ($row as $k => $v) {
                    $rowNarrow[(string) $k] = $v;
                }
                ipam_logical_replay_row($db, $table, $rowNarrow, $idmap, $selfFkPending);
            }

            if (!is_array($footer)) {
                throw new RuntimeException('ipam_restore_logical_apply: missing footer line');
            }
            $expectedChecksum = is_string($footer['checksum_sha256'] ?? null) ? $footer['checksum_sha256'] : '';
            $actualChecksum   = hash_final($hashCtx);
            if (!hash_equals($expectedChecksum, $actualChecksum)) {
                throw new RuntimeException(
                    "ipam_restore_logical_apply: body checksum mismatch — " .
                    "expected $expectedChecksum, got $actualChecksum"
                );
            }
            $rawTotal      = $footer['total_rows'] ?? -1;
            $expectedTotal = is_numeric($rawTotal) ? (int) $rawTotal : -1;
            if ($expectedTotal !== $totalRows) {
                throw new RuntimeException(
                    "ipam_restore_logical_apply: total_rows mismatch — " .
                    "footer=$expectedTotal, observed=$totalRows"
                );
            }

            // Pass 2 — self-referential UPDATEs.
            foreach ($selfFkPending as $tableKey => $pending) {
                $table = (string) $tableKey;
                foreach ($pending as $entry) {
                    $sourcePk     = $entry['source_pk'];
                    $sourceSelfFk = $entry['source_self_fk'];
                    $col          = $entry['col'];
                    $targetPk     = $idmap[$table][$sourcePk] ?? null;
                    $targetSelfFk = $idmap[$table][$sourceSelfFk] ?? null;
                    if ($targetPk === null || $targetSelfFk === null) {
                        // Either source row missing (shouldn't happen) or parent missing.
                        // Skip silently — pass 1 already inserted with NULL self-FK.
                        continue;
                    }
                    $stmt = $db->prepare(
                        "UPDATE " . ipam_logical_q($db, $table)
                        . " SET " . ipam_logical_q($db, $col) . " = :v WHERE id = :pk"
                    );
                    $stmt->execute([':v' => $targetSelfFk, ':pk' => $targetPk]);
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        } finally {
            $closeBracket();
        }

        return [
            'total_rows'     => $totalRows,
            'checksum_ok'    => true,
            'schema_version' => $sourceSchemaVersion,
        ];
    } finally {
        gzclose($gz);
    }
}

/**
 * Replay a single body row: remap FK columns, strip auto-increment PK,
 * INSERT, capture lastInsertId, update idmap. Self-referential FKs are
 * deferred to pass 2 by setting NULL initially and recording in
 * $selfFkPending.
 *
 * @param array<string,mixed>                              $row
 * @param array<string,array<int|string,int>>              $idmap
 * @param array<string,list<array{source_pk:int|string,source_self_fk:int|string,col:string}>> $selfFkPending
 */
function ipam_logical_replay_row(
    PDO $db,
    string $table,
    array $row,
    array &$idmap,
    array &$selfFkPending
): void {
    static $tableMeta = [];
    if (!isset($tableMeta[$table])) {
        $tableMeta[$table] = [
            'fks' => ipam_logical_introspect_fks($db, $table),
            'pk'  => ipam_logical_detect_autoincrement_pk($db, $table),
        ];
    }
    $fks = $tableMeta[$table]['fks'];
    $pk  = $tableMeta[$table]['pk'];

    // Decode every value back from wire form (binary $bin envelopes etc).
    $decoded = [];
    foreach ($row as $col => $val) {
        $decoded[(string) $col] = ipam_logical_decode_value($val);
    }

    // Remember source PK if the table has one.
    $sourcePk = null;
    if ($pk !== null && array_key_exists($pk, $decoded)) {
        $sourcePk = $decoded[$pk];
    }

    // FK remapping pass.
    $selfFkSnapshot = null;
    $selfFkCol      = null;
    foreach ($fks as $fk) {
        $col       = $fk['from'];
        $tgtTable  = $fk['table'];
        if (!array_key_exists($col, $decoded)) continue;
        $srcVal = $decoded[$col];
        if ($srcVal === null) continue;

        // Source FK value must be int or string — anything else is data corruption.
        if (!is_int($srcVal) && !is_string($srcVal)) {
            throw new RuntimeException(
                "ipam_restore_logical_apply: $table.$col FK value has unsupported type " . gettype($srcVal)
            );
        }

        if ($tgtTable === $table) {
            // Self-referential: defer to pass 2; insert with NULL.
            $selfFkSnapshot = $srcVal;
            $selfFkCol      = $col;
            $decoded[$col]  = null;
            continue;
        }
        $mapped = $idmap[$tgtTable][$srcVal] ?? null;
        if ($mapped === null) {
            $srcRepr = is_int($srcVal) ? (string) $srcVal : $srcVal;
            throw new RuntimeException(
                "ipam_restore_logical_apply: unresolved FK — $table.$col references $tgtTable.{$fk['to']} " .
                "with source value $srcRepr but no target row recorded. Likely cause: dump's table_order is " .
                "not topologically sorted parents-first."
            );
        }
        $decoded[$col] = $mapped;
    }

    // Strip auto-increment PK so target engine assigns a fresh one.
    if ($pk !== null && array_key_exists($pk, $decoded)) {
        unset($decoded[$pk]);
    }

    // INSERT with named placeholders. Postgres can't use lastInsertId() without
    // a sequence name, so it gets a RETURNING clause appended and reads back
    // the generated PK directly. SQLite and MySQL keep the lastInsertId() path.
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver     = is_string($driverAttr) ? $driverAttr : '';
    $cols   = array_keys($decoded);
    $colList = implode(', ', array_map(fn($c) => ipam_logical_q($db, (string) $c), $cols));
    $phList  = implode(', ', array_map(fn($c) => ':' . $c, $cols));
    $sql = "INSERT INTO " . ipam_logical_q($db, $table) . " ($colList) VALUES ($phList)";
    if ($driver === 'pgsql' && $pk !== null) {
        $sql .= " RETURNING " . ipam_logical_q($db, $pk);
    }
    $stmt = $db->prepare($sql);

    foreach ($cols as $c) {
        $val = $decoded[$c];
        $param = ':' . $c;
        $kind  = ipam_logical_column_kind((string) $c, $table);
        if ($kind === 'binary' && is_string($val)) {
            ipam_bind_binary($stmt, $param, $val);
        } elseif ($kind === 'timestamp' && $driver === 'mysql' && is_string($val)) {
            // MySQL DATETIME with default sql_mode rejects ISO-8601 'T'/'Z'
            // form — coerce 'YYYY-MM-DDTHH:MM:SSZ' → 'YYYY-MM-DD HH:MM:SS'.
            // sqlite and pgsql accept either form natively.
            $stmt->bindValue($param, ipam_logical_timestamp_for_mysql($val));
        } else {
            $stmt->bindValue($param, $val);
        }
    }
    $stmt->execute();

    // Record idmap entry + defer self-FK if applicable.
    if ($pk !== null && (is_int($sourcePk) || is_string($sourcePk))) {
        if ($driver === 'pgsql') {
            $returnedRaw = $stmt->fetchColumn();
            $targetPk = is_numeric($returnedRaw) ? (int) $returnedRaw : 0;
        } else {
            $targetPk = (int) $db->lastInsertId();
        }
        if (!isset($idmap[$table])) $idmap[$table] = [];
        $idmap[$table][$sourcePk] = $targetPk;

        if ($selfFkSnapshot !== null && $selfFkCol !== null) {
            // selfFkSnapshot was already type-narrowed in the FK-remap loop above
            // (only int|string survives that pass; anything else throws).
            $selfFkPending[$table][] = [
                'source_pk'      => $sourcePk,
                'source_self_fk' => $selfFkSnapshot,
                'col'            => $selfFkCol,
            ];
        }
    }
}
