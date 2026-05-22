<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.27.7 (F-S3-01): round-trip + edge-case tests for the webhook signing
 * secret encryption helpers introduced as the at-rest counterpart to the
 * v3.6.0 TOTP envelope.
 *
 * v3.31.0 (F1, #1235): the webhook-secret crypto was consolidated onto the
 * shared ipam_secret_* pipeline (IPAMSEC1 envelope). New writes now produce
 * IPAMSEC1 envelopes; the legacy '$2W$' AES-256-GCM reader is retained
 * (ipam_webhook_decrypt_legacy) so pre-v3.31.0 rows still decrypt until the
 * F2 migration re-encrypts them.
 *
 * Failure modes these tests pin:
 *   - A new webhook secret carries the IPAMSEC1 envelope prefix and
 *     round-trips through the reader.
 *   - Re-encrypting the same plaintext twice must produce different
 *     ciphertexts (random nonce).
 *   - decrypt(encrypt(s)) must round-trip byte-equal across edge cases
 *     (empty, ASCII, unicode, binary).
 *   - A LEGACY '$2W$' envelope (built with the retained legacy encrypt
 *     helper) must still decrypt through the public reader.
 *   - Legacy plaintext (pre-v3.27.7 rows that the migration would not yet
 *     have rewritten) must continue to flow through the reader as-is so
 *     the existing webhook keeps signing correctly.
 *   - A tampered IPAMSEC1 envelope must fail decrypt (returns null).
 *   - A LEGACY '$2W$' envelope decrypted with the WRONG app_secret must
 *     fail (AEAD tag is key-bound) and return null.
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
        // v3.31.0 (F1): new writes carry the shared IPAMSEC1 envelope prefix.
        $plain = 'whsec_supersecret_signing_key_42';
        $enc   = ipam_webhook_encrypt_secret($plain, $this->appSecret());
        $this->assertStringStartsWith(
            IPAM_SECRET_ENVELOPE_PREFIX,
            $enc,
            'new webhook secrets must carry the shared IPAMSEC1 envelope prefix'
        );
        $this->assertSame($plain, ipam_webhook_decrypt_secret($enc, $this->appSecret()));
    }

    public function testNewSecretUsesSharedPipelineEnvelope(): void
    {
        // F1 contract: ipam_webhook_encrypt_secret now delegates to
        // ipam_secret_encrypt — the produced envelope is an IPAMSEC1 envelope
        // and the public reader returns the plaintext back.
        $enc = ipam_webhook_encrypt_secret('hmac-key', $this->appSecret());
        $this->assertTrue(ipam_secret_is_envelope($enc), 'envelope must be IPAMSEC1-format');
        $this->assertStringStartsNotWith('$2W$', $enc, 'new writes must not use the legacy $2W$ format');
        $this->assertSame('hmac-key', ipam_webhook_decrypt_secret($enc, $this->appSecret()));
    }

    public function testLegacyEnvelopeStillDecrypts(): void
    {
        // A pre-v3.31.0 row written by the retained legacy AES-256-GCM helper
        // must still decrypt through the public reader (the F2 migration
        // re-encrypts these in place, but the reader must cope until then).
        $legacy = ipam_webhook_encrypt_secret_legacy('hmac-key', $this->appSecret());
        $this->assertStringStartsWith('$2W$', $legacy, 'legacy helper must produce a $2W$ envelope');
        $this->assertSame('hmac-key', ipam_webhook_decrypt_secret($legacy, $this->appSecret()));
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

    public function testEmptyStoredDecryptsToEmpty(): void
    {
        // Unsigned webhook — an empty stored row reads back as '' so the
        // reader can distinguish "no secret" from "decryption failed".
        $this->assertSame('', ipam_webhook_decrypt_secret('', $this->appSecret()));
    }

    public function testEmptySecretEncryptsToValidEnvelope(): void
    {
        // v3.31.0 (F1): an empty plaintext now produces a valid IPAMSEC1
        // envelope (ipam_secret_encrypt('')), and the reader round-trips it
        // back to '' — the "unsigned webhook" contract still holds.
        $enc = ipam_webhook_encrypt_secret('', $this->appSecret());
        $this->assertTrue(ipam_secret_is_envelope($enc), 'empty plaintext still yields an IPAMSEC1 envelope');
        $this->assertSame('', ipam_webhook_decrypt_secret($enc, $this->appSecret()));
    }

    public function testLegacyPlaintextPassesThroughReader(): void
    {
        // Pre-v3.27.7 rows are plaintext. The reader must return them
        // verbatim so signing keeps working until the migration encrypts
        // them in-place.
        $legacy = 'legacy_plaintext_signing_key';
        $this->assertSame($legacy, ipam_webhook_decrypt_secret($legacy, $this->appSecret()));
    }

    public function testTamperedCiphertextReturnsNull(): void
    {
        $enc = ipam_webhook_encrypt_secret('shh', $this->appSecret());
        // Flip a byte in the middle of the b64 payload (the body, not the
        // prefix). Pick the replacement char based on what's currently at
        // the position so the result is GUARANTEED to differ — no flaky
        // test where the existing char already matched our replacement.
        $pos = strlen(IPAM_SECRET_ENVELOPE_PREFIX) + 8;
        $current = $enc[$pos];
        $replacement = ($current === 'X') ? 'Y' : 'X';
        $tampered = substr_replace($enc, $replacement, $pos, 1);
        $this->assertNotSame($enc, $tampered, 'tamper must change the byte');
        $this->assertNull(ipam_webhook_decrypt_secret($tampered, $this->appSecret()));
    }

    public function testWrongAppSecretReturnsNull(): void
    {
        // Covers the LEGACY '$2W$' path, which still takes an explicit $key:
        // decrypting with a DIFFERENT app_secret must fail the AEAD tag check
        // (the tag is key-bound) and return null. Wrong-key behaviour of the
        // new IPAMSEC1 path is covered by the G1 key-rotation test, since it
        // requires swapping the process-wide app_secret.
        $legacy = ipam_webhook_encrypt_secret_legacy('hmac-key', $this->appSecret());
        $this->assertNull(ipam_webhook_decrypt_secret($legacy, $this->otherAppSecret()));
    }

    public function testLegacyEnvelopeWithEmptyKeyThrows(): void
    {
        // The legacy '$2W$' reader still needs $key. Decrypting a real
        // legacy envelope with an empty key must fail loudly so a
        // misconfigured (app_secret missing) upgrade is surfaced.
        $legacy = ipam_webhook_encrypt_secret_legacy('shh', $this->appSecret());
        $this->expectException(\RuntimeException::class);
        ipam_webhook_decrypt_secret($legacy, '');
    }

    public function testIpamSecEnvelopeDecryptsWithoutKey(): void
    {
        // The new IPAMSEC1 path derives its key from ipam_app_secret()
        // (config.php), not the $key parameter — so an empty $key does
        // NOT throw for an IPAMSEC1 envelope.
        $enc = ipam_webhook_encrypt_secret('shh', $this->appSecret());
        $this->assertSame('shh', ipam_webhook_decrypt_secret($enc, ''));
    }

    public function testDecryptPlaintextWithEmptyKeyPassesThrough(): void
    {
        // The plaintext-passthrough branch runs BEFORE the empty-key check
        // so legacy rows continue to flow during a misconfigured upgrade.
        $this->assertSame('legacy', ipam_webhook_decrypt_secret('legacy', ''));
    }

    public function testEnvelopeHasMinimumByteLength(): void
    {
        // v3.31.0 (F1): envelope = 'IPAMSEC1.' + base64(24-byte nonce +
        // 16-byte Poly1305 tag + ciphertext). For any plaintext the body is
        // at least 40 bytes raw (nonce + tag), which is 56 base64 chars; the
        // 9-char prefix brings the minimum to 65 chars. Tests pin this so a
        // future refactor can't shrink the envelope.
        $enc = ipam_webhook_encrypt_secret('x', $this->appSecret());
        $this->assertGreaterThanOrEqual(65, strlen($enc));
    }
}
