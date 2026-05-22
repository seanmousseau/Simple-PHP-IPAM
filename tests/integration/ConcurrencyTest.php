<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Write-race concurrency tests for SQLite WAL mode (#466). */
final class ConcurrencyTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'ipam_conc_') ?: '/tmp/ipam_conc_test.sqlite';
        $db = $this->makeDb();
        $db->exec("CREATE TABLE subnets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cidr TEXT NOT NULL,
            ip_version INTEGER NOT NULL,
            network TEXT NOT NULL,
            network_bin BLOB NOT NULL,
            prefix INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT ''
        )");
        $db->exec("CREATE TABLE addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL REFERENCES subnets(id),
            ip TEXT NOT NULL,
            ip_bin BLOB NOT NULL,
            hostname TEXT NOT NULL DEFAULT '',
            owner TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            grp TEXT NOT NULL DEFAULT '',
            mac TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'used',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(subnet_id, ip)
        )");
        $db->prepare("INSERT INTO subnets (id, cidr, ip_version, network, network_bin, prefix) VALUES (1, '10.0.0.0/24', 4, '10.0.0.0', :nb, 24)")
           ->execute([':nb' => inet_pton('10.0.0.0')]);
    }

    protected function tearDown(): void
    {
        if ($this->dbPath === '') {
            return;
        }
        // Hardcode the temp dir so semgrep does not trace $this->dbPath as
        // user-controlled input — it comes from tempnam() in setUp().
        $dir = sys_get_temp_dir();
        $base = basename($this->dbPath);
        foreach (['', '-wal', '-shm'] as $suffix) {
            $target = $dir . DIRECTORY_SEPARATOR . $base . $suffix;
            if (file_exists($target)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use
                @unlink($target);
            }
        }
    }

    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA journal_mode = WAL");
        $pdo->exec("PRAGMA busy_timeout = 5000");
        $pdo->exec("PRAGMA foreign_keys = ON");
        return $pdo;
    }

    public function testConcurrentAddressInsertUniqueViolation(): void
    {
        $connA = $this->makeDb();
        $connB = $this->makeDb();

        $ipBin = inet_pton('10.0.0.1');
        $sql = "INSERT INTO addresses (subnet_id, ip, ip_bin) VALUES (1, '10.0.0.1', :bin)";

        $connA->prepare($sql)->execute([':bin' => $ipBin]);

        $this->expectException(PDOException::class);
        $connB->prepare($sql)->execute([':bin' => $ipBin]);
    }

    public function testScanLockDoesNotBlockShortSaves(): void
    {
        $connA = $this->makeDb();
        $connB = $this->makeDb();

        // Connection A: begin a read transaction (simulates scan holding a read lock)
        $connA->exec("BEGIN");
        $connA->query("SELECT * FROM addresses");

        // Connection B: insert an address (WAL allows concurrent reads + writes)
        $ipBin = inet_pton('10.0.0.5');
        $connB->prepare("INSERT INTO addresses (subnet_id, ip, ip_bin) VALUES (1, '10.0.0.5', :bin)")
              ->execute([':bin' => $ipBin]);

        $connA->exec("COMMIT");

        // Verify the insert landed
        $row = $connB->query("SELECT ip FROM addresses WHERE ip = '10.0.0.5'")->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('10.0.0.5', $row['ip']);
    }

    public function testConsistentSettingsRead(): void
    {
        $connA = $this->makeDb();
        $connB = $this->makeDb();

        // Connection A: begin a write transaction and update description
        $connA->exec("BEGIN");
        $connA->exec("UPDATE subnets SET description = 'modified' WHERE id = 1");

        // Connection B: read should see the old value (WAL snapshot isolation)
        $row = $connB->query("SELECT description FROM subnets WHERE id = 1")->fetch();
        $this->assertSame('', $row['description'], 'Should see old value before commit');

        // Connection A: commit
        $connA->exec("COMMIT");

        // Connection B: now should see the updated value
        $row = $connB->query("SELECT description FROM subnets WHERE id = 1")->fetch();
        $this->assertSame('modified', $row['description'], 'Should see new value after commit');
    }

    public function testLastWriterWinsSubnetUpdate(): void
    {
        $connA = $this->makeDb();
        $connB = $this->makeDb();

        $connA->exec("UPDATE subnets SET description = 'alpha' WHERE id = 1");
        $connB->exec("UPDATE subnets SET description = 'bravo' WHERE id = 1");

        // Both connections should see the last writer's value
        $rowA = $connA->query("SELECT description FROM subnets WHERE id = 1")->fetch();
        $rowB = $connB->query("SELECT description FROM subnets WHERE id = 1")->fetch();
        $this->assertSame('bravo', $rowA['description']);
        $this->assertSame('bravo', $rowB['description']);
    }
}
