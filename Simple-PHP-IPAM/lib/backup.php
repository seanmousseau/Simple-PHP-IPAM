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
                usleep(50_000 * ($attempt + 1));
                continue;
            }
            throw $e;
        }
    }
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
    ?int $nowEpoch = null,
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

    $dest = ipam_backup_dest_load($db, $destId);
    // Thread triggered_by into the destination row so ipam_backup_notify()
    // can pick the right scheduled-vs-manual notification setting (v3.22.0).
    $dest['triggered_by'] = $triggeredBy;
    $client = ipam_backup_dest_client($dest);

    $tmpSql = ipam_backup_dump_to_tmp($db);

    $appSecret = is_string($config['app_secret'] ?? null) ? $config['app_secret'] : '';
    $encrypt = isset($dest['encrypt']) && (is_int($dest['encrypt']) || is_string($dest['encrypt']))
        ? (int) $dest['encrypt'] : 0;
    if ($encrypt === 1) {
        if ($appSecret === '') {
            @unlink($tmpSql); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSql is tempnam()-generated, no user input
            throw new RuntimeException('ipam_backup: encryption requested but app_secret is empty');
        }
        $tmpFile = ipam_backup_encrypt_to_tmp($tmpSql, $appSecret);
        @unlink($tmpSql); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSql is tempnam()-generated, no user input
        $extension = '.enc';
    } else {
        $tmpFile = $tmpSql;
        $extension = '.sql.gz';
    }

    // Random 8-hex-char suffix prevents filename collisions when two
    // runs land in the same second (e.g. manual + scheduled overlap).
    $remoteName = sprintf('ipam-backup-%s-%s%s', gmdate('Ymd-His'), bin2hex(random_bytes(4)), $extension);
    $logId = ipam_backup_insert_log($db, $destId, $triggeredBy, 'running', $remoteName, $scheduleId);

    try {
        $meta = $client->upload($tmpFile, $remoteName);
        ipam_backup_update_log_success($db, $logId, $meta);
        $size = $meta['size'];
        $checksum = $meta['checksum'];
    } catch (Throwable $e) {
        ipam_backup_update_log_failure($db, $logId, $e->getMessage());
        audit($db, 'backup.failed', 'destination', $destId,
              'remote=' . $remoteName . ' error=' . substr($e->getMessage(), 0, 200));
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
        $pruned = ipam_backup_apply_retention($db, $destId, $nowEpoch);
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
 * Build the proc_open command + env for the configured DB driver's native
 * dump tool. Shared by the v3.17 remote-destination pipeline and the
 * legacy CLI backup runner so both stay in sync if a flag changes.
 *
 * Password is always passed via env (MYSQL_PWD / PGPASSWORD), never on
 * the command line.
 *
 * @param array<string,mixed> $config global $config
 * @return array{cmd: list<string>, env: array<string,string>}
 */
function ipam_backup_native_cmd(string $driver, array $config): array
{
    $dsn  = is_string($config['db_dsn']  ?? null) ? $config['db_dsn']  : '';
    $user = is_string($config['db_user'] ?? null) ? $config['db_user'] : '';
    $pass = is_string($config['db_pass'] ?? null) ? $config['db_pass'] : '';
    $existingEnv = getenv(); // returns array<string,string> when called without args

    // Mirror the running app's connection target. Substituting defaults for
    // omitted DSN keys would force TCP onto Unix-socket configs (mismatching
    // mysqldump/pg_dump against the wrong daemon or auth method) and could
    // silently dump the wrong database if dbname is absent. Build the cmd
    // exactly from what the DSN says; require dbname; otherwise omit flags
    // so the dump tool uses the same default lookup path PDO did.
    if ($driver === 'mysql') {
        if (!preg_match('/dbname=([^;]+)/i', $dsn, $m)) {
            throw new RuntimeException('ipam_backup_native_cmd: dbname missing from db_dsn');
        }
        $name = $m[1];
        $cmd  = ['mysqldump', '--single-transaction', '--routines'];
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
            'cmd' => $cmd,
            'env' => array_merge($existingEnv, ['MYSQL_PWD' => $pass]),
        ];
    }
    if ($driver === 'pgsql') {
        if (!preg_match('/dbname=([^;]+)/i', $dsn, $m)) {
            throw new RuntimeException('ipam_backup_native_cmd: dbname missing from db_dsn');
        }
        $name = $m[1];
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
            'cmd' => $cmd,
            'env' => array_merge($existingEnv, ['PGPASSWORD' => $pass]),
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
            try {
                $native = ipam_backup_native_cmd($driver, $cfg);
                // 10-minute deadline matches what an interactive admin would tolerate;
                // larger DBs that legitimately need more should run via cron instead.
                if (!backup_run_dump($native['cmd'], $native['env'], $tmpSql, 600)) {
                    throw new RuntimeException('ipam_backup: ' . $driver . ' dump failed (see error_log)');
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
 * Insert a backup_runs row for a destination-driven backup. Status starts as
 * 'running' (v3.21.0 enum) and gets promoted to 'success' or 'failed' on
 * completion. Encryption mode is captured from the destination's `encrypt`
 * flag — encrypted destinations write 'stored' (v3.17 IPAMBKP2 mode);
 * unencrypted destinations write 'unencrypted'.
 */
function ipam_backup_insert_log(
    PDO $db,
    int $destId,
    string $triggeredBy,
    string $status,
    string $filename,
    ?int $scheduleId = null
): int {
    $now = ipam_dialect()->now();

    // Resolve encryption mode from the destination's encrypt flag.
    $encMode = 'unencrypted';
    try {
        $eStmt = $db->prepare("SELECT encrypt FROM backup_destinations WHERE id = :id");
        $eStmt->execute([':id' => $destId]);
        $enc = $eStmt->fetchColumn();
        if ($enc !== false && (int) $enc === 1) {
            $encMode = 'stored';
        }
    } catch (Throwable) {
        // best-effort; falls back to 'unencrypted' if the lookup fails
    }

    $stmt = $db->prepare(
        "INSERT INTO backup_runs " .
        "(destination_id, schedule_id, backup_type, encryption_mode, triggered_by, status, filename, source_version, started_at) " .
        "VALUES (:d, :sid, 'database', :em, :t, :s, :f, :sv, $now)"
    );
    $stmt->bindValue(':d',   $destId, PDO::PARAM_INT);
    if ($scheduleId === null) {
        $stmt->bindValue(':sid', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':sid', $scheduleId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':em',  $encMode);
    $stmt->bindValue(':t',   $triggeredBy);
    $stmt->bindValue(':s',   $status);
    $stmt->bindValue(':f',   $filename);
    $stmt->bindValue(':sv',  IPAM_VERSION);
    $stmt->execute();
    return (int) $db->lastInsertId();
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
            if ($appSecret === '') {
                throw new RuntimeException('ipam_restore: encrypted backup but app_secret is empty');
            }
            ipam_restore_assert_staged_path($stagedPath); // #762 item 3 — defence-in-depth before write
            backup_decrypt_to_path($downloadPath, $stagedPath, $appSecret);
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
 * @return array{
 *   tables: list<array{name:string,current_rows:int,backup_rows:int,delta:int}>,
 *   schema_diff: list<string>,
 *   total_statements: int,
 *   warnings: list<string>,
 * }
 */
function ipam_restore_dry_run(PDO $db, string $stagedPath): array
{
    $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $driver = is_string($driverAttr) ? $driverAttr : '';
    if ($driver !== 'sqlite') {
        throw new RuntimeException('ipam_restore: dry-run only supports sqlite in v3.17.0');
    }

    $sql = ipam_restore_read_staged_sql($stagedPath);

    // Count INSERT/CREATE statements for each table.
    $tableInsertCounts = [];
    $createdTables = [];
    $warnings = [];

    foreach (explode("\n", $sql) as $line) {
        $trim = ltrim($line);
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
 * Apply a staged backup to the database. Wraps in a transaction;
 * on any failure rolls back and throws.
 *
 * @return array{tables_restored:int,statements:int}
 */
function ipam_restore_apply(PDO $db, string $stagedPath, string $realFilename = '', ?int $destinationId = null): array
{
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

    $sql = ipam_restore_read_staged_sql($stagedPath);

    $tablesSeen = [];
    $statements = 0;

    // SQLite ignores PRAGMA foreign_keys = OFF inside an active transaction.
    // Set it BEFORE beginTransaction(); restore (defensively) afterwards even
    // on the failure path so the connection is left in the expected state.
    $db->exec('PRAGMA foreign_keys = OFF');
    $db->beginTransaction();
    try {
        foreach (ipam_restore_split_sql_statements($sql) as $stmt) {
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

function ipam_restore_read_staged_sql(string $stagedPath): string
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
        $data = '';
        $fh = @gzopen($real, 'rb');
        if ($fh === false) throw new RuntimeException('ipam_restore: gzopen failed');
        try {
            while (!gzeof($fh)) {
                $chunk = gzread($fh, 65536);
                if ($chunk === false) {
                    // gzread() returning false is a corruption error, NOT EOF.
                    // gzeof() catches end-of-file; reaching here means truncation.
                    throw new RuntimeException('ipam_restore: gzread error — backup may be truncated');
                }
                $data .= $chunk;
            }
        } finally {
            gzclose($fh);
        }
        return $data;
    }
    $data = @file_get_contents($real);
    if ($data === false) throw new RuntimeException('ipam_restore: cannot read staged file');
    return $data;
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
 * @return list<string>
 */
function ipam_restore_split_sql_statements(string $sql): array
{
    $out = [];
    $buf = '';
    $depth = 0;
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // ── line comment ── -- ... \n (or end of input)
        if ($c === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $buf .= $sql[$i];
                $i++;
            }
            continue;
        }

        // ── block comment ── /* ... */ (does not nest in standard SQL)
        if ($c === '/' && $next === '*') {
            $buf .= '/*';
            $i += 2;
            while ($i < $len) {
                if ($sql[$i] === '*' && $i + 1 < $len && $sql[$i + 1] === '/') {
                    $buf .= '*/';
                    $i += 2;
                    break;
                }
                $buf .= $sql[$i];
                $i++;
            }
            continue;
        }

        // ── single-quoted string ── '...' with '' or \' escape
        if ($c === "'") {
            $buf .= "'";
            $i++;
            while ($i < $len) {
                // Backslash escape (MySQL default `\'`, also `\\`, `\n`, etc.).
                // Consume the backslash and the following character verbatim
                // so an escaped quote can't terminate the string. Required for
                // MySQL dumps; harmless for SQLite/Postgres standard mode
                // where `\` is a literal byte. (CR feedback PR #1054.)
                if ($sql[$i] === "\\" && $i + 1 < $len) {
                    $buf .= $sql[$i] . $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === "'" && $i + 1 < $len && $sql[$i + 1] === "'") {
                    // Escaped quote: consume both as part of the literal
                    $buf .= "''";
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === "'") {
                    $buf .= "'";
                    $i++;
                    break;
                }
                $buf .= $sql[$i];
                $i++;
            }
            continue;
        }

        // ── double-quoted identifier ── "..."  (ANSI / PostgreSQL)
        if ($c === '"') {
            $buf .= '"';
            $i++;
            while ($i < $len) {
                if ($sql[$i] === '"') {
                    $buf .= '"';
                    $i++;
                    break;
                }
                $buf .= $sql[$i];
                $i++;
            }
            continue;
        }

        // ── backtick-quoted identifier ── `...`  (MySQL)
        if ($c === '`') {
            $buf .= '`';
            $i++;
            while ($i < $len) {
                if ($sql[$i] === '`') {
                    $buf .= '`';
                    $i++;
                    break;
                }
                $buf .= $sql[$i];
                $i++;
            }
            continue;
        }

        // ── PostgreSQL dollar-quoted string ── $tag$ ... $tag$
        if ($c === '$') {
            // Look for opening tag: $ <ident-chars> $
            $j = $i + 1;
            while ($j < $len) {
                $cj = $sql[$j];
                if ($cj === '$') {
                    break;
                }
                // Tag may only be identifier chars (letters, digits, underscore).
                if (!(($cj >= 'a' && $cj <= 'z') || ($cj >= 'A' && $cj <= 'Z') || ($cj >= '0' && $cj <= '9') || $cj === '_')) {
                    break;
                }
                $j++;
            }
            if ($j < $len && $sql[$j] === '$') {
                $tag = substr($sql, $i, $j - $i + 1); // includes the two $
                $buf .= $tag;
                $i = $j + 1;
                $tagLen = strlen($tag);
                while ($i < $len) {
                    if ($sql[$i] === '$' && substr($sql, $i, $tagLen) === $tag) {
                        $buf .= $tag;
                        $i += $tagLen;
                        break;
                    }
                    $buf .= $sql[$i];
                    $i++;
                }
                continue;
            }
            // Lone $ — fall through and treat as ordinary char
        }

        // ── BEGIN ... END block depth (only at top level / NORMAL state) ──
        // Detect a BEGIN word boundary that is not BEGIN TRANSACTION/WORK/;.
        // We're at NORMAL state at this point because all comment/quote/dollar
        // branches above continue'd.
        if (($c === 'B' || $c === 'b')
            && ($i === 0 || !ctype_alnum($sql[$i - 1]) && $sql[$i - 1] !== '_')
            && $i + 4 < $len
            && strcasecmp(substr($sql, $i, 5), 'BEGIN') === 0
            && ($i + 5 >= $len || !(ctype_alnum($sql[$i + 5]) || $sql[$i + 5] === '_'))) {
            // Look past whitespace for the next significant token.
            $k = $i + 5;
            while ($k < $len && ctype_space($sql[$k])) {
                $k++;
            }
            $isTxn = false;
            if ($k < $len) {
                $nextCh = $sql[$k];
                if ($nextCh === ';') {
                    $isTxn = true; // BEGIN; → transaction start
                } elseif ($k + 10 < $len && strcasecmp(substr($sql, $k, 11), 'TRANSACTION') === 0
                          && ($k + 11 >= $len || !(ctype_alnum($sql[$k + 11]) || $sql[$k + 11] === '_'))) {
                    $isTxn = true;
                } elseif ($k + 3 < $len && strcasecmp(substr($sql, $k, 4), 'WORK') === 0
                          && ($k + 4 >= $len || !(ctype_alnum($sql[$k + 4]) || $sql[$k + 4] === '_'))) {
                    $isTxn = true;
                } elseif ($k + 7 < $len && strcasecmp(substr($sql, $k, 8), 'IMMEDIATE') === 0) {
                    $isTxn = true; // SQLite: BEGIN IMMEDIATE
                } elseif ($k + 7 < $len && strcasecmp(substr($sql, $k, 8), 'DEFERRED') === 0) {
                    $isTxn = true; // SQLite: BEGIN DEFERRED
                } elseif ($k + 8 < $len && strcasecmp(substr($sql, $k, 9), 'EXCLUSIVE') === 0) {
                    $isTxn = true; // SQLite: BEGIN EXCLUSIVE
                }
            } else {
                $isTxn = true; // bare BEGIN at end of input
            }
            if (!$isTxn) {
                $depth++;
            }
            $buf .= substr($sql, $i, 5);
            $i += 5;
            continue;
        }

        // ── END decrements depth ──
        if (($c === 'E' || $c === 'e')
            && ($i === 0 || !ctype_alnum($sql[$i - 1]) && $sql[$i - 1] !== '_')
            && $i + 2 < $len
            && strcasecmp(substr($sql, $i, 3), 'END') === 0
            && ($i + 3 >= $len || !(ctype_alnum($sql[$i + 3]) || $sql[$i + 3] === '_'))
            && $depth > 0) {
            $depth--;
            $buf .= substr($sql, $i, 3);
            $i += 3;
            continue;
        }

        // ── statement terminator ──
        if ($c === ';' && $depth === 0) {
            $buf .= ';';
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            $i++;
            continue;
        }

        // Default: copy byte through.
        $buf .= $c;
        $i++;
    }

    $tail = trim($buf);
    if ($tail !== '') {
        $out[] = $tail;
    }
    return $out;
}
