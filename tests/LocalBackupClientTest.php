<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class LocalBackupClientTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__) . '/Simple-PHP-IPAM/data/tmp/lbcTest_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $files = glob($this->dir . '/*');
            if (is_array($files)) {
                foreach ($files as $f) {
                    @unlink($f); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $f from glob() of test-controlled $this->dir under data/tmp/
                }
            }
            @rmdir($this->dir);
        }
    }

    public function testRejectsPathOutsideData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocalBackupClient(['path' => '/etc']);
    }

    public function testRejectsMissingPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocalBackupClient([]);
    }

    public function testCreatesDirectoryUnderDataAndUploads(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        $tmpSrc = tempnam(sys_get_temp_dir(), 'lbc');
        $this->assertNotFalse($tmpSrc);
        file_put_contents($tmpSrc, 'hello');
        $meta = $c->upload($tmpSrc, 'a.bak');
        @unlink($tmpSrc); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSrc is tempnam()-generated
        $this->assertSame(5, $meta['size']);
        $this->assertSame(64, strlen($meta['checksum']));
        $this->assertFileExists($this->dir . '/a.bak');
    }

    public function testListReturnsNewestFirst(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        file_put_contents($this->dir . '/old.bak', 'x');
        touch($this->dir . '/old.bak', time() - 3600);
        file_put_contents($this->dir . '/new.bak', 'y');
        $list = $c->listObjects();
        $this->assertCount(2, $list);
        $this->assertSame('new.bak', $list[0]['name']);
        $this->assertSame('old.bak', $list[1]['name']);
    }

    public function testDeleteRemovesFile(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        file_put_contents($this->dir . '/x.bak', 'x');
        $this->assertTrue($c->delete('x.bak'));
        $this->assertFileDoesNotExist($this->dir . '/x.bak');
    }

    public function testGuardRejectsTraversalNames(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        $this->expectException(InvalidArgumentException::class);
        $c->upload(__FILE__, '../escape');
    }

    public function testTestProbeReportsWritable(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        $r = $c->test();
        $this->assertTrue($r['ok']);
        $this->assertSame('directory writable', $r['message']);
    }

    // -----------------------------------------------------------------------
    // #834 / T3 — error-path + round-trip coverage for the Local destination.
    // The Playwright integration spec exercises the happy path against a
    // seeded ci-local destination; what was missing at the unit level was
    // permission-denied + missing-source + a checksum-validating round-trip.
    // -----------------------------------------------------------------------

    public function testUploadFromMissingSourceThrowsCopyFailed(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copy failed');
        $c->upload($this->dir . '/does-not-exist.src', 'whatever.bak');
    }

    public function testUploadIntoReadOnlyDirThrowsCopyFailed(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || posix_geteuid() === 0) {
            $this->markTestSkipped('chmod-based denial is unreliable on Windows or as root');
        }
        $c = new LocalBackupClient(['path' => $this->dir]);
        $tmp = tempnam(sys_get_temp_dir(), 'lbc');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'payload');

        // Strip write+execute so copy() into the dir fails with EACCES.
        chmod($this->dir, 0o500);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('copy failed');
            $c->upload($tmp, 'denied.bak');
        } finally {
            chmod($this->dir, 0o700);  // restore for tearDown
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmp is tempnam()-generated
        }
    }

    public function testUploadDownloadRoundTripChecksum(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);

        // Source payload — non-trivial size so a partial copy would mismatch.
        $payload = random_bytes(8 * 1024);
        $expectedSha = hash('sha256', $payload);
        $tmpSrc = tempnam(sys_get_temp_dir(), 'lbc');
        $this->assertNotFalse($tmpSrc);
        file_put_contents($tmpSrc, $payload);

        $meta = $c->upload($tmpSrc, 'rt.bak');
        @unlink($tmpSrc); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpSrc is tempnam()-generated
        $this->assertSame(strlen($payload), $meta['size']);
        $this->assertSame($expectedSha,    $meta['checksum']);

        // Round-trip via download path → byte-for-byte equality.
        $tmpDst = tempnam(sys_get_temp_dir(), 'lbc');
        $this->assertNotFalse($tmpDst);
        $this->assertTrue($c->download('rt.bak', $tmpDst));
        $this->assertSame(hash_file('sha256', $tmpDst), $expectedSha);
        @unlink($tmpDst); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpDst is tempnam()-generated

        // listObjects must surface the uploaded blob with matching size.
        $found = null;
        foreach ($c->listObjects() as $row) {
            if ($row['name'] === 'rt.bak') { $found = $row; break; }
        }
        $this->assertNotNull($found);
        $this->assertSame(strlen($payload), $found['size']);

        // delete() must remove it and leave subsequent listObjects empty of it.
        $this->assertTrue($c->delete('rt.bak'));
        foreach ($c->listObjects() as $row) {
            $this->assertNotSame('rt.bak', $row['name']);
        }
    }

    public function testDownloadOfMissingObjectReturnsFalse(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        $tmpDst = tempnam(sys_get_temp_dir(), 'lbc');
        $this->assertNotFalse($tmpDst);
        $this->assertFalse($c->download('not-there.bak', $tmpDst));
        @unlink($tmpDst); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tmpDst is tempnam()-generated
    }

    public function testDeleteOfMissingObjectReturnsFalse(): void
    {
        $c = new LocalBackupClient(['path' => $this->dir]);
        $this->assertFalse($c->delete('absent.bak'));
    }
}
