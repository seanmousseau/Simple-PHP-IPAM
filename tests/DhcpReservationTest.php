<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the DHCP reservation loader (#892).
 *
 * Scope: this unit suite exercises ONLY the genuine, directly-callable
 * reservation primitive — ipam_dhcp_load_reservations(). The reserve_pool
 * conflict-resolution loop in dhcp_pool.php is a procedural page controller
 * and is not callable as a unit; re-implementing it here would only test a
 * copy that silently drifts from the real controller. That conflict logic
 * (used→skipped / reserved→updated / free→created, range validation) is
 * covered end-to-end against the REAL controller in the Playwright spec
 * testing/playwright/tests/dhcp_pool.spec.ts.
 *
 * What ipam_dhcp_load_reservations() guarantees, verified below:
 *   - only status='reserved' rows with a non-empty MAC become DHCP
 *     reservations — a dynamic-lease ('used') boundary row is excluded
 *     even when it carries a MAC;
 *   - results are scoped to the requested subnet_id;
 *   - there is NO duplicate-MAC detection — two reserved rows sharing a
 *     MAC are both returned (documented current behaviour, see #892).
 */
class DhcpReservationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $this->db->exec("CREATE TABLE addresses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subnet_id INTEGER NOT NULL,
            ip TEXT NOT NULL,
            ip_bin BLOB NOT NULL,
            hostname TEXT NOT NULL DEFAULT '',
            mac TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'used',
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $this->db->exec("CREATE INDEX idx_addr_ip_bin ON addresses(subnet_id, ip_bin)");
    }

    /**
     * Insert one address row directly (test fixture helper). Binds ip_bin via
     * the real ipam_bind_binary() so the BLOB column is populated exactly the
     * way production code does it.
     */
    private function seedAddress(
        int $subnetId,
        string $ip,
        string $status,
        string $mac = '',
        string $hostname = ''
    ): void {
        $bin = (string)inet_pton($ip);
        $st  = $this->db->prepare(
            "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, mac, status)
             VALUES (:sid, :ip, :b, :hn, :mac, :st)"
        );
        $st->bindValue(':sid', $subnetId, PDO::PARAM_INT);
        $st->bindValue(':ip', $ip);
        ipam_bind_binary($st, ':b', $bin);
        $st->bindValue(':hn', $hostname);
        $st->bindValue(':mac', $mac);
        $st->bindValue(':st', $status);
        $st->execute();
    }

    // --- DHCP-config eligibility: the used/reserved+MAC boundary ----------

    public function testOnlyReservedRowsWithMacBecomeDhcpReservations(): void
    {
        // A reserved row WITH a MAC -> eligible.
        $this->seedAddress(1, '10.0.0.5', 'reserved', 'AA:BB:CC:DD:EE:FF', 'server1');
        // A reserved row WITHOUT a MAC -> excluded by the loader.
        $this->seedAddress(1, '10.0.0.6', 'reserved', '', 'nomac');
        // A used row WITH a MAC (a dynamic lease) -> excluded: not 'reserved'.
        $this->seedAddress(1, '10.0.0.7', 'used', '11:22:33:44:55:66', 'dynamic');

        $rows = ipam_dhcp_load_reservations($this->db, 1);

        $this->assertCount(1, $rows, 'only the reserved+MAC row is a DHCP reservation');
        $this->assertSame('10.0.0.5', $rows[0]['ip']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $rows[0]['mac']);
    }

    public function testReservationLoaderScopesToSubnet(): void
    {
        $this->seedAddress(1, '10.0.0.5', 'reserved', 'AA:BB:CC:DD:EE:FF', 'in-subnet');
        $this->seedAddress(2, '10.0.0.5', 'reserved', 'AA:BB:CC:DD:EE:00', 'other-subnet');

        $rows = ipam_dhcp_load_reservations($this->db, 1);
        $this->assertCount(1, $rows);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $rows[0]['mac']);
    }

    // --- Documented NON-conflict: code has no duplicate-MAC detection -----

    public function testDuplicateMacAcrossReservationsIsNotDetected(): void
    {
        // Two distinct reserved IPs sharing the same MAC. The schema has no
        // unique (subnet_id, mac) constraint and the loader does not dedupe
        // by MAC, so BOTH rows are returned. This documents current
        // behaviour — it is not asserted to be correct, only to be what the
        // code does today (see #892 report).
        $this->seedAddress(1, '10.0.0.5', 'reserved', 'AA:BB:CC:DD:EE:FF', 'host-a');
        $this->seedAddress(1, '10.0.0.6', 'reserved', 'AA:BB:CC:DD:EE:FF', 'host-b');

        $rows = ipam_dhcp_load_reservations($this->db, 1);
        $this->assertCount(2, $rows, 'duplicate MAC is not detected or rejected by the loader');
        $macs = array_column($rows, 'mac');
        $this->assertSame(['AA:BB:CC:DD:EE:FF', 'AA:BB:CC:DD:EE:FF'], $macs);
    }
}
