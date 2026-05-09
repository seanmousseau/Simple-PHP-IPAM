<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Observability fix O3+O4 (Pass A 2026-05-08, v3.27.1).
 *
 * Before this fix, `ipam_backup_run_for_destination` inserted the
 * `backup_runs` row only AFTER dump + encrypt completed
 * (lib/backup.php:413). Anything that threw between the concurrency
 * check and INSERT produced ZERO forensic trace:
 *   - no backup_runs row → operator sees no failure in History UI
 *   - no `backup.failed` audit (only fired in upload-phase catch)
 *   - last_run_at advanced anyway (`finalize_schedule_run` runs in
 *     finally-like path), so the schedule looked healthy
 *   - stderr-only logging in cron, blackholed on prod's `>/dev/null 2>&1`
 *
 * Fix: wrap the pre-INSERT region in try/catch. On throw, INSERT a
 * backup_runs row with status='failed', synthetic filename
 * `(preflight-failed-<hex>)`, error_message=truncated $e->getMessage(),
 * write `backup.preflight_failed` audit (O4), then re-throw so the
 * caller's failure path (cron $fail) still runs.
 */
final class BackupPreflightFailureTest extends TestCase
{
    private PDO $db;
    /** @var array<string,mixed> */
    private array $config;
    private string $stagingDir;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $this->db->exec($schema);
        $this->db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($this->db);
        apply_migrations($this->db);

        // LocalBackupClient requires the staging path to be under data/.
        // Use a tmp subdir under Simple-PHP-IPAM/data/tmp/ so the orchestrator
        // gets past dest-client validation and into the encrypt block where
        // the bug we're fixing originally fired.
        $dataTmp = realpath(__DIR__ . '/../Simple-PHP-IPAM/data/tmp')
            ?: throw new RuntimeException('Simple-PHP-IPAM/data/tmp must exist for orchestrator tests');
        $this->stagingDir = $dataTmp . '/rt_preflight_' . bin2hex(random_bytes(4));
        mkdir($this->stagingDir, 0700, true);
        $this->db->prepare(
            "INSERT INTO backup_destinations (name, type, config, is_active, default_backup_type, default_encryption_mode, encrypt) " .
            "VALUES ('rt-local-stored', 'local', :cfg, 1, 'database', 'stored', 1)"
        )->execute([':cfg' => json_encode(['path' => $this->stagingDir])]);

        $this->config = ['app_secret' => ''];
        $GLOBALS['config'] = $this->config;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->stagingDir)) {
            foreach (glob($this->stagingDir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($this->stagingDir);
        }
    }

    /** @return array<string, mixed> */
    private function fetchAssoc(string $sql): array
    {
        $stmt = $this->db->query($sql);
        $this->assertNotFalse($stmt, "query failed: $sql");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "no row returned: $sql");
        return $row;
    }

    private function fetchInt(string $sql): int
    {
        $stmt = $this->db->query($sql);
        $this->assertNotFalse($stmt, "query failed: $sql");
        $val = $stmt->fetchColumn();
        return is_numeric($val) ? (int) $val : 0;
    }

    public function testPreflightFailureSurfacesInBackupRunsAndAudit(): void
    {
        $destId = $this->fetchInt("SELECT id FROM backup_destinations WHERE name = 'rt-local-stored'");
        $this->assertGreaterThan(0, $destId);

        $auditBefore = $this->fetchInt("SELECT COALESCE(MAX(id), 0) FROM audit_log");
        $runsBefore = $this->fetchInt("SELECT COUNT(*) FROM backup_runs");

        $threw = false;
        $thrownMessage = '';
        try {
            ipam_backup_run_for_destination($this->db, $this->config, $destId, 'manual', null);
        } catch (\Throwable $e) {
            $threw = true;
            $thrownMessage = $e->getMessage();
            $this->assertStringContainsString('vault', strtolower($thrownMessage));
        }
        $this->assertTrue($threw, 'orchestrator must throw when neither key is configured');

        // O3 contract: a backup_runs row exists with status=failed.
        $runsAfter = $this->fetchAssoc(
            "SELECT id, destination_id, status, filename, error_message FROM backup_runs ORDER BY id DESC LIMIT 1"
        );
        $this->assertSame($destId, to_int($runsAfter['destination_id'] ?? 0));
        $this->assertSame('failed', to_str($runsAfter['status'] ?? ''));
        $errorMessage = to_str($runsAfter['error_message'] ?? '');
        $this->assertNotEmpty($errorMessage, 'error_message must capture the throw cause');
        $this->assertStringContainsString('vault', strtolower($errorMessage));
        $this->assertStringStartsWith('(preflight-failed', to_str($runsAfter['filename'] ?? ''));

        $runsAfterCount = $this->fetchInt("SELECT COUNT(*) FROM backup_runs");
        $this->assertSame($runsBefore + 1, $runsAfterCount);

        // O4 contract: backup.preflight_failed audit row.
        $auditRow = $this->fetchAssoc(
            "SELECT action, entity_type, entity_id, details FROM audit_log " .
            "WHERE id > $auditBefore AND action = 'backup.preflight_failed'"
        );
        $this->assertSame('destination', to_str($auditRow['entity_type'] ?? ''));
        $this->assertSame($destId, to_int($auditRow['entity_id'] ?? 0));
        $this->assertNotEmpty(to_str($auditRow['details'] ?? ''));
    }
}
