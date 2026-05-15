<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * v3.29.0 #888 — Pin `ipam_webhook_sign()`'s wire-format contract.
 *
 * The IPAM project is a webhook SENDER, not a receiver: outgoing
 * webhook deliveries are HMAC-signed with the configured per-webhook
 * secret and the receiving service is responsible for verifying. There
 * is no in-repo verify helper to round-trip against. These tests pin
 * the producer side:
 *
 *   - Wire format: every signature starts with `sha256=`.
 *   - Determinism: same payload + secret produces the same signature
 *     across calls (so retries don't trip downstream replay guards).
 *   - Payload sensitivity: changing one byte of the payload produces a
 *     fully different signature.
 *   - Secret sensitivity: changing one byte of the secret produces a
 *     fully different signature.
 *   - Hex digest shape: payload after the prefix is exactly 64 lowercase
 *     hex characters (sha256 = 32 bytes = 64 hex chars).
 *   - Empty payload edge case: signing the empty string is well-defined
 *     and produces a valid signature with the same wire format.
 *
 * The implementation under test (lib.php:9227) is a one-line wrapper
 * around `hash_hmac('sha256', $payload, $secret)`. The tests below are
 * intentionally strict on the wire format because a future refactor
 * that drops the prefix or switches algorithm without coordinating
 * with receivers would silently break every downstream integration.
 */
final class WebhookSignatureTest extends TestCase
{
    public function testWireFormatPrefix(): void
    {
        $sig = ipam_webhook_sign('hello', 'secret');
        $this->assertStringStartsWith('sha256=', $sig);
    }

    public function testHexDigestShape(): void
    {
        $sig = ipam_webhook_sign('hello', 'secret');
        $hex = substr($sig, strlen('sha256='));
        $this->assertSame(64, strlen($hex), 'sha256 hex digest must be 64 chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex, 'digest must be lowercase hex');
    }

    public function testDeterminism(): void
    {
        $a = ipam_webhook_sign('payload', 'k');
        $b = ipam_webhook_sign('payload', 'k');
        $this->assertSame($a, $b, 'sign(payload, secret) must be deterministic');
    }

    public function testPayloadSensitivity(): void
    {
        $a = ipam_webhook_sign('payload', 'k');
        $b = ipam_webhook_sign('payloaD', 'k'); // one-byte difference
        $this->assertNotSame($a, $b, 'one-byte payload change must change the signature');
    }

    public function testSecretSensitivity(): void
    {
        $a = ipam_webhook_sign('payload', 'secret');
        $b = ipam_webhook_sign('payload', 'secrek'); // one-byte difference
        $this->assertNotSame($a, $b, 'one-byte secret change must change the signature');
    }

    public function testEmptyPayloadIsSignable(): void
    {
        $sig = ipam_webhook_sign('', 'k');
        $this->assertStringStartsWith('sha256=', $sig);
        $hex = substr($sig, strlen('sha256='));
        $this->assertSame(64, strlen($hex));
    }

    public function testEmptySecretIsSignable(): void
    {
        // hash_hmac() accepts an empty key (RFC 2104 allows it; security-
        // wise it's catastrophic but the helper must not crash). Pin the
        // observed behaviour so a future "reject empty secret" guard is
        // a deliberate breaking change, not an accident.
        $sig = ipam_webhook_sign('payload', '');
        $this->assertStringStartsWith('sha256=', $sig);
        $this->assertSame(64, strlen(substr($sig, strlen('sha256='))));
    }

    public function testKnownVector(): void
    {
        // RFC 4231 test case 1: key = 20 bytes of 0x0b, data = "Hi There".
        // HMAC-SHA256 = b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7
        $sig = ipam_webhook_sign('Hi There', str_repeat("\x0b", 20));
        $this->assertSame(
            'sha256=b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7',
            $sig,
            'pinned vs RFC 4231 known-answer HMAC-SHA256 vector to catch algorithm-swap regressions'
        );
    }
}
