<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * A29 / #938 — Pin the shared JSON HTTP POST request shape.
 *
 * Covers the PURE builder ipam_http_post_json_options(): no network
 * call is made. The builder produces the stream-context options array
 * consumed by ipam_http_post_json(); pinning it here guarantees a
 * future refactor cannot silently weaken TLS verification, drop the
 * timeout, or mangle the JSON body encoding.
 */
final class HttpHelperTest extends TestCase
{
    public function testBuilderSetsBodyAndHeaders(): void
    {
        $opts = ipam_http_post_json_options(['a' => 1, 'b' => 'x']);

        // POSTFIELDS is the json_encode of the body.
        $this->assertSame(json_encode(['a' => 1, 'b' => 'x']), $opts['http']['content']);
        $this->assertSame('POST', $opts['http']['method']);

        // Content-Type: application/json present in headers.
        $this->assertStringContainsString('Content-Type: application/json', $opts['http']['header']);
    }

    public function testBuilderEnforcesTlsVerification(): void
    {
        $opts = ipam_http_post_json_options([]);
        $this->assertTrue($opts['ssl']['verify_peer']);
        $this->assertTrue($opts['ssl']['verify_peer_name']);
    }

    public function testBuilderSetsATimeout(): void
    {
        $opts = ipam_http_post_json_options([]);
        $this->assertSame(10, $opts['http']['timeout']);
    }

    public function testBuilderMergesExtraHeaders(): void
    {
        $opts = ipam_http_post_json_options([], ['X-Custom: yes']);
        $this->assertStringContainsString('X-Custom: yes', $opts['http']['header']);
        // Content-Type is still added even when extra headers are passed.
        $this->assertStringContainsString('Content-Type: application/json', $opts['http']['header']);
    }
}
