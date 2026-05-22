<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';

/**
 * #828 / B-P1-15 — legacy retention sort by mtime, not lex.
 *
 * Pre-fix: rsort() on filenames silently broke when filenames diverged
 * from the canonical `ipam-YYYY-MM-DD-HHMMSS` format, since lex order
 * stopped matching creation order. Sort-by-mtime is the correct policy
 * until the legacy v3.7 runner is hard-removed in v3.26.0 (#1059).
 */
class LegacyRetentionMtimeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ipam-retention-mtime-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        if (is_array($files)) {
            foreach ($files as $f) @unlink($f); // nosemgrep
        }
        @rmdir($this->dir);
    }

    public function testPrunesByMtimeNotByName(): void
    {
        // Filenames in REVERSE creation order (lex would prune the wrong side).
        // older-on-disk file gets a "newer" lex name, and vice versa.
        $oldest = $this->touch('ipam-zzz.sqlite', time() - 5000);
        $mid    = $this->touch('ipam-mmm.sqlite', time() - 3000);
        $newest = $this->touch('ipam-aaa.sqlite', time() - 100);

        // Keep 1 most-recent.
        ipam_legacy_retention_prune_by_mtime($this->dir . '/ipam-*.sqlite', 1);

        $this->assertFileDoesNotExist($oldest, 'oldest by mtime must be pruned');
        $this->assertFileDoesNotExist($mid,    'mid by mtime must be pruned');
        $this->assertFileExists($newest,       'newest by mtime must survive');
    }

    public function testRetainsAllWhenWithinLimit(): void
    {
        $a = $this->touch('ipam-1.sqlite', time() - 30);
        $b = $this->touch('ipam-2.sqlite', time() - 20);

        ipam_legacy_retention_prune_by_mtime($this->dir . '/ipam-*.sqlite', 5);

        $this->assertFileExists($a);
        $this->assertFileExists($b);
    }

    public function testNoOpOnEmptyDir(): void
    {
        ipam_legacy_retention_prune_by_mtime($this->dir . '/ipam-*.sqlite', 3);
        $this->assertSame([], glob($this->dir . '/*'));
    }

    public function testZeroRetentionPrunesEverything(): void
    {
        $a = $this->touch('ipam-a.sql', time() - 10);
        $b = $this->touch('ipam-b.sql', time() - 5);

        ipam_legacy_retention_prune_by_mtime($this->dir . '/ipam-*.sql', 0);

        $this->assertFileDoesNotExist($a);
        $this->assertFileDoesNotExist($b);
    }

    private function touch(string $name, int $mtime): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, $mtime);
        return $path;
    }
}
