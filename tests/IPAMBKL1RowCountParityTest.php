<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * Restore-to-empty-DB row-count parity (#835 / T4).
 *
 * Closes the v3.23.0 acceptance criterion: a Logical-format dump applied
 * to a fresh database must yield identical per-table row counts to the
 * source. This is the broadest end-to-end correctness assertion the
 * IPAMBKL1 backend supports — narrower-scoped tests
 * (IPAMBKL1WriterTest, IPAMBKL1RestoreTest) cover specific invariants;
 * this test pins the integral promise.
 *
 * Multi-engine + cross-engine 3×3 parity lives in #1042's dockerized
 * integration tests; here we cover the sqlite reference case with a
 * deliberately broader fixture that touches every interesting table
 * shape:
 *   - Tables with auto-increment integer PKs (users, sites, vrfs, …)
 *   - Tables with composite PKs / no auto-increment (subnet_tags,
 *     address_tags)
 *   - Tables with binary blob columns (subnets.network_bin,
 *     addresses.ip_bin)
 *   - Self-referential tables (sites)
 *   - Append-only tables with triggers (audit_log)
 *   - Tables that the wipe pass intentionally preserves
 *     (schema_migrations)
 */
class IPAMBKL1RowCountParityTest extends TestCase
{
    public function testFullSchemaRoundTripPreservesPerTableRowCounts(): void
    {
        $source = $this->buildPopulatedSource();

        // Capture source row counts BEFORE dumping.
        $expected = $this->captureRowCounts($source);

        // Dump → fresh target → restore via dispatcher (which sniffs IPAMBKL1
        // magic and delegates to the logical path).
        $fixture = tempnam(sys_get_temp_dir(), 'ipambkl1_t4_') . '.bkl1.gz';
        ipam_backup_logical_dump($source, $fixture);

        $target = $this->freshDb();
        $result = ipam_restore_apply($target, $fixture);
        $this->assertSame('logical', $result['format'] ?? null, 'dispatcher took the Logical path');

        // Row-count parity per table — the load-bearing T4 assertion.
        $actual = $this->captureRowCounts($target);

        // Two tables intentionally diverge from strict per-table parity by
        // documented restore semantics:
        //
        //   - schema_migrations — target's migration history is preserved
        //     across restore (the install must be able to resume normal
        //     migration flow), so the count reflects target's chain not
        //     source's. Equality not expected.
        //
        //   - audit_log — append-only by trigger; restore does not (and
        //     cannot) DELETE prior rows. Source's audit history *appends*
        //     to whatever the target carries, which means rows the two
        //     installs share (e.g. a row emitted by a migration both
        //     source and target ran) are duplicated post-restore. Counts
        //     diverge by exactly the size of the shared baseline.
        //
        // Both divergences are spec'd in docs/internal/ipambkl1-format.md.
        // T4 parity therefore asserts *user-data* parity over every other
        // table.
        unset(
            $expected['schema_migrations'], $actual['schema_migrations'],
            $expected['audit_log'],         $actual['audit_log']
        );

        $this->assertSame(
            $expected,
            $actual,
            'restore-to-empty-DB row-count parity (T4) — per-table counts must match source exactly'
        );
    }

    /**
     * Build a source DB with rows in many tables. The shape doesn't matter
     * for this test — only the per-table counts. Fixture is intentionally
     * heterogeneous to widen the parity surface.
     */
    private function buildPopulatedSource(): PDO
    {
        $db = $this->freshDb();

        // 3 users.
        for ($i = 1; $i <= 3; $i++) {
            $stmt = $db->prepare(
                "INSERT INTO users (username, password_hash, role, is_active) VALUES (:u, :h, :r, 1)"
            );
            $stmt->execute([':u' => "user$i", ':h' => 'bogus-hash', ':r' => $i === 1 ? 'admin' : 'readonly']);
        }

        // 3 sites in a 2-level hierarchy.
        $db->exec("INSERT INTO sites (name, description) VALUES ('hq', 'HQ')");
        $hqId = (int) $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO sites (name, description, parent_id) VALUES (:n, :d, :p)");
        $stmt->execute([':n' => 'branch-a', ':d' => 'A', ':p' => $hqId]);
        $stmt->execute([':n' => 'branch-b', ':d' => 'B', ':p' => $hqId]);

        // 2 vrfs.
        $db->exec("INSERT INTO vrfs (name, description) VALUES ('default', '')");
        $db->exec("INSERT INTO vrfs (name, description) VALUES ('mgmt', 'mgmt vrf')");

        // 1 vlan.
        $stmt = $db->prepare(
            "INSERT INTO vlans (vlan_id, name, description, site_id) VALUES (10, 'office', '', :s)"
        );
        $stmt->execute([':s' => $hqId]);

        // 4 contacts.
        for ($i = 1; $i <= 4; $i++) {
            $stmt = $db->prepare("INSERT INTO contacts (name, email) VALUES (:n, :e)");
            $stmt->execute([':n' => "Contact $i", ':e' => "c$i@example.com"]);
        }

        // 5 subnets each with binary network_bin, attached to hqId.
        $subnetIds = [];
        for ($i = 0; $i < 5; $i++) {
            $cidr = "10.$i.0.0/24";
            $stmt = $db->prepare(
                "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id) " .
                "VALUES (:cidr, 4, :net, :nb, 24, :desc, :site)"
            );
            $stmt->bindValue(':cidr', $cidr);
            $stmt->bindValue(':net',  "10.$i.0.0");
            ipam_bind_binary($stmt, ':nb', (string) inet_pton("10.$i.0.0"));
            $stmt->bindValue(':desc', "subnet $i");
            $stmt->bindValue(':site', $hqId, PDO::PARAM_INT);
            $stmt->execute();
            $subnetIds[] = (int) $db->lastInsertId();
        }

        // 20 addresses (4 per subnet) — exercises binary ip_bin at scale.
        foreach ($subnetIds as $i => $sid) {
            for ($j = 1; $j <= 4; $j++) {
                $ip = "10.$i.0.$j";
                $stmt = $db->prepare(
                    "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status) " .
                    "VALUES (:s, :ip, :bin, :h, :st)"
                );
                $stmt->bindValue(':s',   $sid, PDO::PARAM_INT);
                $stmt->bindValue(':ip',  $ip);
                ipam_bind_binary($stmt, ':bin', (string) inet_pton($ip));
                $stmt->bindValue(':h',   "host-$i-$j");
                $stmt->bindValue(':st',  $j === 1 ? 'reserved' : 'used');
                $stmt->execute();
            }
        }

        // 3 tags.
        foreach (['production', 'staging', 'dev'] as $name) {
            $stmt = $db->prepare("INSERT INTO tags (name, colour) VALUES (:n, '#888888')");
            $stmt->execute([':n' => $name]);
        }
        $tagIds = (array) ($db->query("SELECT id FROM tags ORDER BY id")?->fetchAll(PDO::FETCH_COLUMN) ?? []);

        // 6 subnet_tags rows — (5 subnets × varied tags).
        $stmt = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (:s, :t)");
        $pairs = [
            [$subnetIds[0], (int) $tagIds[0]],
            [$subnetIds[1], (int) $tagIds[0]],
            [$subnetIds[1], (int) $tagIds[1]],
            [$subnetIds[2], (int) $tagIds[2]],
            [$subnetIds[3], (int) $tagIds[1]],
            [$subnetIds[4], (int) $tagIds[2]],
        ];
        foreach ($pairs as $p) {
            $stmt->execute([':s' => $p[0], ':t' => $p[1]]);
        }

        // 5 audit_log rows.
        for ($i = 1; $i <= 5; $i++) {
            $stmt = $db->prepare(
                "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, details) " .
                "VALUES (:a, :e, :id, 1, 'user1', :d)"
            );
            $stmt->execute([
                ':a'  => "subnet.update",
                ':e'  => 'subnet',
                ':id' => $subnetIds[$i - 1],
                ':d'  => "audit row $i",
            ]);
        }

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

    /**
     * @return array<string,int> table name → row count, for every user table.
     */
    private function captureRowCounts(PDO $db): array
    {
        $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $tables = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $out = [];
        foreach ($tables as $t) {
            if (!is_string($t)) continue;
            $r = $db->query("SELECT COUNT(*) FROM \"$t\"");
            $val = $r ? $r->fetchColumn() : 0;
            $out[$t] = is_numeric($val) ? (int) $val : 0;
        }
        return $out;
    }
}
