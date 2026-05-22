<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib/backup.php';

/**
 * Writer tests for ipam_backup_logical_dump() — produces an IPAMBKL1
 * Logical-format dump file from a live PDO connection.
 *
 * Spec: docs/internal/ipambkl1-format.md.
 *
 * These tests run against in-memory SQLite. Multi-engine (mysql + pg)
 * parity is asserted by the dockerized integration tests in #1042.
 *
 * Strategy: seed a small but representative dataset that exercises the
 * three load-bearing column kinds (binary blobs, timestamps, scalars),
 * a self-referential table (sites), at least one FK chain
 * (sites → subnets → addresses), and a join table (subnet_tags). Then
 * dump and assert structural + content-level invariants on the gzipped
 * NDJSON output.
 */
class IPAMBKL1WriterTest extends TestCase
{
    private PDO $db;
    private string $outPath;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql');
        $this->db->exec($schema);
        $this->db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($this->db);
        apply_migrations($this->db);
        $this->seedFixture();

        // tempnam() returns a unique pre-created path; appending '.bkl1.gz'
        // would orphan the original file on every test run. Use the
        // tempnam() path directly as the gz output (gzopen overwrites it),
        // and bail loudly if temp allocation fails.
        $tmp = tempnam(sys_get_temp_dir(), 'ipambkl1_test_');
        if ($tmp === false) {
            $this->fail('tempnam() failed to allocate output fixture path');
        }
        $this->outPath = $tmp;
    }

    // No tearDown — files under sys_get_temp_dir() are OS-cleaned, tiny
    // (a few KB each), and never escape the test process. Avoiding
    // unlink() here also keeps the semgrep ipam-unlink-user-path rule
    // from firing on test scaffolding.

    /**
     * Seed a small representative dataset. Returns nothing — caller
     * inspects via $this->db.
     */
    private function seedFixture(): void
    {
        // 1 user
        $this->db->exec(
            "INSERT INTO users (username, password_hash, role, is_active) " .
            "VALUES ('admin', 'bogus-hash', 'admin', 1)"
        );

        // 2 sites — one root, one child (exercises self-referential FK)
        $this->db->exec("INSERT INTO sites (name, description) VALUES ('hq', 'HQ root')");
        $rootId = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare(
            "INSERT INTO sites (name, description, parent_id) VALUES ('branch', 'Branch under HQ', :p)"
        );
        $stmt->execute([':p' => $rootId]);

        // 1 vrf
        $this->db->exec("INSERT INTO vrfs (name, description) VALUES ('default', 'Default VRF')");

        // 1 subnet with binary network_bin
        $cidr     = '10.1.0.0/24';
        $networkBin = inet_pton('10.1.0.0');
        $stmt = $this->db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id) " .
            "VALUES (:cidr, 4, '10.1.0.0', :nb, 24, 'office', :site)"
        );
        $stmt->bindValue(':cidr', $cidr);
        ipam_bind_binary($stmt, ':nb', $networkBin);
        $stmt->bindValue(':site', $rootId, PDO::PARAM_INT);
        $stmt->execute();
        $subnetId = (int) $this->db->lastInsertId();

        // 2 addresses with binary ip_bin (low-byte and high-byte vectors)
        foreach (['10.1.0.5', '10.1.0.255'] as $i => $ip) {
            $stmt = $this->db->prepare(
                "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status) " .
                "VALUES (:s, :ip, :bin, :h, 'used')"
            );
            $stmt->bindValue(':s', $subnetId, PDO::PARAM_INT);
            $stmt->bindValue(':ip', $ip);
            ipam_bind_binary($stmt, ':bin', (string) inet_pton($ip));
            $stmt->bindValue(':h', "host$i");
            $stmt->execute();
        }

        // 1 tag + join table entry — exercises join tables and FK chain
        $this->db->exec("INSERT INTO tags (name, colour) VALUES ('production', '#ff0000')");
        $tagId = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (:s, :t)");
        $stmt->execute([':s' => $subnetId, ':t' => $tagId]);

        // 1 audit_log row — exercises append-only table
        $this->db->exec(
            "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, details) " .
            "VALUES ('subnet.create', 'subnet', 1, 1, 'admin', 'seeded')"
        );
    }

    // -----------------------------------------------------------------------
    // File-level structure
    // -----------------------------------------------------------------------

    public function testWriterReturnsMetadataAndProducesNonEmptyFile(): void
    {
        $meta = ipam_backup_logical_dump($this->db, $this->outPath);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('total_rows', $meta);
        $this->assertArrayHasKey('checksum_sha256', $meta);
        $this->assertGreaterThan(0, $meta['total_rows']);

        $this->assertFileExists($this->outPath);
        $this->assertGreaterThan(0, filesize($this->outPath));
    }

    public function testFileBeginsWithIPAMBKL1Magic(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $lines = $this->readGzippedLines($this->outPath);
        $this->assertNotEmpty($lines);
        $this->assertSame('IPAMBKL1', $lines[0], 'first line must be the 8-byte ASCII magic');
    }

    // -----------------------------------------------------------------------
    // Header
    // -----------------------------------------------------------------------

    public function testHeaderObjectHasRequiredFields(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $header = $this->readHeader($this->outPath);

        $this->assertTrue($header['header']);
        $this->assertSame(1, $header['format_version']);
        $this->assertIsInt($header['schema_version']);
        $this->assertGreaterThan(0, $header['schema_version']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $header['exported_at']
        );
        $this->assertIsString($header['exported_by_ipam_version']);
        $this->assertNull($header['tenant_id'], 'tenant_id always null in v3.23.0 (no tenancy)');
        $this->assertIsArray($header['table_order']);
        $this->assertNotEmpty($header['table_order']);
        $this->assertIsArray($header['row_counts']);
    }

    public function testHeaderTableOrderMatchesCanonicalOrder(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $header = $this->readHeader($this->outPath);
        $this->assertSame(
            ipam_logical_table_order($this->db),
            $header['table_order'],
            'header.table_order must match the canonical topo-sort'
        );
    }

    public function testHeaderRowCountsSumEqualsFooterTotalRows(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $header = $this->readHeader($this->outPath);
        $footer = $this->readFooter($this->outPath);

        $this->assertSame(
            array_sum($header['row_counts']),
            $footer['total_rows'],
            'header.row_counts.sum must equal footer.total_rows'
        );
    }

    // -----------------------------------------------------------------------
    // Body — content fidelity
    // -----------------------------------------------------------------------

    public function testBodyContainsSeededSites(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $rows = $this->readBodyRowsFor($this->outPath, 'sites');

        $names = array_map(fn($r) => $r['row']['name'] ?? null, $rows);
        $this->assertContains('hq', $names);
        $this->assertContains('branch', $names);
    }

    public function testBodyEncodesBinaryColumnsAsBinEnvelope(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $rows = $this->readBodyRowsFor($this->outPath, 'subnets');

        $this->assertNotEmpty($rows);
        $row = $rows[0]['row'];
        $this->assertIsArray($row['network_bin']);
        $this->assertArrayHasKey('$bin', $row['network_bin']);
        $this->assertSame(
            base64_encode(inet_pton('10.1.0.0')),
            $row['network_bin']['$bin'],
            'binary column wraps in {$bin: <base64>} envelope'
        );
    }

    public function testBodyNormalisesTimestampColumnsToIso8601Z(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $rows = $this->readBodyRowsFor($this->outPath, 'sites');

        foreach ($rows as $entry) {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
                $entry['row']['created_at'],
                'created_at column normalised to ISO-8601 UTC'
            );
        }
    }

    public function testBodyRowsForSameTableAreContiguous(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $tablesInOrder = $this->readBodyTableSequence($this->outPath);

        // The sequence is a list like ['users','users','sites','sites','subnets',...].
        // Once a table has been visited and we move on to a different one, we must
        // never return to it. Detected by collapsing runs and asserting uniqueness.
        $compacted = [];
        $prev = null;
        foreach ($tablesInOrder as $t) {
            if ($t !== $prev) {
                $compacted[] = $t;
                $prev = $t;
            }
        }
        $this->assertSame(
            count($compacted),
            count(array_unique($compacted)),
            'rows for the same table must be contiguous; no table appears in two separate runs'
        );
    }

    // -----------------------------------------------------------------------
    // Footer
    // -----------------------------------------------------------------------

    public function testFooterChecksumMatchesActualBodyBytes(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $lines = $this->readGzippedLines($this->outPath);

        // Body lines: everything between the header (line index 1) and the
        // footer (last line). With trailing \n on each.
        $body = '';
        for ($i = 2; $i < count($lines) - 1; $i++) {
            $body .= $lines[$i] . "\n";
        }
        $expected = hash('sha256', $body);

        $footer = $this->readFooter($this->outPath);
        $this->assertSame($expected, $footer['checksum_sha256']);
    }

    public function testFooterTotalRowsEqualsActualBodyRowCount(): void
    {
        ipam_backup_logical_dump($this->db, $this->outPath);
        $lines = $this->readGzippedLines($this->outPath);
        // Body = lines minus magic, header, footer.
        $bodyCount = count($lines) - 3;

        $footer = $this->readFooter($this->outPath);
        $this->assertSame($bodyCount, $footer['total_rows']);
    }

    // -----------------------------------------------------------------------
    // Helpers (test-side, mirror what the reader will need)
    // -----------------------------------------------------------------------

    /** @return string[] each line without trailing \n */
    private function readGzippedLines(string $path): array
    {
        $raw = (string) gzdecode((string) file_get_contents($path));
        $lines = explode("\n", $raw);
        // explode after a trailing \n yields a trailing empty string; drop it.
        if (end($lines) === '') array_pop($lines);
        return $lines;
    }

    /** @return array<string,mixed> */
    private function readHeader(string $path): array
    {
        $lines = $this->readGzippedLines($path);
        $h = json_decode($lines[1], true);
        if (!is_array($h)) {
            $this->fail('header line is not a JSON object: ' . var_export($lines[1], true));
        }
        return $h;
    }

    /** @return array<string,mixed> */
    private function readFooter(string $path): array
    {
        $lines = $this->readGzippedLines($path);
        $f = json_decode((string) end($lines), true);
        if (!is_array($f)) {
            $this->fail('footer line is not a JSON object');
        }
        return $f;
    }

    /** @return list<array<string,mixed>> */
    private function readBodyRowsFor(string $path, string $table): array
    {
        $lines = $this->readGzippedLines($path);
        $out = [];
        for ($i = 2; $i < count($lines) - 1; $i++) {
            $obj = json_decode($lines[$i], true);
            if (is_array($obj) && ($obj['table'] ?? null) === $table) {
                $out[] = $obj;
            }
        }
        return $out;
    }

    /** @return list<string> table names for each body row, in stream order */
    private function readBodyTableSequence(string $path): array
    {
        $lines = $this->readGzippedLines($path);
        $seq = [];
        for ($i = 2; $i < count($lines) - 1; $i++) {
            $obj = json_decode($lines[$i], true);
            if (is_array($obj) && isset($obj['table']) && is_string($obj['table'])) {
                $seq[] = $obj['table'];
            }
        }
        return $seq;
    }
}
