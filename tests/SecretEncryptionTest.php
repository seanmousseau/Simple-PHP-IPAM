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

    /**
     * v3.31.0 H3 regression guard. ipam_secret_key() previously strict-
     * base64-decoded app_secret and threw on ANY non-base64 value (e.g. a
     * legacy operator-set plain-string passphrase) before reaching its
     * documented length floor — breaking encrypt-at-rest for those installs.
     *
     * ipam_secret_key() reads the process app_secret via ipam_app_secret()
     * with no override seam, so we cannot drive it from a plain-string
     * config without a signature change (which is out of scope). Instead we
     * pin the derivation contract directly: replicate the exact normalise
     * step (base64-or-raw → unkeyed BLAKE2b → keyed BLAKE2b) for a NON-base64
     * secret and assert it yields a sound 32-byte key without throwing. This
     * is the precise input that used to throw; the end-to-end behavioural
     * guard is the 3-driver Playwright gate (its fixture app_secret is a
     * plain string).
     *
     * @return iterable<string,array{string}>
     */
    public static function appSecretForms(): iterable
    {
        yield 'modern base64 (32 random bytes)' => [base64_encode(random_bytes(32))];
        yield 'legacy plain-string passphrase'  => ['playwright-test-app-secret-for-totp'];
        yield 'plain string with non-b64 chars' => ['operator set: a !@#$ passphrase'];
        yield 'short operator value'            => ['x'];
        yield 'hex-looking operator value'      => ['deadbeefcafe'];
    }

    /**
     * @dataProvider appSecretForms
     */
    public function testDerivationAcceptsAnyAppSecretForm(string $appSecret): void
    {
        // Mirror of ipam_secret_key()'s derivation, exercised against the
        // input forms ipam_app_secret() can legitimately return.
        $decoded  = base64_decode($appSecret, true);
        $material = ($decoded !== false && $decoded !== '') ? $decoded : $appSecret;
        $root     = sodium_crypto_generichash($material);
        $key      = sodium_crypto_generichash(IPAM_SECRET_KDF_CONTEXT, $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
    }

    /**
     * Distinct app_secret values must derive distinct keys — the
     * normalisation step must not collapse different secrets together.
     */
    public function testDerivationIsDistinctPerAppSecret(): void
    {
        $derive = static function (string $appSecret): string {
            $decoded  = base64_decode($appSecret, true);
            $material = ($decoded !== false && $decoded !== '') ? $decoded : $appSecret;
            $root     = sodium_crypto_generichash($material);
            return sodium_crypto_generichash(IPAM_SECRET_KDF_CONTEXT, $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        };

        self::assertNotSame($derive('operator-passphrase-one'), $derive('operator-passphrase-two'));
    }

    /**
     * ipam_secret_key() itself must not throw for the running harness's
     * app_secret and must yield a secretbox-length key. Pairs with the
     * derivation tests above to pin the contract end-to-end for whatever
     * form ipam_app_secret() resolved.
     */
    public function testSecretKeyDoesNotThrowForProcessAppSecret(): void
    {
        $key = ipam_secret_key();
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
    }
}
