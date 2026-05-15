<?php
declare(strict_types=1);

namespace Tests\Migration;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: v2.9.0-blob-affinity (#410). Verifies that the TEXT-affinity
 * network_bin/ip_bin rows produced by legacy PARAM_STR inserts get
 * rewritten to native BLOB via PARAM_LOB, so ORDER BY ip_bin keeps a
 * stable byte-order after v2.9.0 lib.php starts writing PARAM_LOB rows.
 */
final class IpStorageTest extends Base
{
    public function testBlobAffinityMigrationFlipsTextRowsToBlob(): void
    {
        $db = $this->makePreVrfDb();

        $textSubnetCount = (int)$db->query(
            "SELECT COUNT(*) FROM subnets WHERE typeof(network_bin) != 'blob'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $textSubnetCount, 'precondition: subnets should have TEXT-affinity rows');

        $textAddrCount = (int)$db->query(
            "SELECT COUNT(*) FROM addresses WHERE typeof(ip_bin) != 'blob'"
        )->fetchColumn();
        $this->assertGreaterThan(0, $textAddrCount, 'precondition: addresses should have TEXT-affinity rows');

        $beforeAddrHex = $db->query("SELECT id, hex(ip_bin) AS h FROM addresses ORDER BY id")->fetchAll();
        $beforeAddrSort = $db->query("SELECT id FROM addresses ORDER BY ip_bin")->fetchAll();

        \apply_migrations($db);

        $textSubnetAfter = (int)$db->query(
            "SELECT COUNT(*) FROM subnets WHERE typeof(network_bin) != 'blob'"
        )->fetchColumn();
        $this->assertSame(0, $textSubnetAfter, 'all subnets.network_bin must be BLOB after migration');

        $textAddrAfter = (int)$db->query(
            "SELECT COUNT(*) FROM addresses WHERE typeof(ip_bin) != 'blob'"
        )->fetchColumn();
        $this->assertSame(0, $textAddrAfter, 'all addresses.ip_bin must be BLOB after migration');

        $afterAddrHex = $db->query("SELECT id, hex(ip_bin) AS h FROM addresses ORDER BY id")->fetchAll();
        $this->assertSame($beforeAddrHex, $afterAddrHex, 'address bytes must be byte-equal after BLOB affinity flip');

        $afterAddrSort = $db->query("SELECT id FROM addresses ORDER BY ip_bin")->fetchAll();
        $this->assertSame($beforeAddrSort, $afterAddrSort, 'ORDER BY ip_bin must produce the same sequence after migration');

        $audit = $db->query(
            "SELECT details FROM audit_log WHERE action = 'migration.blob_affinity_normalized'"
        )->fetchAll();
        $this->assertCount(1, $audit, 'one audit row should document the migration');
        $this->assertStringContainsString('addresses=', (string)$audit[0]['details']);
    }

    public function testBlobAffinityMigrationIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $auditBefore = (int)$db->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'migration.blob_affinity_normalized'"
        )->fetchColumn();

        $db->prepare("DELETE FROM schema_migrations WHERE version = '2.9.0-blob-affinity'")->execute();

        \apply_migrations($db);

        $auditAfter = (int)$db->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'migration.blob_affinity_normalized'"
        )->fetchColumn();
        $this->assertSame($auditBefore, $auditAfter, 'idempotent re-run must not add a duplicate audit row');

        $marker = $db->query(
            "SELECT 1 FROM schema_migrations WHERE version = '2.9.0-blob-affinity'"
        )->fetchColumn();
        $this->assertNotFalse($marker, 'schema_migrations marker must be re-recorded after re-run');
    }
}
