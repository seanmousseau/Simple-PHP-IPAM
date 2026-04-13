<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for apply_migrations() — specifically verifying that
 * table-rebuild migrations do not cascade-delete child data.
 *
 * Root cause being guarded: with PRAGMA foreign_keys = ON, SQLite performs an
 * implicit row-by-row DELETE before DROP TABLE, triggering ON DELETE CASCADE on
 * all child tables. The 2.1.0-vrfs migration rebuilds the subnets table via
 * DROP TABLE + rename; without the FK-off guard in apply_migrations(), this
 * wipes every row in addresses (and subnet_tags, alert_state).
 *
 * These tests build a database in the exact pre-migration state (schema present,
 * rows populated, relevant migration NOT yet recorded in schema_migrations) and
 * then call apply_migrations() to confirm data survives.
 */
class MigrationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Open a fresh in-memory SQLite PDO connection with the same settings used
     * by ipam_db(): ERRMODE_EXCEPTION, FETCH_ASSOC, and foreign_keys = ON.
     */
    private function makeDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");
        return $db;
    }

    /**
     * Build a database that matches the v2.0.0 schema state immediately before
     * the 2.1.0-vrfs migration runs:
     *   - All migrations up through 2.1.0-contacts recorded in schema_migrations
     *   - subnets table WITHOUT vrf_id (the column the rebuild adds)
     *   - subnets has the old UNIQUE(cidr) constraint (not UNIQUE(cidr, vrf_id))
     *   - addresses table with ON DELETE CASCADE on subnet_id
     *   - A handful of subnets and addresses inserted
     *
     * Returns the PDO connection with test data present.
     */
    private function makePreVrfDb(): PDO
    {
        $db = $this->makeDb();

        $db->exec("
            CREATE TABLE schema_migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                version    TEXT    NOT NULL UNIQUE,
                applied_at TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE sites (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                parent_id   INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE vlans (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                vlan_id     INTEGER NOT NULL,
                name        TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE contacts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                email      TEXT NOT NULL DEFAULT '',
                phone      TEXT NOT NULL DEFAULT '',
                org        TEXT NOT NULL DEFAULT '',
                note       TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // subnets — v2.0.0 schema: UNIQUE(cidr), no vrf_id column
        $db->exec("
            CREATE TABLE subnets (
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
            )
        ");

        $db->exec("
            CREATE TABLE tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL UNIQUE,
                colour     TEXT NOT NULL DEFAULT '#6c757d',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE subnet_tags (
                subnet_id INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                tag_id    INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
                PRIMARY KEY (subnet_id, tag_id)
            )
        ");
        $db->exec("
            CREATE TABLE alert_state (
                subnet_id       INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                level           TEXT    NOT NULL,
                last_alerted_at TEXT    NOT NULL,
                PRIMARY KEY (subnet_id, level)
            )
        ");

        // addresses — with ON DELETE CASCADE on subnet_id (the cascade vector)
        $db->exec("
            CREATE TABLE addresses (
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
            )
        ");
        $db->exec("
            CREATE TABLE address_tags (
                address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
                tag_id     INTEGER NOT NULL REFERENCES tags(id)      ON DELETE CASCADE,
                PRIMARY KEY (address_id, tag_id)
            )
        ");

        $db->exec("
            CREATE TABLE audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                action      TEXT NOT NULL,
                entity_type TEXT NOT NULL DEFAULT '',
                entity_id   INTEGER,
                user_id     INTEGER,
                username    TEXT NOT NULL DEFAULT '',
                ip          TEXT NOT NULL DEFAULT '',
                user_agent  TEXT NOT NULL DEFAULT '',
                details     TEXT NOT NULL DEFAULT '',
                created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // Mark all migrations that ran before 2.1.0-vrfs as already applied
        $alreadyApplied = [
            '0.3', '0.7', '0.9', '0.11', '0.12', '0.13', '0.14',
            '1.4', '1.9', '1.11', '1.12', '1.13', '1.19.0',
            '2.0.0-alert-state', '2.0.0-site-hierarchy', '2.0.0-tags', '2.0.0-vlans',
            '2.1.0-contacts',
        ];
        $st = $db->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
        foreach ($alreadyApplied as $v) {
            $st->execute([$v]);
        }

        // Insert test subnets
        $ins = $db->prepare("
            INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute(['10.0.0.0/24', 4, '10.0.0.0', inet_pton('10.0.0.0'), 24, 'test subnet 1']);
        $ins->execute(['10.1.0.0/24', 4, '10.1.0.0', inet_pton('10.1.0.0'), 24, 'test subnet 2']);

        // Insert test addresses
        $ins = $db->prepare("
            INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status)
            VALUES (?, ?, ?, ?, 'used')
        ");
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $ins->execute([1, $ip, inet_pton($ip), "host-$ip"]);
        }
        foreach (['10.1.0.1', '10.1.0.2'] as $ip) {
            $ins->execute([2, $ip, inet_pton($ip), "host-$ip"]);
        }

        return $db;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * The 2.1.0-vrfs migration must not delete any addresses.
     *
     * This is the exact scenario that caused the production data-loss bug:
     * the migration drops and recreates the subnets table; with FK enforcement
     * active, DROP TABLE cascades to addresses via ON DELETE CASCADE, wiping all
     * rows. apply_migrations() must disable FK enforcement before the transaction
     * and restore it afterwards.
     */
    public function testVrfMigrationPreservesAddresses(): void
    {
        $db = $this->makePreVrfDb();

        $before = (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn();
        $this->assertSame(5, $before, 'Pre-condition: 5 test addresses must exist before migration');

        apply_migrations($db);

        $after = (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn();
        $this->assertSame(5, $after, '2.1.0-vrfs must not delete any addresses');
    }

    /**
     * Subnet rows and their IDs must be intact after the rebuild so that
     * addresses.subnet_id foreign key references remain valid.
     */
    public function testVrfMigrationPreservesSubnetIds(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $count = (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn();
        $this->assertSame(2, $count, 'Both test subnets must survive the rebuild');

        $ids = array_map('intval', $db->query("SELECT id FROM subnets ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([1, 2], $ids, 'Subnet IDs must be unchanged so address FK refs stay valid');
    }

    /**
     * After the migration subnets must have a vrf_id column and all pre-existing
     * subnets must be in the global (NULL) VRF.
     */
    public function testVrfMigrationAddsVrfIdColumn(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $cols = array_column(
            $db->query("PRAGMA table_info(subnets)")->fetchAll(),
            'name'
        );
        $this->assertContains('vrf_id', $cols, 'subnets must have vrf_id after migration');

        $nonNull = (int)$db->query("SELECT count(*) FROM subnets WHERE vrf_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $nonNull, 'Pre-existing subnets must default to vrf_id = NULL');
    }

    /**
     * Addresses must still JOIN to subnets correctly after the migration,
     * confirming FK integrity is intact end-to-end.
     */
    public function testVrfMigrationAddressFkIntact(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $joined = (int)$db->query("
            SELECT count(*) FROM addresses a
            JOIN subnets s ON a.subnet_id = s.id
        ")->fetchColumn();

        $this->assertSame(5, $joined, 'All addresses must still join to subnets after migration');
    }

    /**
     * PRAGMA foreign_keys must be back ON once apply_migrations() returns.
     * Leaving it OFF would silently allow FK violations in all subsequent
     * application code.
     */
    public function testForeignKeyEnforcementRestoredAfterMigration(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $fkOn = (int)$db->query("PRAGMA foreign_keys")->fetchColumn();
        $this->assertSame(1, $fkOn, 'PRAGMA foreign_keys must be ON after apply_migrations()');
    }

    /**
     * apply_migrations() must be idempotent: a second call on the same database
     * must apply zero additional migrations and leave all row counts unchanged.
     */
    public function testMigrationsAreIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $first = apply_migrations($db);

        $this->assertSame([], $first, 'Second call must apply no migrations');
        $this->assertSame(5, (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn());
        $this->assertSame(2, (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn());
    }

    /**
     * After the migration the UNIQUE(cidr, vrf_id) constraint must be enforced:
     * inserting a duplicate CIDR within the same non-NULL VRF must fail.
     *
     * Note: SQLite treats NULL as distinct from every other value (including
     * other NULLs), so UNIQUE(cidr, vrf_id) does NOT catch duplicates when
     * vrf_id is NULL. The meaningful uniqueness guarantee is that two subnets
     * with the same CIDR cannot coexist in the same named VRF.
     */
    public function testVrfMigrationUniqueCidrConstraintEnforced(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        // Insert a VRF (created by the migration)
        $db->exec("INSERT INTO vrfs (name, description, rd) VALUES ('test-vrf', '', '')");
        $vrfId = (int)$db->lastInsertId();

        // First subnet in this VRF — must succeed
        $ins = $db->prepare("
            INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, vrf_id)
            VALUES (?, 4, ?, ?, 24, ?)
        ");
        $ins->execute(['10.99.0.0/24', '10.99.0.0', inet_pton('10.99.0.0'), $vrfId]);

        // Duplicate cidr+vrf_id must throw
        $this->expectException(PDOException::class);
        $ins->execute(['10.99.0.0/24', '10.99.0.0', inet_pton('10.99.0.0'), $vrfId]);
    }
}
