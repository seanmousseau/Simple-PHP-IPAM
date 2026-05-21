<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for pure utility functions in lib.php.
 * These functions have no side effects and require no DB or session.
 */
class UtilTest extends TestCase
{
    // -----------------------------------------------------------------------
    // e() — HTML escaping
    // -----------------------------------------------------------------------

    public function testEscapesTagCharacters(): void
    {
        $this->assertSame('&lt;script&gt;', e('<script>'));
    }

    public function testEscapesAmpersand(): void
    {
        $this->assertSame('Hello &amp; World', e('Hello & World'));
    }

    public function testEscapesDoubleQuote(): void
    {
        $this->assertSame('&quot;quoted&quot;', e('"quoted"'));
    }

    public function testEscapesSingleQuote(): void
    {
        $this->assertSame('it&#039;s', e("it's"));
    }

    public function testPlainTextUnchanged(): void
    {
        $this->assertSame('plain text', e('plain text'));
    }

    public function testEmptyStringUnchanged(): void
    {
        $this->assertSame('', e(''));
    }

    // -----------------------------------------------------------------------
    // parse_cidr()
    // -----------------------------------------------------------------------

    public function testParseCidrV4Valid(): void
    {
        $result = parse_cidr('192.168.1.0/24');
        $this->assertNotNull($result);
        $this->assertSame(4, $result['version']);
        $this->assertSame('192.168.1.0', $result['network']);
        $this->assertSame(24, $result['prefix']);
        $this->assertSame(4, strlen($result['net_bin']));
    }

    public function testParseCidrHostBitsMasked(): void
    {
        // Host bits should be masked to the network address
        $result = parse_cidr('192.168.1.100/24');
        $this->assertNotNull($result);
        $this->assertSame('192.168.1.0', $result['network']);
    }

    public function testParseCidrV4SlashZero(): void
    {
        $result = parse_cidr('0.0.0.0/0');
        $this->assertNotNull($result);
        $this->assertSame(0, $result['prefix']);
    }

    public function testParseCidrV4SlashThirtyTwo(): void
    {
        $result = parse_cidr('10.0.0.1/32');
        $this->assertNotNull($result);
        $this->assertSame('10.0.0.1', $result['network']);
        $this->assertSame(32, $result['prefix']);
    }

    public function testParseCidrV6Valid(): void
    {
        $result = parse_cidr('2001:db8::/32');
        $this->assertNotNull($result);
        $this->assertSame(6, $result['version']);
        $this->assertSame(32, $result['prefix']);
        $this->assertSame(16, strlen($result['net_bin']));
    }

    public function testParseCidrNoCidrSeparator(): void
    {
        $this->assertNull(parse_cidr('192.168.1.0'));
    }

    public function testParseCidrPrefixTooLarge(): void
    {
        $this->assertNull(parse_cidr('192.168.1.0/33'));
    }

    public function testParseCidrNegativePrefix(): void
    {
        $this->assertNull(parse_cidr('192.168.1.0/-1'));
    }

    public function testParseCidrInvalidIp(): void
    {
        $this->assertNull(parse_cidr('256.0.0.0/8'));
        $this->assertNull(parse_cidr('notanip/24'));
    }

    public function testParseCidrNonNumericPrefix(): void
    {
        $this->assertNull(parse_cidr('192.168.1.0/abc'));
    }

    public function testParseCidrLeadingTrailingSpaces(): void
    {
        $result = parse_cidr('  192.168.1.0/24  ');
        $this->assertNotNull($result);
        $this->assertSame('192.168.1.0', $result['network']);
    }

    // -----------------------------------------------------------------------
    // apply_prefix_mask()
    // -----------------------------------------------------------------------

    public function testApplyPrefixMaskAll(): void
    {
        $bin = (string)inet_pton('255.255.255.255');
        $this->assertSame($bin, apply_prefix_mask($bin, 32));
    }

    public function testApplyPrefixMaskNone(): void
    {
        $bin = (string)inet_pton('192.168.1.1');
        $zero = (string)inet_pton('0.0.0.0');
        $this->assertSame($zero, apply_prefix_mask($bin, 0));
    }

    public function testApplyPrefixMaskByte(): void
    {
        $bin = (string)inet_pton('192.168.1.1');
        $expected = (string)inet_pton('192.0.0.0');
        $this->assertSame($expected, apply_prefix_mask($bin, 8));
    }

    public function testApplyPrefixMaskPartialByte(): void
    {
        // /25 = 11111111.11111111.11111111.10000000
        $bin = (string)inet_pton('192.168.1.200');
        $expected = (string)inet_pton('192.168.1.128');
        $this->assertSame($expected, apply_prefix_mask($bin, 25));
    }

    // -----------------------------------------------------------------------
    // ip_in_cidr()
    // -----------------------------------------------------------------------

    public function testIpInCidrTrue(): void
    {
        $this->assertTrue(ip_in_cidr('192.168.1.50', '192.168.1.0', 24));
        $this->assertTrue(ip_in_cidr('10.0.0.1', '10.0.0.0', 8));
    }

    public function testIpInCidrNetworkAddressItself(): void
    {
        $this->assertTrue(ip_in_cidr('192.168.1.0', '192.168.1.0', 24));
    }

    public function testIpInCidrFalse(): void
    {
        $this->assertFalse(ip_in_cidr('192.168.2.1', '192.168.1.0', 24));
        $this->assertFalse(ip_in_cidr('10.1.0.1', '10.0.0.0', 24));
    }

    public function testIpInCidrInvalidIp(): void
    {
        $this->assertFalse(ip_in_cidr('invalid', '192.168.1.0', 24));
        $this->assertFalse(ip_in_cidr('192.168.1.1', 'invalid', 24));
    }

    public function testIpInCidrVersionMismatch(): void
    {
        $this->assertFalse(ip_in_cidr('192.168.1.1', '2001:db8::', 24));
    }

    public function testIpInCidrV6(): void
    {
        $this->assertTrue(ip_in_cidr('2001:db8::1', '2001:db8::', 32));
        $this->assertFalse(ip_in_cidr('2001:db9::1', '2001:db8::', 32));
    }

    // -----------------------------------------------------------------------
    // normalize_ip()
    // -----------------------------------------------------------------------

    public function testNormalizeIpV4(): void
    {
        $result = normalize_ip('192.168.1.1');
        $this->assertNotNull($result);
        $this->assertSame('192.168.1.1', $result['ip']);
        $this->assertSame(4, $result['version']);
        $this->assertSame(4, strlen($result['bin']));
    }

    public function testNormalizeIpV6(): void
    {
        $result = normalize_ip('2001:db8::1');
        $this->assertNotNull($result);
        $this->assertSame(6, $result['version']);
        $this->assertSame(16, strlen($result['bin']));
    }

    public function testNormalizeIpV6Expanded(): void
    {
        // Both forms should normalise to the same canonical form
        $a = normalize_ip('2001:0db8:0000:0000:0000:0000:0000:0001');
        $b = normalize_ip('2001:db8::1');
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a['bin'], $b['bin']);
    }

    public function testNormalizeIpInvalid(): void
    {
        $this->assertNull(normalize_ip('not-an-ip'));
        $this->assertNull(normalize_ip(''));
        $this->assertNull(normalize_ip('256.0.0.0'));
    }

    public function testNormalizeIpTrimsSpaces(): void
    {
        $result = normalize_ip('  10.0.0.1  ');
        $this->assertNotNull($result);
        $this->assertSame('10.0.0.1', $result['ip']);
    }

    // -----------------------------------------------------------------------
    // ipv4_bin_to_int() / ipv4_int_to_bin()
    // -----------------------------------------------------------------------

    public function testIpv4BinToIntZero(): void
    {
        $this->assertSame(0, ipv4_bin_to_int("\x00\x00\x00\x00"));
    }

    public function testIpv4BinToIntMax(): void
    {
        $this->assertSame(0xFFFFFFFF, ipv4_bin_to_int("\xFF\xFF\xFF\xFF"));
    }

    public function testIpv4BinToIntKnown(): void
    {
        // 192.168.1.1 = 0xC0A80101
        $bin = (string)inet_pton('192.168.1.1');
        $this->assertSame(0xC0A80101, ipv4_bin_to_int($bin));
    }

    public function testIpv4IntToBinZero(): void
    {
        $this->assertSame("\x00\x00\x00\x00", ipv4_int_to_bin(0));
    }

    public function testIpv4IntToBinMax(): void
    {
        $this->assertSame("\xFF\xFF\xFF\xFF", ipv4_int_to_bin(0xFFFFFFFF));
    }

    public function testIpv4IntToBinKnown(): void
    {
        $this->assertSame((string)inet_pton('192.168.1.1'), ipv4_int_to_bin(0xC0A80101));
    }

    public function testIpv4BinIntRoundtrip(): void
    {
        foreach (['10.20.30.40', '0.0.0.0', '255.255.255.255', '172.16.0.1'] as $ip) {
            $original = (string)inet_pton($ip);
            $this->assertSame($original, ipv4_int_to_bin(ipv4_bin_to_int($original)), "Roundtrip failed for $ip");
        }
    }

    // -----------------------------------------------------------------------
    // ipam_normalise_version()
    // -----------------------------------------------------------------------

    public function testNormaliseVersionTwoPart(): void
    {
        $this->assertSame('1.2.0', ipam_normalise_version('1.2'));
        $this->assertSame('0.15.0', ipam_normalise_version('0.15'));
    }

    public function testNormaliseVersionThreePart(): void
    {
        $this->assertSame('1.2.1', ipam_normalise_version('1.2.1'));
        $this->assertSame('1.15.0', ipam_normalise_version('1.15.0'));
    }

    public function testNormaliseVersionStripsLeadingV(): void
    {
        $this->assertSame('1.2.1', ipam_normalise_version('v1.2.1'));
        $this->assertSame('1.2.0', ipam_normalise_version('v1.2'));
    }

    public function testNormaliseVersionOnePart(): void
    {
        $this->assertSame('1.0.0', ipam_normalise_version('1'));
    }

    // -----------------------------------------------------------------------
    // normalize_status()
    // -----------------------------------------------------------------------

    public function testNormalizeStatusCanonicalValues(): void
    {
        $this->assertSame('used', normalize_status('used'));
        $this->assertSame('reserved', normalize_status('reserved'));
        $this->assertSame('free', normalize_status('free'));
    }

    public function testNormalizeStatusAliasesForUsed(): void
    {
        $this->assertSame('used', normalize_status('inuse'));
        $this->assertSame('used', normalize_status('in-use'));
        $this->assertSame('used', normalize_status('active'));
    }

    public function testNormalizeStatusAliasesForReserved(): void
    {
        $this->assertSame('reserved', normalize_status('res'));
        $this->assertSame('reserved', normalize_status('reservation'));
    }

    public function testNormalizeStatusAliasesForFree(): void
    {
        $this->assertSame('free', normalize_status('avail'));
        $this->assertSame('free', normalize_status('available'));
        $this->assertSame('free', normalize_status('unused'));
    }

    public function testNormalizeStatusCaseInsensitive(): void
    {
        $this->assertSame('used', normalize_status('USED'));
        $this->assertSame('free', normalize_status('FREE'));
        $this->assertSame('reserved', normalize_status('RESERVED'));
    }

    public function testNormalizeStatusNullAndEmptyDefaultToUsed(): void
    {
        $this->assertSame('used', normalize_status(null));
        $this->assertSame('used', normalize_status(''));
    }

    public function testNormalizeStatusUnknownDefaultsToUsed(): void
    {
        $this->assertSame('used', normalize_status('whatever'));
    }

    // -----------------------------------------------------------------------
    // ipv6_bin_increment()
    // -----------------------------------------------------------------------

    public function testIpv6BinIncrementBasic(): void
    {
        // ::1 + 1 = ::2
        $input    = (string)inet_pton('::1');
        $expected = (string)inet_pton('::2');
        $this->assertSame($expected, ipv6_bin_increment($input));
    }

    public function testIpv6BinIncrementLowByteCarry(): void
    {
        // ::ff + 1 = ::100
        $input    = (string)inet_pton('::ff');
        $expected = (string)inet_pton('::100');
        $this->assertSame($expected, ipv6_bin_increment($input));
    }

    public function testIpv6BinIncrementGroupCarry(): void
    {
        // ::ffff + 1 = ::1:0
        $input    = (string)inet_pton('::ffff');
        $expected = (string)inet_pton('::1:0');
        $this->assertSame($expected, ipv6_bin_increment($input));
    }

    public function testIpv6BinIncrementKnownSubnet(): void
    {
        // 2001:db8:: + 1 = 2001:db8::1
        $input    = (string)inet_pton('2001:db8::');
        $expected = (string)inet_pton('2001:db8::1');
        $this->assertSame($expected, ipv6_bin_increment($input));
    }

    public function testIpv6BinIncrementMaxWrapsToZero(): void
    {
        // ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff + 1 = ::
        $input    = (string)inet_pton('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff');
        $expected = (string)inet_pton('::');
        $this->assertSame($expected, ipv6_bin_increment($input));
    }

    public function testIpv6BinIncrementProducesCorrectLength(): void
    {
        $input = (string)inet_pton('2001:db8::1');
        $this->assertSame(16, strlen(ipv6_bin_increment($input)));
    }

    // -----------------------------------------------------------------------
    // ipam_format_datetime()
    // -----------------------------------------------------------------------

    protected function setUp(): void
    {
        // Ensure $GLOBALS['config'] is available (ipam_format_datetime reads it).
        if (!isset($GLOBALS['config'])) {
            $GLOBALS['config'] = [];
        }
    }

    public function testDisplayDatetimeEmptyStringReturnsEmpty(): void
    {
        ipam_setting_cache_set('branding.timezone', 'UTC', null);
        $this->assertSame('', ipam_format_datetime(''));
    }

    public function testDisplayDatetimeUtcPassthrough(): void
    {
        ipam_setting_cache_set('branding.timezone', 'UTC', null);
        $this->assertSame('2024-06-15 10:30:00', ipam_format_datetime('2024-06-15 10:30:00', 'Y-m-d H:i:s'));
    }

    public function testDisplayDatetimeConvertsToTokyoTime(): void
    {
        // Asia/Tokyo = UTC+9, no DST — unambiguous conversion.
        // 03:00 UTC → 12:00 JST
        ipam_setting_cache_set('branding.timezone', 'Asia/Tokyo', null);
        $this->assertSame('2024-01-15 12:00:00', ipam_format_datetime('2024-01-15 03:00:00', 'Y-m-d H:i:s'));
    }

    public function testDisplayDatetimeConvertsToNegativeOffset(): void
    {
        // America/New_York in January = EST (UTC-5).
        // 17:00 UTC → 12:00 EST
        ipam_setting_cache_set('branding.timezone', 'America/New_York', null);
        $this->assertSame('2024-01-15 12:00:00', ipam_format_datetime('2024-01-15 17:00:00', 'Y-m-d H:i:s'));
    }

    public function testDisplayDatetimeCustomFormat(): void
    {
        ipam_setting_cache_set('branding.timezone', 'UTC', null);
        $this->assertSame('15/06/2024', ipam_format_datetime('2024-06-15 10:30:00', 'd/m/Y'));
    }

    public function testDisplayDatetimeFallsBackOnInvalidInput(): void
    {
        ipam_setting_cache_set('branding.timezone', 'UTC', null);
        // Invalid datetime — should return the raw input rather than throwing.
        $this->assertSame('not-a-date', ipam_format_datetime('not-a-date'));
    }

    public function testDisplayDatetimeEmptyTimezoneDefaultsToUtc(): void
    {
        ipam_setting_cache_set('branding.timezone', '', null);
        $this->assertSame('2024-06-15 10:30:00', ipam_format_datetime('2024-06-15 10:30:00', 'Y-m-d H:i:s'));
    }

    public function testDisplayDatetimeMidnightBoundary(): void
    {
        // UTC+9: 2024-01-16 00:00:00 JST = 2024-01-15 15:00:00 UTC
        ipam_setting_cache_set('branding.timezone', 'Asia/Tokyo', null);
        $this->assertSame('2024-01-16 00:00:00', ipam_format_datetime('2024-01-15 15:00:00', 'Y-m-d H:i:s'));
    }

    // -----------------------------------------------------------------------
    // ipam_compute_broadcast_bin() — scanner broadcast exclusion (#363)
    // -----------------------------------------------------------------------

    public function testBroadcastBinSlash29(): void
    {
        $net = parse_cidr('10.0.0.0/29');
        $this->assertNotNull($net);
        $bcast = ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']);
        $this->assertNotNull($bcast);
        $this->assertSame('10.0.0.7', inet_ntop($bcast));
    }

    public function testBroadcastBinSlash24(): void
    {
        $net = parse_cidr('192.168.1.0/24');
        $this->assertNotNull($net);
        $bcast = ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']);
        $this->assertNotNull($bcast);
        $this->assertSame('192.168.1.255', inet_ntop($bcast));
    }

    public function testBroadcastBinSlash16(): void
    {
        $net = parse_cidr('172.16.0.0/16');
        $this->assertNotNull($net);
        $bcast = ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']);
        $this->assertNotNull($bcast);
        $this->assertSame('172.16.255.255', inet_ntop($bcast));
    }

    public function testBroadcastBinSlash30(): void
    {
        $net = parse_cidr('10.1.2.4/30');
        $this->assertNotNull($net);
        $bcast = ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']);
        $this->assertNotNull($bcast);
        $this->assertSame('10.1.2.7', inet_ntop($bcast));
    }

    public function testBroadcastBinSlash31ReturnsNull(): void
    {
        // RFC 3021: /31 point-to-point, both addresses usable, no broadcast.
        $net = parse_cidr('10.0.0.0/31');
        $this->assertNotNull($net);
        $this->assertNull(ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']));
    }

    public function testBroadcastBinSlash32ReturnsNull(): void
    {
        // Single host, no broadcast.
        $net = parse_cidr('10.0.0.5/32');
        $this->assertNotNull($net);
        $this->assertNull(ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']));
    }

    public function testBroadcastBinIpv6ReturnsNull(): void
    {
        // IPv6 has no broadcast concept.
        $net = parse_cidr('2001:db8::/64');
        $this->assertNotNull($net);
        $this->assertNull(ipam_compute_broadcast_bin($net['net_bin'], $net['prefix']));
    }

    public function testGatewayBinSlash24(): void
    {
        $net = parse_cidr('192.168.1.0/24');
        $this->assertNotNull($net);
        $gw = ipam_compute_gateway_bin($net['net_bin'], $net['prefix']);
        $this->assertNotNull($gw);
        $this->assertSame('192.168.1.1', inet_ntop($gw));
    }

    public function testGatewayBinSlash30(): void
    {
        $net = parse_cidr('10.1.2.4/30');
        $this->assertNotNull($net);
        $gw = ipam_compute_gateway_bin($net['net_bin'], $net['prefix']);
        $this->assertNotNull($gw);
        $this->assertSame('10.1.2.5', inet_ntop($gw));
    }

    public function testGatewayBinSlash31ReturnsNull(): void
    {
        $net = parse_cidr('10.0.0.0/31');
        $this->assertNotNull($net);
        $this->assertNull(ipam_compute_gateway_bin($net['net_bin'], $net['prefix']));
    }

    public function testGatewayBinSlash32ReturnsNull(): void
    {
        $net = parse_cidr('10.0.0.5/32');
        $this->assertNotNull($net);
        $this->assertNull(ipam_compute_gateway_bin($net['net_bin'], $net['prefix']));
    }

    public function testGatewayBinIpv6ReturnsNull(): void
    {
        $net = parse_cidr('2001:db8::/64');
        $this->assertNotNull($net);
        $this->assertNull(ipam_compute_gateway_bin($net['net_bin'], $net['prefix']));
    }

    // -----------------------------------------------------------------------
    // TOTP helpers (v3.6.0, #418)
    // -----------------------------------------------------------------------

    public function testTotpSecretRoundTrip(): void
    {
        $secret = ipam_totp_generate_secret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret, 'Secret must be 32 base32 chars');

        $key = str_repeat('k', 32);
        $enc = ipam_totp_encrypt_secret($secret, $key);
        $this->assertNotEquals($secret, $enc, 'Encrypted must differ from plaintext');

        $dec = ipam_totp_decrypt_secret($enc, $key);
        $this->assertEquals($secret, $dec, 'Decrypt must recover original');
    }

    public function testTotpBackupCodeFormat(): void
    {
        $codes = ipam_totp_generate_backup_codes(8);
        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{8}$/', $code,
                'Backup code must be XXXXXXXX-XXXXXXXX uppercase hex');
        }
        // All codes must be unique
        $this->assertCount(8, array_unique($codes));
    }

    // -----------------------------------------------------------------------
    // API per-key rate limiting — DB-backed helper (v3.6.0, #419)
    // -----------------------------------------------------------------------

    public function testRateLimitHelperAllowsAndDenies(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE rate_limit_buckets (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket_key   TEXT NOT NULL,
            window_start TEXT NOT NULL,
            count        INTEGER NOT NULL DEFAULT 0,
            UNIQUE(bucket_key, window_start)
        )");

        $key = 'test-key';
        $windowSec = 60;
        $max = 3;

        // First 3 requests should be allowed (return 0)
        for ($i = 0; $i < $max; $i++) {
            $this->assertSame(0, ipam_api_key_rate_limit_check($db, $key, $windowSec, $max),
                "Request #$i should be allowed");
        }

        // 4th request must be denied (return > 0 seconds).
        // Retry-After can exceed $windowSec in the sliding-window Case B scenario
        // (overflow driven by the current bucket becoming the weighted previous
        // bucket in the next window), so the upper bound is 2 * $windowSec.
        $retryAfter = ipam_api_key_rate_limit_check($db, $key, $windowSec, $max);
        $this->assertGreaterThan(0, $retryAfter, '4th request should be denied');
        $this->assertLessThanOrEqual($windowSec * 2, $retryAfter, 'Retry-After must not exceed two windows');
    }

    public function testRateLimitHelperRejectsInvalidWindow(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE rate_limit_buckets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket_key TEXT NOT NULL, window_start TEXT NOT NULL,
            count INTEGER NOT NULL DEFAULT 0, UNIQUE(bucket_key, window_start)
        )");
        $this->expectException(\InvalidArgumentException::class);
        ipam_api_key_rate_limit_check($db, 'k', 0, 10);
    }

    // -----------------------------------------------------------------------
    // XSS escaping of version strings (issue #467)
    // -----------------------------------------------------------------------

    public function testUpdateCheckEscapesVersionString(): void
    {
        $dangerous = '<script>alert(1)</script>v3.6.0';
        $escaped = e($dangerous);
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
        $this->assertStringContainsString('v3.6.0', $escaped);
    }

    // -----------------------------------------------------------------------
    // ipam_render() / ipam_render_string() — view helper (v3.8.0, #522)
    // -----------------------------------------------------------------------

    public function test_ipam_render_throws_on_missing_view(): void
    {
        $this->expectException(\RuntimeException::class);
        ipam_render('__nonexistent_view_xyz__');
    }

    public function test_ipam_render_executes_known_view(): void
    {
        ob_start();
        ipam_render('_empty');
        $output = ob_get_clean();
        $this->assertSame('', $output);
    }
}
