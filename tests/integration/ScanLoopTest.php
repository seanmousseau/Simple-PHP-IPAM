<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared scan-loop helpers in lib/scan.php (#1291).
 *
 * Covers:
 *   - ipam_scan_select_due_subnets(): returns only due, active schedules.
 *   - ipam_scan_run_for_subnet(): returns the expected result-array shape
 *     without live network calls (no addresses seeded -> scanned=0,up=0,down=0).
 *   - Audit-shape distinction: $source='cron' writes a scan.run audit row with
 *     a (cron) tag; $source='cli' writes no audit row.
 */
final class ScanLoopTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------------

    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /**
     * Minimal schema: subnets, scan_schedules, addresses, scan_results,
     * audit_log.  Mirrors ScannerTest::makeFixtureDb() without address rows so
     * no live probes are triggered inside ipam_scan_subnet().
     */
    private function makeFixtureDb(): PDO
    {
        $pdo = $this->makeDb();

        $pdo->exec("CREATE TABLE subnets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cidr TEXT NOT NULL,
            ip_version INTEGER NOT NULL DEFAULT 4,
            network TEXT NOT NULL DEFAULT '',
            network_bin BLOB NOT NULL DEFAULT '',
            prefix INTEGER NOT NULL DEFAULT 24,
            description TEXT NOT NULL DEFAULT ''
        )");

        $pdo->exec("CREATE TABLE scan_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL,
            method TEXT NOT NULL DEFAULT 'icmp',
            tcp_port INTEGER,
            interval_minutes INTEGER NOT NULL DEFAULT 60,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_run_at TEXT,
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(subnet_id)
        )");

        $pdo->exec("CREATE TABLE addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL,
            ip TEXT NOT NULL,
            ip_bin BLOB NOT NULL,
            hostname TEXT NOT NULL DEFAULT '',
            owner TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            grp TEXT NOT NULL DEFAULT '',
            mac TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'used',
            last_seen_at TEXT,
            is_stale INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(subnet_id, ip)
        )");

        $pdo->exec("CREATE TABLE scan_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL,
            address_id INTEGER,
            ip TEXT NOT NULL,
            method TEXT NOT NULL,
            is_up INTEGER NOT NULL DEFAULT 0,
            latency_ms INTEGER,
            scanned_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        // Schema mirrors schema.sql exactly: username/ip/user_agent are nullable
        // so audit() can INSERT with null when there is no active session (cron/CLI).
        $pdo->exec("CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            user_id INTEGER,
            username TEXT,
            action TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER,
            ip TEXT,
            user_agent TEXT,
            details TEXT
        )");

        // Seed three subnets.
        // CR #1307 #8: network_bin must be proper 4-byte binary (inet_pton) bound
        // via ipam_bind_binary()+PARAM_LOB — invariant #1 from CLAUDE.md. Seeding
        // as empty text '' diverges from the project invariant and can mask
        // regressions in scan helpers that read network_bin.
        $subnetSeeds = [
            [1, '10.0.1.0/24', '10.0.1.0', 24],
            [2, '10.0.2.0/24', '10.0.2.0', 24],
            [3, '10.0.3.0/24', '10.0.3.0', 24],
        ];
        foreach ($subnetSeeds as [$sid, $cidr, $network, $prefix]) {
            $st = $pdo->prepare(
                "INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix)
                 VALUES (:id, :cidr, 4, :net, :nb, :pfx)"
            );
            $netBin = (string) inet_pton($network);
            $st->bindValue(':id', $sid, PDO::PARAM_INT);
            $st->bindValue(':cidr', $cidr);
            $st->bindValue(':net', $network);
            ipam_bind_binary($st, ':nb', $netBin);
            $st->bindValue(':pfx', $prefix, PDO::PARAM_INT);
            $st->execute();
        }

        return $pdo;
    }

    /**
     * Seed a scan_schedule row.
     *
     * @param PDO         $pdo
     * @param int         $subnetId
     * @param int         $isActive       1=active, 0=inactive
     * @param string|null $lastRunAt      ISO-8601 UTC string, or null (never run)
     * @param int         $intervalMinutes
     */
    private function seedSchedule(
        PDO $pdo,
        int $subnetId,
        int $isActive,
        ?string $lastRunAt,
        int $intervalMinutes = 60
    ): void {
        $pdo->prepare(
            "INSERT INTO scan_schedules (subnet_id, is_active, last_run_at, interval_minutes)
             VALUES (:sid, :active, :last, :interval)"
        )->execute([
            ':sid'      => $subnetId,
            ':active'   => $isActive,
            ':last'     => $lastRunAt,
            ':interval' => $intervalMinutes,
        ]);
    }

    // -----------------------------------------------------------------------
    // ipam_scan_select_due_subnets() tests
    // -----------------------------------------------------------------------

    /**
     * Never-run active schedules are always due.
     */
    public function testSelectDueReturnsNeverRunSchedules(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $due = ipam_scan_select_due_subnets($pdo);

        $this->assertCount(1, $due);
        $this->assertSame(1, (int) $due[0]['id']);
    }

    /**
     * Inactive schedules are never returned, regardless of last_run_at.
     */
    public function testSelectDueSkipsInactiveSchedules(): void
    {
        $pdo = $this->makeFixtureDb();
        // Active but recently run -- not due
        $recentlyRun = gmdate('Y-m-d H:i:s', time() - 30);  // 30s ago
        $this->seedSchedule($pdo, 1, 1, $recentlyRun, 60);
        // Inactive and never run -- still must be excluded
        $this->seedSchedule($pdo, 2, 0, null, 60);

        $due = ipam_scan_select_due_subnets($pdo);

        // Subnet 1 ran 30s ago with a 60-min interval -- not yet due.
        // Subnet 2 is inactive -- never due.
        $this->assertCount(0, $due);
    }

    /**
     * Active schedule whose interval has elapsed is returned.
     * Active schedule whose interval has NOT elapsed is not returned.
     */
    public function testSelectDueFiltersOnInterval(): void
    {
        $pdo = $this->makeFixtureDb();

        // Subnet 1: ran 2 hours ago, interval = 60 min -> due
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);
        $this->seedSchedule($pdo, 1, 1, $twoHoursAgo, 60);

        // Subnet 2: ran 5 minutes ago, interval = 60 min -> NOT due
        $fiveMinAgo = gmdate('Y-m-d H:i:s', time() - 300);
        $this->seedSchedule($pdo, 2, 1, $fiveMinAgo, 60);

        $due = ipam_scan_select_due_subnets($pdo);

        $ids = array_map(static fn ($r) => (int) $r['id'], $due);
        $this->assertContains(1, $ids, 'Subnet 1 (overdue) must be selected');
        $this->assertNotContains(2, $ids, 'Subnet 2 (not yet due) must not be selected');
    }

    /**
     * All three combinations: one due, one not-due, one disabled.
     */
    public function testSelectDueCombinedScenario(): void
    {
        $pdo = $this->makeFixtureDb();

        // Due: never run
        $this->seedSchedule($pdo, 1, 1, null, 60);
        // Not due: ran 2 minutes ago on a 60-minute interval
        $twoMinAgo = gmdate('Y-m-d H:i:s', time() - 120);
        $this->seedSchedule($pdo, 2, 1, $twoMinAgo, 60);
        // Disabled: never run but is_active=0
        $this->seedSchedule($pdo, 3, 0, null, 60);

        $due = ipam_scan_select_due_subnets($pdo);
        $ids = array_map(static fn ($r) => (int) $r['id'], $due);

        $this->assertCount(1, $due, 'Exactly one subnet should be due');
        $this->assertSame([1], $ids);
    }

    /**
     * Returned rows must include 'id', 'cidr', 'method', 'interval_minutes',
     * 'last_run_at'.
     */
    public function testSelectDueRowShape(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $due = ipam_scan_select_due_subnets($pdo);

        $this->assertCount(1, $due);
        $row = $due[0];
        foreach (['id', 'cidr', 'method', 'interval_minutes', 'last_run_at'] as $key) {
            $this->assertArrayHasKey($key, $row, "Row must have '$key' key");
        }
    }

    // -----------------------------------------------------------------------
    // ipam_scan_run_for_subnet() result-shape tests (no live network calls)
    // -----------------------------------------------------------------------

    /**
     * Calling with source='cli' returns the expected result-array shape.
     * No addresses seeded -> scanned=0, up=0, down=0.
     */
    public function testRunForSubnetCliResultShape(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $subnet = [
            'id'       => 1,
            'cidr'     => '10.0.1.0/24',
            'method'   => 'icmp',
            'tcp_port' => null,
        ];
        $result = ipam_scan_run_for_subnet($pdo, $subnet, 'cli');

        foreach (['scanned', 'up', 'down', 'stale_marked', 'elapsed_sec'] as $key) {
            $this->assertArrayHasKey($key, $result, "Result must have '$key' key");
        }
        $this->assertSame(0, $result['scanned']);
        $this->assertSame(0, $result['up']);
        $this->assertSame(0, $result['down']);
        $this->assertIsFloat($result['elapsed_sec']);
    }

    /**
     * Calling with source='cron' returns the same result-array shape.
     */
    public function testRunForSubnetCronResultShape(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $subnet = [
            'id'       => 1,
            'cidr'     => '10.0.1.0/24',
            'method'   => 'icmp',
            'tcp_port' => null,
        ];
        $result = ipam_scan_run_for_subnet($pdo, $subnet, 'cron');

        foreach (['scanned', 'up', 'down', 'stale_marked', 'elapsed_sec'] as $key) {
            $this->assertArrayHasKey($key, $result, "Result must have '$key' key");
        }
    }

    // -----------------------------------------------------------------------
    // Audit-shape distinction: cron vs cli (#1161 / PASS-C F-S2-04)
    // -----------------------------------------------------------------------

    /**
     * source='cron' must write exactly one scan.run audit row carrying (cron).
     */
    public function testRunForSubnetCronWritesAuditWithCronTag(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $subnet = [
            'id'       => 1,
            'cidr'     => '10.0.1.0/24',
            'method'   => 'icmp',
            'tcp_port' => null,
        ];
        ipam_scan_run_for_subnet($pdo, $subnet, 'cron');

        $rows = $pdo->query(
            "SELECT action, entity_type, entity_id, details
             FROM audit_log WHERE action = 'scan.run'"
        )->fetchAll();

        $this->assertCount(1, $rows, "source='cron' must write exactly one scan.run audit row");
        $this->assertSame('subnet', $rows[0]['entity_type']);
        $this->assertSame(1, (int) $rows[0]['entity_id']);
        $this->assertStringContainsString('(cron)', $rows[0]['details'],
            "cron audit row must carry the (cron) tag in details");
    }

    /**
     * source='cli' must NOT write any scan.run audit row.
     * (scan_run.php only outputs JSON to stdout; audit is the caller's concern.)
     */
    public function testRunForSubnetCliDoesNotWriteAudit(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $subnet = [
            'id'       => 1,
            'cidr'     => '10.0.1.0/24',
            'method'   => 'icmp',
            'tcp_port' => null,
        ];
        ipam_scan_run_for_subnet($pdo, $subnet, 'cli');

        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'scan.run'"
        )->fetchColumn();

        $this->assertSame(0, $count, "source='cli' must not write any scan.run audit row");
    }

    /**
     * last_run_at is updated in scan_schedules after a scan (both sources).
     */
    public function testRunForSubnetUpdatesLastRunAt(): void
    {
        $pdo = $this->makeFixtureDb();
        $this->seedSchedule($pdo, 1, 1, null, 60);

        $before = time();

        $subnet = [
            'id'       => 1,
            'cidr'     => '10.0.1.0/24',
            'method'   => 'icmp',
            'tcp_port' => null,
        ];
        ipam_scan_run_for_subnet($pdo, $subnet, 'cron');

        $row = $pdo->query(
            "SELECT last_run_at FROM scan_schedules WHERE subnet_id = 1"
        )->fetch();
        $this->assertNotNull($row['last_run_at'],
            'last_run_at must be set after ipam_scan_run_for_subnet()');

        $ts = strtotime($row['last_run_at'] . ' UTC');
        $this->assertGreaterThanOrEqual($before, $ts,
            'last_run_at must be >= the timestamp before the call');
    }
}
