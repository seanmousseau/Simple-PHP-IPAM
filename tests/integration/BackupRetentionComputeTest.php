<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * Tests for ipam_retention_compute_deletions() — the DB-aware planning step
 * that resolves schedule config + eligible-row filtering and dispatches to
 * the pure ipam_gfs_select_for_deletion() selector.
 *
 * Introduced by #826 split (compute / build_client / apply / orchestrator).
 * Selector unit tests live in BackupRetentionTest; this file covers only
 * the DB-shape behaviour the compute function adds on top.
 */
final class BackupRetentionComputeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA foreign_keys = ON');

        $this->db->exec("CREATE TABLE backup_destinations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            config TEXT NOT NULL DEFAULT '{}'
        )");
        $this->db->exec("CREATE TABLE backup_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            destination_id INTEGER NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
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
    }

    private function seedDestination(string $type = 'local'): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_destinations (name, type, config) VALUES ('Test', :t, '{}')"
        );
        $stmt->execute([':t' => $type]);
        return (int) $this->db->lastInsertId();
    }

    private function seedSchedule(int $destId, int $h, int $d, int $w, int $m, int $active = 1): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_schedules (destination_id, is_active,
             retention_hourly, retention_daily, retention_weekly, retention_monthly)
             VALUES (:did, :a, :h, :d, :w, :m)"
        );
        $stmt->execute([
            ':did' => $destId, ':a' => $active,
            ':h' => $h, ':d' => $d, ':w' => $w, ':m' => $m,
        ]);
    }

    private function seedRun(int $destId, string $startedAt, string $status = 'success', int $protected = 0): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_runs (destination_id, filename, status, is_protected, started_at)
             VALUES (:d, :f, :s, :p, :t)"
        );
        $stmt->execute([
            ':d' => $destId,
            ':f' => 'ipam-' . substr($startedAt, 0, 10) . '.enc',
            ':s' => $status,
            ':p' => $protected,
            ':t' => $startedAt,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function testThrowsWhenDestinationDoesNotExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ipam_retention_compute_deletions($this->db, 999);
    }

    public function testEmptyResultWhenNoRuns(): void
    {
        $destId = $this->seedDestination();
        $this->assertSame([], ipam_retention_compute_deletions($this->db, $destId));
    }

    public function testUsesDefaultsWhenNoSchedule(): void
    {
        // Default config is keep_hourly=0 / keep_daily=7 / keep_weekly=4 /
        // keep_monthly=3. With 5 backups all on distinct days, all win their
        // own daily slot → none pruned.
        $destId = $this->seedDestination();
        for ($i = 0; $i < 5; $i++) {
            $this->seedRun($destId, sprintf('2026-04-%02d 10:00:00', 24 + $i));
        }
        $ids = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertSame([], $ids, 'default config keeps 5 distinct daily backups');
    }

    public function testUsesScheduleConfigWhenPresent(): void
    {
        // Schedule keeps only 1 daily slot (and zero of everything else).
        // 3 distinct daily backups → 1 wins daily, 2 pruned.
        $destId = $this->seedDestination();
        $this->seedSchedule($destId, 0, 1, 0, 0);
        $idA = $this->seedRun($destId, '2026-04-26 10:00:00');
        $idB = $this->seedRun($destId, '2026-04-27 10:00:00');
        $idC = $this->seedRun($destId, '2026-04-28 10:00:00'); // newest

        $ids = ipam_retention_compute_deletions($this->db, $destId);
        sort($ids);
        $this->assertSame([$idA, $idB], $ids, 'newest wins single daily slot; older two pruned');
        $this->assertNotContains($idC, $ids);
    }

    public function testInactiveScheduleIsIgnored(): void
    {
        // is_active=0 schedule must NOT be used; defaults take over.
        $destId = $this->seedDestination();
        $this->seedSchedule($destId, 0, 1, 0, 0, 0);
        for ($i = 0; $i < 5; $i++) {
            $this->seedRun($destId, sprintf('2026-04-%02d 10:00:00', 24 + $i));
        }
        $ids = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertSame([], $ids, 'inactive schedule falls through to defaults; defaults keep all 5');
    }

    public function testProtectedRowsExcluded(): void
    {
        // Tight schedule: keep_daily=1. Protected rows must not be pruned and
        // must not even count toward slot capacity (compute filters them out
        // upstream of the selector).
        $destId = $this->seedDestination();
        $this->seedSchedule($destId, 0, 1, 0, 0);
        $idProtected = $this->seedRun($destId, '2026-04-26 10:00:00', 'success', 1);
        $idA         = $this->seedRun($destId, '2026-04-27 10:00:00');
        $idB         = $this->seedRun($destId, '2026-04-28 10:00:00'); // newest non-protected

        $ids = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertNotContains($idProtected, $ids, 'protected row never enters delete list');
        $this->assertContains($idA, $ids, 'older non-protected pruned (newest wins single daily)');
        $this->assertNotContains($idB, $ids);
    }

    public function testNonSuccessRunsExcluded(): void
    {
        // Failed/running/pruned rows must not be candidates.
        $destId = $this->seedDestination();
        $this->seedSchedule($destId, 0, 1, 0, 0);
        $this->seedRun($destId, '2026-04-26 10:00:00', 'failed');
        $this->seedRun($destId, '2026-04-27 10:00:00', 'running');
        $this->seedRun($destId, '2026-04-28 10:00:00', 'retention_pruned');
        $idAlive = $this->seedRun($destId, '2026-04-29 10:00:00', 'success');

        $ids = ipam_retention_compute_deletions($this->db, $destId);
        $this->assertSame([], $ids, 'single success row wins the only daily slot — nothing to prune');
        $this->assertNotContains($idAlive, $ids);
    }
}
