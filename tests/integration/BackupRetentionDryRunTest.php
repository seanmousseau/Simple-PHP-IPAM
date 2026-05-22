<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * Round-trip property test for the v3.25.0 retention rehome (#1044 D4):
 * "the dry-run preview is exactly what the apply pass deletes."
 *
 * The compute step (ipam_retention_compute_deletions) is the dry-run UI's
 * source of truth — operators see "what would be deleted" before clicking
 * Prune. The apply step (ipam_retention_apply_deletions) does the actual
 * delete. If the two ever drift (e.g. apply re-fetches the candidate set
 * and picks a different bucket due to a non-deterministic ORDER BY,
 * or apply mutates state mid-pass that re-shapes the next iteration's
 * bucket selection), the operator's preview lies — they delete one set
 * and another set survives, or vice-versa.
 *
 * This test asserts the two never drift: compute returns IDs S; passing S
 * back through apply deletes the rows whose IDs match S exactly, no more
 * and no less. Per-tier and protected-row exclusions are covered by
 * BackupRetentionTest / BackupRetentionComputeTest separately.
 */
final class BackupRetentionDryRunTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');

        // v3.25.0 retention columns live on backup_destinations directly.
        $this->db->exec("CREATE TABLE backup_destinations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            config TEXT NOT NULL DEFAULT '{}',
            retention_hourly INTEGER NOT NULL DEFAULT 0,
            retention_daily INTEGER NOT NULL DEFAULT 7,
            retention_weekly INTEGER NOT NULL DEFAULT 4,
            retention_monthly INTEGER NOT NULL DEFAULT 3
        )");
        $this->db->exec("CREATE TABLE backup_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            destination_id INTEGER NOT NULL,
            filename TEXT NOT NULL,
            status TEXT NOT NULL,
            is_protected INTEGER NOT NULL DEFAULT 0,
            started_at TEXT NOT NULL
        )");
        // ipam_retention_apply_deletions emits an audit row on success;
        // a minimal table is enough to absorb the INSERT.
        $this->db->exec("CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT, entity_type TEXT, entity_id INTEGER,
            user_id INTEGER, username TEXT, ip TEXT, user_agent TEXT,
            details TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
    }

    private function seedDestination(int $h, int $d, int $w, int $m): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_destinations (name, type, retention_hourly, retention_daily, retention_weekly, retention_monthly)
             VALUES ('Test', 'local', :h, :d, :w, :m)"
        );
        $stmt->execute([':h' => $h, ':d' => $d, ':w' => $w, ':m' => $m]);
        return (int) $this->db->lastInsertId();
    }

    private function seedRun(int $destId, string $startedAt, string $status = 'success', int $protected = 0): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_runs (destination_id, filename, status, is_protected, started_at)
             VALUES (:d, :f, :s, :p, :t)"
        );
        $stmt->execute([
            ':d' => $destId,
            ':f' => 'ipam-' . substr($startedAt, 0, 10) . '-' . md5($startedAt) . '.enc',
            ':s' => $status,
            ':p' => $protected,
            ':t' => $startedAt,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Stub client whose delete() always succeeds. Lets the apply pass
     * focus purely on the "did the row's ID match a candidate?" semantics
     * without needing a real S3 / SFTP / Local backend.
     */
    private function nullClient(): BackupClientInterface
    {
        return new class implements BackupClientInterface {
            public function upload(string $localPath, string $remoteName): array
            {
                return ['size' => 0, 'checksum' => ''];
            }
            public function download(string $remoteName, string $destPath): bool
            {
                return true;
            }
            public function listObjects(): array
            {
                return [];
            }
            public function delete(string $remoteName): bool
            {
                return true;
            }
            public function test(): array
            {
                return ['ok' => true, 'message' => '', 'latency_ms' => 0];
            }
        };
    }

    private function liveIds(int $destId): array
    {
        $st = $this->db->prepare(
            "SELECT id FROM backup_runs WHERE destination_id = :d AND status = 'success' ORDER BY id"
        );
        $st->execute([':d' => $destId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    private function prunedIds(int $destId): array
    {
        $st = $this->db->prepare(
            "SELECT id FROM backup_runs WHERE destination_id = :d AND status = 'retention_pruned' ORDER BY id"
        );
        $st->execute([':d' => $destId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testDryRunIsEmptyWhenBelowCapacity(): void
    {
        $destId = $this->seedDestination(0, 7, 4, 3);
        // Three daily runs, well below the daily=7 cap.
        $this->seedRun($destId, '2026-05-01 02:00:00');
        $this->seedRun($destId, '2026-05-02 02:00:00');
        $this->seedRun($destId, '2026-05-03 02:00:00');

        $preview = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertSame([], $preview);

        $applied = ipam_retention_apply_deletions($this->db, $this->nullClient(), $destId, $preview);
        $this->assertSame(0, $applied);
        $this->assertSame([], $this->prunedIds($destId));
    }

    public function testDryRunMatchesApplyExactly(): void
    {
        // 0/2/0/0 keeps two daily slots; older daily-bucket rows must prune.
        $destId = $this->seedDestination(0, 2, 0, 0);
        $ids = [];
        $ids[] = $this->seedRun($destId, '2026-05-01 02:00:00');
        $ids[] = $this->seedRun($destId, '2026-05-02 02:00:00');
        $ids[] = $this->seedRun($destId, '2026-05-03 02:00:00');
        $ids[] = $this->seedRun($destId, '2026-05-04 02:00:00');
        $ids[] = $this->seedRun($destId, '2026-05-05 02:00:00');

        $preview = ipam_retention_compute_deletions($this->db, $destId);
        sort($preview);

        $applied = ipam_retention_apply_deletions($this->db, $this->nullClient(), $destId, $preview);
        $this->assertSame(count($preview), $applied,
            'apply must mark every preview ID');
        $this->assertSame($preview, $this->prunedIds($destId),
            'apply must mark exactly the preview IDs and no others');

        $survivors = $this->liveIds($destId);
        sort($survivors);
        $expectedSurvivors = array_values(array_diff($ids, $preview));
        sort($expectedSurvivors);
        $this->assertSame($expectedSurvivors, $survivors,
            'every non-preview ID must survive');
    }

    public function testProtectedRowsExcludedFromBothDryRunAndApply(): void
    {
        $destId = $this->seedDestination(0, 1, 0, 0);
        $oldProtected = $this->seedRun($destId, '2026-05-01 02:00:00', 'success', 1);
        $this->seedRun($destId, '2026-05-02 02:00:00', 'success', 0);
        $this->seedRun($destId, '2026-05-03 02:00:00', 'success', 0);

        $preview = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertNotContains($oldProtected, $preview,
            'protected row must never appear in dry-run preview');

        ipam_retention_apply_deletions($this->db, $this->nullClient(), $destId, $preview);

        // Protected row must still be live, regardless of age.
        $live = $this->liveIds($destId);
        $this->assertContains($oldProtected, $live,
            'protected row must survive the apply pass');
    }

    public function testEmptyPreviewMakesApplyANoOp(): void
    {
        $destId = $this->seedDestination(0, 7, 4, 3);
        $this->seedRun($destId, '2026-05-01 02:00:00');
        $this->seedRun($destId, '2026-05-02 02:00:00');

        $preview = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertSame([], $preview);

        $applied = ipam_retention_apply_deletions($this->db, $this->nullClient(), $destId, $preview);
        $this->assertSame(0, $applied);
        $this->assertSame([], $this->prunedIds($destId),
            'no rows should change status when preview is empty');
    }
}
