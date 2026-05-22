<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * UpgradeReplayTest — verifies that apply_migrations() can run the full chain
 * from old SQLite fixture databases to the current schema without data loss.
 *
 * Rationale: the v2.2.1 bug silently deleted all address rows when upgrading
 * from v1.x because PRAGMA foreign_keys = ON caused DROP TABLE to cascade.
 * These tests catch that class of regression by running real fixture DBs
 * through the migration chain and asserting row counts are preserved.
 *
 * Fixtures are minimal hand-crafted SQLite databases, not live installs.
 * See tests/fixtures/upgrade/README.md for how to regenerate them.
 */
class UpgradeReplayTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function copyToTmp(string $fixturePath): string
    {
        $tmp = sys_get_temp_dir() . '/ipam_upgrade_test_' . uniqid() . '.sqlite';
        $this->assertTrue(copy($fixturePath, $tmp), "Could not copy fixture to {$tmp}");
        return $tmp;
    }

    private function openDb(string $path): PDO
    {
        $db = new PDO("sqlite:{$path}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    }

    private function countRows(PDO $db, string $table): int
    {
        try {
            $r = $db->query("SELECT COUNT(*) AS c FROM {$table}")?->fetch();
            return $r ? (int)$r['c'] : 0;
        } catch (Throwable) {
            return -1; // table may not yet exist
        }
    }

    /**
     * Core assertion: apply_migrations() on a copied fixture, then verify:
     *  - No row loss in addresses and subnets vs pre-migration count
     *  - schema_migrations has all migrations recorded
     *  - FK enforcement is re-enabled (ON) after migrations
     *  - Second apply_migrations() call is idempotent (no error, no new rows)
     */
    private function assertUpgradeSafe(string $fixturePath, int $expectedSubnets, int $expectedAddresses): void
    {
        $this->assertFileExists($fixturePath, "Fixture not found: {$fixturePath}");

        $tmp = $this->copyToTmp($fixturePath);
        try {
            $db = $this->openDb($tmp);

            // Baseline row counts before migration
            $subnetsBefore  = $this->countRows($db, 'subnets');
            $addressesBefore = $this->countRows($db, 'addresses');

            // Run migrations
            apply_migrations($db);

            // schema_migrations must exist and have entries
            $migCount = $this->countRows($db, 'schema_migrations');
            $this->assertGreaterThan(0, $migCount, 'schema_migrations is empty after apply_migrations()');

            // Row counts must be preserved or only grow (never shrink)
            $subnetsAfter   = $this->countRows($db, 'subnets');
            $addressesAfter = $this->countRows($db, 'addresses');
            $this->assertGreaterThanOrEqual(
                max($subnetsBefore, $expectedSubnets),
                $subnetsAfter,
                'Subnets were lost during migration'
            );
            $this->assertGreaterThanOrEqual(
                max($addressesBefore, $expectedAddresses),
                $addressesAfter,
                'Addresses were lost during migration (v2.2.1 regression)'
            );

            // FK enforcement must be ON after apply_migrations()
            $fkRow = $db->query("PRAGMA foreign_keys")?->fetch();
            $this->assertSame(1, (int)($fkRow['foreign_keys'] ?? 0), 'PRAGMA foreign_keys is OFF after migration');

            // Idempotency: second call must not throw or duplicate rows
            $applied2 = apply_migrations($db);
            $this->assertSame([], $applied2, 'Second apply_migrations() returned non-empty result');
            $this->assertSame($subnetsAfter,   $this->countRows($db, 'subnets'),   'Subnet count changed on idempotency pass');
            $this->assertSame($addressesAfter, $this->countRows($db, 'addresses'), 'Address count changed on idempotency pass');

        } finally {
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use
        }
    }

    // -------------------------------------------------------------------------
    // Test cases
    // -------------------------------------------------------------------------

    /**
     * Build the v2.2.1 regression fixture in-memory (no SQLite file required).
     *
     * This is the exact scenario that caused the data-loss bug:
     *   - subnets table WITHOUT vrf_id (pre-2.1.0-vrfs schema)
     *   - Populated with 3 subnets and 12 addresses
     *   - 2.1.0-vrfs migration NOT yet recorded
     *
     * Equivalent to what an operator would see upgrading from v1.18 or v1.19.
     */
    public function testV221RegressionShape(): void
    {
        // Build the pre-migration state in-memory using the same schema as
        // MigrationTest::makePreVrfDb() — correct migration IDs, correct tables.
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");

        $db->exec("CREATE TABLE schema_migrations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            version    TEXT    NOT NULL UNIQUE,
            applied_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL DEFAULT 'admin',
            is_active     INTEGER NOT NULL DEFAULT 1,
            created_at    TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE login_attempts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            ip           TEXT NOT NULL,
            attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE api_keys (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            key_hash    TEXT    NOT NULL UNIQUE,
            is_active   INTEGER NOT NULL DEFAULT 1,
            is_readonly INTEGER NOT NULL DEFAULT 0,
            description TEXT    NOT NULL DEFAULT '',
            created_by  INTEGER,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            last_used_at TEXT
        )");

        $db->exec("CREATE TABLE sites (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            parent_id   INTEGER REFERENCES sites(id) ON DELETE SET NULL,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE vlans (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            vlan_id     INTEGER NOT NULL,
            name        TEXT    NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE contacts (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL,
            email      TEXT NOT NULL DEFAULT '',
            phone      TEXT NOT NULL DEFAULT '',
            org        TEXT NOT NULL DEFAULT '',
            note       TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        // Pre-2.1.0-vrfs subnets table — no vrf_id, UNIQUE(cidr)
        $db->exec("CREATE TABLE subnets (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            cidr        TEXT    NOT NULL UNIQUE,
            ip_version  INTEGER NOT NULL,
            network     TEXT    NOT NULL,
            network_bin BLOB    NOT NULL,
            prefix      INTEGER NOT NULL,
            description TEXT    NOT NULL DEFAULT '',
            site_id     INTEGER,
            vlan_id     INTEGER,
            vlan_fk     INTEGER REFERENCES vlans(id) ON DELETE SET NULL,
            created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE tags (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT NOT NULL UNIQUE,
            colour     TEXT NOT NULL DEFAULT '#6c757d',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE subnet_tags (
            subnet_id INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
            tag_id    INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
            PRIMARY KEY (subnet_id, tag_id)
        )");
        $db->exec("CREATE TABLE alert_state (
            subnet_id       INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
            level           TEXT    NOT NULL,
            last_alerted_at TEXT    NOT NULL,
            PRIMARY KEY (subnet_id, level)
        )");

        $db->exec("CREATE TABLE addresses (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id        INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
            ip               TEXT    NOT NULL,
            ip_bin           BLOB    NOT NULL,
            hostname         TEXT    NOT NULL DEFAULT '',
            owner            TEXT    NOT NULL DEFAULT '',
            owner_contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
            note             TEXT    NOT NULL DEFAULT '',
            grp              TEXT    NOT NULL DEFAULT '',
            status           TEXT    NOT NULL DEFAULT 'used',
            mac              TEXT    NOT NULL DEFAULT '',
            expires_at       TEXT,
            created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
            updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE address_tags (
            address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
            tag_id     INTEGER NOT NULL REFERENCES tags(id)      ON DELETE CASCADE,
            PRIMARY KEY (address_id, tag_id)
        )");

        // Mirrors Simple-PHP-IPAM/schema.sql: user_id / username / ip /
        // user_agent / details are nullable. Migrations run with no logged-in
        // user, so audit() (C12 #933) binds username = NULL — a NOT NULL
        // column here would diverge from production and break the replay.
        $db->exec("CREATE TABLE audit_log (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at  TEXT NOT NULL DEFAULT (datetime('now')),
            user_id     INTEGER,
            username    TEXT,
            action      TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id   INTEGER,
            ip          TEXT,
            user_agent  TEXT,
            details     TEXT
        )");

        // Correct migration IDs — match actual migrations.php keys, all before 2.1.0-vrfs
        $alreadyApplied = [
            '0.3', '0.7', '0.9', '0.11', '0.12', '0.13', '0.14',
            '1.4', '1.9', '1.11', '1.12', '1.13', '1.19.0',
            '2.0.0-alert-state', '2.0.0-site-hierarchy', '2.0.0-tags', '2.0.0-vlans',
            '2.1.0-contacts',
        ];
        $ins = $db->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
        foreach ($alreadyApplied as $v) {
            $ins->execute([$v]);
        }

        // Insert 3 subnets and 12 addresses
        $insSubnet = $db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
             VALUES (?, 4, ?, ?, ?, ?)"
        );
        $insSubnet->execute(['10.0.0.0/24', '10.0.0.0', inet_pton('10.0.0.0'), 24, 'test subnet 1']);
        $insSubnet->execute(['10.0.1.0/24', '10.0.1.0', inet_pton('10.0.1.0'), 24, 'test subnet 2']);
        $insSubnet->execute(['10.0.2.0/24', '10.0.2.0', inet_pton('10.0.2.0'), 24, 'test subnet 3']);

        $insAddr = $db->prepare(
            "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, owner, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $addrRows = [
            [1,'10.0.0.1','h1','alice','used'], [1,'10.0.0.2','h2','bob','used'],
            [1,'10.0.0.3','h3','','used'],      [1,'10.0.0.4','h4','','reserved'],
            [2,'10.0.1.1','h5','','used'],      [2,'10.0.1.2','h6','','used'],
            [2,'10.0.1.3','h7','','used'],      [2,'10.0.1.4','h8','','free'],
            [3,'10.0.2.1','h9','','used'],      [3,'10.0.2.2','h10','','used'],
            [3,'10.0.2.3','h11','','used'],     [3,'10.0.2.4','h12','','used'],
        ];
        foreach ($addrRows as [$sid, $ip, $hn, $owner, $status]) {
            $insAddr->execute([$sid, $ip, inet_pton($ip), $hn, $owner, $status]);
        }

        $subnetsBefore  = $this->countRows($db, 'subnets');
        $addressesBefore = $this->countRows($db, 'addresses');

        // Run migrations — this is the regression scenario
        apply_migrations($db);

        $subnetsAfter   = $this->countRows($db, 'subnets');
        $addressesAfter = $this->countRows($db, 'addresses');

        $this->assertGreaterThanOrEqual($subnetsBefore, $subnetsAfter,
            'Subnets lost during migration (v2.2.1 regression)');
        $this->assertGreaterThanOrEqual($addressesBefore, $addressesAfter,
            'Addresses lost during migration — this is the v2.2.1 data-loss regression');
        $this->assertSame(12, $addressesAfter,
            "Expected 12 addresses after migration, got {$addressesAfter}");
        $this->assertSame(3, $subnetsAfter,
            "Expected 3 subnets after migration, got {$subnetsAfter}");

        // FK must be ON
        $fkRow = $db->query("PRAGMA foreign_keys")?->fetch();
        $this->assertSame(1, (int)($fkRow['foreign_keys'] ?? 0), 'FK enforcement off after migration');

        // Idempotency
        $applied2 = apply_migrations($db);
        $this->assertSame([], $applied2);
        $this->assertSame(12, $this->countRows($db, 'addresses'));
    }

    /**
     * Fixture-based tests. These use SQLite files committed in tests/fixtures/upgrade/.
     * If the files don't exist the test is skipped — they are generated manually
     * (see tests/fixtures/upgrade/README.md).
     */
    public function testFixtureV118Empty(): void
    {
        $f = __DIR__ . '/../fixtures/upgrade/v1.18-empty.sqlite';
        $this->assertFileExists(
            $f,
            'UpgradeReplay fixture missing: ' . $f
            . ' — generate per tests/fixtures/upgrade/README.md or commit fixtures.'
            . ' Silent skip retired in v3.26.0 (#868).'
        );
        $this->assertUpgradeSafe($f, 0, 0);
    }

    public function testFixtureV119WithData(): void
    {
        $f = __DIR__ . '/../fixtures/upgrade/v1.19-with-data.sqlite';
        $this->assertFileExists(
            $f,
            'UpgradeReplay fixture missing: ' . $f
            . ' — generate per tests/fixtures/upgrade/README.md or commit fixtures.'
            . ' Silent skip retired in v3.26.0 (#868).'
        );
        // Fixture has ~50 subnets and ~500 addresses — no loss expected
        $this->assertUpgradeSafe($f, 50, 500);
    }

    public function testFixtureV25Large(): void
    {
        $f = __DIR__ . '/../fixtures/upgrade/v2.5-large.sqlite';
        $this->assertFileExists(
            $f,
            'UpgradeReplay fixture missing: ' . $f
            . ' — generate per tests/fixtures/upgrade/README.md or commit fixtures.'
            . ' Silent skip retired in v3.26.0 (#868).'
        );
        // Fixture has ~5000 addresses — specifically test throughput
        $this->assertUpgradeSafe($f, 0, 5000);
    }
}
