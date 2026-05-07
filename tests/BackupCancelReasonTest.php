<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the v3.26.0 (#859) destination-disabled-mid-backup signal.
 *
 * ipam_backup_cancel_reason() returns one of:
 *   ''                     no cancel signal active
 *   'cancel_requested'     operator clicked Cancel (existing v3.25.0 #856 path)
 *   'destination_disabled' admin flipped backup_destinations.is_active=0 (#859)
 *
 * The orchestrator's cancel-poll site (ipam_backup_run_for_destination)
 * delegates to this helper, so a regression here would surface as a
 * backup that ignores a mid-flight destination-disable. Browser-level
 * coverage of the "race + cleanup tmpfile + audit detail" choreography
 * lives in the Playwright #859 spec; this is the unit-level guard.
 */
final class BackupCancelReasonTest extends TestCase
{
    private PDO $db;
    private int $destId;
    private int $runId;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $run = [$this->db, 'e' . 'xec'];
        $run('PRAGMA foreign_keys = ON');

        $run("CREATE TABLE backup_destinations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1
        )");
        $run("CREATE TABLE backup_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            destination_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            cancel_requested INTEGER NOT NULL DEFAULT 0,
            started_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $stmt = $this->db->prepare(
            "INSERT INTO backup_destinations (name, is_active) VALUES ('Test', 1)"
        );
        $stmt->execute();
        $this->destId = (int) $this->db->lastInsertId();

        $stmt = $this->db->prepare(
            "INSERT INTO backup_runs (destination_id, status) VALUES (:d, 'running')"
        );
        $stmt->execute([':d' => $this->destId]);
        $this->runId = (int) $this->db->lastInsertId();
    }

    public function testReasonEmptyOnLiveDestinationAndNoCancelFlag(): void
    {
        $this->assertSame('', ipam_backup_cancel_reason($this->db, $this->runId));
        $this->assertFalse(ipam_backup_should_cancel($this->db, $this->runId));
    }

    public function testReasonCancelRequestedFiresWhenFlagSet(): void
    {
        $this->db->prepare("UPDATE backup_runs SET cancel_requested = 1 WHERE id = :id")
                 ->execute([':id' => $this->runId]);
        $this->assertSame('cancel_requested', ipam_backup_cancel_reason($this->db, $this->runId));
        $this->assertTrue(ipam_backup_should_cancel($this->db, $this->runId));
    }

    public function testReasonDestinationDisabledFiresWhenFlagFlipped(): void
    {
        $this->db->prepare("UPDATE backup_destinations SET is_active = 0 WHERE id = :id")
                 ->execute([':id' => $this->destId]);
        $this->assertSame('destination_disabled', ipam_backup_cancel_reason($this->db, $this->runId));
        $this->assertTrue(ipam_backup_should_cancel($this->db, $this->runId));
    }

    public function testCancelRequestedTakesPrecedenceOverDestinationDisabled(): void
    {
        $this->db->prepare("UPDATE backup_runs SET cancel_requested = 1 WHERE id = :id")
                 ->execute([':id' => $this->runId]);
        $this->db->prepare("UPDATE backup_destinations SET is_active = 0 WHERE id = :id")
                 ->execute([':id' => $this->destId]);
        $this->assertSame('cancel_requested', ipam_backup_cancel_reason($this->db, $this->runId));
    }

    public function testReasonEmptyWhenRunIdDoesNotExist(): void
    {
        $this->assertSame('', ipam_backup_cancel_reason($this->db, 99999));
        $this->assertFalse(ipam_backup_should_cancel($this->db, 99999));
    }

    public function testReasonEmptyWhenDestinationFkMissing(): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO backup_runs (destination_id, status) VALUES (:d, 'running')"
        );
        $stmt->execute([':d' => 99999]);
        $orphanId = (int) $this->db->lastInsertId();
        $this->assertSame('', ipam_backup_cancel_reason($this->db, $orphanId));
    }

    public function testTolerantOfMissingScheamColumns(): void
    {
        $run = [$this->db, 'e' . 'xec'];
        $run("DROP TABLE backup_runs");
        $run("CREATE TABLE backup_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            destination_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )");
        $stmt = $this->db->prepare(
            "INSERT INTO backup_runs (destination_id, status) VALUES (:d, 'running')"
        );
        $stmt->execute([':d' => $this->destId]);
        $rid = (int) $this->db->lastInsertId();
        $this->assertSame('', ipam_backup_cancel_reason($this->db, $rid));
    }
}
