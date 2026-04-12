<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the v2.3.0 scanner utility functions in lib.php.
 *
 * All tests operate on pure logic (parsing, stale detection) without
 * live network calls.  Database-backed helpers use an in-memory SQLite
 * database seeded with minimal fixture data.
 */
class ScannerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->query("PRAGMA foreign_keys = ON");
        return $pdo;
    }

    /**
     * Build a minimal DB with subnets, addresses, and scan_results tables.
     */
    private function makeFixtureDb(): PDO
    {
        $pdo = $this->makeDb();

        $pdo->query("CREATE TABLE subnets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cidr TEXT NOT NULL, ip_version INTEGER NOT NULL,
            network TEXT NOT NULL, network_bin BLOB NOT NULL, prefix INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT ''
        )");
        $pdo->query("CREATE TABLE addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL, ip TEXT NOT NULL, ip_bin BLOB NOT NULL,
            hostname TEXT NOT NULL DEFAULT '', owner TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '', grp TEXT NOT NULL DEFAULT '',
            mac TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'used',
            last_seen_at TEXT, is_stale INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(subnet_id, ip)
        )");
        $pdo->query("CREATE TABLE scan_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL, address_id INTEGER,
            ip TEXT NOT NULL, method TEXT NOT NULL,
            is_up INTEGER NOT NULL DEFAULT 0, latency_ms INTEGER,
            scanned_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $pdo->query("CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL, entity_type TEXT NOT NULL DEFAULT '',
            entity_id INTEGER, user_id INTEGER, username TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '', user_agent TEXT NOT NULL DEFAULT '',
            details TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        // One /24 subnet
        $pdo->prepare("INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix)
                   VALUES (1, '10.0.0.0/24', 4, '10.0.0.0', :nb, 24)")
            ->execute([':nb' => inet_pton('10.0.0.0')]);

        // Three addresses
        $ins = $pdo->prepare("INSERT INTO addresses (id, subnet_id, ip, ip_bin) VALUES (?,1,?,?)");
        foreach ([[1,'10.0.0.1'],[2,'10.0.0.2'],[3,'10.0.0.3']] as [$id, $ip]) {
            $ins->execute([$id, $ip, inet_pton($ip)]);
        }

        return $pdo;
    }

    // -----------------------------------------------------------------------
    // ipam_parse_arp_table() tests
    // -----------------------------------------------------------------------

    public function testParseArpTableSpaceSeparated(): void
    {
        $result = ipam_parse_arp_table("192.168.1.1 aa:bb:cc:dd:ee:ff\n192.168.1.2 11:22:33:44:55:66");
        $this->assertCount(2, $result);
        $this->assertSame('192.168.1.1', $result[0]['ip']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result[0]['mac']);
        $this->assertSame('192.168.1.2', $result[1]['ip']);
    }

    public function testParseArpTableTabSeparated(): void
    {
        $result = ipam_parse_arp_table("10.0.0.5\t00:11:22:33:44:55");
        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.5', $result[0]['ip']);
        $this->assertSame('00:11:22:33:44:55', $result[0]['mac']);
    }

    public function testParseArpTableCsvFormat(): void
    {
        $result = ipam_parse_arp_table("10.0.0.10,aa-bb-cc-dd-ee-ff");
        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.10', $result[0]['ip']);
        $this->assertSame('aa-bb-cc-dd-ee-ff', $result[0]['mac']);
    }

    public function testParseArpTableIgnoresCommentLines(): void
    {
        $result = ipam_parse_arp_table("# header line\n192.168.1.1 aa:bb:cc:dd:ee:ff\n# another comment");
        $this->assertCount(1, $result);
    }

    public function testParseArpTableIgnoresInvalidLines(): void
    {
        $result = ipam_parse_arp_table(
            "not-an-ip aa:bb:cc:dd:ee:ff\n192.168.1.1 not-a-mac\njunk\n10.0.0.1 00:11:22:33:44:55"
        );
        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.1', $result[0]['ip']);
    }

    public function testParseArpTableEmptyInput(): void
    {
        $this->assertSame([], ipam_parse_arp_table(''));
        $this->assertSame([], ipam_parse_arp_table('   '));
    }

    public function testParseArpTableExtraColumnsIgnored(): void
    {
        // Linux `arp -a` style: hostname (ip) at mac [ether] on iface
        $result = ipam_parse_arp_table("router (10.0.0.1) at aa:bb:cc:dd:ee:ff [ether] on eth0");
        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.1', $result[0]['ip']);
        $this->assertSame('aa:bb:cc:dd:ee:ff', $result[0]['mac']);
    }

    // -----------------------------------------------------------------------
    // ipam_apply_arp_import() tests
    // -----------------------------------------------------------------------

    public function testApplyArpImportUpdatesMatchingAddresses(): void
    {
        $pdo = $this->makeFixtureDb();
        $entries = [
            ['ip' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff'],
            ['ip' => '10.0.0.2', 'mac' => '11:22:33:44:55:66'],
            ['ip' => '10.0.0.99', 'mac' => 'ff:ff:ff:ff:ff:ff'], // not in subnet
        ];

        $stats = ipam_apply_arp_import($pdo, $entries, 1);
        $this->assertSame(2, $stats['matched']);
        $this->assertSame(2, $stats['updated']);

        $row = $pdo->query("SELECT mac FROM addresses WHERE ip='10.0.0.1'")->fetch();
        $this->assertSame('aa:bb:cc:dd:ee:ff', $row['mac']);
    }

    public function testApplyArpImportSkipsUnchangedMac(): void
    {
        $pdo = $this->makeFixtureDb();
        $pdo->prepare("UPDATE addresses SET mac=? WHERE ip='10.0.0.1'")->execute(['aa:bb:cc:dd:ee:ff']);

        $stats = ipam_apply_arp_import($pdo, [['ip' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff']], 1);
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(0, $stats['updated']);
    }

    public function testApplyArpImportReturnsZeroForNoMatches(): void
    {
        $pdo = $this->makeFixtureDb();
        $stats = ipam_apply_arp_import($pdo, [['ip' => '172.16.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff']], 1);
        $this->assertSame(0, $stats['matched']);
        $this->assertSame(0, $stats['updated']);
    }

    // -----------------------------------------------------------------------
    // ipam_mark_stale_addresses() tests
    // -----------------------------------------------------------------------

    public function testMarkStaleAddressesAfterConsecutiveMisses(): void
    {
        $pdo = $this->makeFixtureDb();

        // Insert 3 consecutive down results for address 1
        $ins = $pdo->prepare("INSERT INTO scan_results (subnet_id, address_id, ip, method, is_up) VALUES (1,1,'10.0.0.1','icmp',0)");
        $ins->execute(); $ins->execute(); $ins->execute();

        $changed = ipam_mark_stale_addresses($pdo, 1, 3);
        $this->assertGreaterThanOrEqual(1, $changed);

        $row = $pdo->query("SELECT is_stale FROM addresses WHERE id=1")->fetch();
        $this->assertSame(1, (int) $row['is_stale']);
    }

    public function testMarkStaleAddressesClearsStaleWhenHostReturns(): void
    {
        $pdo = $this->makeFixtureDb();
        $pdo->prepare("UPDATE addresses SET is_stale=1 WHERE id=1")->execute();

        // Insert a successful scan result
        $pdo->prepare("INSERT INTO scan_results (subnet_id, address_id, ip, method, is_up) VALUES (1,1,'10.0.0.1','icmp',1)")->execute();

        $changed = ipam_mark_stale_addresses($pdo, 1, 3);
        $this->assertGreaterThanOrEqual(1, $changed);

        $row = $pdo->query("SELECT is_stale FROM addresses WHERE id=1")->fetch();
        $this->assertSame(0, (int) $row['is_stale']);
    }

    public function testMarkStaleAddressesDoesNotFlagWithNoScanData(): void
    {
        $pdo = $this->makeFixtureDb();
        $changed = ipam_mark_stale_addresses($pdo, 1, 3);
        $this->assertSame(0, $changed);

        $row = $pdo->query("SELECT COALESCE(SUM(is_stale),0) AS s FROM addresses")->fetch();
        $this->assertSame('0', (string) $row['s']);
    }

    public function testMarkStaleAddressesDoesNotFlagBelowThreshold(): void
    {
        $pdo = $this->makeFixtureDb();

        // Only 2 misses — threshold is 3
        $ins = $pdo->prepare("INSERT INTO scan_results (subnet_id, address_id, ip, method, is_up) VALUES (1,1,'10.0.0.1','icmp',0)");
        $ins->execute(); $ins->execute();

        $changed = ipam_mark_stale_addresses($pdo, 1, 3);
        $this->assertSame(0, $changed);

        $row = $pdo->query("SELECT is_stale FROM addresses WHERE id=1")->fetch();
        $this->assertSame(0, (int) $row['is_stale']);
    }
}
