<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class BackupUploadFileModeTest extends TestCase
{
    public function testStagedUploadModeIs0640(): void
    {
        // ipam_restore_stage_uploaded_file() enforces the staging containment
        // invariant: $dst MUST live under Simple-PHP-IPAM/data/tmp/. Place
        // both the (test-owned) src and dst inside the sanctioned dir.
        $stagingRoot = realpath(__DIR__ . '/../../Simple-PHP-IPAM/data/tmp');
        $this->assertNotFalse($stagingRoot, 'Simple-PHP-IPAM/data/tmp must exist');
        $suffix = bin2hex(random_bytes(4));
        $src = $stagingRoot . '/upload-mode-src-' . $suffix . '.bin';
        $dst = $stagingRoot . '/upload-mode-dst-' . $suffix;

        try {
            file_put_contents($src, "IPAMBKP3");
            chmod($src, 0666);
            $this->assertTrue(ipam_restore_stage_uploaded_file($src, $dst));
            clearstatcache(true, $dst);
            $mode = fileperms($dst) & 0777;
            $this->assertSame(0640, $mode, sprintf('expected 0640, got 0%o', $mode));
        } finally {
            @unlink($dst); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-owned staging path
            @unlink($src); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-owned staging path
        }
    }

    public function testStagedUploadRejectsPathOutsideTmpdir(): void
    {
        // Pin the containment guard: any $dst outside data/tmp/ must throw.
        $outsideDir = sys_get_temp_dir() . '/ipam-bkup-outside-' . bin2hex(random_bytes(4));
        mkdir($outsideDir, 0700, true);
        try {
            $src = $outsideDir . '/src.bin';
            file_put_contents($src, "x");
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/staged path is not under data\/tmp/');
            ipam_restore_stage_uploaded_file($src, $outsideDir . '/dst');
        } finally {
            @unlink($outsideDir . '/src.bin'); // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-owned tempdir
            @rmdir($outsideDir);
        }
    }
}
