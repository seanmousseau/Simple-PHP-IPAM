<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class SecretEncryptionTest extends TestCase
{
    public function testKeyIsSecretboxKeyLength(): void
    {
        $key = ipam_secret_key();
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
    }

    public function testKeyIsDeterministicForSameAppSecret(): void
    {
        self::assertSame(ipam_secret_key(), ipam_secret_key());
    }

    public function testRoundTrip(): void
    {
        $env = ipam_secret_encrypt('hunter2');
        self::assertStringStartsWith(IPAM_SECRET_ENVELOPE_PREFIX, $env);
        self::assertSame('hunter2', ipam_secret_decrypt($env));
    }

    public function testRoundTripEmptyString(): void
    {
        self::assertSame('', ipam_secret_decrypt(ipam_secret_encrypt('')));
    }

    public function testEachEncryptUsesFreshNonce(): void
    {
        self::assertNotSame(ipam_secret_encrypt('x'), ipam_secret_encrypt('x'));
    }

    public function testPlaintextPassthroughOnDecrypt(): void
    {
        self::assertSame('not-an-envelope', ipam_secret_decrypt('not-an-envelope'));
    }

    public function testTamperedCiphertextReturnsNull(): void
    {
        $env = ipam_secret_encrypt('topsecret');
        $tampered = substr($env, 0, -4) . 'AAAA';
        self::assertNull(ipam_secret_decrypt($tampered));
    }

    public function testMalformedEnvelopeReturnsNull(): void
    {
        self::assertNull(ipam_secret_decrypt(IPAM_SECRET_ENVELOPE_PREFIX . 'short'));
    }

    public function testIsEnvelopeDetection(): void
    {
        self::assertTrue(ipam_secret_is_envelope(ipam_secret_encrypt('a')));
        self::assertFalse(ipam_secret_is_envelope('plain'));
    }
}
