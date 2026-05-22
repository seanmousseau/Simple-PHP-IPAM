<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #872: ipam_validate_webhook_url() must reject hosts that resolve into the
 * private/loopback/CGNAT/multicast/IPv4-mapped-IPv6 ranges. Tests pass
 * literal IP hosts so DNS is not invoked — the function takes the IP-host
 * fast path and exercises the range filter directly.
 *
 * Settings access is stubbed by leaving ipam_setting('webhook.allow_private_ips')
 * at its default false; tests pre-empt that check by passing an empty
 * $config arg.
 */
final class WebhookSsrfTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function vectors(): iterable
    {
        // Must be REJECTED (private / loopback / link-local / cgnat / multicast / mapped)
        yield 'loopback v4'              => ['http://127.0.0.1',                false];
        yield 'loopback v6'              => ['http://[::1]',                    false];
        yield 'unspecified v6'           => ['http://[::]',                     false];
        yield 'rfc1918 10/8'             => ['http://10.0.0.1',                 false];
        yield 'rfc1918 192.168'          => ['http://192.168.1.1',              false];
        yield 'rfc1918 172.16'           => ['http://172.16.0.1',               false];
        yield 'this network 0/8'         => ['http://0.0.0.0',                  false];
        yield 'cgnat 100.64'             => ['http://100.64.0.1',               false];
        yield 'multicast 239'            => ['http://239.255.255.250',          false];
        yield 'link-local v4'            => ['http://169.254.169.254',          false];
        yield 'mapped v6 loopback'       => ['http://[::ffff:127.0.0.1]',       false];
        yield 'mapped v6 rfc1918'        => ['http://[::ffff:10.0.0.1]',        false];
        yield 'ula fc00::'               => ['http://[fc00::1]',                false];
        yield 'link-local v6'            => ['http://[fe80::1]',                false];
        yield 'multicast v6'             => ['http://[ff02::1]',                false];
        yield 'mapped v6 prefix only'    => ['http://[::ffff:0:0]',             false];
        yield 'nat64 well-known'         => ['http://[64:ff9b::1.2.3.4]',       false];

        // Must PASS (public)
        yield 'public 1.1.1.1'           => ['https://1.1.1.1',                 true];
        yield 'public 8.8.8.8'           => ['https://8.8.8.8',                 true];
        yield 'public v6 2606:4700::'    => ['https://[2606:4700:4700::1111]',  true];
    }

    /**
     * @dataProvider vectors
     */
    public function testRangeFilter(string $url, bool $expected): void
    {
        $this->assertSame(
            $expected,
            ipam_validate_webhook_url($url, []),
            "Expected ipam_validate_webhook_url('{$url}') === " . ($expected ? 'true' : 'false')
        );
    }

    public function testInvalidScheme(): void
    {
        $this->assertFalse(ipam_validate_webhook_url('ftp://example.com', []));
        $this->assertFalse(ipam_validate_webhook_url('file:///etc/passwd', []));
    }

    public function testMalformedUrl(): void
    {
        $this->assertFalse(ipam_validate_webhook_url('not a url', []));
        $this->assertFalse(ipam_validate_webhook_url('http://', []));
    }
}
