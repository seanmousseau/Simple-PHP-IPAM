<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * Multi-engine coverage for the two reflection helpers that drive the
 * IPAMBKL1 reader's per-row replay (#1042 dockerized matrix slice).
 *
 *   - ipam_logical_introspect_fks() — must return parents-first FK metadata
 *     across sqlite/mysql/pgsql so the FK-remap loop can find target rows.
 *   - ipam_logical_detect_autoincrement_pk() — must identify the strip-on-
 *     insert PK column on each engine (none for composite-PK join tables).
 *
 * SQLite is always exercised; MySQL/Postgres run only when their DSN envs
 * are set (same gate convention as SchemaParityTest).
 */
class IPAMBKL1IntrospectMultiEngineTest extends TestCase
{
    private const SQLITE_SCHEMA = __DIR__ . '/../Simple-PHP-IPAM/schema.sql';
    private const MYSQL_SCHEMA  = __DIR__ . '/../Simple-PHP-IPAM/schema.mysql.sql';
    private const PGSQL_SCHEMA  = __DIR__ . '/../Simple-PHP-IPAM/schema.pgsql.sql';

    public function testSqliteIntrospectsAddressesFks(): void
    {
        $db = $this->loadSqliteSchema();
        $this->assertHasFk(ipam_logical_introspect_fks($db, 'addresses'), 'subnet_id', 'subnets', 'id');
        $this->assertHasFk(ipam_logical_introspect_fks($db, 'addresses'), 'owner_contact_id', 'contacts', 'id');
    }

    public function testSqliteDetectsIntegerPk(): void
    {
        $db = $this->loadSqliteSchema();
        $this->assertSame('id', ipam_logical_detect_autoincrement_pk($db, 'subnets'));
        // Composite PK join table — no strip-on-insert column.
        $this->assertNull(ipam_logical_detect_autoincrement_pk($db, 'subnet_tags'));
    }

    public function testMysqlIntrospectsAddressesFks(): void
    {
        $db = $this->mysqlOrSkip();
        $this->assertHasFk(ipam_logical_introspect_fks($db, 'addresses'), 'subnet_id', 'subnets', 'id');
        $this->assertHasFk(ipam_logical_introspect_fks($db, 'addresses'), 'owner_contact_id', 'contacts', 'id');
    }

    public function testMysqlDetectsAutoIncrementPk(): void
    {
        $db = $this->mysqlOrSkip();
        $this->assertSame('id', ipam_logical_detect_autoincrement_pk($db, 'subnets'));
        $this->assertNull(ipam_logical_detect_autoincrement_pk($db, 'subnet_tags'));
    }

    public function testPgsqlIntrospectsAddressesFks(): void
    {
        $db = $this->pgsqlOrSkip();
        $this->assertHasFk(ipam_logical_introspect_fks($db, 'addresses'), 'subnet_id', 'subnets', 'id');
        $this->assertHasFk(ipam_logical_introspect_fks($db, 'addresses'), 'owner_contact_id', 'contacts', 'id');
    }

    public function testPgsqlDetectsSerialOrIdentityPk(): void
    {
        $db = $this->pgsqlOrSkip();
        $this->assertSame('id', ipam_logical_detect_autoincrement_pk($db, 'subnets'));
        $this->assertNull(ipam_logical_detect_autoincrement_pk($db, 'subnet_tags'));
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @param list<array{from:string,table:string,to:string}> $fks
     */
    private function assertHasFk(array $fks, string $from, string $table, string $to): void
    {
        foreach ($fks as $fk) {
            if ($fk['from'] === $from && $fk['table'] === $table && $fk['to'] === $to) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail("FK $from -> $table.$to not found; got " . json_encode($fks));
    }

    private function loadSqliteSchema(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $sql = (string) file_get_contents(self::SQLITE_SCHEMA);
        $db->exec($sql);
        return $db;
    }

    private function mysqlOrSkip(): PDO
    {
        $dsn = (string) getenv('IPAM_MYSQL_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('IPAM_MYSQL_DSN not set');
        }
        $user = (string) getenv('IPAM_MYSQL_USER');
        $pass = (string) getenv('IPAM_MYSQL_PASS');
        $dbName = $this->extractDbName($dsn);
        $adminDsn = (string) preg_replace('/(^mysql:|;)dbname=[^;]+;?/', '$1', $dsn);
        $adminDsn = rtrim($adminDsn, ';');
        $admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
        $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        unset($admin);
        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec((string) file_get_contents(self::MYSQL_SCHEMA));
        return $db;
    }

    private function pgsqlOrSkip(): PDO
    {
        $dsn = (string) getenv('IPAM_PGSQL_DSN');
        if ($dsn === '') {
            $this->markTestSkipped('IPAM_PGSQL_DSN not set');
        }
        $user = (string) getenv('IPAM_PGSQL_USER');
        $pass = (string) getenv('IPAM_PGSQL_PASS');
        $dbName = $this->extractDbName($dsn);
        $adminDsn = (string) preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn);
        $admin = new PDO($adminDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
            . "WHERE datname = " . $admin->quote($dbName) . " AND pid <> pg_backend_pid()"
        );
        $admin->exec("DROP DATABASE IF EXISTS \"$dbName\"");
        $admin->exec("CREATE DATABASE \"$dbName\"");
        unset($admin);
        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $db->exec((string) file_get_contents(self::PGSQL_SCHEMA));
        return $db;
    }

    private function extractDbName(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
            return $m[1];
        }
        return '';
    }
}
