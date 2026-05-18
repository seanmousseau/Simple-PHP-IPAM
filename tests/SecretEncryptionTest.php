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

    public function testNearMissPrefixIsTreatedAsPlaintext(): void
    {
        // Missing trailing dot / wrong version tag must NOT be treated as an envelope.
        self::assertFalse(ipam_secret_is_envelope('IPAMSEC1'));
        self::assertFalse(ipam_secret_is_envelope('IPAMSEC2.abc'));
        self::assertSame('IPAMSEC1', ipam_secret_decrypt('IPAMSEC1'));
    }

    /**
     * Key-rotation smoke test. Models the operator rotating `app_secret`:
     * a `settings` row encrypted under the OLD key must become unreadable
     * (decrypts to null), NOT silently decrypt to wrong plaintext — the
     * IPAMSEC1 envelope's Poly1305 MAC is key-bound, so a mismatched key
     * fails the authenticator. We construct the "foreign" envelope with the
     * exact byte layout of ipam_secret_encrypt() (prefix + base64(nonce ‖
     * ciphertext)) but under an unrelated random 32-byte key, so the test is
     * fully deterministic and needs no config.php manipulation.
     */
    public function testForeignKeyEnvelopeDecryptsToNull(): void
    {
        $foreignKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher     = sodium_crypto_secretbox('rotated-out-secret', $nonce, $foreignKey);
        $foreignEnv = IPAM_SECRET_ENVELOPE_PREFIX . base64_encode($nonce . $cipher);

        // Well-formed envelope, but the current key cannot open it.
        self::assertTrue(ipam_secret_is_envelope($foreignEnv));
        self::assertNull(
            ipam_secret_decrypt($foreignEnv),
            'envelope from a different app_secret must be unreadable, never wrong plaintext'
        );
    }

    /**
     * The "no-op" half of key rotation: with the SAME (current) key in
     * place, encryption/decryption is unaffected — a normally-encrypted
     * value still round-trips. Pairs with testForeignKeyEnvelopeDecryptsToNull.
     */
    public function testKeyRotationIsNoOpForCurrentKey(): void
    {
        $value = 'still-valid-secret';
        self::assertSame($value, ipam_secret_decrypt(ipam_secret_encrypt($value)));
    }
}
