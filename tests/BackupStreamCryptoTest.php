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

    public function testV1BackupRestoresUnchanged(): void
    {
        $secret = 'unit-test-secret-not-real';
        $payload = random_bytes(8192);
        $v1Blob = backup_encrypt($payload, $secret); // single-shot GCM, IPAMBKP1
        $src = $this->tmpDir . '/v1.enc';
        $dst = $this->tmpDir . '/v1.dec';
        file_put_contents($src, $v1Blob);

        backup_decrypt_to_path($src, $dst, $secret);

        $this->assertSame(hash('sha256', $payload), hash_file('sha256', $dst));
    }

    public function testV2DispatchesToStream(): void
    {
        $secret = 'unit-test-secret-not-real';
        $payload = random_bytes(8192);
        $src = $this->tmpDir . '/v2-plain.bin';
        $enc = $this->tmpDir . '/v2.enc';
        $dst = $this->tmpDir . '/v2.dec';
        file_put_contents($src, $payload);

        backup_encrypt_stream($src, $enc, $secret);
        backup_decrypt_to_path($enc, $dst, $secret);

        $this->assertSame(hash('sha256', $payload), hash_file('sha256', $dst));
    }

    public function testBadMagicRejected(): void
    {
        $src = $this->tmpDir . '/junk.enc';
        $dst = $this->tmpDir . '/junk.dec';
        file_put_contents($src, "NOTBACKUP" . random_bytes(64));
        $this->expectException(RuntimeException::class);
        backup_decrypt_to_path($src, $dst, 'unit-test-secret-not-real');
    }
}
