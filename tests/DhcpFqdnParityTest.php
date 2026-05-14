<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ipam_dhcp_normalize_hostname() — the canonical hostname
 * normaliser shared by the ISC dhcpd and Kea JSON DHCP generators so
 * the two configs agree on what's emitted for any given raw input.
 *
 * (#1163, PASS-C F-S6-01)
 */
final class DhcpFqdnParityTest extends TestCase
{
    /** @return array<string, array{0:string,1:?string}> */
    public static function hostnames(): array
    {
        return [
            'simple'                  => ['printer1', 'printer1'],
            'dotted_takes_left_label' => ['printer1.lan.example', 'printer1'],
            'underscores'             => ['my_printer', 'my-printer'],
            'spaces'                  => ['my printer', 'my-printer'],
            'leading_digit'           => ['1printer', 'printer'],
            'empty'                   => ['', null],
            'whitespace_only'         => ['   ', null],
            'punctuation_only'        => ['!!!', null],
            'too_long'                => [str_repeat('a', 80), str_repeat('a', 63)],
            'xss_shape'               => ['<script>', 'script'],
            'trailing_hyphens'        => ['name---', 'name'],
            'leading_hyphens'         => ['---name', 'name'],
            'unicode_alpha_collapses' => ['αβγ', null],   // all non-ASCII → all '-' → trimmed to ''
        ];
    }

    /** @dataProvider hostnames */
    public function testNormalize(string $in, ?string $expected): void
    {
        $this->assertSame($expected, ipam_dhcp_normalize_hostname($in));
    }

    public function testNormalizedLabelIsAlwaysSingleLabel(): void
    {
        // No matter the input, the result must never contain a dot.
        foreach (['a.b.c', 'name.example.com', 'sub.domain'] as $raw) {
            $out = ipam_dhcp_normalize_hostname($raw);
            $this->assertNotNull($out, "expected non-null result for {$raw}");
            $this->assertStringNotContainsString('.', $out);
        }
    }

    public function testNormalizedLabelMatchesRfc1123Shape(): void
    {
        // Letter-digit-hyphen, leading alpha, max 63 chars.
        foreach (['printer1', 'my-printer', str_repeat('a', 63), 'a1b2c3'] as $in) {
            $out = ipam_dhcp_normalize_hostname($in);
            $this->assertNotNull($out);
            $this->assertMatchesRegularExpression(
                '/^[a-zA-Z][a-zA-Z0-9-]{0,62}$/',
                $out,
                "expected {$out} to match single-label RFC 1123 shape"
            );
        }
    }
}
