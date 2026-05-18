<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ipam_prefix_to_netmask() bounds checking.
 *
 * Verifies valid prefix conversions and that out-of-range values
 * throw InvalidArgumentException instead of producing silent corruption.
 */
class DhcpNetmaskTest extends TestCase
{
    public function testPrefixZeroReturnsAllZeros(): void
    {
        $this->assertSame('0.0.0.0', ipam_prefix_to_netmask(0));
    }

    public function testPrefix24Returns255255255Zero(): void
    {
        $this->assertSame('255.255.255.0', ipam_prefix_to_netmask(24));
    }

    public function testPrefix32ReturnsAllOnes(): void
    {
        $this->assertSame('255.255.255.255', ipam_prefix_to_netmask(32));
    }

    public function testNegativePrefixThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipam_prefix_to_netmask(-1);
    }

    public function testPrefixAbove32Throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipam_prefix_to_netmask(33);
    }
}
