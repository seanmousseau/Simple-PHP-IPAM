<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class DhcpExtractionParityTest extends TestCase
{
    /** @var string[] All functions Task B1 moved into lib/dhcp.php (2 renderers + 5 helpers). */
    private const DHCP_FUNCTIONS = [
        'ipam_render_dhcpd_conf',
        'ipam_render_kea_json',
        'ipam_dhcp_load_subnets',
        'ipam_dhcp_load_reservations',
        'ipam_prefix_to_netmask',
        'ipam_normalize_mac_for_dhcp',
        'ipam_dhcp_normalize_hostname',
    ];

    public function testDhcpRenderersLiveInDedicatedModule(): void
    {
        $expected = realpath(__DIR__ . '/../Simple-PHP-IPAM/lib/dhcp.php');
        foreach (self::DHCP_FUNCTIONS as $fn) {
            $ref = new \ReflectionFunction($fn);
            self::assertSame($expected, realpath((string) $ref->getFileName()), "$fn must live in lib/dhcp.php");
        }
    }

    public function testLibPhpNoLongerDefinesRenderers(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/lib.php');
        foreach (self::DHCP_FUNCTIONS as $fn) {
            self::assertStringNotContainsString("function $fn", $src);
        }
    }
}
