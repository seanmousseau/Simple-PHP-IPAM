<?php
declare(strict_types=1);

/**
 * Orchestrates restore from a remote backup. Phase 12 builds the
 * download/decrypt/verify path; Phase 13 adds dry-run and live apply.
 */
final class RestoreEngine
{
    /** @param array<string,mixed> $config global $config */
    public function __construct(private PDO $db, private array $config) {}

    /**
     * Download a remote backup, decrypt if encrypted, verify checksum,
     * and stage the plain .sql.gz file in data/tmp/. Returns absolute path.
     *
     * @return array{path:string,size:int,filename:string,encrypted:bool}
     */
    public function prepareForRestore(int $destinationId, string $remoteName): array
    {
        $client = $this->clientFor($destinationId);

        // Sanity: reject any name with traversal characters before passing to client.
        // Backslash rejection is defence-in-depth: direct POSTs to download_remote_backup.php
        // can reach this method without first going through the remote_backups.php name-guard.
        if ($remoteName === ''
            || str_contains($remoteName, '/')
            || str_contains($remoteName, '\\')
            || str_contains($remoteName, "\0")
            || str_starts_with($remoteName, '.')) {
            throw new InvalidArgumentException('RestoreEngine: invalid remote name');
        }

        $tmpDir = dirname(__DIR__) . '/data/tmp';
        if (!is_dir($tmpDir)) {
            if (!@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
                throw new RuntimeException('RestoreEngine: cannot create tmp dir');
            }
        }
        $rand = bin2hex(random_bytes(8));
        $isEnc = str_ends_with($remoteName, '.enc');
        $stagedExt = $isEnc ? '.sql.gz' : (str_ends_with($remoteName, '.sql.gz') ? '.sql.gz' : '.bin');
        $downloadPath = $tmpDir . '/restore_dl_' . $rand;
        $stagedPath   = $tmpDir . '/restore_staged_' . $rand . $stagedExt;

        if (!$client->download($remoteName, $downloadPath)) {
            throw new RuntimeException('RestoreEngine: file not found on remote');
        }

        // Compute checksum of the on-the-wire blob BEFORE decryption,
        // to match how BackupEngine recorded it during upload.
        $observedHash = hash_file('sha256', $downloadPath);
        if ($observedHash === false) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- random local path
            @unlink($downloadPath);
            throw new RuntimeException('RestoreEngine: cannot hash downloaded file');
        }

        // Verify against backup_log row if one exists for this filename.
        // Mismatch is fatal — never apply a backup whose stored checksum disagrees.
        // Restrict to type='backup' rows — restore rows write the same filename
        // but their checksum field would not match (and may be NULL).
        $stmt = $this->db->prepare(
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
            throw new RuntimeException('RestoreEngine: checksum mismatch — refusing to stage file');
        }

        try {
            if ($isEnc) {
                $appSecret = is_string($this->config['app_secret'] ?? null) ? $this->config['app_secret'] : '';
                if ($appSecret === '') {
                    throw new RuntimeException('RestoreEngine: encrypted backup but app_secret is empty');
                }
                $cipherBlob = @file_get_contents($downloadPath);
                if ($cipherBlob === false) {
                    throw new RuntimeException('RestoreEngine: cannot read downloaded blob');
                }
                $plain = backup_decrypt($cipherBlob, $appSecret);
                if (@file_put_contents($stagedPath, $plain) === false) {
                    throw new RuntimeException('RestoreEngine: cannot write staged file');
                }
            } else {
                if (!@copy($downloadPath, $stagedPath)) {
                    throw new RuntimeException('RestoreEngine: cannot stage downloaded file');
                }
            }
        } finally {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- $downloadPath generated locally from random hex; tmpDir is project-controlled
            if (is_file($downloadPath)) @unlink($downloadPath);
        }

        $size = filesize($stagedPath);
        if ($size === false) {
            throw new RuntimeException('RestoreEngine: staged file size unreadable');
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
     * @param array{filename?:string,destination_id?:int,size?:int} $meta
     */
    public function sign(string $stagedPath, array $meta = []): string
    {
        $appSecret = is_string($this->config['app_secret'] ?? null) ? $this->config['app_secret'] : '';
        if ($appSecret === '') {
            throw new RuntimeException('RestoreEngine: cannot sign without app_secret');
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
     * @param array{filename?:string,destination_id?:int,size?:int} $meta
     */
    public function verifySigned(string $stagedPath, string $signature, array $meta = []): ?string
    {
        try {
            $expected = $this->sign($stagedPath, $meta);
        } catch (Throwable) {
            return null;
        }
        if (!hash_equals($expected, $signature)) return null;
        // Containment guard: must be under data/tmp/
        $tmpDir = dirname(__DIR__) . '/data/tmp';
        $real = realpath($stagedPath);
        if ($real === false) return null;
        if (!str_starts_with($real . '/', rtrim($tmpDir, '/') . '/')) return null;
        return $real;
    }

    /**
     * Parse a staged .sql.gz dump and report what restoring it would do,
     * without actually modifying the database. SQLite-only (matches BackupEngine).
     *
     * @return array{
     *   tables: list<array{name:string,current_rows:int,backup_rows:int,delta:int}>,
     *   schema_diff: list<string>,
     *   total_statements: int,
     *   warnings: list<string>,
     * }
     */
    public function dryRun(string $stagedPath): array
    {
        $driverAttr = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : '';
        if ($driver !== 'sqlite') {
            throw new RuntimeException('RestoreEngine: dry-run only supports sqlite in v3.17.0');
        }

        $sql = $this->readStagedSql($stagedPath);

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
                $r = $this->db->query("SELECT COUNT(*) FROM \"$name\"");
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
        $existingStmt = $this->db->query("SELECT name FROM sqlite_master WHERE type='table'");
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
    public function apply(string $stagedPath, string $realFilename = '', ?int $destinationId = null): array
    {
        $driverAttr = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : '';
        if ($driver !== 'sqlite') {
            throw new RuntimeException('RestoreEngine: apply only supports sqlite in v3.17.0');
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
            $matchStmt = $this->db->prepare(
                "SELECT destination_id FROM backup_log
                 WHERE filename = :f AND status = 'success'
                 ORDER BY started_at DESC LIMIT 1"
            );
            $matchStmt->execute([':f' => $filename]);
            $matched = $matchStmt->fetchColumn();
            if (is_numeric($matched)) $destId = (int) $matched;
        }

        $now = ipam_dialect()->now();
        $logStmt = $this->db->prepare(
            "INSERT INTO backup_log (destination_id, triggered_by, type, status, filename, started_at)
             VALUES (:d, 'web_restore', 'restore', 'running', :f, $now)"
        );
        $logStmt->execute([':d' => $destId, ':f' => $filename]);
        $logId = (int) $this->db->lastInsertId();

        $sql = $this->readStagedSql($stagedPath);

        $tablesSeen = [];
        $statements = 0;

        $this->db->beginTransaction();
        try {
            // SQLite needs FK off during bulk restore (mirror migration pattern)
            $this->db->exec('PRAGMA foreign_keys = OFF');

            foreach ($this->splitSqlStatements($sql) as $stmt) {
                if ($stmt === '' || str_starts_with(ltrim($stmt), '--')) continue;
                $this->db->exec($stmt);
                $statements++;
                if (preg_match('/^INSERT INTO ["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/', ltrim($stmt), $m)) {
                    $tablesSeen[$m[1]] = true;
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            // Mark log entry failed — swallow any logging error so the real exception propagates
            try {
                $nowF = ipam_dialect()->now();
                $updF = $this->db->prepare(
                    "UPDATE backup_log SET status = 'failed', error_message = :e, completed_at = $nowF WHERE id = :id"
                );
                $updF->execute([':e' => substr($e->getMessage(), 0, 1000), ':id' => $logId]);
            } catch (Throwable) { /* swallow */ }
            throw new RuntimeException('RestoreEngine: apply failed — ' . $e->getMessage(), 0, $e);
        } finally {
            $this->db->exec('PRAGMA foreign_keys = ON');
        }

        // Mark log entry success
        try {
            $now2 = ipam_dialect()->now();
            $upd = $this->db->prepare(
                "UPDATE backup_log SET status = 'success', size_bytes = :sz, completed_at = $now2 WHERE id = :id"
            );
            $upd->execute([':sz' => filesize($stagedPath) ?: 0, ':id' => $logId]);
        } catch (Throwable) { /* swallow logging failures */ }

        // Bring schema up to date if backup is from older version
        try {
            apply_migrations($this->db);
        } catch (Throwable $e) {
            // Don't fail the restore for migration issues; surface as warning via audit
            error_log('[restore] post-restore migrations failed: ' . $e->getMessage());
        }

        // Audit: significant destructive action. Records into audit_log so
        // operators can see who restored what and when, independent of the
        // backup_log row we already updated above.
        try {
            audit(
                $this->db,
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

    private function readStagedSql(string $stagedPath): string
    {
        // Containment guard: must be under data/tmp/. Defence-in-depth in case
        // an upstream signature/validation step is bypassed or refactored.
        $tmpDir = dirname(__DIR__) . '/data/tmp';
        $real = realpath($stagedPath);
        $tmpReal = realpath($tmpDir);
        if ($real === false || $tmpReal === false || !str_starts_with($real . '/', rtrim($tmpReal, '/') . '/')) {
            throw new RuntimeException('RestoreEngine: staged file is not under data/tmp/');
        }
        if (!is_file($real)) {
            throw new RuntimeException('RestoreEngine: staged file not found');
        }
        if (str_ends_with($real, '.sql.gz')) {
            $data = '';
            $fh = @gzopen($real, 'rb');
            if ($fh === false) throw new RuntimeException('RestoreEngine: gzopen failed');
            try {
                while (!gzeof($fh)) {
                    $chunk = gzread($fh, 65536);
                    if ($chunk === false) {
                        // gzread() returning false is a corruption error, NOT EOF.
                        // gzeof() catches end-of-file; reaching here means truncation.
                        throw new RuntimeException('RestoreEngine: gzread error — backup may be truncated');
                    }
                    $data .= $chunk;
                }
            } finally {
                gzclose($fh);
            }
            return $data;
        }
        $data = @file_get_contents($real);
        if ($data === false) throw new RuntimeException('RestoreEngine: cannot read staged file');
        return $data;
    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        // Naive splitter by semicolon at line end; sufficient for the
        // ipam_db_dump_stream output which is line-oriented and uses
        // ; LF as terminator. Skips multi-line BEGIN/END trigger bodies
        // by tracking depth.
        $out = [];
        $buf = '';
        $depth = 0;
        foreach (explode("\n", $sql) as $line) {
            $stripped = trim($line);
            if (preg_match('/\bBEGIN\b/i', $stripped) && !preg_match('/\bBEGIN TRANSACTION\b/i', $stripped)) {
                $depth++;
            }
            $buf .= $line . "\n";
            if (preg_match('/\bEND\s*;?\s*$/i', $stripped) && $depth > 0) {
                $depth--;
            }
            if ($depth === 0 && str_ends_with(rtrim($line), ';')) {
                $out[] = trim($buf);
                $buf = '';
            }
        }
        if (trim($buf) !== '') $out[] = trim($buf);
        return $out;
    }

    private function clientFor(int $id): BackupClientInterface
    {
        $stmt = $this->db->prepare("SELECT * FROM backup_destinations WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('RestoreEngine: destination not found');
        }
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        $cfgJson = is_string($row['config'] ?? null) ? $row['config'] : '{}';
        $cfg = json_decode($cfgJson, true);
        if (!is_array($cfg)) {
            throw new RuntimeException('RestoreEngine: destination config invalid');
        }
        /** @var array<string,mixed> $typedCfg */
        $typedCfg = [];
        foreach ($cfg as $k => $v) {
            if (is_string($k)) $typedCfg[$k] = $v;
        }
        return match ($type) {
            's3'    => new S3Client($typedCfg),
            'sftp'  => new SftpClient($typedCfg),
            'local' => new LocalBackupClient($typedCfg),
            default => throw new RuntimeException('RestoreEngine: unknown destination type'),
        };
    }
}
