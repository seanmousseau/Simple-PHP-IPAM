<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Closes finding C4 (#885): confirm that a fresh install on MySQL or
 * PostgreSQL stamps every migration version into schema_migrations
 * up-front (via the engine-specific schema file path in ipam_db_init),
 * so the SQLite-only PRAGMA-bearing closures (e.g. 0.3 backfilling
 * subnets.network_bin via PRAGMA table_info) are never re-executed
 * against a non-SQLite engine.
 *
 * The SQLite path is already covered by MigrationTest::testAllMigrations…
 *
 * MySQL + Postgres tests require live DSNs (IPAM_MYSQL_DSN,
 * IPAM_PGSQL_DSN). Locally these are usually unset and the tests
 * skip; in CI the env vars are wired by .github/workflows/php-qa.yml's
 * matrix, and CI=true makes a missing DSN a hard failure (not a silent
 * skip) so we can never lose multi-driver coverage by accident.
 */
final class MigrationFreshInstallMultiDriverTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/lib.php';
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/migrations.php';
    }

    /**
     * @return array<int, string>
     */
    private function knownMigrationVersions(): array
    {
        return array_keys(ipam_migrations());
    }

    private function dsnOrSkip(string $envKey): string
    {
        $dsn = getenv($envKey);
        if ($dsn === false || $dsn === '') {
            $isCi = (getenv('CI') === 'true' || getenv('CI') === '1');
            $msg  = "$envKey not set — required for multi-driver fresh-install coverage (#885)";
            if ($isCi) {
                $this->fail($msg . '. CI=true is meant to wire this DSN; refusing to silently skip.');
            }
            $this->markTestSkipped($msg . ' (set the env var to run locally).');
        }
        return $dsn;
    }

    private function connect(string $dsn, string $driver): PDO
    {
        $user = (string)(getenv('IPAM_' . strtoupper($driver) . '_USER') ?: '');
        $pass = (string)(getenv('IPAM_' . strtoupper($driver) . '_PASS') ?: '');
        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE         => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            // CR #1100: skipping a connection failure means CI can go
            // green without exercising the cross-driver fresh-install
            // path that this test exists to guard. In CI the DSN was
            // explicitly provided (the bootstrap-app + service-container
            // fixtures wire it up); a connect failure there is a real
            // regression. Fail loudly. Locally a missing service is
            // fine to skip.
            $isCi = getenv('CI') === 'true' || getenv('GITHUB_ACTIONS') === 'true';
            if ($isCi) {
                $this->fail("Cannot connect to $driver DSN in CI: " . $e->getMessage());
            }
            $this->markTestSkipped("Cannot connect to $driver DSN: " . $e->getMessage());
        }
    }

    private function dropAllTables(PDO $db, string $driver): void
    {
        if ($driver === 'mysql') {
            $db->exec("SET FOREIGN_KEY_CHECKS=0");
            $rows = $db->query("SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll();
            foreach ($rows as $r) {
                $db->exec("DROP TABLE IF EXISTS `" . str_replace('`', '', (string)$r['n']) . "`");
            }
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
        } elseif ($driver === 'pgsql') {
            $db->exec("DROP SCHEMA public CASCADE");
            $db->exec("CREATE SCHEMA public");
        }
    }

    /**
     * @param 'mysql'|'pgsql' $driver
     */
    private function runFreshInstallScenario(string $driver, string $envKey): void
    {
        $dsn = $this->dsnOrSkip($envKey);
        $db  = $this->connect($dsn, $driver);

        $this->dropAllTables($db, $driver);

        // Pin the matching dialect for ipam_db_init.
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/MysqlDialect.php';
        require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/PgsqlDialect.php';
        // CR #1100: capture the prior dialect (if any) so the finally
        // block restores it instead of always unsetting. Otherwise a
        // test that ran earlier and pinned its own dialect would lose
        // that state and pollute downstream tests.
        $hadDialect  = array_key_exists('ipam_dialect', $GLOBALS);
        $prevDialect = $GLOBALS['ipam_dialect'] ?? null;
        $GLOBALS['ipam_dialect'] = $driver === 'mysql' ? new MysqlDialect() : new PgsqlDialect();

        // Restore globals so other tests aren't poisoned.
        $hadConfig  = array_key_exists('config', $GLOBALS);
        $prevConfig = $GLOBALS['config'] ?? null;
        $GLOBALS['config'] = ['proxy_trust' => false, 'bootstrap_admin' => ['username' => 'admin', 'password' => 'changeme!']];

        try {
            ipam_db_init($db);

            $stamped = array_map(
                static fn(array $r): string => (string)$r['version'],
                $db->query("SELECT version FROM schema_migrations")->fetchAll()
            );
            sort($stamped);
            $expected = $this->knownMigrationVersions();
            sort($expected);

            $this->assertSame(
                $expected,
                $stamped,
                "On $driver, fresh-install must stamp every known migration version up-front "
                . "so SQLite-only closures (e.g. the 0.3 PRAGMA backfill) are never re-executed."
            );

            // Now verify the gate: clearing schema_migrations and replaying must
            // NOT crash with a SQLite-specific PRAGMA error against the fresh
            // engine schema. apply_migrations() will replay every closure; each
            // must guard its driver-specific syntax.
            //
            // We don't assert the closures are no-ops structurally here — that's
            // SchemaParityTest's job. This test only verifies the closures don't
            // throw when forced to run on the wrong engine.
            $db->exec("DELETE FROM schema_migrations");
            try {
                apply_migrations($db);
            } catch (\Throwable $e) {
                $this->fail(
                    "apply_migrations() crashed on $driver after stamp clear: "
                    . $e->getMessage()
                    . "\nThis means a migration closure has SQLite-specific syntax "
                    . "(typically PRAGMA table_info) that lacks an engine guard. "
                    . "C4 / #885 regression."
                );
            }
            $this->assertGreaterThan(
                0,
                (int)$db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn(),
                "After replay, schema_migrations must be non-empty on $driver."
            );
        } finally {
            // Tidy up the test database so subsequent runs start clean.
            $this->dropAllTables($db, $driver);
            if ($hadDialect) {
                $GLOBALS['ipam_dialect'] = $prevDialect;
            } else {
                unset($GLOBALS['ipam_dialect']);
            }
            if ($hadConfig) {
                $GLOBALS['config'] = $prevConfig;
            } else {
                unset($GLOBALS['config']);
            }
        }
    }

    public function testFreshInstallStampsAllMigrationsOnMysql(): void
    {
        $this->runFreshInstallScenario('mysql', 'IPAM_MYSQL_DSN');
    }

    public function testFreshInstallStampsAllMigrationsOnPgsql(): void
    {
        $this->runFreshInstallScenario('pgsql', 'IPAM_PGSQL_DSN');
    }
}
