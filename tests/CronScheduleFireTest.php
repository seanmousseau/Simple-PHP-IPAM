<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cron tick fire-decision tests (#840, T7, v3.24.0).
 *
 * The plan called for a clock-injection seam, but the scheduler's actual
 * fire decision is `next_run_at <= NOW()` evaluated by the database, not
 * by PHP's time(). Past-dating next_run_at via direct insert exercises
 * the same decision deterministically without a code change to the
 * scheduler.
 *
 * CronConcurrencyTest already covers:
 *   - past-dated due schedule -> claimed + advanced
 *   - future-dated schedule   -> not claimed
 *   - serial double-claim     -> only first wins
 *
 * This file fills the remaining T7 cases:
 *   - is_active = 0 schedule       -> not claimed
 *   - destination is_active = 0    -> not claimed
 *   - both inactive bits flipped   -> not claimed
 *   - reactivating an inactive row -> fires on next tick
 *
 * Same minimal schema strategy as CronConcurrencyTest -- the claim helper
 * only touches backup_destinations + backup_schedules, so we don't need
 * to replay the full migration chain for sharper coverage.
 */
class CronScheduleFireTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/ipam_cron_fire_' . bin2hex(random_bytes(8)) . '.sqlite';
        $db = $this->openDb();
        $this->createSchema($db);
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && is_file($this->tmpFile)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpFile is sys_get_temp_dir() + random hex; no user input
            @unlink($this->tmpFile);
        }
        $this->tmpFile = '';
    }

    private function openDb(): PDO
    {
        $db = new PDO('sqlite:' . $this->tmpFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->ddl($db, "PRAGMA foreign_keys = ON");
        $this->ddl($db, "PRAGMA busy_timeout = 5000");
        return $db;
    }

    private function ddl(PDO $db, string $sql): void
    {
        $db->exec($sql);
    }

    private function createSchema(PDO $db): void
    {
        $this->ddl($db, "
            CREATE TABLE backup_destinations (
              id         INTEGER PRIMARY KEY AUTOINCREMENT,
              name       TEXT    NOT NULL,
              type       TEXT    NOT NULL,
              config     TEXT    NOT NULL DEFAULT '{}',
              encrypt    INTEGER NOT NULL DEFAULT 1,
              is_active  INTEGER NOT NULL DEFAULT 1,
              created_at TEXT    NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->ddl($db, "
            CREATE TABLE backup_schedules (
              id                INTEGER PRIMARY KEY AUTOINCREMENT,
              destination_id    INTEGER NOT NULL REFERENCES backup_destinations(id) ON DELETE CASCADE,
              frequency         TEXT    NOT NULL DEFAULT 'daily',
              time_of_day       TEXT    NOT NULL DEFAULT '02:00',
              day_of_week       INTEGER,
              day_of_month      INTEGER,
              retention_hourly  INTEGER NOT NULL DEFAULT 0,
              retention_daily   INTEGER NOT NULL DEFAULT 7,
              retention_weekly  INTEGER NOT NULL DEFAULT 4,
              retention_monthly INTEGER NOT NULL DEFAULT 3,
              is_active         INTEGER NOT NULL DEFAULT 1,
              last_run_at       TEXT,
              next_run_at       TEXT,
              created_at        TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
    }

    private function seedSchedule(PDO $db, int $destActive, int $schedActive): int
    {
        $db->prepare("INSERT INTO backup_destinations (name, type, is_active) VALUES (:n, 'local', :a)")
           ->execute([':n' => 'fire-test-' . bin2hex(random_bytes(2)), ':a' => $destActive]);
        $destId = (int) $db->lastInsertId();

        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $st = $db->prepare(
            "INSERT INTO backup_schedules (destination_id, frequency, time_of_day, next_run_at, is_active)
             VALUES (:d, 'daily', '02:00', :n, :a)"
        );
        $st->execute([':d' => $destId, ':n' => $past, ':a' => $schedActive]);
        return $destId;
    }

    public function testInactiveScheduleNotFired(): void
    {
        $db = $this->openDb();
        $this->seedSchedule($db, 1, 0); // dest active, schedule inactive

        $row = ipam_backup_claim_due_schedule($db);
        $this->assertNull($row, 'is_active=0 schedule must not be claimed even when due');

        // And the row's next_run_at must NOT have been advanced.
        $stmt = $db->query("SELECT next_run_at FROM backup_schedules LIMIT 1");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        // fetchColumn() returns false on no rows; casting that to string
        // would yield '' which silently passes the assertLessThan check.
        // Guard explicitly so a missing seed row fails loudly.
        $nra = $stmt->fetchColumn();
        $this->assertNotFalse($nra, 'expected one seeded schedule row');
        $this->assertLessThan(gmdate('Y-m-d H:i:s'), (string) $nra, 'next_run_at must remain in the past');
    }

    public function testInactiveDestinationNotFired(): void
    {
        $db = $this->openDb();
        $this->seedSchedule($db, 0, 1); // dest inactive, schedule active

        $row = ipam_backup_claim_due_schedule($db);
        $this->assertNull($row, 'destination is_active=0 must mask the schedule from cron');
    }

    public function testBothInactiveNotFired(): void
    {
        $db = $this->openDb();
        $this->seedSchedule($db, 0, 0);
        $this->assertNull(ipam_backup_claim_due_schedule($db));
    }

    public function testReactivatingScheduleFiresOnNextTick(): void
    {
        // Common operator flow: pause a schedule (is_active=0), unpause
        // it later. The unpaused schedule whose next_run_at is in the past
        // MUST fire on the next cron tick -- this is the "I just unpaused
        // it, why isn't it running?" loop nobody wants to debug.
        $db = $this->openDb();
        $this->seedSchedule($db, 1, 0);
        $this->assertNull(ipam_backup_claim_due_schedule($db));

        $this->ddl($db, "UPDATE backup_schedules SET is_active = 1");
        $row = ipam_backup_claim_due_schedule($db);
        $this->assertIsArray($row, 'reactivated schedule must fire on next tick');
        $this->assertArrayHasKey('id', $row);
    }

    public function testReactivatingDestinationFiresPendingSchedule(): void
    {
        // Mirror of the above for the destination side.
        $db = $this->openDb();
        $this->seedSchedule($db, 0, 1);
        $this->assertNull(ipam_backup_claim_due_schedule($db));

        $this->ddl($db, "UPDATE backup_destinations SET is_active = 1");
        $row = ipam_backup_claim_due_schedule($db);
        $this->assertIsArray($row);
    }
}
