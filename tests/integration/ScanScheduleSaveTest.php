<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 Task 8.1 (#917) — unit tests for the consolidated scan-schedule
 * helpers ipam_scan_schedule_save() / ipam_scan_schedule_delete() in lib.php.
 *
 * These helpers replace the byte-identical upsert/delete blocks that
 * scan_schedule_save.php and subnets.php each shipped inline. The tests
 * exercise the helper directly against an in-memory SQLite database, in the
 * same harness style as ScannerTest.
 */
final class ScanScheduleSaveTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        // ipam_dialect() lazily falls back to SqliteDialect when no connection
        // has been bootstrapped, which is exactly the historical test default.
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // scan_schedules table matching schema.sql (UNIQUE(subnet_id) is what
        // the dialect upsert conflict-target relies on).
        $this->db->exec(
            "CREATE TABLE scan_schedules ("
            . "  id               INTEGER PRIMARY KEY AUTOINCREMENT,"
            . "  subnet_id        INTEGER NOT NULL,"
            . "  method           TEXT NOT NULL DEFAULT 'icmp',"
            . "  tcp_port         INTEGER,"
            . "  interval_minutes INTEGER NOT NULL DEFAULT 60,"
            . "  is_active        INTEGER NOT NULL DEFAULT 1,"
            . "  last_run_at      TEXT,"
            . "  created_at       TEXT NOT NULL DEFAULT (datetime('now')),"
            . "  updated_at       TEXT NOT NULL DEFAULT (datetime('now')),"
            . "  UNIQUE(subnet_id)"
            . ")"
        );
        // audit() inserts into audit_log; provide a table whose columns match
        // the production INSERT (user_id, username, action, entity_type,
        // entity_id, ip, user_agent, details) so the helper's audit call
        // succeeds without bootstrapping the full schema.
        $this->db->exec(
            "CREATE TABLE audit_log ("
            . "  id          INTEGER PRIMARY KEY AUTOINCREMENT,"
            . "  user_id     INTEGER,"
            . "  username    TEXT,"
            . "  action      TEXT NOT NULL,"
            . "  entity_type TEXT,"
            . "  entity_id   INTEGER,"
            . "  ip          TEXT,"
            . "  user_agent  TEXT,"
            . "  details     TEXT,"
            . "  created_at  TEXT NOT NULL DEFAULT (datetime('now'))"
            . ")"
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchSchedule(int $subnetId): ?array
    {
        $st = $this->db->prepare("SELECT * FROM scan_schedules WHERE subnet_id = :sid");
        $st->execute([':sid' => $subnetId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    public function testSaveInsertsANewSchedule(): void
    {
        ipam_scan_schedule_save($this->db, 42, 'icmp', null, 60, 1);

        $row = $this->fetchSchedule(42);
        self::assertNotNull($row);
        self::assertSame('icmp', $row['method']);
        self::assertNull($row['tcp_port']);
        self::assertSame(60, (int) $row['interval_minutes']);
        self::assertSame(1, (int) $row['is_active']);
    }

    public function testSaveUpdatesAnExistingScheduleInPlace(): void
    {
        ipam_scan_schedule_save($this->db, 42, 'icmp', null, 60, 1);
        ipam_scan_schedule_save($this->db, 42, 'tcp', 443, 15, 0);

        // Still exactly one row for the subnet — upsert, not a second insert.
        $count = $this->db->query("SELECT COUNT(*) AS c FROM scan_schedules")->fetch();
        self::assertSame(1, (int) $count['c']);

        $row = $this->fetchSchedule(42);
        self::assertNotNull($row);
        self::assertSame('tcp', $row['method']);
        self::assertSame(443, (int) $row['tcp_port']);
        self::assertSame(15, (int) $row['interval_minutes']);
        self::assertSame(0, (int) $row['is_active']);
    }

    public function testSaveWritesAnAuditEvent(): void
    {
        ipam_scan_schedule_save($this->db, 7, 'both', 22, 30, 1);

        $st = $this->db->query(
            "SELECT action, entity_type, entity_id, details FROM audit_log ORDER BY id DESC LIMIT 1"
        );
        $row = $st->fetch();
        self::assertIsArray($row);
        self::assertSame('scan.schedule_update', $row['action']);
        self::assertSame('subnet', $row['entity_type']);
        self::assertSame(7, (int) $row['entity_id']);
        self::assertSame('method=both interval=30m active=1', $row['details']);
    }

    public function testDeleteRemovesTheSchedule(): void
    {
        ipam_scan_schedule_save($this->db, 99, 'icmp', null, 60, 1);
        self::assertNotNull($this->fetchSchedule(99));

        ipam_scan_schedule_delete($this->db, 99);
        self::assertNull($this->fetchSchedule(99));
    }

    public function testDeleteWritesAnAuditEvent(): void
    {
        ipam_scan_schedule_save($this->db, 5, 'icmp', null, 60, 1);
        ipam_scan_schedule_delete($this->db, 5);

        $st = $this->db->query(
            "SELECT action, entity_type, entity_id, details FROM audit_log ORDER BY id DESC LIMIT 1"
        );
        $row = $st->fetch();
        self::assertIsArray($row);
        self::assertSame('scan.schedule_delete', $row['action']);
        self::assertSame('subnet', $row['entity_type']);
        self::assertSame(5, (int) $row['entity_id']);
        self::assertSame('', $row['details']);
    }

    public function testDeleteOfAbsentScheduleIsANoOp(): void
    {
        // No row exists; delete must not throw and must leave the table empty.
        ipam_scan_schedule_delete($this->db, 12345);
        $count = $this->db->query("SELECT COUNT(*) AS c FROM scan_schedules")->fetch();
        self::assertSame(0, (int) $count['c']);
    }
}
