<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BackupStreamCryptoTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ipam-bk-stream-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $f from glob() of test-controlled tmpDir
        }
        @rmdir($this->tmpDir);
    }

    public function testRoundTripSmallPayload(): void
    {
        $secret = 'unit-test-secret-not-real';
        $src = $this->tmpDir . '/plain.bin';
        $enc = $this->tmpDir . '/enc.bin';
        $dec = $this->tmpDir . '/dec.bin';
        $payload = random_bytes(4096);
        file_put_contents($src, $payload);

        backup_encrypt_stream($src, $enc, $secret);
        backup_decrypt_stream($enc, $dec, $secret);

        $this->assertSame(hash('sha256', $payload), hash_file('sha256', $dec));
        $this->assertSame('IPAMBKP2', substr((string) file_get_contents($enc), 0, 8));
    }
}
