<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Round-trip + tamper-detection coverage for the v3.26.0 (#1098) vault
 * helpers. The wrap/unwrap layer is exercised here with an injected
 * bootstrap key so the test suite does not need a writable config.php.
 * Auto-generation behaviour for bootstrap_key itself is covered indirectly
 * by the integration suite during bootstrap.
 */
final class VaultTest extends TestCase
{
    private string $bootstrapKey;

    protected function setUp(): void
    {
        // 32-byte deterministic key for reproducibility — the wrap path
        // injects its own random nonce so ciphertext still varies between
        // runs. Tests assert behaviour, not exact bytes.
        $this->bootstrapKey = str_repeat("\x42", IPAM_BOOTSTRAP_KEY_LEN);
    }

    public function testRoundTripEmptyString(): void
    {
        $env = ipam_vault_wrap('', $this->bootstrapKey);
        $this->assertStringStartsWith(IPAM_VAULT_ENVELOPE_PREFIX, $env);
        $this->assertSame('', ipam_vault_unwrap($env, $this->bootstrapKey));
    }

    public function testRoundTripVaultKeySize(): void
    {
        $vaultKey = random_bytes(32);
        $env = ipam_vault_wrap($vaultKey, $this->bootstrapKey);
        $this->assertSame($vaultKey, ipam_vault_unwrap($env, $this->bootstrapKey));
    }

    public function testRoundTripLargePayload(): void
    {
        $payload = random_bytes(1024);
        $env = ipam_vault_wrap($payload, $this->bootstrapKey);
        $this->assertSame($payload, ipam_vault_unwrap($env, $this->bootstrapKey));
    }

    public function testNoncesAreUniquePerWrap(): void
    {
        // Two wraps of the same plaintext under the same key must produce
        // different envelopes — otherwise we have a stuck nonce, which is
        // catastrophic for crypto_secretbox security.
        $a = ipam_vault_wrap('same plaintext', $this->bootstrapKey);
        $b = ipam_vault_wrap('same plaintext', $this->bootstrapKey);
        $this->assertNotSame($a, $b);
        // But both must round-trip to the same plaintext.
        $this->assertSame('same plaintext', ipam_vault_unwrap($a, $this->bootstrapKey));
        $this->assertSame('same plaintext', ipam_vault_unwrap($b, $this->bootstrapKey));
    }

    public function testTamperedCiphertextThrows(): void
    {
        $env = ipam_vault_wrap('secret', $this->bootstrapKey);
        // Flip one bit in the body. Since the body is base64, mutating a
        // single character breaks Poly1305 authentication.
        $body = substr($env, strlen(IPAM_VAULT_ENVELOPE_PREFIX));
        $tampered = IPAM_VAULT_ENVELOPE_PREFIX
            . substr($body, 0, -2)
            . ($body[strlen($body) - 2] === 'A' ? 'B' : 'A')
            . substr($body, -1);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication failed|malformed base64|too short/');
        ipam_vault_unwrap($tampered, $this->bootstrapKey);
    }

    public function testWrongBootstrapKeyThrows(): void
    {
        $env = ipam_vault_wrap('secret', $this->bootstrapKey);
        $wrongKey = str_repeat("\xAA", IPAM_BOOTSTRAP_KEY_LEN);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication failed/');
        ipam_vault_unwrap($env, $wrongKey);
    }

    public function testMissingPrefixThrows(): void
    {
        $env = ipam_vault_wrap('secret', $this->bootstrapKey);
        // Strip the IPAMWK1. magic — leaves only the base64 body.
        $bare = substr($env, strlen(IPAM_VAULT_ENVELOPE_PREFIX));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IPAMWK1 envelope prefix/');
        ipam_vault_unwrap($bare, $this->bootstrapKey);
    }

    public function testWrongLengthBootstrapKeyRefusedOnWrap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be 32 bytes/');
        ipam_vault_wrap('whatever', 'too-short');
    }

    public function testWrongLengthBootstrapKeyRefusedOnUnwrap(): void
    {
        $env = ipam_vault_wrap('secret', $this->bootstrapKey);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be 32 bytes/');
        ipam_vault_unwrap($env, 'too-short');
    }

    public function testFingerprintIs8HexChars(): void
    {
        $key = random_bytes(32);
        $fp = ipam_vault_fingerprint($key);
        $this->assertSame(8, strlen($fp));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $fp);
    }

    public function testFingerprintIsStable(): void
    {
        $key = str_repeat("\x01", 32);
        $this->assertSame(
            ipam_vault_fingerprint($key),
            ipam_vault_fingerprint($key)
        );
    }

    public function testFingerprintDiffersForDifferentKeys(): void
    {
        $a = str_repeat("\x01", 32);
        $b = str_repeat("\x02", 32);
        $this->assertNotSame(
            ipam_vault_fingerprint($a),
            ipam_vault_fingerprint($b)
        );
    }
}
