<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test against a real PostgreSQL 14+ server (#388).
 *
 * Skipped whenever IPAM_PGSQL_DSN is not set in the environment, so it
 * does not slow down day-to-day local SQLite-only development. Runs
 * inside the CI `pgsql` matrix slot that spins up a postgres:14 service
 * container, or when a developer sets the env variables manually to
 * validate PgsqlDialect / schema.pgsql.sql changes against a live
 * server.
 *
 * Coverage goals parallel MysqlSmokeTest:
 *
 *   1. ipam_db($config) opens a PDO connection on the pgsql driver and
 *      enforces the PG14+ version floor.
 *   2. ipam_db_init($db) loads schema.pgsql.sql end-to-end through
 *      PDO::exec() (validates every CREATE TABLE / FUNCTION / TRIGGER).
 *   3. schema_migrations pre-seed covers every historical version so
 *      apply_migrations() is a no-op on fresh Postgres.
 *   4. Binary IP columns round-trip cleanly for the three locked #410
 *      test vectors under BYTEA with PDO::PARAM_LOB binding.
 *   5. audit_log append-only triggers raise an exception on both UPDATE
 *      and DELETE attempts — and the ipam.bypass_append_only SET LOCAL
 *      escape hatch works for housekeeping routines.
 *   6. ORDER BY ip_bin is byte-wise and matches SQLite's sort order.
 */
#[Group('pgsql')]
final class PgsqlSmokeTest extends TestCase
{
    private PDO $db;
    /** @var array<string, mixed> */
    private array $config;
    private bool $hadConfigFile = false;

    public static function setUpBeforeClass(): void
    {
        if (getenv('IPAM_PGSQL_DSN') === false || getenv('IPAM_PGSQL_DSN') === '') {
            self::markTestSkipped('IPAM_PGSQL_DSN not set; skipping Postgres smoke tests');
        }
    }

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/lib.php';

        $dsn  = (string)getenv('IPAM_PGSQL_DSN');
        $user = (string)getenv('IPAM_PGSQL_USER');
        $pass = (string)getenv('IPAM_PGSQL_PASS');

        // Connect to the admin database (`postgres`) to drop + recreate the
        // target DB for a pristine fixture. Postgres does not allow DROP
        // DATABASE while any session is connected to it, so we have to
        // admin-connect on a different dbname.
        $dbName = $this->extractDbNameFromDsn($dsn);
        $this->assertNotSame('', $dbName, 'DSN must include dbname');

        $adminDsn = (string)preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn);
        $admin = new PDO($adminDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        // Terminate any leftover connections to the target DB from a prior
        // interrupted test run before we try to drop it.
        $admin->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
            . "WHERE datname = " . $admin->quote($dbName) . " AND pid <> pg_backend_pid()"
        );
        $admin->exec("DROP DATABASE IF EXISTS \"$dbName\"");
        $admin->exec("CREATE DATABASE \"$dbName\"");
        unset($admin);

        $this->config = [
            'db_driver' => 'pgsql',
            'db_dsn'    => $dsn,
            'db_user'   => $user,
            'db_pass'   => $pass,
            'bootstrap_admin' => [
                'username' => 'admin',
                'password' => 'testing123',
            ],
        ];
        $GLOBALS['config'] = $this->config;

        $cfgPath = dirname(__DIR__) . '/Simple-PHP-IPAM/config.php';
        $this->hadConfigFile = file_exists($cfgPath);
        if ($this->hadConfigFile) {
            copy($cfgPath, $cfgPath . '.smoke-bak');
        }
        $written = file_put_contents(
            $cfgPath,
            "<?php return " . var_export($this->config, true) . ";\n"
        );
        $this->assertNotFalse($written, "Failed to write test config stub to $cfgPath");

        unset($GLOBALS['ipam_dialect']);

        $this->db = ipam_db($this->config);
        ipam_db_init($this->db);
    }

    protected function tearDown(): void
    {
        $cfgPath = dirname(__DIR__) . '/Simple-PHP-IPAM/config.php';
        $bak = $cfgPath . '.smoke-bak';
        if ($this->hadConfigFile) {
            if (file_exists($bak)) {
                copy($bak, $cfgPath);
                @unlink($bak);
            }
        } else {
            if (file_exists($cfgPath)) {
                @unlink($cfgPath);
            }
        }
        unset($GLOBALS['ipam_dialect']);
        unset($GLOBALS['config']);
    }

    private function extractDbNameFromDsn(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    public function testDialectIsPgsql(): void
    {
        $this->assertSame('pgsql', ipam_dialect()->driver_name());
    }

    public function testServerVersionMeetsFloor(): void
    {
        $version = (string)$this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->assertTrue(
            version_compare($version, '14.0', '>='),
            "Expected Postgres >= 14.0, got $version"
        );
    }

    public function testSchemaMigrationsPreseeded(): void
    {
        $row = $this->db->query("SELECT COUNT(*) AS c FROM schema_migrations")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(41, (int)$row['c']);
    }

    public function testBootstrapAdminUserInserted(): void
    {
        $row = $this->db->query("SELECT username, role FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('admin', $row['username']);
        $this->assertSame('admin', $row['role']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ipRoundTripVectors(): array
    {
        return [
            'IPv4 low'       => ['10.0.0.0'],
            'IPv4 all-high'  => ['255.255.255.255'],
            'IPv6 compact'   => ['2001:db8::'],
            'IPv6 all-high'  => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ipRoundTripVectors')]
    public function testBinaryIpRoundTrip(string $ip): void
    {
        $bin = inet_pton($ip);
        $this->assertNotFalse($bin);
        $isIpv6      = strpos($ip, ':') !== false;
        $expectedLen = $isIpv6 ? 16 : 4;
        $this->assertSame($expectedLen, strlen($bin));

        if ($isIpv6) {
            $netCidr = '2001:db8::/32';
            $netText = '2001:db8::';
            $netBin  = inet_pton($netText);
            $prefix  = 32;
            $version = 6;
        } else {
            $netCidr = '10.0.0.0/24';
            $netText = '10.0.0.0';
            $netBin  = inet_pton($netText);
            $prefix  = 24;
            $version = 4;
        }
        $this->assertNotFalse($netBin);
        $this->assertSame($expectedLen, strlen($netBin), 'parent subnet network_bin is native length');

        $sIns = $this->db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, notes)
             VALUES (:cidr, :ver, :net, :nbin, :pfx, '', '')"
        );
        $sIns->bindValue(':cidr', $netCidr);
        $sIns->bindValue(':ver',  $version, PDO::PARAM_INT);
        $sIns->bindValue(':net',  $netText);
        ipam_bind_binary($sIns, ':nbin', $netBin);
        $sIns->bindValue(':pfx',  $prefix, PDO::PARAM_INT);
        $sIns->execute();
        $subnetId = (int)$this->db->lastInsertId('subnets_id_seq');
        // Postgres lastInsertId needs the sequence name; when IDENTITY is
        // used, the implicit sequence is <table>_<col>_seq.
        if ($subnetId === 0) {
            $subnetId = (int)$this->db
                ->query("SELECT id FROM subnets WHERE cidr = " . $this->db->quote($netCidr))
                ->fetchColumn();
        }
        $this->assertGreaterThan(0, $subnetId);

        $sOut = $this->db->prepare("SELECT network_bin FROM subnets WHERE id = :id");
        $sOut->execute([':id' => $subnetId]);
        $fetchedNet = (string)$sOut->fetch(PDO::FETCH_ASSOC)['network_bin'];
        $this->assertSame(strlen($netBin), strlen($fetchedNet), 'subnet network_bin native length preserved');
        $this->assertSame($netBin, $fetchedNet, 'subnet network_bin bytes preserved');

        $ins = $this->db->prepare(
            "INSERT INTO addresses (subnet_id, ip, ip_bin, note) VALUES (:sid, :ip, :bin, '')"
        );
        $ins->bindValue(':sid', $subnetId, PDO::PARAM_INT);
        $ins->bindValue(':ip', $ip);
        ipam_bind_binary($ins, ':bin', $bin);
        $ins->execute();

        $out = $this->db->prepare("SELECT ip_bin FROM addresses WHERE ip = :ip");
        $out->execute([':ip' => $ip]);
        $fetched = (string)$out->fetch(PDO::FETCH_ASSOC)['ip_bin'];
        $this->assertSame(strlen($bin), strlen($fetched), 'native length preserved');
        $this->assertSame($bin, $fetched, 'bytes preserved');
    }

    public function testAuditLogUpdateIsBlocked(): void
    {
        $this->db->exec("INSERT INTO audit_log (action, entity_type) VALUES ('test.event', 'test')");
        try {
            $this->db->exec("UPDATE audit_log SET action = 'tampered' WHERE action = 'test.event'");
            $this->fail('UPDATE on audit_log should have been blocked by trigger');
        } catch (PDOException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function testAuditLogDeleteIsBlocked(): void
    {
        $this->db->exec("INSERT INTO audit_log (action, entity_type) VALUES ('test.event', 'test')");
        try {
            $this->db->exec("DELETE FROM audit_log WHERE action = 'test.event'");
            $this->fail('DELETE on audit_log should have been blocked by trigger');
        } catch (PDOException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    public function testAuditLogBypassAllowsHousekeepingDelete(): void
    {
        // Regression guard for the #387 smoke-test finding that the bypass
        // path originally `RETURN NULL`ed, which silently suppressed the
        // DELETE in Postgres BEFORE triggers. The fix routes through
        // TG_OP + RETURN OLD / NEW so the operation actually proceeds.
        $this->db->exec("INSERT INTO audit_log (action, entity_type) VALUES ('test.housekeeping', 'test')");
        $this->db->beginTransaction();
        $this->db->exec("SET LOCAL ipam.bypass_append_only = '1'");
        $stmt = $this->db->prepare("DELETE FROM audit_log WHERE action = :a");
        $stmt->execute([':a' => 'test.housekeeping']);
        $this->assertSame(1, $stmt->rowCount(), 'bypass path must actually delete the row');
        $this->db->commit();

        $count = (int)$this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action = 'test.housekeeping'")
            ->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testBinaryIpSortOrderIsByteWise(): void
    {
        // One /24 and three children, to verify ORDER BY ip_bin is
        // byte-wise rather than lexicographic on the text representation
        // (which would sort '10.0.0.254' before '10.0.0.100').
        $sIns = $this->db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, notes)
             VALUES (:cidr, :ver, :net, :nbin, :pfx, '', '')"
        );
        $netBin = inet_pton('10.0.0.0');
        $sIns->bindValue(':cidr', '10.0.0.0/24');
        $sIns->bindValue(':ver',  4, PDO::PARAM_INT);
        $sIns->bindValue(':net',  '10.0.0.0');
        ipam_bind_binary($sIns, ':nbin', (string)$netBin);
        $sIns->bindValue(':pfx',  24, PDO::PARAM_INT);
        $sIns->execute();
        $sid = (int)$this->db
            ->query("SELECT id FROM subnets WHERE cidr = '10.0.0.0/24'")
            ->fetchColumn();

        foreach (['10.0.0.254', '10.0.0.1', '10.0.0.100'] as $ip) {
            $bin = inet_pton($ip);
            $aIns = $this->db->prepare(
                "INSERT INTO addresses (subnet_id, ip, ip_bin, note) VALUES (:sid, :ip, :bin, '')"
            );
            $aIns->bindValue(':sid', $sid, PDO::PARAM_INT);
            $aIns->bindValue(':ip',  $ip);
            ipam_bind_binary($aIns, ':bin', (string)$bin);
            $aIns->execute();
        }

        $ordered = $this->db
            ->query("SELECT ip FROM addresses WHERE subnet_id = $sid ORDER BY ip_bin ASC")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['10.0.0.1', '10.0.0.100', '10.0.0.254'], $ordered);
    }
}
