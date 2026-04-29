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

    public function testTamperedCiphertextFailsHmac(): void
    {
        $secret = 'unit-test-secret-not-real';
        $src = $this->tmpDir . '/p.bin';
        $enc = $this->tmpDir . '/e.bin';
        $dst = $this->tmpDir . '/d.bin';
        file_put_contents($src, random_bytes(4096));
        backup_encrypt_stream($src, $enc, $secret);

        // Flip a byte deep inside the ciphertext (after header, before HMAC tail).
        $blob = (string) file_get_contents($enc);
        $blob[100] = chr(ord($blob[100]) ^ 0xff);
        file_put_contents($enc, $blob);

        $this->expectExceptionMessageMatches('/hmac mismatch/');
        backup_decrypt_stream($enc, $dst, $secret);
    }

    public function testTamperedHmacTagFails(): void
    {
        $secret = 'unit-test-secret-not-real';
        $src = $this->tmpDir . '/p.bin';
        $enc = $this->tmpDir . '/e.bin';
        $dst = $this->tmpDir . '/d.bin';
        file_put_contents($src, random_bytes(4096));
        backup_encrypt_stream($src, $enc, $secret);

        $blob = (string) file_get_contents($enc);
        $last = strlen($blob) - 1;
        $blob[$last] = chr(ord($blob[$last]) ^ 0x01);
        file_put_contents($enc, $blob);

        $this->expectExceptionMessageMatches('/hmac mismatch/');
        backup_decrypt_stream($enc, $dst, $secret);
    }

    public function testTruncatedFileFails(): void
    {
        $secret = 'unit-test-secret-not-real';
        $src = $this->tmpDir . '/p.bin';
        $enc = $this->tmpDir . '/e.bin';
        $dst = $this->tmpDir . '/d.bin';
        file_put_contents($src, random_bytes(4096));
        backup_encrypt_stream($src, $enc, $secret);

        $blob = (string) file_get_contents($enc);
        file_put_contents($enc, substr($blob, 0, strlen($blob) - 8));

        $this->expectException(RuntimeException::class);
        backup_decrypt_stream($enc, $dst, $secret);
    }

    /**
     * Acceptance test from issue #769: stream-encrypt + stream-decrypt a >100 MB
     * payload while peak memory usage stays well below 128 MiB.
     *
     * Skipped if the test host has less than 512 MiB of free /tmp space.
     *
     * @group slow
     */
    public function testLargePayloadStreamsBelowMemoryCap(): void
    {
        $tmpFree = @disk_free_space($this->tmpDir);
        if ($tmpFree !== false && $tmpFree < 512 * 1024 * 1024) {
            $this->markTestSkipped('insufficient free /tmp space (need 512 MiB headroom)');
        }
        $secret = 'unit-test-secret-not-real';
        $src = $this->tmpDir . '/big.bin';
        $enc = $this->tmpDir . '/big.enc';
        $dec = $this->tmpDir . '/big.dec';

        // Build a 110 MiB synthetic file in 1 MiB chunks.
        $fh = fopen($src, 'wb');
        $this->assertNotFalse($fh);
        $block = str_repeat("ABCDEFGHIJKLMNOP", 65536); // 1 MiB
        for ($i = 0; $i < 110; $i++) fwrite($fh, $block);
        fclose($fh);

        $beforeEnc = memory_get_peak_usage(true);
        backup_encrypt_stream($src, $enc, $secret);
        backup_decrypt_stream($enc, $dec, $secret);
        $peak = memory_get_peak_usage(true);

        $this->assertSame(hash_file('sha256', $src), hash_file('sha256', $dec));
        $this->assertLessThan(96 * 1024 * 1024, $peak,
            sprintf('peak memory %d exceeded 96 MiB cap (started at %d)', $peak, $beforeEnc));
        $this->assertGreaterThan(110 * 1024 * 1024, filesize($enc));
    }
}
