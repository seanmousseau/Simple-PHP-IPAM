<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class DhcpExtractionParityTest extends TestCase
{
    public function testDhcpRenderersLiveInDedicatedModule(): void
    {
        $expected = realpath(__DIR__ . '/../Simple-PHP-IPAM/lib/dhcp.php');
        foreach (['ipam_render_dhcpd_conf', 'ipam_render_kea_json'] as $fn) {
            $ref = new \ReflectionFunction($fn);
            self::assertSame($expected, realpath((string) $ref->getFileName()), "$fn must live in lib/dhcp.php");
        }
    }

    public function testLibPhpNoLongerDefinesRenderers(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/lib.php');
        self::assertStringNotContainsString('function ipam_render_dhcpd_conf', $src);
        self::assertStringNotContainsString('function ipam_render_kea_json', $src);
    }
}
