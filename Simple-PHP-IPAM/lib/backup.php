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
 *   4. Insert/update a row in backup_log.
 *   5. Apply GFS retention via ipam_backup_apply_retention().
 *
 * Pipeline (restore from a remote backup):
 *   1. Download the remote blob to data/tmp/ via the destination client.
 *   2. Verify SHA-256 against backup_log.checksum (#762 item 4).
 *   3. Decrypt if encrypted, stage as .sql.gz under data/tmp/.
 *   4. Sign the staged path so the wizard can hand it back safely.
 *   5. Dry-run, then apply with PRAGMA foreign_keys = OFF inside a transaction.
 *
 * Additive to the legacy v3.7.0 backup.php CLI which writes to backup_history;
 * this module writes to the v3.17 backup_log table.
 */

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
    ?int $nowEpoch = null
): array {
    $dest = ipam_backup_dest_load($db, $destId);
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
    $logId = ipam_backup_insert_log($db, $destId, $triggeredBy, 'running', $remoteName);

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

function ipam_backup_insert_log(PDO $db, int $destId, string $triggeredBy, string $status, string $filename): int
{
    $now = ipam_dialect()->now();
    $stmt = $db->prepare(
        "INSERT INTO backup_log (destination_id, triggered_by, status, filename, started_at)
         VALUES (:d, :t, :s, :f, $now)"
    );
    $stmt->execute([':d' => $destId, ':t' => $triggeredBy, ':s' => $status, ':f' => $filename]);
    return (int) $db->lastInsertId();
}

/** @param array{size:int,checksum:string} $meta */
function ipam_backup_update_log_success(PDO $db, int $logId, array $meta): void
{
    $now = ipam_dialect()->now();
    $stmt = $db->prepare(
        "UPDATE backup_log SET status='success', size_bytes=:sz, checksum=:cs, completed_at=$now WHERE id=:id"
    );
    $stmt->execute([':sz' => $meta['size'], ':cs' => $meta['checksum'], ':id' => $logId]);
}

function ipam_backup_update_log_failure(PDO $db, int $logId, string $error): void
{
    $now = ipam_dialect()->now();
    $stmt = $db->prepare(
        "UPDATE backup_log SET status='failed', error_message=:e, completed_at=$now WHERE id=:id"
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

    // Verify against backup_log row if one exists for this filename.
    // Mismatch is fatal — never apply a backup whose stored checksum disagrees.
    // Restrict to type='backup' rows — restore rows write the same filename
    // but their checksum field would not match (and may be NULL).
    $stmt = $db->prepare(
        "SELECT checksum FROM backup_log
         WHERE destination_id = :d AND filename = :f AND status = 'success'
           AND (type = 'backup' OR type IS NULL)
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

    // Log entry — track restore in backup_log for visibility on history page (#701).
    // Use the real backup filename (passed by caller) when available; fall back
    // to the staged tmp filename only if not provided. The staged tmp filename
    // (e.g. restore_staged_<rand>.sql.gz) is meaningless for history viewers.
    $filename = $realFilename !== '' ? $realFilename : basename($stagedPath);

    // destination_id: prefer explicit caller-supplied value (the same id that
    // staged the file). Fall back to filename lookup only if the caller didn't
    // know — and even then, prefer the most recent success across destinations
    // since same-name backups on multiple destinations are inherently ambiguous.
    $destId = $destinationId !== null && $destinationId > 0 ? $destinationId : null;
    if ($destId === null) {
        $matchStmt = $db->prepare(
            "SELECT destination_id FROM backup_log
             WHERE filename = :f AND status = 'success'
             ORDER BY started_at DESC LIMIT 1"
        );
        $matchStmt->execute([':f' => $filename]);
        $matched = $matchStmt->fetchColumn();
        if (is_numeric($matched)) $destId = (int) $matched;
    }

    $now = ipam_dialect()->now();
    $logStmt = $db->prepare(
        "INSERT INTO backup_log (destination_id, triggered_by, type, status, filename, started_at)
         VALUES (:d, 'web_restore', 'restore', 'running', :f, $now)"
    );
    $logStmt->execute([':d' => $destId, ':f' => $filename]);
    $logId = (int) $db->lastInsertId();

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
        // Mark log entry failed — swallow any logging error so the real exception propagates
        try {
            $nowF = ipam_dialect()->now();
            $updF = $db->prepare(
                "UPDATE backup_log SET status = 'failed', error_message = :e, completed_at = $nowF WHERE id = :id"
            );
            $updF->execute([':e' => substr($e->getMessage(), 0, 1000), ':id' => $logId]);
        } catch (Throwable) { /* swallow */ }
        throw new RuntimeException('ipam_restore: apply failed — ' . $e->getMessage(), 0, $e);
    } finally {
        $db->exec('PRAGMA foreign_keys = ON');
    }

    // Mark log entry success
    try {
        $now2 = ipam_dialect()->now();
        $upd = $db->prepare(
            "UPDATE backup_log SET status = 'success', size_bytes = :sz, completed_at = $now2 WHERE id = :id"
        );
        $upd->execute([':sz' => filesize($stagedPath) ?: 0, ':id' => $logId]);
    } catch (Throwable) { /* swallow logging failures */ }

    // Bring schema up to date if backup is from older version
    try {
        apply_migrations($db);
    } catch (Throwable $e) {
        // Don't fail the restore for migration issues; surface as warning via audit
        error_log('[restore] post-restore migrations failed: ' . $e->getMessage());
    }

    // Audit: significant destructive action. Records into audit_log so
    // operators can see who restored what and when, independent of the
    // backup_log row we already updated above.
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

        // ── single-quoted string ── '...' with '' escape
        if ($c === "'") {
            $buf .= "'";
            $i++;
            while ($i < $len) {
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
