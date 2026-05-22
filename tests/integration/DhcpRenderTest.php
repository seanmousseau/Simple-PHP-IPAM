<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ipam_render_dhcpd_conf() and ipam_render_kea_json().
 *
 * Builds an in-memory SQLite database with representative IPv4 and IPv6
 * subnets, reserved addresses with MACs, and unreserved/no-MAC addresses.
 * Verifies output structure, option rendering, reservation inclusion/exclusion,
 * and IPv6 skip behaviour.
 */
class DhcpRenderTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->db->exec("CREATE TABLE subnets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cidr TEXT NOT NULL,
            ip_version INTEGER NOT NULL,
            network TEXT NOT NULL,
            network_bin BLOB NOT NULL,
            prefix INTEGER NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            dhcp_routers TEXT,
            dhcp_dns_servers TEXT,
            dhcp_domain_name TEXT,
            dhcp_lease_default INTEGER,
            dhcp_lease_max INTEGER,
            dhcp_next_server TEXT,
            dhcp_boot_filename TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $this->db->exec("CREATE TABLE addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL,
            ip TEXT NOT NULL,
            ip_bin BLOB NOT NULL,
            hostname TEXT NOT NULL DEFAULT '',
            mac TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'used',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $this->db->exec("CREATE INDEX idx_addr_ip_bin ON addresses(subnet_id, ip_bin)");

        // IPv4 subnet 1 — 10.0.0.0/24 with full DHCP options
        $this->db->exec("INSERT INTO subnets
            (cidr, ip_version, network, network_bin, prefix,
             dhcp_routers, dhcp_dns_servers, dhcp_domain_name,
             dhcp_lease_default, dhcp_lease_max,
             dhcp_next_server, dhcp_boot_filename)
            VALUES
            ('10.0.0.0/24', 4, '10.0.0.0', X'0A000000', 24,
             '10.0.0.1', '8.8.8.8, 8.8.4.4', 'example.com',
             3600, 7200,
             '10.0.0.1', 'pxelinux.0')");

        // IPv4 subnet 2 — 192.168.1.0/24 with minimal options (only routers)
        $this->db->exec("INSERT INTO subnets
            (cidr, ip_version, network, network_bin, prefix,
             dhcp_routers)
            VALUES
            ('192.168.1.0/24', 4, '192.168.1.0', X'C0A80100', 24,
             '192.168.1.1')");

        // IPv6 subnet — should be silently skipped
        $this->db->exec("INSERT INTO subnets
            (cidr, ip_version, network, network_bin, prefix)
            VALUES
            ('2001:db8::/32', 6, '2001:db8::', X'20010DB8000000000000000000000000', 32)");

        // Subnet 1 addresses: 1 reserved+MAC, 1 reserved no-MAC (excluded), 1 used+MAC (excluded)
        $this->db->exec("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, mac, status)
            VALUES (1, '10.0.0.5',  X'0A000005', 'server1', 'AA:BB:CC:DD:EE:FF', 'reserved')");
        $this->db->exec("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, mac, status)
            VALUES (1, '10.0.0.6',  X'0A000006', 'nohostname', '', 'reserved')");
        $this->db->exec("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, mac, status)
            VALUES (1, '10.0.0.10', X'0A00000A', 'workstation', '11:22:33:44:55:66', 'used')");

        // Subnet 2 addresses: 1 reserved+MAC, no hostname (should generate host-<ip> name)
        $this->db->exec("INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, mac, status)
            VALUES (2, '192.168.1.50', X'C0A80132', '', 'de:ad:be:ef:00:01', 'reserved')");
    }

    // --- dhcpd.conf tests ---

    public function testDhcpdConfContainsSubnetBlocks(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringContainsString('subnet 10.0.0.0 netmask 255.255.255.0 {', $output);
        $this->assertStringContainsString('subnet 192.168.1.0 netmask 255.255.255.0 {', $output);
    }

    public function testDhcpdConfSkipsIpv6(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringNotContainsString('2001:db8::', $output);
        $this->assertStringNotContainsString('subnet4', $output);
    }

    public function testDhcpdConfRendersAllOptions(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringContainsString('option routers 10.0.0.1;', $output);
        $this->assertStringContainsString('option domain-name-servers 8.8.8.8, 8.8.4.4;', $output);
        $this->assertStringContainsString('option domain-name "example.com";', $output);
        $this->assertStringContainsString('default-lease-time 3600;', $output);
        $this->assertStringContainsString('max-lease-time 7200;', $output);
        $this->assertStringContainsString('next-server 10.0.0.1;', $output);
        $this->assertStringContainsString('filename "pxelinux.0";', $output);
    }

    public function testDhcpdConfOmitsNullOptions(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, [2]); // subnet 2: only routers set
        $this->assertStringContainsString('option routers 192.168.1.1;', $output);
        $this->assertStringNotContainsString('domain-name-servers', $output);
        $this->assertStringNotContainsString('domain-name', $output);
        $this->assertStringNotContainsString('default-lease-time', $output);
        $this->assertStringNotContainsString('next-server', $output);
        $this->assertStringNotContainsString('filename', $output);
    }

    public function testDhcpdConfIncludesReservedWithMac(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringContainsString('host server1-10-0-0-5 {', $output);
        $this->assertStringContainsString('hardware ethernet aa:bb:cc:dd:ee:ff;', $output);
        $this->assertStringContainsString('fixed-address 10.0.0.5;', $output);
    }

    public function testDhcpdConfExcludesReservedWithoutMac(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringNotContainsString('10.0.0.6', $output);
    }

    public function testDhcpdConfExcludesUsedAddresses(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringNotContainsString('10.0.0.10', $output);
    }

    public function testDhcpdConfGeneratesHostNameWhenEmpty(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, [2]);
        $this->assertStringContainsString('host host-192-168-1-50 {', $output);
    }

    public function testDhcpdConfNormalisesMac(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, [2]);
        $this->assertStringContainsString('hardware ethernet de:ad:be:ef:00:01;', $output);
    }

    public function testDhcpdConfSubnetFilterWorks(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, [1]);
        $this->assertStringContainsString('subnet 10.0.0.0', $output);
        $this->assertStringNotContainsString('192.168.1.0', $output);
    }

    public function testDhcpdConfContainsHeader(): void
    {
        $output = ipam_render_dhcpd_conf($this->db, []);
        $this->assertStringContainsString('# Generated by Simple PHP IPAM', $output);
        $this->assertStringContainsString('# Subnets: 2', $output);
    }

    // --- Kea JSON tests ---

    public function testKeaJsonIsValidJson(): void
    {
        $output = ipam_render_kea_json($this->db, []);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertNull(json_last_error() === JSON_ERROR_NONE ? null : json_last_error());
    }

    public function testKeaJsonHasDhcp4Structure(): void
    {
        $output  = ipam_render_kea_json($this->db, []);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('Dhcp4', $decoded);
        $this->assertArrayHasKey('subnet4', $decoded['Dhcp4']);
        $this->assertCount(2, $decoded['Dhcp4']['subnet4']); // 2 IPv4, IPv6 skipped
    }

    public function testKeaJsonSkipsIpv6(): void
    {
        $output  = ipam_render_kea_json($this->db, []);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        foreach ($decoded['Dhcp4']['subnet4'] as $s) {
            $this->assertStringNotContainsString('2001', (string)($s['subnet'] ?? ''));
        }
    }

    public function testKeaJsonRendersOptions(): void
    {
        $output  = ipam_render_kea_json($this->db, [1]);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $subnet  = $decoded['Dhcp4']['subnet4'][0];
        $this->assertSame('10.0.0.0/24', $subnet['subnet']);
        $this->assertArrayHasKey('option-data', $subnet);
        $names = array_column($subnet['option-data'], 'name');
        $this->assertContains('routers', $names);
        $this->assertContains('domain-name-servers', $names);
        $this->assertContains('domain-name', $names);
        $this->assertSame(3600, $subnet['valid-lifetime']);
        $this->assertSame(7200, $subnet['max-valid-lifetime']);
    }

    public function testKeaJsonRendersReservations(): void
    {
        $output  = ipam_render_kea_json($this->db, [1]);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $subnet = $decoded['Dhcp4']['subnet4'][0];
        $this->assertArrayHasKey('reservations', $subnet);
        $this->assertCount(1, $subnet['reservations']); // only the MAC-bearing reserved row
        $res = $subnet['reservations'][0];
        $this->assertSame('aa:bb:cc:dd:ee:ff', $res['hw-address']);
        $this->assertSame('10.0.0.5', $res['ip-address']);
        $this->assertSame('server1', $res['hostname']);
    }

    public function testKeaJsonSubnetFilterWorks(): void
    {
        $output  = ipam_render_kea_json($this->db, [2]);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded['Dhcp4']['subnet4']);
        $this->assertSame('192.168.1.0/24', $decoded['Dhcp4']['subnet4'][0]['subnet']);
    }

    public function testKeaJsonOmitsHostnameKeyWhenEmpty(): void
    {
        $output  = ipam_render_kea_json($this->db, [2]);
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $res = $decoded['Dhcp4']['subnet4'][0]['reservations'][0];
        $this->assertArrayNotHasKey('hostname', $res);
    }

    // --- Helper function tests ---

    public function testPrefixToNetmask(): void
    {
        $this->assertSame('255.255.255.0', ipam_prefix_to_netmask(24));
        $this->assertSame('255.255.0.0', ipam_prefix_to_netmask(16));
        $this->assertSame('255.0.0.0', ipam_prefix_to_netmask(8));
        $this->assertSame('255.255.255.255', ipam_prefix_to_netmask(32));
        $this->assertSame('0.0.0.0', ipam_prefix_to_netmask(0));
        $this->assertSame('255.255.255.128', ipam_prefix_to_netmask(25));
    }

    public function testNormalizeMac(): void
    {
        $this->assertSame('aa:bb:cc:dd:ee:ff', ipam_normalize_mac_for_dhcp('AA:BB:CC:DD:EE:FF'));
        $this->assertSame('aa:bb:cc:dd:ee:ff', ipam_normalize_mac_for_dhcp('AA-BB-CC-DD-EE-FF'));
        $this->assertSame('aa:bb:cc:dd:ee:ff', ipam_normalize_mac_for_dhcp('aabbccddeeff'));
        $this->assertSame('de:ad:be:ef:00:01', ipam_normalize_mac_for_dhcp('de:ad:be:ef:00:01'));
    }
}
