<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_history.php';

/**
 * Tests for the History tab action handlers (#803):
 *   - ipam_backup_run_verify(): re-fetch from destination + sha256 compare
 *   - ipam_backup_run_delete(): protect-flag check + best-effort destination
 *                               delete + row delete
 *
 * Uses a Local destination pointed at a tmp dir so the file-side step is
 * exercised end-to-end without network. S3/SFTP paths are smoke-covered
 * by the Playwright spec.
 */
final class BackupAdminHistoryActionsTest extends TestCase
{
    private \PDO $db;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        // LocalBackupClient enforces a path-traversal guard: the destination
        // path must resolve under <app>/data/. Use a temp dir there so the
        // constructor accepts our test destination.
        $this->tmpDir = dirname(__DIR__) . '/Simple-PHP-IPAM/data/tmp/historyActions_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
        $config = ['path' => $this->tmpDir];
        $st = $this->db->prepare("INSERT INTO backup_destinations (id, name, type, config) VALUES (1, 'tmp-local', 'local', :c)");
        $st->execute([':c' => (string) json_encode($config, JSON_UNESCAPED_SLASHES)]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (scandir($this->tmpDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($this->tmpDir . '/' . $f);
            }
            @rmdir($this->tmpDir);
        }
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function seedRunWithFile(string $contents, array $overrides = []): int
    {
        $name = 'ipam-test.sql';
        file_put_contents($this->tmpDir . '/' . $name, $contents);
        $row = array_merge([
            'destination_id'  => 1,
            'backup_type'     => 'logical',
            'encryption_mode' => 'unencrypted',
            'triggered_by'    => 'manual',
            'status'          => 'success',
            'filename'        => $name,
            'size_bytes'      => strlen($contents),
            'checksum'        => hash('sha256', $contents),
            'started_at'      => '2026-05-01 02:00:00',
            'completed_at'    => '2026-05-01 02:00:01',
            'is_protected'    => 0,
            'source_version'  => '3.21.0',
        ], $overrides);
        $cols = array_keys($row);
        $sql = 'INSERT INTO backup_runs (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')';
        $st = $this->db->prepare($sql);
        $params = [];
        foreach ($row as $k => $v) {
            $params[':' . $k] = $v;
        }
        $st->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function testVerifyHappyPath(): void
    {
        $id = $this->seedRunWithFile('hello world');
        $result = ipam_backup_run_verify($this->db, $id);
        $this->assertTrue($result['ok']);
        $this->assertSame(hash('sha256', 'hello world'), $result['actual']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    public function testVerifyMismatch(): void
    {
        $id = $this->seedRunWithFile('hello world');
        // Corrupt the file on the destination after the row is recorded.
        file_put_contents($this->tmpDir . '/ipam-test.sql', 'tampered');
        $result = ipam_backup_run_verify($this->db, $id);
        $this->assertFalse($result['ok']);
        $this->assertSame(hash('sha256', 'hello world'), $result['expected']);
        $this->assertSame(hash('sha256', 'tampered'),    $result['actual']);
    }

    public function testVerifyMissingFile(): void
    {
        $id = $this->seedRunWithFile('hello world');
        unlink($this->tmpDir . '/ipam-test.sql');
        $result = ipam_backup_run_verify($this->db, $id);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('no_artifact', $result['error']);
    }

    public function testVerifyUnknownIdReturnsNotFound(): void
    {
        $result = ipam_backup_run_verify($this->db, 99999);
        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['error']);
    }
}
