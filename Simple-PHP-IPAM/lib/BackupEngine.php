<?php
declare(strict_types=1);

/**
 * Orchestrates a single backup run for a v3.17 destination:
 *   1. Dump the database to a gzipped tmp file (SQLite-only in v3.17.0)
 *   2. Optionally encrypt with AES-256-GCM via backup_encrypt()
 *   3. Upload via the destination-specific BackupClientInterface
 *   4. Insert/update a row in the new backup_log table
 *   5. Apply GFS retention via ipam_backup_apply_retention()
 *
 * Additive to the legacy v3.7.0 backup.php CLI which continues to write to
 * the legacy backup_history table. This engine writes to backup_log only.
 */
final class BackupEngine
{
    /** @param array<string,mixed> $config global $config */
    public function __construct(private PDO $db, private array $config) {}

    /**
     * Run backup for one destination.
     * @return array{log_id:int,filename:string,size:int,checksum:string,pruned:int}
     */
    public function runForDestination(int $destId, string $triggeredBy = 'manual'): array
    {
        $dest = $this->loadDestination($destId);
        $client = $this->clientFor($dest);

        $tmpSql = $this->dumpToTmp();

        $appSecret = is_string($this->config['app_secret'] ?? null) ? $this->config['app_secret'] : '';
        $encrypt = isset($dest['encrypt']) && (is_int($dest['encrypt']) || is_string($dest['encrypt']))
            ? (int) $dest['encrypt'] : 0;
        if ($encrypt === 1) {
            if ($appSecret === '') {
                @unlink($tmpSql); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSql is tempnam()-generated in dumpToTmp(), no user input
                throw new RuntimeException('BackupEngine: encryption requested but app_secret is empty');
            }
            $tmpFile = $this->encryptToTmp($tmpSql, $appSecret);
            @unlink($tmpSql); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSql is tempnam()-generated in dumpToTmp(), no user input
            $extension = '.enc';
        } else {
            $tmpFile = $tmpSql;
            $extension = '.sql.gz';
        }

        $remoteName = sprintf('ipam-backup-%s%s', gmdate('Ymd-His'), $extension);
        $logId = $this->insertLog($destId, $triggeredBy, 'running', $remoteName);

        try {
            $meta = $client->upload($tmpFile, $remoteName);
            $this->updateLogSuccess($logId, $meta);
            $size = $meta['size'];
            $checksum = $meta['checksum'];
        } catch (Throwable $e) {
            $this->updateLogFailure($logId, $e->getMessage());
            audit($this->db, 'backup.failed', 'destination', $destId,
                  'remote=' . $remoteName . ' error=' . substr($e->getMessage(), 0, 200));
            @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpFile is tempnam()-generated, no user input
            throw $e;
        }

        @unlink($tmpFile); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpFile is tempnam()-generated, no user input

        $pruned = 0;
        try {
            $pruned = ipam_backup_apply_retention($this->db, $destId);
        } catch (Throwable $e) {
            error_log('[backup] retention failed for destination ' . $destId . ': ' . $e->getMessage());
        }

        audit($this->db, 'backup.run', 'destination', $destId,
              'remote=' . $remoteName . ' size=' . $size . ' pruned=' . $pruned);

        return [
            'log_id' => $logId,
            'filename' => $remoteName,
            'size' => $size,
            'checksum' => $checksum,
            'pruned' => $pruned,
        ];
    }

    /** @return array<string,mixed> */
    private function loadDestination(int $id): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM backup_destinations WHERE id = :id AND is_active = 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('BackupEngine: destination not found or inactive');
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
    private function clientFor(array $dest): BackupClientInterface
    {
        $type = is_string($dest['type'] ?? null) ? $dest['type'] : '';
        $configJson = is_string($dest['config'] ?? null) ? $dest['config'] : '{}';
        $cfg = json_decode($configJson, true);
        if (!is_array($cfg)) {
            throw new RuntimeException('BackupEngine: destination config is not valid JSON');
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
            default => throw new RuntimeException('BackupEngine: unknown destination type ' . $type),
        };
    }

    private function dumpToTmp(): string
    {
        $driverAttr = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : '';
        if ($driver !== 'sqlite') {
            throw new RuntimeException(
                'BackupEngine: only sqlite dumping is supported in v3.17.0; MySQL/PostgreSQL backup pending follow-up'
            );
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ipambk_');
        if ($tmp === false) {
            throw new RuntimeException('BackupEngine: tempnam failed');
        }
        $tmpGz = $tmp . '.sql.gz';
        @rename($tmp, $tmpGz);
        $fh = @gzopen($tmpGz, 'wb9');
        if ($fh === false) {
            @unlink($tmpGz); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpGz derives from tempnam(); no user input
            throw new RuntimeException('BackupEngine: gzopen failed');
        }
        try {
            ipam_db_dump_stream($this->db, function (string $chunk) use ($fh): void {
                gzwrite($fh, $chunk);
            });
        } finally {
            gzclose($fh);
        }
        return $tmpGz;
    }

    private function encryptToTmp(string $srcPath, string $appSecret): string
    {
        $plain = @file_get_contents($srcPath);
        if ($plain === false) {
            throw new RuntimeException('BackupEngine: cannot read dump for encryption');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ipambkE_');
        if ($tmp === false) {
            throw new RuntimeException('BackupEngine: tempnam failed');
        }
        $cipher = backup_encrypt($plain, $appSecret);
        if (@file_put_contents($tmp, $cipher) === false) {
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmp is tempnam()-generated, no user input
            throw new RuntimeException('BackupEngine: cannot write encrypted blob');
        }
        return $tmp;
    }

    private function insertLog(int $destId, string $triggeredBy, string $status, string $filename): int
    {
        $now = ipam_dialect()->now();
        $stmt = $this->db->prepare(
            "INSERT INTO backup_log (destination_id, triggered_by, status, filename, started_at)
             VALUES (:d, :t, :s, :f, $now)"
        );
        $stmt->execute([':d' => $destId, ':t' => $triggeredBy, ':s' => $status, ':f' => $filename]);
        return (int) $this->db->lastInsertId();
    }

    /** @param array{size:int,checksum:string} $meta */
    private function updateLogSuccess(int $logId, array $meta): void
    {
        $now = ipam_dialect()->now();
        $stmt = $this->db->prepare(
            "UPDATE backup_log SET status='success', size_bytes=:sz, checksum=:cs, completed_at=$now WHERE id=:id"
        );
        $stmt->execute([':sz' => $meta['size'], ':cs' => $meta['checksum'], ':id' => $logId]);
    }

    private function updateLogFailure(int $logId, string $error): void
    {
        $now = ipam_dialect()->now();
        $stmt = $this->db->prepare(
            "UPDATE backup_log SET status='failed', error_message=:e, completed_at=$now WHERE id=:id"
        );
        $stmt->execute([':e' => substr($error, 0, 1000), ':id' => $logId]);
    }
}
