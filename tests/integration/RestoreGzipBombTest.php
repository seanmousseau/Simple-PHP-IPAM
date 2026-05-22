<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib/backup.php';

/**
 * #1149 (Pass C S-003): a gzip bomb passes the compressed-upload cap
 * (backup_max_upload_size_mb is enforced on the compressed bytes) and only
 * explodes on decompression, exhausting data/tmp/. ipam_restore_read_staged_sql()
 * — the streaming reader used by dry-run and apply — must cap the decompressed
 * byte count and abort.
 *
 * Fixture files use fixed names under Simple-PHP-IPAM/data/tmp/ (the scratch
 * dir restore staging already uses) and are overwritten each run, so they are
 * bounded to two small compressed files; the `restore_dl_` prefix means the
 * app's tmp-cleanup housekeeping reaps them. No unlink() — keeps the file ops
 * trivially auditable.
 */
class RestoreGzipBombTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $dataDir = realpath(__DIR__ . '/../../Simple-PHP-IPAM/data');
        if ($dataDir === false) {
            mkdir(__DIR__ . '/../../Simple-PHP-IPAM/data', 0775, true);
            $dataDir = realpath(__DIR__ . '/../../Simple-PHP-IPAM/data');
        }
        $this->tmpDir = $dataDir . '/tmp';
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0775, true);
        }
    }

    public function testDecompressedSizeCapRejectsAGzipBomb(): void
    {
        // ~80 MiB of plaintext (well over the 64 MiB floor cap), written
        // chunk-by-chunk so the test never holds it all in memory. Highly
        // compressible -> the on-disk .sql.gz is a few KB.
        $bombPath = $this->tmpDir . '/restore_dl_pwtest_gzipbomb.sql.gz';
        $gz = gzopen($bombPath, 'wb1');
        $this->assertNotFalse($gz, 'gzopen for bomb fixture');
        $chunk = str_repeat('0', 1024 * 1024); // 1 MiB of '0' bytes
        for ($i = 0; $i < 80; $i++) {
            gzwrite($gz, $chunk);
        }
        gzclose($gz);
        $this->assertLessThan(5 * 1024 * 1024, (int) filesize($bombPath), 'bomb fixture should compress small');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/decompressed size exceeds/i');

        // Draining the generator must throw before it reads the full 80 MiB.
        foreach (ipam_restore_read_staged_sql($bombPath) as $_chunk) {
            // no-op
        }
    }

    public function testLegitimateSmallGzDumpStillStreams(): void
    {
        $path = $this->tmpDir . '/restore_dl_pwtest_gziplegit.sql.gz';
        $gz = gzopen($path, 'wb6');
        $this->assertNotFalse($gz);
        gzwrite($gz, "-- IPAM SQLite dump\nBEGIN;\nCREATE TABLE t (id INTEGER);\nINSERT INTO t VALUES (1);\nCOMMIT;\n");
        gzclose($gz);
        $lines = [];
        foreach (ipam_restore_read_staged_sql($path) as $line) {
            $lines[] = $line;
        }
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('CREATE TABLE t', implode('', $lines));
    }

    // ── ipam_restore_max_decompressed_bytes() — the cap calculation ──────────

    private const MiB = 1024 * 1024;

    public function testDecompressedCapFloorAppliesToSmallUploads(): void
    {
        $floor = 64 * self::MiB;
        // A tiny upload — including any realistic gzip bomb (a few KB compressed) —
        // gets only the floor, so it can never blow data/tmp/ to GB scale.
        $this->assertSame($floor, ipam_restore_max_decompressed_bytes(0));
        $this->assertSame($floor, ipam_restore_max_decompressed_bytes(4 * 1024));      // 4 KiB
        $this->assertSame($floor, ipam_restore_max_decompressed_bytes(2 * self::MiB)); // 25× = 50 MiB < floor
        $this->assertSame($floor, ipam_restore_max_decompressed_bytes(-5));            // defensive: negative → floor
    }

    public function testDecompressedCapScalesWithUploadAboveTheFloor(): void
    {
        // 4 MiB compressed → 100 MiB cap (25×). The old #1149 10× cap would have
        // rejected a realistic ~15–20× dump here (40 MiB < a ~70 MiB plaintext);
        // 25× accepts it.
        $this->assertSame(100 * self::MiB, ipam_restore_max_decompressed_bytes(4 * self::MiB));
        $this->assertSame(250 * self::MiB, ipam_restore_max_decompressed_bytes(10 * self::MiB));
        // A realistically-sized Database-format dump: 7.4 MiB compressed → ~124 MiB
        // plaintext (~17×) must be comfortably under the cap.
        $cap = ipam_restore_max_decompressed_bytes((int) (7.4 * self::MiB));
        $this->assertGreaterThan(124 * self::MiB, $cap);
    }

    public function testDecompressedCapHasAbsoluteCeiling(): void
    {
        $ceiling = 4 * 1024 * 1024 * 1024;
        // 200 MiB compressed × 25 = 5 GiB → clamped to the 4 GiB ceiling so even
        // the largest allowed compressed upload can't expand unboundedly.
        $this->assertSame($ceiling, ipam_restore_max_decompressed_bytes(200 * self::MiB));
        $this->assertSame($ceiling, ipam_restore_max_decompressed_bytes(1024 * self::MiB));
    }
}
