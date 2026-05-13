<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class BackupUploadFileModeTest extends TestCase
{
    public function testStagedUploadModeIs0640(): void
    {
        $tmpDir = sys_get_temp_dir() . '/ipam-bkup-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0700, true);
        try {
            $src = $tmpDir . '/src.bin';
            file_put_contents($src, "IPAMBKP3");
            chmod($src, 0666);
            $dst = $tmpDir . '/restore_dl_test';
            $this->assertTrue(ipam_restore_stage_uploaded_file($src, $dst));
            clearstatcache(true, $dst);
            $mode = fileperms($dst) & 0777;
            $this->assertSame(0640, $mode, sprintf('expected 0640, got 0%o', $mode));
        } finally {
            @unlink($tmpDir . '/restore_dl_test');
            @unlink($tmpDir . '/src.bin');
            @rmdir($tmpDir);
        }
    }
}
