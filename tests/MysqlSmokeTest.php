<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test against a real MySQL 8.0+ server (#384).
 *
 * This class is **skipped** whenever IPAM_MYSQL_DSN is not set in the
 * environment, so it does not slow down day-to-day local SQLite-only
 * development. It only runs inside the CI `mysql` matrix slot that
 * spins up a MySQL 8.0 service container, or when a developer sets the
 * env variables manually to validate MysqlDialect / schema.mysql.sql
 * changes against a live server.
 *
 * Coverage goals:
 *
 *   1. ipam_db($config) opens a PDO connection on the mysql driver and
 *      enforces the 8.0+ version floor.
 *   2. ipam_db_init($db) loads schema.mysql.sql end-to-end through
 *      PDO::exec() (validates that every CREATE TABLE / TRIGGER is
 *      valid MySQL syntax, not just valid SqliteDialect output).
 *   3. The schema_migrations pre-seed covers every historical version,
 *      so apply_migrations() is a no-op on fresh MySQL.
 *   4. Binary IP columns (ip_bin, network_bin) round-trip cleanly for
 *      the three locked #410 test vectors under VARBINARY(16).
 *   5. audit_log append-only triggers raise SQLSTATE '45000' errno 1644
 *      on both UPDATE and DELETE attempts.
 *   6. ORDER BY ip_bin is byte-wise and matches SQLite's sort order.
 *
 * Not in scope (deferred to other tests / CI slots):
 *   - Full Playwright UI suite against MySQL — tracked in #433.
 *   - test_api.sh against MySQL — runs from the CI workflow directly.
 *   - DialectTest + MysqlDialectTest string-output tests — those are
 *     engine-independent and always run.
 */
#[Group('mysql')]
final class MysqlSmokeTest extends TestCase
{
    private PDO $db;
    /** @var array<string, mixed> */
    private array $config;
    private bool $hadConfigFile = false;

    public static function setUpBeforeClass(): void
    {
        if (getenv('IPAM_MYSQL_DSN') === false || getenv('IPAM_MYSQL_DSN') === '') {
            self::markTestSkipped('IPAM_MYSQL_DSN not set; skipping MySQL smoke tests');
        }
    }

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/lib.php';

        $dsn  = (string)getenv('IPAM_MYSQL_DSN');
        $user = (string)getenv('IPAM_MYSQL_USER');
        $pass = (string)getenv('IPAM_MYSQL_PASS');

        // Connect as root (or whoever the env credentials are) and drop +
        // recreate the target database for a pristine fixture. We resolve
        // the dbname from the DSN to parameterize this without hardcoding.
        $dbName = $this->extractDbNameFromDsn($dsn);
        $this->assertNotSame('', $dbName, 'DSN must include dbname');

        $adminDsn = preg_replace('/;dbname=[^;]+/', '', $dsn);
        $this->assertNotNull($adminDsn, 'preg_replace on DSN must succeed');
        $admin = new PDO($adminDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
        $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        unset($admin);

        $this->config = [
            'db_driver' => 'mysql',
            'db_dsn'    => $dsn,
            'db_user'   => $user,
            'db_pass'   => $pass,
            'bootstrap_admin' => [
                'username' => 'admin',
                'password' => 'testing123',
            ],
        ];
        $GLOBALS['config'] = $this->config;

        // Write a minimal config.php stub so ipam_db_init() can require it
        // when bootstrapping the admin user on a fresh install. Restored in
        // tearDown(). Track whether the file existed originally so teardown
        // can either restore the backup OR unlink the stub it created.
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

        // Fresh dialect for each test — the globals cache needs to pick up
        // MysqlDialect based on the config we just pinned.
        unset($GLOBALS['ipam_dialect']);

        $this->db = ipam_db($this->config);
        ipam_db_init($this->db);
    }

    protected function tearDown(): void
    {
        $cfgPath = dirname(__DIR__) . '/Simple-PHP-IPAM/config.php';
        $bak = $cfgPath . '.smoke-bak';
        if ($this->hadConfigFile) {
            // Restore the original file from the backup.
            if (file_exists($bak)) {
                copy($bak, $cfgPath);
                @unlink($bak);
            }
        } else {
            // Nothing to restore — remove the stub we wrote so the worktree
            // is clean for later local runs.
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

    public function testDialectIsMysql(): void
    {
        $this->assertSame('mysql', ipam_dialect()->driver_name());
    }

    public function testServerVersionMeetsFloor(): void
    {
        $version = (string)$this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->assertTrue(
            version_compare($version, '8.0.0', '>='),
            "Expected MySQL >= 8.0.0, got $version"
        );
    }

    public function testSchemaMigrationsPreseeded(): void
    {
        $row = $this->db->query("SELECT COUNT(*) c FROM schema_migrations")->fetch(PDO::FETCH_ASSOC);
        // Must match the list in schema.mysql.sql — the pre-seed count is
        // the regression gate here. If a new historical migration is
        // added, this must go up too.
        $this->assertSame(33, (int)$row['c']);
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
            'IPv4 low'           => ['10.0.0.0'],
            'IPv4 all-high'      => ['255.255.255.255'],
            'IPv6 compact'       => ['2001:db8::'],
            'IPv6 all-high'      => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
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

        // Seed a parent subnet row whose network_bin is native-length for the
        // IP family being tested. Before v2.10.0 this fixture always used a
        // 4-byte /24 IPv4 subnet even for the IPv6 vectors, so the 16-byte
        // network_bin round-trip was never exercised. Creating a real IPv6
        // subnet row for IPv6 vectors proves VARBINARY(16) stores both the
        // 4-byte and 16-byte forms correctly without padding.
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
        $sIns->bindValue(':pfx',  $prefix,  PDO::PARAM_INT);
        $sIns->execute();
        $subnetId = (int)$this->db->lastInsertId();

        // Round-trip the parent subnet's network_bin first so the 16-byte
        // shape is asserted independently of the child address row.
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
            $this->assertSame('45000', (string)($e->errorInfo[0] ?? ''));
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
            $this->assertSame('45000', (string)($e->errorInfo[0] ?? ''));
        }
    }
}
