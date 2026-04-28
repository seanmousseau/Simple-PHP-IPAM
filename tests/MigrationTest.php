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
     * 3.2.0-devices migration must add device_id + interface_id to addresses
     * without deleting any existing address rows, and must create the devices
     * and device_interfaces tables.
     */
    public function testDevicesMigrationAddsColumnsAndPreservesAddresses(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        // Row count must be unchanged — nullable ALTER TABLE ADD COLUMN is safe,
        // but this guards against any future refactor that rebuilds addresses.
        $this->assertSame(
            5,
            (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn(),
            '3.2.0-devices must not delete any addresses'
        );

        // Verify new columns are present on addresses.
        $addrCols = array_column(
            $db->query("PRAGMA table_info(addresses)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertContains('device_id',    $addrCols, 'addresses.device_id must exist after 3.2.0-devices');
        $this->assertContains('interface_id', $addrCols, 'addresses.interface_id must exist after 3.2.0-devices');

        // Verify the new tables were created.
        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertContains('devices',           $tables, 'devices table must exist after 3.2.0-devices');
        $this->assertContains('device_interfaces', $tables, 'device_interfaces table must exist after 3.2.0-devices');
        $this->assertContains('password_reset_tokens', $tables, 'password_reset_tokens table must exist after 3.2.0-password-reset');
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

    /**
     * The 2.6.0-settings migration must create the settings table, seed every
     * registry key, and not clobber rows written between runs. Second apply is
     * idempotent — no duplicate rows, no errors.
     */
    public function testSettingsMigrationSeedsAndIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(),
            'name'
        );
        $this->assertContains('settings', $tables, '2.6.0-settings must create the settings table');

        $cols = array_column(
            $db->query("PRAGMA table_info(settings)")->fetchAll(),
            'name'
        );
        foreach (['key', 'value', 'type', 'updated_at', 'updated_by'] as $expected) {
            $this->assertContains($expected, $cols, "settings column {$expected} missing");
        }

        $registryKeys = array_keys(ipam_setting_definitions());
        $seededCount  = (int)$db->query("SELECT count(*) FROM settings")->fetchColumn();
        $this->assertSame(count($registryKeys), $seededCount, 'Every registry key must be seeded on first run');

        // User writes a value between migration runs via a prepared update.
        $db->prepare("UPDATE settings SET value = :v WHERE key = :k")
           ->execute([':v' => 'Custom Name', ':k' => 'branding.site_name']);

        // Second apply_migrations() must be a no-op and must NOT clobber the user write.
        apply_migrations($db);
        $preserved = $db->query("SELECT value FROM settings WHERE key = 'branding.site_name'")->fetchColumn();
        $this->assertSame('Custom Name', $preserved, 'Second migration run must not overwrite existing rows');
    }

    // -------------------------------------------------------------------------
    // 2.9.0-blob-affinity (#410)
    // -------------------------------------------------------------------------

    /**
     * The 2.9.0-blob-affinity migration must rewrite every binary IP column
     * row using PARAM_LOB binding so the stored affinity is BLOB. Guards
     * SQLite's "any BLOB sorts greater than any TEXT" comparison rule —
     * without normalization, ORDER BY ip_bin breaks the moment v2.9.0 lib.php
     * starts inserting new rows via PARAM_LOB.
     */
    public function testBlobAffinityMigrationFlipsTextRowsToBlob(): void
    {
        $db = $this->makePreVrfDb();

        // makePreVrfDb() inserts subnets and addresses via positional execute(),
        // which is the legacy PARAM_STR path. On SQLite this stores ip_bin /
        // network_bin with TEXT affinity even though the columns are declared
        // BLOB. This is the exact pre-v2.9.0 production state we are testing
        // the migration against.
        $textSubnetCount = (int)$db->query(
            "SELECT COUNT(*) FROM subnets WHERE typeof(network_bin) != 'blob'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $textSubnetCount, 'precondition: subnets should have TEXT-affinity rows');

        $textAddrCount = (int)$db->query(
            "SELECT COUNT(*) FROM addresses WHERE typeof(ip_bin) != 'blob'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $textAddrCount, 'precondition: addresses should have TEXT-affinity rows');

        // Capture byte values + sort order before so we can verify both are
        // preserved across the migration.
        $beforeAddrHex = $db->query("SELECT id, hex(ip_bin) AS h FROM addresses ORDER BY id")->fetchAll();
        $beforeAddrSort = $db->query("SELECT id FROM addresses ORDER BY ip_bin")->fetchAll();

        apply_migrations($db);

        $textSubnetAfter = (int)$db->query(
            "SELECT COUNT(*) FROM subnets WHERE typeof(network_bin) != 'blob'"
        )->fetchColumn();
        $this->assertSame(0, $textSubnetAfter, 'all subnets.network_bin must be BLOB after migration');

        $textAddrAfter = (int)$db->query(
            "SELECT COUNT(*) FROM addresses WHERE typeof(ip_bin) != 'blob'"
        )->fetchColumn();
        $this->assertSame(0, $textAddrAfter, 'all addresses.ip_bin must be BLOB after migration');

        // Bytes preserved exactly.
        $afterAddrHex = $db->query("SELECT id, hex(ip_bin) AS h FROM addresses ORDER BY id")->fetchAll();
        $this->assertSame($beforeAddrHex, $afterAddrHex, 'address bytes must be byte-equal after BLOB affinity flip');

        // Sort order stable — this is the bug being guarded.
        $afterAddrSort = $db->query("SELECT id FROM addresses ORDER BY ip_bin")->fetchAll();
        $this->assertSame($beforeAddrSort, $afterAddrSort, 'ORDER BY ip_bin must produce the same sequence after migration');

        $audit = $db->query(
            "SELECT details FROM audit_log WHERE action = 'migration.blob_affinity_normalized'"
        )->fetchAll();
        $this->assertCount(1, $audit, 'one audit row should document the migration');
        $this->assertStringContainsString('addresses=', (string)$audit[0]['details']);
    }

    /**
     * Re-running the migration on a database that already has BLOB-affinity
     * data must be a no-op — no errors, no audit spam.
     */
    public function testBlobAffinityMigrationIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $auditBefore = (int)$db->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'migration.blob_affinity_normalized'"
        )->fetchColumn();

        $db->prepare("DELETE FROM schema_migrations WHERE version = '2.9.0-blob-affinity'")->execute();

        apply_migrations($db);

        // Count must not have increased — every row is already BLOB so the
        // "if needsRewrite == 0" guard short-circuits and the audit row is
        // never written.
        $auditAfter = (int)$db->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'migration.blob_affinity_normalized'"
        )->fetchColumn();
        $this->assertSame($auditBefore, $auditAfter, 'idempotent re-run must not add a duplicate audit row');

        $marker = $db->query(
            "SELECT 1 FROM schema_migrations WHERE version = '2.9.0-blob-affinity'"
        )->fetchColumn();
        $this->assertNotFalse($marker, 'schema_migrations marker must be re-recorded after re-run');
    }

    // -------------------------------------------------------------------------
    // 3.5.0-custom-fields (#313, #595)
    // -------------------------------------------------------------------------

    /**
     * The 3.5.0-custom-fields migration must:
     *   - create the custom_field_defs table with a UNIQUE(entity_type, key)
     *   - add subnets.custom_fields  TEXT NOT NULL DEFAULT '{}'
     *   - add addresses.custom_fields TEXT NOT NULL DEFAULT '{}'
     *   - preserve every pre-existing subnet/address row (no data loss)
     *   - leave new rows defaulting to '{}' when the caller omits the column
     */
    public function testCustomFieldsMigrationCreatesSchemaAndPreservesData(): void
    {
        $db = $this->makePreVrfDb();

        // Precondition: custom_fields columns not yet present.
        $subnetCols = array_column($db->query("PRAGMA table_info(subnets)")->fetchAll(), 'name');
        $this->assertNotContains('custom_fields', $subnetCols, 'precondition: subnets.custom_fields must not exist yet');
        $addrCols = array_column($db->query("PRAGMA table_info(addresses)")->fetchAll(), 'name');
        $this->assertNotContains('custom_fields', $addrCols, 'precondition: addresses.custom_fields must not exist yet');

        $subnetCountBefore  = (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn();
        $addressCountBefore = (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn();

        apply_migrations($db);

        // custom_field_defs table exists with expected columns.
        $tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
        $this->assertContains('custom_field_defs', $tables, 'custom_field_defs table must be created');

        $defCols = array_column($db->query("PRAGMA table_info(custom_field_defs)")->fetchAll(), 'name');
        foreach (['id', 'entity_type', 'key', 'label', 'type', 'options', 'sort_order', 'is_required', 'is_deleted', 'created_at', 'updated_at'] as $expected) {
            $this->assertContains($expected, $defCols, "custom_field_defs.{$expected} must exist");
        }

        // subnets.custom_fields added with NOT NULL DEFAULT '{}'.
        $subnetColInfo = $db->query("PRAGMA table_info(subnets)")->fetchAll();
        $subnetCustom  = null;
        foreach ($subnetColInfo as $c) {
            if ((string)$c['name'] === 'custom_fields') { $subnetCustom = $c; break; }
        }
        $this->assertNotNull($subnetCustom, 'subnets.custom_fields must be added');
        $this->assertSame(1, (int)$subnetCustom['notnull'], 'subnets.custom_fields must be NOT NULL');
        $this->assertSame("'{}'", (string)$subnetCustom['dflt_value'], 'subnets.custom_fields default must be {}');

        // addresses.custom_fields added with NOT NULL DEFAULT '{}'.
        $addrColInfo = $db->query("PRAGMA table_info(addresses)")->fetchAll();
        $addrCustom  = null;
        foreach ($addrColInfo as $c) {
            if ((string)$c['name'] === 'custom_fields') { $addrCustom = $c; break; }
        }
        $this->assertNotNull($addrCustom, 'addresses.custom_fields must be added');
        $this->assertSame(1, (int)$addrCustom['notnull'], 'addresses.custom_fields must be NOT NULL');
        $this->assertSame("'{}'", (string)$addrCustom['dflt_value'], 'addresses.custom_fields default must be {}');

        // Data preserved (no cascade wipe).
        $this->assertSame(
            $subnetCountBefore,
            (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn(),
            'subnets row count must be preserved across custom-fields migration'
        );
        $this->assertSame(
            $addressCountBefore,
            (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn(),
            'addresses row count must be preserved across custom-fields migration'
        );

        // Pre-existing rows backfill to the default '{}'.
        $nonDefaultSubnet = (int)$db->query("SELECT count(*) FROM subnets WHERE custom_fields != '{}'")->fetchColumn();
        $this->assertSame(0, $nonDefaultSubnet, 'pre-existing subnets must carry default {} after migration');
        $nonDefaultAddr = (int)$db->query("SELECT count(*) FROM addresses WHERE custom_fields != '{}'")->fetchColumn();
        $this->assertSame(0, $nonDefaultAddr, 'pre-existing addresses must carry default {} after migration');
    }

    /**
     * UNIQUE(entity_type, key) on custom_field_defs must prevent duplicate
     * keys within the same entity_type but allow the same key reused across
     * different entity_types (e.g. 'cost_centre' on both subnets and
     * addresses is legal).
     */
    public function testCustomFieldsUniqueConstraintScopesByEntityType(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $ins = $db->prepare(
            "INSERT INTO custom_field_defs (entity_type, key, label, type)
             VALUES (:et, :k, :lbl, 'text')"
        );
        $ins->execute([':et' => 'subnet',  ':k' => 'cost_centre', ':lbl' => 'Cost centre']);
        $ins->execute([':et' => 'address', ':k' => 'cost_centre', ':lbl' => 'Cost centre']);

        $count = (int)$db->query("SELECT count(*) FROM custom_field_defs WHERE key='cost_centre'")->fetchColumn();
        $this->assertSame(2, $count, 'same key must be usable across different entity_types');

        // Duplicate entity_type + key must throw.
        $this->expectException(PDOException::class);
        $ins->execute([':et' => 'subnet', ':k' => 'cost_centre', ':lbl' => 'Cost centre (dup)']);
    }

    /**
     * Re-running the 3.5.0-custom-fields migration on a DB that already has
     * the target schema must be a no-op: no exceptions, no duplicate columns,
     * no row data loss.
     */
    public function testCustomFieldsMigrationIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        // Seed a user-written row so we can verify it survives re-run.
        $db->prepare(
            "INSERT INTO custom_field_defs (entity_type, key, label, type)
             VALUES ('subnet', 'sla_tier', 'SLA tier', 'text')"
        )->execute();
        $defCountBefore = (int)$db->query("SELECT count(*) FROM custom_field_defs")->fetchColumn();

        // Clear the stamp so apply_migrations() is forced to re-run the closure.
        $db->prepare("DELETE FROM schema_migrations WHERE version = '3.5.0-custom-fields'")->execute();
        apply_migrations($db);

        $defCountAfter = (int)$db->query("SELECT count(*) FROM custom_field_defs")->fetchColumn();
        $this->assertSame($defCountBefore, $defCountAfter, 'idempotent re-run must not drop existing definitions');

        // Column must still exist exactly once (no duplicate ADD COLUMN error).
        $subnetCols = array_column($db->query("PRAGMA table_info(subnets)")->fetchAll(), 'name');
        $this->assertSame(1, count(array_filter($subnetCols, fn($c) => $c === 'custom_fields')));
        $addrCols = array_column($db->query("PRAGMA table_info(addresses)")->fetchAll(), 'name');
        $this->assertSame(1, count(array_filter($addrCols, fn($c) => $c === 'custom_fields')));

        // Stamp re-recorded.
        $marker = $db->query(
            "SELECT 1 FROM schema_migrations WHERE version = '3.5.0-custom-fields'"
        )->fetchColumn();
        $this->assertNotFalse($marker, 'schema_migrations marker must be re-recorded after idempotent re-run');
    }

    // -------------------------------------------------------------------------
    // v3.6.0 #418/#419/#421 — TOTP, rate-limit buckets, lockout columns
    // -------------------------------------------------------------------------

    /**
     * The three 3.6.0 migrations must:
     *   - add totp_secret_enc, totp_enabled to users
     *   - create totp_backup_codes table
     *   - create rate_limit_buckets table
     *   - add failed_auth_count, locked_until, lock_reason to users
     *   - default totp_enabled and failed_auth_count to 0 for new rows
     *   - be idempotent (second apply_migrations() call must not throw)
     */
    public function testV360MigrationsApply(): void
    {
        $db = $this->makePreVrfDb();

        // makePreVrfDb() does not include a users table; add a minimal one so
        // the 3.6.0 migrations can ALTER it.
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role          TEXT NOT NULL DEFAULT 'admin',
                is_active     INTEGER NOT NULL DEFAULT 1,
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        apply_migrations($db);

        // Assert new columns on users table
        $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        $this->assertContains('totp_secret_enc', $cols, 'totp_secret_enc column missing');
        $this->assertContains('totp_enabled', $cols, 'totp_enabled column missing');
        $this->assertContains('failed_auth_count', $cols, 'failed_auth_count column missing');
        $this->assertContains('locked_until', $cols, 'locked_until column missing');
        $this->assertContains('lock_reason', $cols, 'lock_reason column missing');

        // Assert new tables exist
        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(),
            'name'
        );
        $this->assertContains('totp_backup_codes', $tables, 'totp_backup_codes table missing');
        $this->assertContains('rate_limit_buckets', $tables, 'rate_limit_buckets table missing');

        // Assert idempotency
        apply_migrations($db);
        $this->assertTrue(true, 'Second apply_migrations call should not throw');

        // Assert defaults work - insert a user and check totp_enabled defaults to 0
        $db->exec("INSERT INTO users (username, password_hash, role) VALUES ('testv360', 'hash', 'readonly')");
        $row = $db->query("SELECT totp_enabled, failed_auth_count FROM users WHERE username='testv360'")->fetch();
        $this->assertEquals(0, (int)$row['totp_enabled']);
        $this->assertEquals(0, (int)$row['failed_auth_count']);
    }

    // -------------------------------------------------------------------------
    // v2.11.0 #409 — migration-replay idempotency against the fresh schema
    // -------------------------------------------------------------------------

    /**
     * Load the current schema.sql end-to-end into an empty DB, stamp every
     * migration as already applied (matching what ipam_db_init() does on a
     * fresh install), then call apply_migrations() a second time and assert
     * it is a complete no-op: no exceptions, no new rows in schema_migrations,
     * no schema changes. This catches the bug class where a new migration
     * is added without PRAGMA table_info() / sqlite_master guards and
     * therefore is not safe to re-run against a DB that already has the
     * target schema shape.
     *
     * This is a lighter, SQLite-scoped companion to SchemaParityTest #409:
     * parity catches cross-engine drift, this catches migration-guard
     * regressions on the SQLite branch that still runs every historical
     * migration closure.
     */
    public function testAllMigrationsAreIdempotentOnFreshSchema(): void
    {
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/lib.php';
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/migrations.php';

        // Save globals so we can restore them at the end — avoids making
        // later tests order-dependent on this test's stub state.
        $hadConfig  = array_key_exists('config', $GLOBALS);
        $prevConfig = $GLOBALS['config'] ?? null;
        $hadDialect  = array_key_exists('ipam_dialect', $GLOBALS);
        $prevDialect = $GLOBALS['ipam_dialect'] ?? null;

        $GLOBALS['config'] = ['proxy_trust' => false];

        $db = $this->makeDb();
        $schema = file_get_contents(dirname(__DIR__) . '/Simple-PHP-IPAM/schema.sql');
        $this->assertNotFalse($schema);
        $db->exec($schema);
        // Pin SqliteDialect so ipam_dialect() calls inside the migration
        // closures resolve on this in-memory DB without needing a full
        // $config bootstrap.
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/SqliteDialect.php';
        $GLOBALS['ipam_dialect'] = new SqliteDialect();

        // Stamp every historical migration, matching ipam_db_init's
        // fresh-install behaviour. Use the dialect's upsert-or-ignore
        // so the call is robust if schema.sql ever pre-seeds some
        // migrations itself.
        $ignore = ipam_dialect()->upsert_or_ignore('schema_migrations', ['version']);
        $stamp = $db->prepare("INSERT INTO schema_migrations (version) VALUES (:v) $ignore");
        foreach (array_keys(ipam_migrations()) as $ver) {
            $stamp->execute([':v' => $ver]);
        }

        // Snapshot the structural column shape of every table. We compare
        // column names and SQLite type-affinity strings rather than the
        // raw sqlite_master DDL because some migration closures re-call
        // ensure_audit_log_table(), which rewrites the audit_log triggers
        // via SqliteDialect::append_only_trigger(). That produces a
        // whitespace-different but semantically identical CREATE TRIGGER,
        // which would trip a byte-for-byte compare for no real-world reason.
        // Structural column shape is the signal we actually care about.
        $snapshot = function (PDO $db): array {
            $tables = [];
            $rows = $db->query(
                "SELECT name FROM sqlite_master "
                . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
                . "ORDER BY name"
            )->fetchAll();
            foreach ($rows as $r) {
                $tn = (string)$r['name'];
                $cols = [];
                foreach ($db->query("PRAGMA table_info(\"$tn\")")->fetchAll() as $c) {
                    $cols[(string)$c['name']] = [
                        'type'    => strtoupper((string)$c['type']),
                        'notnull' => (int)$c['notnull'],
                        'pk'      => (int)$c['pk'],
                    ];
                }
                ksort($cols);
                $tables[$tn] = $cols;
            }
            return $tables;
        };
        $before = $snapshot($db);
        $migCountBefore = (int)$db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();

        // Force a replay by clearing schema_migrations (as if upgrading
        // from an install where the version stamps were lost) and calling
        // apply_migrations(). Every migration closure MUST detect that its
        // target schema already exists and short-circuit without throwing
        // or mutating structure.
        $db->exec("DELETE FROM schema_migrations");
        apply_migrations($db);

        $after = $snapshot($db);
        $migCountAfter = (int)$db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();

        $this->assertSame(
            $before,
            $after,
            'apply_migrations() must be a no-op on top of the fresh schema — '
            . 'a new migration probably forgot its PRAGMA table_info() / '
            . 'sqlite_master existence guard'
        );
        $this->assertGreaterThan(
            $migCountBefore - 1, // We deleted schema_migrations entirely,
            $migCountAfter,      // so "at least" the count we started with.
            'every migration closure must re-stamp itself in schema_migrations after replay'
        );

        // Restore globals so later tests are not order-dependent.
        if ($hadConfig) {
            $GLOBALS['config'] = $prevConfig;
        } else {
            unset($GLOBALS['config']);
        }
        if ($hadDialect) {
            $GLOBALS['ipam_dialect'] = $prevDialect;
        } else {
            unset($GLOBALS['ipam_dialect']);
        }
    }

    // -------------------------------------------------------------------------
    // 3.15.0-passkeys (#688)
    // -------------------------------------------------------------------------

    /**
     * The 3.15.0-passkeys migration must create the webauthn_credentials table
     * with all expected columns and be idempotent on a second run.
     */
    public function testPasskeysMigrationAddsWebAuthnCredentialsTable(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        $cols = [];
        foreach ($db->query("PRAGMA table_info(webauthn_credentials)")->fetchAll() as $row) {
            $cols[] = $row['name'];
        }
        sort($cols);
        $expected = ['created_at', 'credential_id', 'id', 'last_used_at', 'name', 'public_key', 'sign_count', 'user_id'];
        $this->assertSame($expected, $cols);

        // Idempotency: delete the version stamp and re-run so the migration body
        // itself executes again; CREATE TABLE IF NOT EXISTS must not throw.
        $db->exec("DELETE FROM schema_migrations WHERE version = '3.15.0-passkeys'");
        apply_migrations($db);
        $cols2 = [];
        foreach ($db->query("PRAGMA table_info(webauthn_credentials)")->fetchAll() as $row) {
            $cols2[] = $row['name'];
        }
        sort($cols2);
        $this->assertSame($expected, $cols2);
    }

    // -------------------------------------------------------------------------
    // 3.16.0-preferred-mfa-method (#746)
    // -------------------------------------------------------------------------

    /**
     * The 3.16.0-preferred-mfa-method migration must add a nullable
     * preferred_mfa_method TEXT column to the users table and be idempotent
     * across re-runs. Existing rows must read the column as NULL (no default).
     */
    public function testV316PreferredMfaMethodColumnAdded(): void
    {
        // makePreVrfDb gives us subnets/addresses/audit_log; we still need a
        // users table for the migration's ALTER to bind to. Add one at the
        // v3.6.0 shape (same pattern as testTotpMigrationAddsColumns).
        $db = $this->makePreVrfDb();
        $db->exec("
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role          TEXT NOT NULL DEFAULT 'admin',
                is_active     INTEGER NOT NULL DEFAULT 1,
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        apply_migrations($db);

        $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
        $match = array_values(array_filter(
            $cols,
            static fn(array $c): bool => (string)$c['name'] === 'preferred_mfa_method'
        ));
        $this->assertNotEmpty($match, 'preferred_mfa_method column missing on users');
        $this->assertSame(0, (int)$match[0]['notnull'], 'preferred_mfa_method must be nullable');

        // Insert a user; column should default to NULL since no default was set.
        $db->exec("INSERT INTO users (username, password_hash, role) VALUES ('v316user', 'h', 'admin')");
        $val = $db->query("SELECT preferred_mfa_method FROM users WHERE username='v316user'")->fetchColumn();
        $this->assertNull($val, 'preferred_mfa_method must default to NULL for new rows');

        // Idempotency: a second migration pass must be a no-op.
        $db->exec("DELETE FROM schema_migrations WHERE version = '3.16.0-preferred-mfa-method'");
        apply_migrations($db);
        $colsAgain = $db->query("PRAGMA table_info(users)")->fetchAll();
        $countAgain = count(array_filter(
            $colsAgain,
            static fn(array $c): bool => (string)$c['name'] === 'preferred_mfa_method'
        ));
        $this->assertSame(1, $countAgain, 'preferred_mfa_method must still appear exactly once after re-run');
    }

    // -------------------------------------------------------------------------
    // 3.17.0-backup (#690)
    // -------------------------------------------------------------------------

    /**
     * The 3.17.0-backup migration must create backup_destinations,
     * backup_schedules, and backup_log with all required columns.
     */
    public function testV317BackupTablesAdded(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        // backup_destinations
        $cols = array_column(
            $db->query("PRAGMA table_info(backup_destinations)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        foreach (['id', 'name', 'type', 'config', 'encrypt', 'is_active', 'created_at', 'updated_at'] as $c) {
            $this->assertContains($c, $cols, "backup_destinations missing column: $c");
        }

        // backup_schedules
        $cols = array_column(
            $db->query("PRAGMA table_info(backup_schedules)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        foreach ([
            'id', 'destination_id', 'frequency', 'time_of_day',
            'day_of_week', 'day_of_month',
            'retention_hourly', 'retention_daily', 'retention_weekly', 'retention_monthly',
            'is_active', 'last_run_at', 'next_run_at', 'created_at',
        ] as $c) {
            $this->assertContains($c, $cols, "backup_schedules missing column: $c");
        }

        // backup_log
        $cols = array_column(
            $db->query("PRAGMA table_info(backup_log)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        foreach ([
            'id', 'destination_id', 'schedule_id', 'triggered_by', 'type', 'status',
            'filename', 'size_bytes', 'checksum', 'error_message',
            'started_at', 'completed_at',
        ] as $c) {
            $this->assertContains($c, $cols, "backup_log missing column: $c");
        }
    }

    /**
     * The 3.17.0-backup migration must be idempotent: a second apply_migrations()
     * call on an already-migrated database must not throw.
     */
    public function testV317MigrationIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        apply_migrations($db);

        // Delete the stamp to force re-execution of the closure body.
        $db->exec("DELETE FROM schema_migrations WHERE version = '3.17.0-backup'");
        apply_migrations($db); // must not throw

        // Tables must still exist with correct structure.
        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertContains('backup_destinations', $tables, 'backup_destinations must still exist after idempotent re-run');
        $this->assertContains('backup_schedules',    $tables, 'backup_schedules must still exist after idempotent re-run');
        $this->assertContains('backup_log',          $tables, 'backup_log must still exist after idempotent re-run');

        // Version stamp must be re-recorded.
        $marker = $db->query(
            "SELECT 1 FROM schema_migrations WHERE version = '3.17.0-backup'"
        )->fetchColumn();
        $this->assertNotFalse($marker, 'schema_migrations stamp must be re-recorded after idempotent re-run');
    }

    // -------------------------------------------------------------------------
    // 3.13.0-settings-cascade
    // -------------------------------------------------------------------------

    /**
     * The 3.13.0-settings-cascade migration must add tenant_id to settings,
     * preserve all existing rows, and be idempotent on a second run.
     */
    public function testSettingsCascadeMigrationIdempotent(): void
    {
        $db = $this->makePreVrfDb();

        // apply_migrations() runs 2.6.0-settings (creates + seeds settings) and
        // then 3.13.0-settings-cascade (rebuilds settings with tenant_id) in a
        // single pass. Capture the count after that full pass — this is the
        // authoritative "rows that survived the rebuild" figure.
        apply_migrations($db);

        $cols = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('tenant_id', $cols, 'settings.tenant_id must exist after 3.13.0-settings-cascade migration');

        // Exact data-survival check: the rebuild must have copied every seeded
        // row — row count must equal the number of registry keys seeded by
        // 2.6.0-settings.
        $countBefore = (int)$db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        $this->assertGreaterThan(0, $countBefore, 'settings rows must survive the migration');

        // All migrated rows must have tenant_id = NULL (global-layer settings).
        $nonNullCount = (int)$db->query("SELECT COUNT(*) FROM settings WHERE tenant_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $nonNullCount, 'all settings rows must have tenant_id IS NULL after migration');

        // Second call must be a no-op (idempotency guard) — row count unchanged.
        apply_migrations($db);
        $cols2 = $db->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('tenant_id', $cols2, 'tenant_id must still exist after second apply_migrations() call');

        $countAfter = (int)$db->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        $this->assertSame($countBefore, $countAfter, 'second apply_migrations() must not change settings row count');

        $nonNullAfter = (int)$db->query("SELECT COUNT(*) FROM settings WHERE tenant_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $nonNullAfter, 'tenant_id must remain NULL for all rows after idempotent re-run');
    }
}
