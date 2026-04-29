<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #762 item 3: every write/read into the restore
 * staging area must go through one centralized "must be under data/tmp/"
 * helper, so a future refactor cannot accidentally bypass containment.
 *
 * The current code derives staged paths from a fixed $tmpDir and a
 * server-generated random suffix, so user input cannot influence the
 * write path today. The helper exists as defence-in-depth: if a future
 * change introduces user input into the path construction, the
 * containment check fails-closed.
 */
final class RestoreStagingPathTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        // The helper anchors on dirname(__FILE__-of-lib/backup.php) . '/data/tmp'
        // i.e. Simple-PHP-IPAM/data/tmp. Make sure it exists for realpath().
        $this->tmpDir = realpath(__DIR__ . '/../Simple-PHP-IPAM') . '/data/tmp';
        if (!is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0775, true);
        }
    }

    public function testAssertAcceptsLegitimateStagedPath(): void
    {
        $path = $this->tmpDir . '/restore_staged_' . bin2hex(random_bytes(4)) . '.sql.gz';
        ipam_restore_assert_staged_path($path); // void on success
        $this->assertTrue(true);
    }

    public function testAssertRejectsTraversalUnderTmpDir(): void
    {
        $this->expectException(RuntimeException::class);
        ipam_restore_assert_staged_path($this->tmpDir . '/../../etc/passwd');
    }

    public function testAssertRejectsAbsolutePathOutsideTmpDir(): void
    {
        $this->expectException(RuntimeException::class);
        ipam_restore_assert_staged_path('/etc/passwd');
    }

    public function testAssertRejectsDataRootSqlite(): void
    {
        $this->expectException(RuntimeException::class);
        ipam_restore_assert_staged_path(dirname($this->tmpDir) . '/ipam.sqlite');
    }

    public function testAssertRejectsEmptyPath(): void
    {
        $this->expectException(RuntimeException::class);
        ipam_restore_assert_staged_path('');
    }

    public function testCanonicalizeReturnsRealpathForExistingFile(): void
    {
        $real = $this->tmpDir . '/restore_canon_' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($real, 'x');
        try {
            $canon = ipam_restore_canonicalize_staged($real);
            $this->assertSame(realpath($real), $canon);
        } finally {
            @unlink($real);
        }
    }

    public function testCanonicalizeReturnsNullForOutsideTmpDir(): void
    {
        $this->assertNull(ipam_restore_canonicalize_staged('/etc/passwd'));
    }

    public function testCanonicalizeReturnsNullForNonExistentFile(): void
    {
        $this->assertNull(ipam_restore_canonicalize_staged($this->tmpDir . '/does-not-exist.sql.gz'));
    }
}
