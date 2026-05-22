<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 2 Task 2.2 — verifies the IP-math extraction from lib.php
 * landed cleanly. The functions must (a) still exist in the global namespace
 * and (b) be declared in Simple-PHP-IPAM/lib/ip.php rather than lib.php
 * (proves the move was a real move, not a copy).
 *
 * tests/bootstrap.php requires lib.php; lib.php in turn requires
 * lib/ip.php, so by the time this test runs every IP-math function should
 * be in scope from its new home.
 *
 * ipam_bind_binary is invariant #1 from CLAUDE.md (binary IPs at native
 * 4B/16B + PARAM_LOB). The reflection check below proves it relocated; the
 * existing BinaryBindTest covers behavioural parity.
 */
final class IpExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function ipFunctions(): array
    {
        return [
            'ipam_bind_binary',
            'ip_in_any_cidr',
            'parse_cidr',
            'apply_prefix_mask',
            'ipam_compute_broadcast_bin',
            'ipam_compute_gateway_bin',
            'ip_in_cidr',
            'normalize_ip',
            'ipv4_bin_to_int',
            'ipv4_int_to_bin',
            'ipv4_int_to_text',
            'ipv4_assignable_count',
            'subnet_contains_bin',
            'ipv4_broadcast_bin',
            'ipv4_broadcast_int',
            'ipv6_bin_increment',
            'netmask_to_prefix',
            'cidr_from_ip_and_prefix',
            'subnet_overlap_warning_text',
        ];
    }

    public function testIpFunctionsAreDefined(): void
    {
        foreach ($this->ipFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testIpFunctionsLiveInIpFile(): void
    {
        foreach ($this->ipFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/ip.php',
                (string)$declarer,
                "$fn should be declared in lib/ip.php, not " . (string)$declarer
            );
        }
    }
}
