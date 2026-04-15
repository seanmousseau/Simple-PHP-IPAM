<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Round-trip tests for ipam_bind_binary() (#410).
 *
 * Three test vectors that catch the most common driver-binding bugs:
 *
 *   inet_pton('10.0.0.0')        — \x0A\x00\x00\x00 — null bytes after first
 *   inet_pton('2001:db8::')      — \x20\x01\x0D\xB8\x00...\x00 — mostly nulls
 *   inet_pton('255.255.255.255') — \xFF\xFF\xFF\xFF — all high bytes
 *
 * If any of these round-trips incorrectly, the binding is broken and any
 * stored IP could be corrupted. v2.10.0 / v2.11.0 will add MysqlDialect /
 * PgsqlDialect fixtures here; the SQLite vectors stay as the baseline.
 */
final class BinaryBindTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE bin_test (id INTEGER PRIMARY KEY, payload BLOB)");
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function vectors(): iterable
    {
        yield 'IPv4 with null bytes after first byte' => ['10.0.0.0', "\x0A\x00\x00\x00", 4];
        yield 'IPv6 mostly null bytes'                 => ['2001:db8::', "\x20\x01\x0D\xB8\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00", 16];
        yield 'IPv4 all high bytes (broadcast)'        => ['255.255.255.255', "\xFF\xFF\xFF\xFF", 4];
        yield 'IPv4 leading null byte'                 => ['0.0.0.1', "\x00\x00\x00\x01", 4];
        yield 'IPv6 ULA leading high byte'             => ['fc00::1', "\xFC\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01", 16];
    }

    /**
     * @dataProvider vectors
     */
    public function testRoundTripPreservesBytes(string $human, string $expectedBytes, int $expectedLen): void
    {
        $packed = inet_pton($human);
        $this->assertNotFalse($packed, "inet_pton must accept $human");
        $this->assertSame($expectedBytes, $packed, 'test vector must match inet_pton output');
        $this->assertSame($expectedLen, strlen($packed));

        $stmt = $this->pdo->prepare("INSERT INTO bin_test (payload) VALUES (:b)");
        ipam_bind_binary($stmt, ':b', $packed);
        $stmt->execute();
        $id = (int)$this->pdo->lastInsertId();

        $row = $this->pdo->query("SELECT typeof(payload) AS t, payload, length(payload) AS l FROM bin_test WHERE id = $id")
            ->fetch();
        $this->assertNotFalse($row);
        $this->assertSame('blob', $row['t'], 'PARAM_LOB must produce BLOB affinity on SQLite');
        $this->assertSame($expectedLen, (int)$row['l'], 'stored length must equal native byte length');
        $this->assertSame($packed, $row['payload'], 'bytes must round-trip exactly');
        $this->assertSame(inet_ntop($expectedBytes), inet_ntop($row['payload']));
    }

    public function testIpv4StoredAsFourBytesNotPadded(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO bin_test (payload) VALUES (:b)");
        ipam_bind_binary($stmt, ':b', inet_pton('192.168.1.1'));
        $stmt->execute();
        $len = (int)$this->pdo->query("SELECT length(payload) FROM bin_test ORDER BY id DESC LIMIT 1")
            ->fetchColumn();
        $this->assertSame(4, $len);
    }

    public function testIpv6StoredAsSixteenBytes(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO bin_test (payload) VALUES (:b)");
        ipam_bind_binary($stmt, ':b', inet_pton('fe80::1'));
        $stmt->execute();
        $len = (int)$this->pdo->query("SELECT length(payload) FROM bin_test ORDER BY id DESC LIMIT 1")
            ->fetchColumn();
        $this->assertSame(16, $len);
    }

    public function testOrderByIsConsistentAcrossBindings(): void
    {
        $ips = ['10.0.0.5', '10.0.0.1', '10.0.0.10', '10.0.0.2'];
        foreach ($ips as $ip) {
            $stmt = $this->pdo->prepare("INSERT INTO bin_test (payload) VALUES (:b)");
            ipam_bind_binary($stmt, ':b', inet_pton($ip));
            $stmt->execute();
        }
        $rows = $this->pdo->query("SELECT payload FROM bin_test ORDER BY payload")->fetchAll();
        $sorted = array_map(fn(array $r): string => (string)inet_ntop($r['payload']), $rows);
        $this->assertSame(['10.0.0.1', '10.0.0.2', '10.0.0.5', '10.0.0.10'], $sorted);
    }
}
