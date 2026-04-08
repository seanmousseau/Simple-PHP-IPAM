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
}
