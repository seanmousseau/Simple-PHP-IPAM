<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/lib/backup.php';

/**
 * Cross-engine 3×3 round-trip parity for IPAMBKL1 (#1042 D4 cmd 12-13).
 *
 * For every (source, target) ∈ {sqlite, mysql, pgsql}², dump a heterogeneous
 * fixture from the source engine, apply via ipam_restore_apply on the
 * target engine, and assert per-table row-count parity. This is the
 * load-bearing assertion that engine-agnostic Logical-format works in v3.23.0.
 *
 * The two intentional T4 divergences are excluded (see
 * IPAMBKL1RowCountParityTest comments + docs/internal/ipambkl1-format.md):
 *   - schema_migrations: target's chain is preserved across restore.
 *   - audit_log: append-only, source rows append to target's baseline.
 *
 * Gating:
 *   - sqlite→sqlite always runs.
 *   - mysql / pgsql legs run only when IPAM_MYSQL_DSN / IPAM_PGSQL_DSN are
 *     set (matches SchemaParityTest convention).
 */
class IPAMBKL1CrossEngineParityTest extends TestCase
{
    private const SQLITE_SCHEMA = __DIR__ . '/../../Simple-PHP-IPAM/schema.sql';
    private const MYSQL_SCHEMA  = __DIR__ . '/../../Simple-PHP-IPAM/schema.mysql.sql';
    private const PGSQL_SCHEMA  = __DIR__ . '/../../Simple-PHP-IPAM/schema.pgsql.sql';

    /**
     * @return iterable<string, array{0:string,1:string}>
     */
    public static function matrix(): iterable
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $src) {
            foreach (['sqlite', 'mysql', 'pgsql'] as $tgt) {
                yield "$src->$tgt" => [$src, $tgt];
            }
        }
    }

    #[DataProvider('matrix')]
    public function testRoundTripPreservesPerTableRowCounts(string $src, string $tgt): void
    {
        $source = $this->freshDb($src);
        $this->seedFixture($source);

        $expected = $this->captureRowCounts($source);

        // Fixture lives under sys_get_temp_dir() and is overwritten next run;
        // OS tmp reaper handles cleanup. Keeping it on disk also aids debugging
        // when a parity assertion fails. Use the tempnam() path directly so
        // the original allocation isn't orphaned by a `.bkl1.gz` append.
        $fixture = tempnam(sys_get_temp_dir(), 'ipambkl1_xe_');
        $this->assertNotFalse($fixture, 'tempnam() allocation must succeed');
        ipam_backup_logical_dump($source, $fixture);

        $target = $this->freshDb($tgt);
        $result = ipam_restore_apply($target, $fixture);
        $this->assertSame('logical', $result['format'] ?? null, "{$src}->{$tgt}: dispatcher must take Logical path");

        $actual = $this->captureRowCounts($target);

        // Drop expected divergences before comparison.
        unset(
            $expected['schema_migrations'], $actual['schema_migrations'],
            $expected['audit_log'],         $actual['audit_log']
        );

        $this->assertSame(
            $expected,
            $actual,
            "{$src}->{$tgt}: cross-engine row-count parity must hold for user-data tables"
        );
    }

    // -----------------------------------------------------------------------
    // fixture
    // -----------------------------------------------------------------------

    private function seedFixture(PDO $db): void
    {
        // 3 users.
        $stmt = $db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active) VALUES (:u, :h, :r, 1)"
        );
        for ($i = 1; $i <= 3; $i++) {
            $stmt->execute([':u' => "user$i", ':h' => 'bogus-hash', ':r' => $i === 1 ? 'admin' : 'readonly']);
        }

        // 3 sites in a 2-level hierarchy.
        $db->exec("INSERT INTO sites (name, description) VALUES ('hq', 'HQ')");
        $hqId = (int) $db->lastInsertId();
        $stmt = $db->prepare("INSERT INTO sites (name, description, parent_id) VALUES (:n, :d, :p)");
        $stmt->execute([':n' => 'branch-a', ':d' => 'A', ':p' => $hqId]);
        $stmt->execute([':n' => 'branch-b', ':d' => 'B', ':p' => $hqId]);

        // 2 vrfs.
        $db->exec("INSERT INTO vrfs (name, description) VALUES ('default', '')");
        $db->exec("INSERT INTO vrfs (name, description) VALUES ('mgmt', 'mgmt vrf')");

        // 1 vlan.
        $stmt = $db->prepare(
            "INSERT INTO vlans (vlan_id, name, description, site_id) VALUES (10, 'office', '', :s)"
        );
        $stmt->execute([':s' => $hqId]);

        // 4 contacts.
        $stmt = $db->prepare("INSERT INTO contacts (name, email) VALUES (:n, :e)");
        for ($i = 1; $i <= 4; $i++) {
            $stmt->execute([':n' => "Contact $i", ':e' => "c$i@example.com"]);
        }

        // 5 subnets with binary network_bin.
        $subnetIds = [];
        for ($i = 0; $i < 5; $i++) {
            $stmt = $db->prepare(
                "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description, site_id) " .
                "VALUES (:cidr, 4, :net, :nb, 24, :desc, :site)"
            );
            $stmt->bindValue(':cidr', "10.$i.0.0/24");
            $stmt->bindValue(':net',  "10.$i.0.0");
            ipam_bind_binary($stmt, ':nb', (string) inet_pton("10.$i.0.0"));
            $stmt->bindValue(':desc', "subnet $i");
            $stmt->bindValue(':site', $hqId, PDO::PARAM_INT);
            $stmt->execute();
            $subnetIds[] = (int) $db->lastInsertId();
        }

        // 20 addresses (4/subnet) with binary ip_bin.
        foreach ($subnetIds as $i => $sid) {
            for ($j = 1; $j <= 4; $j++) {
                $ip = "10.$i.0.$j";
                $stmt = $db->prepare(
                    "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status) " .
                    "VALUES (:s, :ip, :bin, :h, :st)"
                );
                $stmt->bindValue(':s',  $sid, PDO::PARAM_INT);
                $stmt->bindValue(':ip', $ip);
                ipam_bind_binary($stmt, ':bin', (string) inet_pton($ip));
                $stmt->bindValue(':h',  "host-$i-$j");
                $stmt->bindValue(':st', $j === 1 ? 'reserved' : 'used');
                $stmt->execute();
            }
        }

        // 3 tags.
        $stmt = $db->prepare("INSERT INTO tags (name, colour) VALUES (:n, '#888888')");
        foreach (['production', 'staging', 'dev'] as $name) {
            $stmt->execute([':n' => $name]);
        }
        $tagRows = $db->query("SELECT id FROM tags ORDER BY id")?->fetchAll(PDO::FETCH_COLUMN) ?? [];
        $tagIds = array_map('intval', is_array($tagRows) ? $tagRows : []);

        // 6 subnet_tags rows (composite PK / no auto-increment).
        $stmt = $db->prepare("INSERT INTO subnet_tags (subnet_id, tag_id) VALUES (:s, :t)");
        $pairs = [
            [$subnetIds[0], $tagIds[0]],
            [$subnetIds[1], $tagIds[0]],
            [$subnetIds[1], $tagIds[1]],
            [$subnetIds[2], $tagIds[2]],
            [$subnetIds[3], $tagIds[1]],
            [$subnetIds[4], $tagIds[2]],
        ];
        foreach ($pairs as $p) {
            $stmt->execute([':s' => $p[0], ':t' => $p[1]]);
        }

        // 5 audit_log rows (append-only via trigger on sqlite).
        $stmt = $db->prepare(
            "INSERT INTO audit_log (action, entity_type, entity_id, user_id, username, details) " .
            "VALUES (:a, :e, :id, 1, 'user1', :d)"
        );
        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute([
                ':a'  => "subnet.update",
                ':e'  => 'subnet',
                ':id' => $subnetIds[$i - 1],
                ':d'  => "audit row $i",
            ]);
        }
    }

    // -----------------------------------------------------------------------
    // engine-aware infrastructure
    // -----------------------------------------------------------------------

    private function freshDb(string $engine): PDO
    {
        return match ($engine) {
            'sqlite' => $this->freshSqlite(),
            'mysql'  => $this->freshMysql(),
            'pgsql'  => $this->freshPgsql(),
            default  => throw new RuntimeException("unknown engine $engine"),
        };
    }

    private function freshSqlite(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string) file_get_contents(self::SQLITE_SCHEMA));
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        return $db;
    }

    private function freshMysql(): PDO
    {
        $dsn = (string) getenv('IPAM_MYSQL_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('IPAM_MYSQL_DSN not set');
        }
        $user = (string) getenv('IPAM_MYSQL_USER');
        $pass = (string) getenv('IPAM_MYSQL_PASS');
        $dbName = $this->dsnDb($dsn);
        $adminDsn = rtrim((string) preg_replace('/(^mysql:|;)dbname=[^;]+;?/', '$1', $dsn), ';');
        $admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
        $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        unset($admin);

        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec((string) file_get_contents(self::MYSQL_SCHEMA));
        $prevDialect = $GLOBALS['ipam_dialect'] ?? null;
        ipam_dialect_from_config(['db_driver' => 'mysql']);
        try {
            ensure_migrations_table($db);
            apply_migrations($db);
        } finally {
            if ($prevDialect instanceof Dialect) {
                $GLOBALS['ipam_dialect'] = $prevDialect;
            } else {
                unset($GLOBALS['ipam_dialect']);
            }
        }
        return $db;
    }

    private function freshPgsql(): PDO
    {
        $dsn = (string) getenv('IPAM_PGSQL_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('IPAM_PGSQL_DSN not set');
        }
        $user = (string) getenv('IPAM_PGSQL_USER');
        $pass = (string) getenv('IPAM_PGSQL_PASS');
        $dbName = $this->dsnDb($dsn);
        $adminDsn = (string) preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn);
        $admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity " .
            "WHERE datname = " . $admin->quote($dbName) . " AND pid <> pg_backend_pid()"
        );
        $admin->exec("DROP DATABASE IF EXISTS \"$dbName\"");
        $admin->exec("CREATE DATABASE \"$dbName\"");
        unset($admin);

        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Match production ipam_db() — pdo_pgsql returns BYTEA as a stream
        // resource by default; PgsqlStatement auto-unwraps to string so
        // ipam_logical_encode_value() doesn't reject ip_bin/network_bin.
        require_once __DIR__ . '/../../Simple-PHP-IPAM/PgsqlStatement.php';
        $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PgsqlStatement::class]);
        $db->exec((string) file_get_contents(self::PGSQL_SCHEMA));
        $prevDialect = $GLOBALS['ipam_dialect'] ?? null;
        ipam_dialect_from_config(['db_driver' => 'pgsql']);
        try {
            ensure_migrations_table($db);
            apply_migrations($db);
        } finally {
            if ($prevDialect instanceof Dialect) {
                $GLOBALS['ipam_dialect'] = $prevDialect;
            } else {
                unset($GLOBALS['ipam_dialect']);
            }
        }
        return $db;
    }

    private function dsnDb(string $dsn): string
    {
        return preg_match('/dbname=([^;]+)/', $dsn, $m) ? $m[1] : '';
    }

    /**
     * @return array<string,int>
     */
    private function captureRowCounts(PDO $db): array
    {
        $driverAttr = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverAttr) ? $driverAttr : '';
        $tables = match ($driver) {
            'sqlite' => $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                ?->fetchAll(PDO::FETCH_COLUMN) ?: [],
            'mysql'  => $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")
                ?->fetchAll(PDO::FETCH_COLUMN) ?: [],
            'pgsql'  => $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() ORDER BY table_name")
                ?->fetchAll(PDO::FETCH_COLUMN) ?: [],
            default  => [],
        };
        $out = [];
        foreach ($tables as $t) {
            if (!is_string($t)) continue;
            $r = $db->query("SELECT COUNT(*) FROM " . ipam_logical_q($db, $t));
            $val = $r ? $r->fetchColumn() : 0;
            $out[$t] = is_numeric($val) ? (int) $val : 0;
        }
        // Normalise key order so per-engine information_schema sort
        // differences (MySQL 8.0 default collation vs MariaDB 10.x —
        // underscore sorts at different positions) don't trip the
        // assertSame() comparison. Counts are what we want to compare,
        // not iteration order.
        ksort($out, SORT_STRING);
        return $out;
    }
}
