<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

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
        $dataDir = realpath(__DIR__ . '/../Simple-PHP-IPAM/data');
        if ($dataDir === false) {
            mkdir(__DIR__ . '/../Simple-PHP-IPAM/data', 0775, true);
            $dataDir = realpath(__DIR__ . '/../Simple-PHP-IPAM/data');
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
}
