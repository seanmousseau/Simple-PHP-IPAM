<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class HkdfTest extends TestCase
{
    public function testDeterministic(): void
    {
        $a = ipam_hkdf_sha256('master', 'ipam-v3:backup', 32);
        $b = ipam_hkdf_sha256('master', 'ipam-v3:backup', 32);
        $this->assertSame($a, $b);
        $this->assertSame(32, strlen($a));
    }

    public function testInfoStringChangesKey(): void
    {
        $a = ipam_hkdf_sha256('master', 'ipam-v3:backup', 32);
        $b = ipam_hkdf_sha256('master', 'ipam-v3:totp',   32);
        $this->assertNotSame($a, $b);
    }

    public function testRfc5869VectorA1(): void
    {
        // RFC 5869 Test Case 1: SHA-256
        $ikm  = hex2bin('0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b');
        $salt = hex2bin('000102030405060708090a0b0c');
        $info = hex2bin('f0f1f2f3f4f5f6f7f8f9');
        $okm  = hex2bin('3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865');
        $this->assertSame($okm, ipam_hkdf_sha256((string)$ikm, (string)$info, 42, (string)$salt));
    }
}
