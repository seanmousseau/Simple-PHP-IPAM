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
        $list = $c->list();
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
}
