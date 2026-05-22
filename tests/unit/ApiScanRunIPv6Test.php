<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression test pinning that api_scan_run rejects IPv6
 * subnets with HTTP 400 BEFORE the /28 prefix check.
 *
 * ipam_scan_subnet() is IPv4-only; without this guard an IPv6 /64
 * (prefix=64 >= 28) would pass the cap check and fall into the IPv4-only
 * scanner. (#1160, PASS-C F-S2-01)
 *
 * A direct functional test is impractical because api_scan_run is
 * declared `: never` and api_error()/api_json() call exit(). This test
 * pins the guard at the source level: the function body must SELECT
 * ip_version, and reject ip_version != 4 before checking prefix < 28.
 */
final class ApiScanRunIPv6Test extends TestCase
{
    private string $body = '';

    protected function setUp(): void
    {
        $apiPath = __DIR__ . '/../../Simple-PHP-IPAM/api.php';
        $src = file_get_contents($apiPath);
        $this->assertNotFalse($src, 'api.php must be readable');

        $start = strpos($src, 'function api_scan_run');
        $this->assertNotFalse($start, 'api_scan_run not found');
        $bodyStart = strpos($src, '{', $start);
        $this->assertNotFalse($bodyStart);
        $depth = 0;
        $i = $bodyStart;
        $len = strlen($src);
        for (; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        $this->body = substr($src, $bodyStart, $i - $bodyStart + 1);
    }

    public function testSelectsIpVersionColumn(): void
    {
        $this->assertMatchesRegularExpression(
            '/SELECT[^"]*\bip_version\b[^"]*FROM\s+subnets/i',
            $this->body,
            'api_scan_run must SELECT ip_version so the IPv6 guard can read it'
        );
    }

    public function testRejectsNonIPv4(): void
    {
        $this->assertMatchesRegularExpression(
            '/to_int\(\s*\$subnet\[\s*[\'"]ip_version[\'"]\s*\]\s*\)\s*!==\s*4/',
            $this->body,
            'api_scan_run must compare ip_version !== 4 before the prefix check'
        );
        $this->assertStringContainsString(
            'IPv4-only',
            $this->body,
            'rejection message must mention "IPv4-only"'
        );
    }

    public function testIPv6GuardPrecedesPrefixCheck(): void
    {
        $ipv6Pos  = strpos($this->body, 'ip_version');
        $prefixPos = strpos($this->body, "to_int(\$subnet['prefix']) < 28");
        $this->assertNotFalse($ipv6Pos);
        $this->assertNotFalse($prefixPos);
        $this->assertLessThan(
            $prefixPos,
            $ipv6Pos,
            'IPv6 reject must come before the /28 prefix check so IPv6 /64 does not pass'
        );
    }
}
