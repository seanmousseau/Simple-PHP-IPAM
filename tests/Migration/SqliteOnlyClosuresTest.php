<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;
use PDOException;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: closures that are SQLite-specific by construction (PRAGMA
 * foreign_keys, SQLite table-rebuild via DROP + CREATE), focused on the
 * v2.1.0 VRF migration which is the canonical "must not cascade-delete"
 * regression guard.
 */
final class SqliteOnlyClosuresTest extends Base
{
    public function testVrfMigrationPreservesAddresses(): void
    {
        $db = $this->makePreVrfDb();

        $before = (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn();
        $this->assertSame(5, $before, 'Pre-condition: 5 test addresses must exist before migration');

        \apply_migrations($db);

        $after = (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn();
        $this->assertSame(5, $after, '2.1.0-vrfs must not delete any addresses');
    }

    public function testVrfMigrationPreservesSubnetIds(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $count = (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn();
        $this->assertSame(2, $count, 'Both test subnets must survive the rebuild');

        $ids = array_map('intval', $db->query("SELECT id FROM subnets ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame([1, 2], $ids, 'Subnet IDs must be unchanged so address FK refs stay valid');
    }

    public function testVrfMigrationAddsVrfIdColumn(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $cols = array_column(
            $db->query("PRAGMA table_info(subnets)")->fetchAll(),
            'name'
        );
        $this->assertContains('vrf_id', $cols, 'subnets must have vrf_id after migration');

        $nonNull = (int)$db->query("SELECT count(*) FROM subnets WHERE vrf_id IS NOT NULL")->fetchColumn();
        $this->assertSame(0, $nonNull, 'Pre-existing subnets must default to vrf_id = NULL');
    }

    public function testVrfMigrationAddressFkIntact(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $joined = (int)$db->query("
            SELECT count(*) FROM addresses a
            JOIN subnets s ON a.subnet_id = s.id
        ")->fetchColumn();

        $this->assertSame(5, $joined, 'All addresses must still join to subnets after migration');
    }

    public function testForeignKeyEnforcementRestoredAfterMigration(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $fkOn = (int)$db->query("PRAGMA foreign_keys")->fetchColumn();
        $this->assertSame(1, $fkOn, 'PRAGMA foreign_keys must be ON after apply_migrations()');
    }

    public function testVrfMigrationUniqueCidrConstraintEnforced(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $db->exec("INSERT INTO vrfs (name, description, rd) VALUES ('test-vrf', '', '')");
        $vrfId = (int)$db->lastInsertId();

        $ins = $db->prepare("
            INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, vrf_id)
            VALUES (?, 4, ?, ?, 24, ?)
        ");
        $ins->execute(['10.99.0.0/24', '10.99.0.0', inet_pton('10.99.0.0'), $vrfId]);

        $this->expectException(PDOException::class);
        $ins->execute(['10.99.0.0/24', '10.99.0.0', inet_pton('10.99.0.0'), $vrfId]);
    }
}
