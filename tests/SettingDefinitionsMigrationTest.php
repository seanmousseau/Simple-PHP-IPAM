<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/migrations.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 Task 3.2 (ADR-001) — the `3.30.0-setting-definitions` migration.
 *
 * Asserts that the closure:
 *   - creates the setting_definitions table when absent (engine-portable);
 *   - seeds every key from ipam_setting_definitions() PHP registry;
 *   - is idempotent — a second run produces no error and no extra rows.
 *
 * Engine-parametric. Runs on SQLite always; skips MySQL/Postgres when DSN
 * env vars are not set (mirrors MysqlSmokeTest / PgsqlSmokeTest gating).
 */
final class SettingDefinitionsMigrationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function driverProvider(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql'  => ['mysql'];
        yield 'pgsql'  => ['pgsql'];
    }

    #[DataProvider('driverProvider')]
    public function testTableExistsAndIsSeededAfterMigration(string $driver): void
    {
        $db = $this->openConnection($driver);
        $migration = $this->loadMigration();

        $migration($db);

        $this->assertTrue($this->tableExists($db, $driver, 'setting_definitions'));

        $countRow = $db->query('SELECT COUNT(*) AS c FROM setting_definitions')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($countRow);
        $count = (int) $countRow['c'];
        $this->assertGreaterThan(0, $count);

        $registry = ipam_setting_definitions();
        $this->assertSame(count($registry), $count, 'every registry key should seed exactly one row');

        $keyCol = $driver === 'mysql' ? '`key`' : ($driver === 'pgsql' ? '"key"' : 'key');
        $seededRows = $db->query("SELECT {$keyCol} FROM setting_definitions")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertIsArray($seededRows);
        foreach (array_keys($registry) as $registryKey) {
            $this->assertContains($registryKey, $seededRows, "registry key {$registryKey} should be seeded");
        }
    }

    #[DataProvider('driverProvider')]
    public function testMigrationIsIdempotent(string $driver): void
    {
        $db = $this->openConnection($driver);
        $migration = $this->loadMigration();

        $migration($db);
        $firstRow = $db->query('SELECT COUNT(*) AS c FROM setting_definitions')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($firstRow);
        $first = (int) $firstRow['c'];

        // Replay — must not raise on existing PK rows.
        $migration($db);
        $secondRow = $db->query('SELECT COUNT(*) AS c FROM setting_definitions')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($secondRow);
        $second = (int) $secondRow['c'];

        $this->assertSame($first, $second, 'replay must not change row count');
    }

    /**
     * @return \Closure(PDO): void
     */
    private function loadMigration(): \Closure
    {
        $migs = ipam_migrations();
        $this->assertArrayHasKey('3.30.0-setting-definitions', $migs);
        /** @var \Closure(PDO): void $closure */
        $closure = $migs['3.30.0-setting-definitions'];
        return $closure;
    }

    private function openConnection(string $driver): PDO
    {
        if ($driver === 'sqlite') {
            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $GLOBALS['db'] = $db;
            // Force-rebuild the cached dialect to match this driver.
            unset($GLOBALS['ipam_dialect']);
            ipam_dialect_from_config(['db_driver' => 'sqlite']);
            return $db;
        }

        $envKey = $driver === 'mysql' ? 'IPAM_TEST_MYSQL_DSN' : 'IPAM_TEST_PGSQL_DSN';
        $dsn = getenv($envKey);
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped("{$envKey} not set — skipping {$driver} run");
        }
        $user = getenv($driver === 'mysql' ? 'IPAM_TEST_MYSQL_USER' : 'IPAM_TEST_PGSQL_USER') ?: null;
        $pass = getenv($driver === 'mysql' ? 'IPAM_TEST_MYSQL_PASS' : 'IPAM_TEST_PGSQL_PASS') ?: null;
        $db = new PDO($dsn, is_string($user) ? $user : null, is_string($pass) ? $pass : null);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Isolate the test — drop pre-existing setting_definitions if the
        // live schema already carries it.
        $db->query('DROP TABLE IF EXISTS setting_definitions');
        $GLOBALS['db'] = $db;
        unset($GLOBALS['ipam_dialect']);
        ipam_dialect_from_config(['db_driver' => $driver]);
        return $db;
    }

    private function tableExists(PDO $db, string $driver, string $name): bool
    {
        if ($driver === 'sqlite') {
            $st = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :n");
            $st->execute([':n' => $name]);
            return (bool) $st->fetchColumn();
        }
        if ($driver === 'mysql') {
            $st = $db->prepare(
                'SELECT table_name FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = :n'
            );
            $st->execute([':n' => $name]);
            return (bool) $st->fetchColumn();
        }
        // pgsql
        $st = $db->prepare(
            'SELECT tablename FROM pg_tables '
            . 'WHERE schemaname = ANY(current_schemas(false)) AND tablename = :n'
        );
        $st->execute([':n' => $name]);
        return (bool) $st->fetchColumn();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['ipam_dialect']);
    }
}
