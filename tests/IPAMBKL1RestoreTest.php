<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * Round-trip tests for ipam_restore_logical_apply() — the reader half
 * of the IPAMBKL1 backend. Streams an IPAMBKL1 file, builds the idmap,
 * remaps FK columns, and inserts via PDO with re-emit-IDs semantics.
 *
 * Spec: docs/internal/ipambkl1-format.md → "Replay strategy — re-emit IDs".
 *
 * Strategy: produce a known-good fixture by running the v3.23.0 writer
 * over a seeded source DB (same fixture shape as IPAMBKL1WriterTest),
 * then apply the fixture onto a fresh target DB and assert row-count
 * parity, FK preservation under the idmap, binary fidelity, and the
 * self-referential two-pass for sites.parent_id.
 *
 * SQLite-only here. Multi-engine + cross-engine parity asserted by the
 * 3x3 matrix in #1042's dockerized integration tests.
 */
class IPAMBKL1RestoreTest extends TestCase
{
    private string $fixturePath;
    private int $sourceRootSiteId;
    private int $sourceBranchSiteId;
    private int $sourceSubnetId;
    private string $sourceSubnetCidr;

    protected function setUp(): void
    {
        $src = $this->buildSourceDb();
        $this->fixturePath = tempnam(sys_get_temp_dir(), 'ipambkl1_fixture_') . '.bkl1.gz';
        ipam_backup_logical_dump($src, $this->fixturePath);
    }

    /**
     * Build a populated source DB — same shape as IPAMBKL1WriterTest's
     * fixture so writer/reader tests share the round-trip surface.
     * Records source IDs as instance state for cross-check.
     */
    private function buildSourceDb(): PDO
    {
        $db = $this->freshDb();

        $db->exec(
            "INSERT INTO users (username, password_hash, role, is_active) " .
            "VALUES ('admin', 'bogus-hash', 'admin', 1)"
        );

        $db->exec("INSERT INTO sites (name, description) VALUES ('hq', 'HQ root')");
        $this->sourceRootSiteId = (int) $db->lastInsertId();

        $stmt = $db->prepare(
            "INSERT INTO sites (name, description, parent_id) VALUES ('branch', 'Branch under HQ', :p)"
        );
        $stmt->execute([':p' => $this->sourceRootSiteId]);
        $this->sourceBranchSiteId = (int) $db->lastInsertId();

        $db->exec("INSERT INTO vrfs (name, description) VALUES ('default', 'Default VRF')");

        $this->sourceSubnetCidr = '10.1.0.0/24';
        $stmt = $db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id) " .
            "VALUES (:cidr, 4, '10.1.0.0', :nb, 24, 'office', :site)"
        );
        $stmt->bindValue(':cidr', $this->sourceSubnetCidr);
        ipam_bind_binary($stmt, ':nb', (string) inet_pton('10.1.0.0'));
        $stmt->bindValue(':site', $this->sourceRootSiteId, PDO::PARAM_INT);
        $stmt->execute();
        $this->sourceSubnetId = (int) $db->lastInsertId();

        foreach (['10.1.0.5', '10.1.0.255'] as $i => $ip) {
            $stmt = $db->prepare(
                "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status) " .
                "VALUES (:s, :ip, :bin, :h, 'used')"
            );
            $stmt->bindValue(':s', $this->sourceSubnetId, PDO::PARAM_INT);
            $stmt->bindValue(':ip', $ip);
            ipam_bind_binary($stmt, ':bin', (string) inet_pton($ip));
            $stmt->bindValue(':h', "host$i");
            $stmt->execute();
        }

        $db->exec("INSERT INTO tags (name, colour) VALUES ('production', '#ff0000')");
        $tagId = (int) $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (:s, :t)");
        $stmt->execute([':s' => $this->sourceSubnetId, ':t' => $tagId]);

        $db->exec(
            "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, details) " .
            "VALUES ('subnet.create', 'subnet', 1, 1, 'admin', 'seeded')"
        );

        return $db;
    }

    private function freshDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        return $db;
    }

    // -----------------------------------------------------------------------
    // Function-shape contract
    // -----------------------------------------------------------------------

    public function testReaderReturnsMetadata(): void
    {
        $target = $this->freshDb();
        $meta = ipam_restore_logical_apply($target, $this->fixturePath);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('total_rows', $meta);
        $this->assertGreaterThan(0, $meta['total_rows']);
    }

    // -----------------------------------------------------------------------
    // Row-count parity per table (T4 from #835)
    // -----------------------------------------------------------------------

    public function testRowCountsParityForSeededTables(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        foreach (['users', 'sites', 'vrfs', 'subnets', 'addresses', 'tags', 'subnet_tags', 'audit_log'] as $t) {
            $stmt = $target->query("SELECT COUNT(*) FROM \"$t\"");
            $count = $stmt ? (int) $stmt->fetchColumn() : -1;
            $this->assertGreaterThan(
                0,
                $count,
                "table '$t' should have rows after restore"
            );
        }

        // Sites: 2 seeded (hq + branch).
        $count = (int) ($target->query("SELECT COUNT(*) FROM sites")?->fetchColumn() ?? 0);
        $this->assertSame(2, $count);

        // Subnets: 1 seeded.
        $count = (int) ($target->query("SELECT COUNT(*) FROM subnets")?->fetchColumn() ?? 0);
        $this->assertSame(1, $count);

        // Addresses: 2 seeded.
        $count = (int) ($target->query("SELECT COUNT(*) FROM addresses")?->fetchColumn() ?? 0);
        $this->assertSame(2, $count);
    }

    // -----------------------------------------------------------------------
    // FK relationships preserved via idmap (the load-bearing invariant)
    // -----------------------------------------------------------------------

    public function testSubnetSiteIdRemapsToRestoredSite(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        // Source: subnet.site_id == sourceRootSiteId (hq).
        // Target: subnet.site_id should be the *new* PK of the row whose name='hq'.
        $hqId = (int) ($target->query("SELECT id FROM sites WHERE name='hq'")?->fetchColumn() ?? 0);
        $this->assertGreaterThan(0, $hqId);

        $subnetSite = (int) (
            $target->query("SELECT site_id FROM subnets WHERE cidr=" . $target->quote($this->sourceSubnetCidr))?->fetchColumn() ?? 0
        );
        $this->assertSame($hqId, $subnetSite, 'subnet.site_id remaps to restored hq site');
    }

    public function testAddressSubnetIdRemapsToRestoredSubnet(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        $subnetId = (int) (
            $target->query("SELECT id FROM subnets WHERE cidr=" . $target->quote($this->sourceSubnetCidr))?->fetchColumn() ?? 0
        );
        $this->assertGreaterThan(0, $subnetId);

        $stmt = $target->query("SELECT subnet_id FROM addresses ORDER BY ip");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $sid) {
            $this->assertSame($subnetId, (int) $sid, 'every address.subnet_id remaps to restored subnet');
        }
    }

    public function testJoinTableSubnetTagsResolveBothSides(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        $subnetId = (int) (
            $target->query("SELECT id FROM subnets WHERE cidr=" . $target->quote($this->sourceSubnetCidr))?->fetchColumn() ?? 0
        );
        $tagId = (int) ($target->query("SELECT id FROM tags WHERE name='production'")?->fetchColumn() ?? 0);

        $stmt = $target->prepare("SELECT COUNT(*) FROM subnet_tags WHERE subnet_id=:s AND tag_id=:t");
        $stmt->execute([':s' => $subnetId, ':t' => $tagId]);
        $count = (int) $stmt->fetchColumn();
        $this->assertSame(1, $count, 'subnet_tags entry resolves to (restored subnet, restored tag)');
    }

    // -----------------------------------------------------------------------
    // Self-referential sites.parent_id — two-pass replay invariant
    // -----------------------------------------------------------------------

    public function testSitesParentIdRemapsToRestoredParent(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        $hqId     = (int) ($target->query("SELECT id FROM sites WHERE name='hq'")?->fetchColumn() ?? 0);
        $branchParent = $target->query("SELECT parent_id FROM sites WHERE name='branch'")?->fetchColumn();

        $this->assertGreaterThan(0, $hqId);
        $this->assertNotFalse($branchParent);
        $this->assertSame($hqId, (int) $branchParent, 'branch.parent_id should map to restored hq.id');
    }

    public function testRootSiteParentIdRemainsNull(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        $rootParent = $target->query("SELECT parent_id FROM sites WHERE name='hq'")?->fetchColumn();
        $this->assertNull($rootParent, 'root site stays parent_id=NULL');
    }

    // -----------------------------------------------------------------------
    // Binary fidelity end-to-end
    // -----------------------------------------------------------------------

    public function testBinaryColumnsRoundTripByteExact(): void
    {
        $target = $this->freshDb();
        ipam_restore_logical_apply($target, $this->fixturePath);

        $stmt = $target->query("SELECT network_bin FROM subnets WHERE cidr=" . $target->quote($this->sourceSubnetCidr));
        $blob = $stmt ? $stmt->fetchColumn() : false;
        if (is_resource($blob)) {
            $blob = stream_get_contents($blob);
        }
        $this->assertSame(inet_pton('10.1.0.0'), $blob, 'subnet.network_bin round-trips byte-exact');

        // Each address's ip_bin matches its ip text-form.
        $rows = $target->query("SELECT ip, ip_bin FROM addresses ORDER BY ip")?->fetchAll(PDO::FETCH_ASSOC) ?? [];
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $bin = $r['ip_bin'];
            if (is_resource($bin)) $bin = stream_get_contents($bin);
            $this->assertSame(inet_pton((string) $r['ip']), $bin, "address.ip_bin matches inet_pton(ip)");
        }
    }

    // -----------------------------------------------------------------------
    // Idempotent FK guard — running restore should not leak FK-disabled state
    // -----------------------------------------------------------------------

    public function testForeignKeysReEnabledAfterRestore(): void
    {
        $target = $this->freshDb();
        $target->exec('PRAGMA foreign_keys = ON');
        ipam_restore_logical_apply($target, $this->fixturePath);

        $val = $target->query('PRAGMA foreign_keys')?->fetchColumn();
        $this->assertSame(1, (int) $val, 'PRAGMA foreign_keys must be ON after restore exits');
    }

    // -----------------------------------------------------------------------
    // Schema-version compat: equal-version path is the basic case
    // -----------------------------------------------------------------------

    public function testEqualSchemaVersionAcceptsRestore(): void
    {
        $target = $this->freshDb();
        // Same migration set on source and target → schema_version equal → direct replay.
        $meta = ipam_restore_logical_apply($target, $this->fixturePath);
        $this->assertGreaterThan(0, $meta['total_rows']);
    }

    public function testNewerSchemaVersionInDumpRefuses(): void
    {
        $target = $this->freshDb();
        // Forge a fixture whose header claims a higher schema_version.
        $tampered = $this->forgeFixtureWithSchemaVersion(999_999_999);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/schema.*newer|upgrade.*install/i');
        ipam_restore_logical_apply($target, $tampered);
    }

    // -----------------------------------------------------------------------
    // Wizard dispatch — ipam_restore_apply() magic-byte sniff
    // -----------------------------------------------------------------------

    public function testRestoreApplyDispatchesLogicalMagicToLogicalReader(): void
    {
        $target = $this->freshDb();
        // Same fixture file built in setUp via the Logical writer.
        $result = ipam_restore_apply($target, $this->fixturePath);

        // Logical-path indicators in the result envelope.
        $this->assertSame('logical', $result['format'] ?? null);

        // And the data made it across the dispatcher.
        $sites = (int) ($target->query("SELECT COUNT(*) FROM sites")?->fetchColumn() ?? 0);
        $this->assertSame(2, $sites);
    }

    public function testRestoreSniffMagicDetectsIPAMBKL1(): void
    {
        $magic = ipam_restore_sniff_magic($this->fixturePath);
        $this->assertSame('IPAMBKL1', $magic);
    }

    public function testOlderSchemaVersionRestoreSucceeds(): void
    {
        $target = $this->freshDb();
        // Forge a fixture whose header claims a much lower schema_version
        // (simulates a backup taken from a v3.x install long before the
        // target's current migration high-water mark).
        $forged = $this->forgeFixtureWithSchemaVersion(1);

        $meta = ipam_restore_logical_apply($target, $forged);
        $this->assertGreaterThan(0, $meta['total_rows']);

        // Restore completed successfully — data is present.
        $sites = (int) ($target->query("SELECT COUNT(*) FROM sites")?->fetchColumn() ?? 0);
        $this->assertSame(2, $sites);

        // Target's migration history is preserved (NOT replaced by source's).
        // This is the load-bearing invariant: the install can keep
        // running migrations from where it left off without re-applying
        // anything during restore.
        $migs = (int) ($target->query("SELECT COUNT(*) FROM schema_migrations")?->fetchColumn() ?? 0);
        $this->assertGreaterThan(
            1,
            $migs,
            'target schema_migrations preserved across restore from older-version dump'
        );
    }

    /**
     * Rewrite the header line of a fixture to claim a different schema_version.
     */
    private function forgeFixtureWithSchemaVersion(int $newVersion): string
    {
        $raw = (string) gzdecode((string) file_get_contents($this->fixturePath));
        $lines = explode("\n", $raw);
        if (end($lines) === '') array_pop($lines);

        $h = json_decode($lines[1], true);
        if (!is_array($h)) {
            $this->fail('cannot parse header to forge');
        }
        $h['schema_version'] = $newVersion;
        $lines[1] = (string) json_encode($h, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $reassembled = implode("\n", $lines) . "\n";
        $forgedPath = tempnam(sys_get_temp_dir(), 'ipambkl1_forged_') . '.bkl1.gz';
        file_put_contents($forgedPath, (string) gzencode($reassembled, 9));
        return $forgedPath;
    }
}
