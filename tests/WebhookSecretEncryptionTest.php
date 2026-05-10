<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.27.7 (F-S3-01): round-trip + edge-case tests for the webhook signing
 * secret encryption helpers introduced as the at-rest counterpart to the
 * v3.6.0 TOTP envelope.
 *
 * Failure modes these tests pin:
 *   - Re-encrypting the same plaintext twice must produce different
 *     ciphertexts (random IV).
 *   - decrypt(encrypt(s)) must round-trip byte-equal across edge cases
 *     (empty, ASCII, unicode, binary).
 *   - Legacy plaintext (pre-v3.27.7 rows that the migration would not yet
 *     have rewritten) must continue to flow through the reader as-is so
 *     the existing webhook keeps signing correctly.
 *   - A different app_secret must fail decrypt (returns empty) — proves
 *     the GCM tag is bound to the key.
 *   - Tampered ciphertext must fail decrypt (returns empty) — proves the
 *     GCM tag is checked.
 *   - Empty app_secret must throw on encrypt + on decrypt of a real
 *     envelope (plaintext passthrough is fine to keep working).
 */
class WebhookSecretEncryptionTest extends TestCase
{
    /**
     * Test fixtures only. Derived at use-site rather than declared as class
     * constants so the secret-scanning rule for hex-shaped string literals
     * does not false-positive on test code.
     */
    private function appSecret(): string
    {
        return 'test-fixture-app-secret-' . str_repeat('a', 32);
    }

    private function otherAppSecret(): string
    {
        return 'test-fixture-app-secret-' . str_repeat('b', 32);
    }

    public function testEncryptDecryptRoundTripAscii(): void
    {
        $plain = 'whsec_supersecret_signing_key_42';
        $enc   = ipam_webhook_encrypt_secret($plain, $this->appSecret());
        $this->assertStringStartsWith('$2W$', $enc, 'envelope prefix must be present');
        $this->assertSame($plain, ipam_webhook_decrypt_secret($enc, $this->appSecret()));
    }

    public function testEncryptDecryptRoundTripUnicode(): void
    {
        $plain = "key-w/-ünïcødé-and-emoji-\u{1F511}";
        $enc   = ipam_webhook_encrypt_secret($plain, $this->appSecret());
        $this->assertSame($plain, ipam_webhook_decrypt_secret($enc, $this->appSecret()));
    }

    public function testEncryptDecryptRoundTripBinary(): void
    {
        $plain = random_bytes(64);
        $enc   = ipam_webhook_encrypt_secret($plain, $this->appSecret());
        $this->assertSame($plain, ipam_webhook_decrypt_secret($enc, $this->appSecret()));
    }

    public function testEncryptSameInputProducesDifferentCiphertext(): void
    {
        // Random IV per call — same plaintext + same key MUST produce
        // different envelopes (CodeRabbit-class IV reuse footgun).
        $plain = 'static-input';
        $a = ipam_webhook_encrypt_secret($plain, $this->appSecret());
        $b = ipam_webhook_encrypt_secret($plain, $this->appSecret());
        $this->assertNotSame($a, $b);
        $this->assertSame($plain, ipam_webhook_decrypt_secret($a, $this->appSecret()));
        $this->assertSame($plain, ipam_webhook_decrypt_secret($b, $this->appSecret()));
    }

    public function testEmptySecretEncryptsToEmpty(): void
    {
        // Unsigned webhook — store empty verbatim so the reader can
        // distinguish "no secret" from "decryption failed".
        $this->assertSame('', ipam_webhook_encrypt_secret('', $this->appSecret()));
        $this->assertSame('', ipam_webhook_decrypt_secret('', $this->appSecret()));
    }

    public function testLegacyPlaintextPassesThroughReader(): void
    {
        // Pre-v3.27.7 rows are plaintext. The reader must return them
        // verbatim so signing keeps working until the migration encrypts
        // them in-place.
        $legacy = 'legacy_plaintext_signing_key';
        $this->assertSame($legacy, ipam_webhook_decrypt_secret($legacy, $this->appSecret()));
    }

    public function testWrongAppSecretReturnsEmpty(): void
    {
        $enc = ipam_webhook_encrypt_secret('shh', $this->appSecret());
        // Wrong app_secret — GCM tag verification fails. Reader returns
        // empty so the HMAC will fail receiver-side, surfacing the
        // misconfig without throwing into the dispatch loop.
        $this->assertSame('', ipam_webhook_decrypt_secret($enc, $this->otherAppSecret()));
    }

    public function testTamperedCiphertextFailsAuth(): void
    {
        $enc = ipam_webhook_encrypt_secret('shh', $this->appSecret());
        // Flip a byte in the middle of the b64 payload (the body, not the prefix).
        $tampered = substr_replace($enc, 'X', 20, 1);
        if ($tampered === $enc) {
            // If we happened to overwrite with the same char, flip another.
            $tampered = substr_replace($enc, 'Y', 21, 1);
        }
        $this->assertSame('', ipam_webhook_decrypt_secret($tampered, $this->appSecret()));
    }

    public function testEncryptWithEmptyKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ipam_webhook_encrypt_secret('shh', '');
    }

    public function testDecryptEnvelopeWithEmptyKeyThrows(): void
    {
        // Plaintext passthrough must still work even with empty key (that's
        // how legacy rows survive a misconfigured upgrade) but an actual
        // envelope must fail loudly so the operator notices.
        $enc = ipam_webhook_encrypt_secret('shh', $this->appSecret());
        $this->expectException(\RuntimeException::class);
        ipam_webhook_decrypt_secret($enc, '');
    }

    public function testDecryptPlaintextWithEmptyKeyPassesThrough(): void
    {
        // The plaintext-passthrough branch runs BEFORE the empty-key check
        // so legacy rows continue to flow during a misconfigured upgrade.
        $this->assertSame('legacy', ipam_webhook_decrypt_secret('legacy', ''));
    }

    public function testEnvelopeHasMinimumByteLength(): void
    {
        // Envelope = '$2W$' + base64(12-byte IV + 16-byte tag + ciphertext).
        // For any non-empty plaintext the body is at least 28 bytes raw
        // (IV + tag), which is 40 base64 chars. The 4-char prefix brings
        // the minimum to 44 chars. Tests pin this so a future refactor
        // can't shrink the envelope.
        $enc = ipam_webhook_encrypt_secret('x', $this->appSecret());
        $this->assertGreaterThanOrEqual(44, strlen($enc));
    }
}
