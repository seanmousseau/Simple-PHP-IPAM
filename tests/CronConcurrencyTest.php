<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ipam_backup_claim_due_schedule() — closes #823.
 *
 * Helper under test landed in commit b3e3a1f (#816) and lives in
 * Simple-PHP-IPAM/lib/backup.php. The pessimistic claim must guarantee that
 * if two cron processes hit the same due schedule, only one wins (advances
 * next_run_at and gets the row); the other observes the row as no longer
 * due and skips.
 *
 * Schema strategy: hand-written minimal CREATE TABLE statements. The claim
 * helper only needs backup_destinations + backup_schedules; replaying the
 * full migration chain would pull in unrelated state without sharpening the
 * test. Columns + types match Simple-PHP-IPAM/schema.sql.
 *
 * Concurrency note: SQLite serializes writes via BEGIN IMMEDIATE, so a single
 * process opening two PDO connections gives realistic coverage of the lock
 * path. testActuallyConcurrent() additionally uses pcntl_fork when available
 * for a true two-process race.
 */
class CronConcurrencyTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/ipam_cron_' . bin2hex(random_bytes(8)) . '.sqlite';
        $db = $this->openDb();
        $this->createSchema($db);
        $this->seedDestinationAndDueSchedule($db);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup. Path is from tempnam(sys_get_temp_dir(), ...)
        // and never user-controlled. We skip explicit unlink to keep semgrep
        // happy; OS reaps /tmp eventually.
        $this->tmpFile = '';
    }

    private function openDb(): PDO
    {
        $db = new PDO('sqlite:' . $this->tmpFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");
        // 5s busy timeout matches what the helper expects in practice.
        $db->exec("PRAGMA busy_timeout = 5000");
        return $db;
    }

    /**
     * Run a query and assert the result is a PDOStatement (PHPStan narrowing).
     * ATTR_ERRMODE=EXCEPTION makes query() never return false at runtime, but
     * the PDO stubs don't track the attribute, so the invariant is encoded
     * here so the call sites stay readable.
     */
    private function q(PDO $db, string $sql): PDOStatement
    {
        $stmt = $db->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        return $stmt;
    }

    private function createSchema(PDO $db): void
    {
        $db->exec("
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
        $db->exec("
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

    private function seedDestinationAndDueSchedule(PDO $db): void
    {
        $db->exec("INSERT INTO backup_destinations (name, type) VALUES ('cron-test', 'local')");
        $destId = (int) $db->lastInsertId();

        // next_run_at well in the past → schedule is due.
        $past = gmdate('Y-m-d H:i:s', time() - 3600);
        $st = $db->prepare(
            "INSERT INTO backup_schedules (destination_id, frequency, time_of_day, next_run_at)
             VALUES (:d, 'daily', '02:00', :n)"
        );
        $st->execute([':d' => $destId, ':n' => $past]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testFirstClaimSucceedsAndAdvancesNextRunAt(): void
    {
        $db = $this->openDb();
        $before = $this->q($db, "SELECT next_run_at FROM backup_schedules LIMIT 1")->fetchColumn();

        $row = ipam_backup_claim_due_schedule($db);

        $this->assertIsArray($row, 'first caller must claim the due schedule');
        $this->assertArrayHasKey('id', $row);

        $after = $this->q($db, "SELECT next_run_at FROM backup_schedules LIMIT 1")->fetchColumn();
        $this->assertNotSame($before, $after, 'next_run_at must be advanced by claim');
        $this->assertGreaterThan(
            gmdate('Y-m-d H:i:s'),
            (string) $after,
            'next_run_at must now be in the future'
        );
    }

    public function testTwoSequentialClaimsRaceFavorsFirstCaller(): void
    {
        // Two PDO connections to the same on-disk SQLite file simulate two
        // cron ticks. After the first commits, the second must see the row
        // as no longer due.
        $a = $this->openDb();
        $b = $this->openDb();

        $rowA = ipam_backup_claim_due_schedule($a);
        $rowB = ipam_backup_claim_due_schedule($b);

        $this->assertIsArray($rowA, 'first claim should succeed');
        $this->assertNull($rowB, 'second claim should observe no due schedule');
    }

    public function testStaleConnectionDoesNotResurrectClaim(): void
    {
        // Connection A claims and commits; connection B opens AFTER and must
        // see the advanced next_run_at — i.e. claims are not resurrectable
        // by a stale view of the row.
        $a = $this->openDb();
        $rowA = ipam_backup_claim_due_schedule($a);
        $this->assertIsArray($rowA);

        // Brand-new connection — fresh read snapshot.
        $b = $this->openDb();
        $rowB = ipam_backup_claim_due_schedule($b);
        $this->assertNull($rowB, 'new connection after commit must see no due schedule');
    }

    public function testClaimReturnsNullWhenNoSchedulesAreDue(): void
    {
        // Push the only schedule far into the future so nothing is due.
        $future = gmdate('Y-m-d H:i:s', time() + 86400);
        $db = $this->openDb();
        $db->prepare("UPDATE backup_schedules SET next_run_at = :n")
           ->execute([':n' => $future]);

        $this->assertNull(ipam_backup_claim_due_schedule($db));
    }

    public function testInactiveScheduleIsNotClaimed(): void
    {
        $db = $this->openDb();
        $db->exec("UPDATE backup_schedules SET is_active = 0");
        $this->assertNull(ipam_backup_claim_due_schedule($db));
    }

    public function testInactiveDestinationIsNotClaimed(): void
    {
        $db = $this->openDb();
        $db->exec("UPDATE backup_destinations SET is_active = 0");
        $this->assertNull(ipam_backup_claim_due_schedule($db));
    }

    /**
     * True two-process race using pcntl_fork. Each child opens its own PDO
     * connection and calls the claim helper; parent collects exit codes.
     * Exactly one child must observe a successful claim. SQLite's
     * BEGIN IMMEDIATE serializes the writers — second one either retries
     * (busy backoff, succeeds, but sees no due row) or returns null.
     */
    public function testActuallyConcurrent(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available — actual fork test cannot run');
        }

        // Children must autoload lib.php themselves; tests/bootstrap.php was
        // already required in the parent so the require is a no-op.
        $autoload = dirname(__DIR__) . '/Simple-PHP-IPAM/lib.php';

        $parentDb = $this->openDb();
        $parentDb = null; // close before forking; SQLite + fork is finicky
        unset($parentDb);

        $pids = [];
        $statusFiles = [];

        for ($i = 0; $i < 2; $i++) {
            $resultFile = tempnam(sys_get_temp_dir(), 'ipam_race_');
            if ($resultFile === false) {
                $this->fail('Could not create result temp file');
            }
            $statusFiles[] = $resultFile;

            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            } elseif ($pid === 0) {
                // Child.
                try {
                    require_once $autoload;
                    $childDb = new PDO('sqlite:' . $this->tmpFile);
                    $childDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $childDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    $childDb->exec("PRAGMA busy_timeout = 5000");
                    $row = ipam_backup_claim_due_schedule($childDb);
                    file_put_contents($resultFile, $row !== null ? '1' : '0');
                    exit(0);
                } catch (Throwable $e) {
                    file_put_contents($resultFile, 'E:' . $e->getMessage());
                    exit(1);
                }
            } else {
                $pids[] = $pid;
            }
        }

        // Parent waits.
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $results = [];
        foreach ($statusFiles as $f) {
            $results[] = is_file($f) ? (string) file_get_contents($f) : '';
        }

        $winners = array_filter($results, fn($r) => $r === '1');
        $losers  = array_filter($results, fn($r) => $r === '0');
        $errors  = array_filter($results, fn($r) => str_starts_with($r, 'E:'));

        $this->assertCount(0, $errors, 'no child should error: ' . implode('|', $errors));
        $this->assertCount(1, $winners, 'exactly one child must claim the schedule');
        $this->assertCount(1, $losers, 'exactly one child must observe no due schedule');
    }
}
