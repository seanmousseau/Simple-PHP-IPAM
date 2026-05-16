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
     * v3.30.0 Task 3.3 (ADR-001) — the `3.30.0-drop-settings-type` migration
     * removes the legacy `settings.type` column. After it runs, the settings
     * table must carry no `type` column on any engine.
     */
    #[DataProvider('driverProvider')]
    public function testSettingsTypeColumnIsDroppedAfterMigration(string $driver): void
    {
        $db = $this->openConnection($driver);
        $this->createLegacySettingsTable($db, $driver);

        // Sanity check: the legacy table starts WITH the type column.
        $this->assertTrue(
            $this->settingsHasTypeColumn($db, $driver),
            'fixture should start with the legacy settings.type column'
        );

        // Seed representative rows so the SQLite INSERT-SELECT → DROP → RENAME
        // table rebuild (invariant #2 — the v2.2.1 wipe class of migration) is
        // proven to preserve user data, not just remove the column. One GLOBAL
        // row (tenant_id IS NULL) and one TENANT-scoped row, each with distinct
        // value / updated_at / updated_by.
        $seedRows = [
            [
                'tenant_id'  => null,
                'key'        => 'app.theme',
                'value'      => 'midnight-blue',
                'updated_at' => '2025-01-02 03:04:05',
                'updated_by' => 11,
            ],
            [
                'tenant_id'  => 7,
                'key'        => 'app.theme',
                'value'      => 'high-contrast',
                'updated_at' => '2026-04-30 23:59:58',
                'updated_by' => 42,
            ],
        ];
        $keyCol = $driver === 'mysql' ? '`key`' : ($driver === 'pgsql' ? '"key"' : 'key');
        $ins = $db->prepare(
            "INSERT INTO settings (tenant_id, {$keyCol}, value, updated_at, updated_by) "
            . 'VALUES (:tenant_id, :key, :value, :updated_at, :updated_by)'
        );
        foreach ($seedRows as $row) {
            $ins->execute([
                ':tenant_id'  => $row['tenant_id'],
                ':key'        => $row['key'],
                ':value'      => $row['value'],
                ':updated_at' => $row['updated_at'],
                ':updated_by' => $row['updated_by'],
            ]);
        }

        $drop = $this->loadDropMigration();
        $drop($db);

        $this->assertFalse(
            $this->settingsHasTypeColumn($db, $driver),
            'settings.type must be gone after 3.30.0-drop-settings-type'
        );

        // Data-preservation: every seeded row must survive the rebuild with
        // value / updated_at / updated_by / tenant_id / key byte-intact.
        $countRow = $db->query('SELECT COUNT(*) AS c FROM settings')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($countRow);
        $this->assertSame(
            count($seedRows),
            (int) $countRow['c'],
            'no rows may be lost in the settings table rebuild'
        );
        foreach ($seedRows as $row) {
            if ($row['tenant_id'] === null) {
                $sel = $db->prepare(
                    "SELECT tenant_id, {$keyCol} AS k, value, updated_at, updated_by "
                    . "FROM settings WHERE {$keyCol} = :key AND tenant_id IS NULL"
                );
                $sel->execute([':key' => $row['key']]);
            } else {
                $sel = $db->prepare(
                    "SELECT tenant_id, {$keyCol} AS k, value, updated_at, updated_by "
                    . "FROM settings WHERE {$keyCol} = :key AND tenant_id = :tenant_id"
                );
                $sel->execute([':key' => $row['key'], ':tenant_id' => $row['tenant_id']]);
            }
            $got = $sel->fetch(PDO::FETCH_ASSOC);
            $this->assertIsArray($got, "seeded row {$row['key']} must survive the rebuild");
            $this->assertSame($row['key'], $got['k'], 'key must be byte-intact');
            $this->assertSame($row['value'], $got['value'], 'value must be byte-intact');
            $this->assertSame(
                $row['updated_at'],
                (string) $got['updated_at'],
                'updated_at must be byte-intact'
            );
            $this->assertSame(
                $row['updated_by'],
                (int) $got['updated_by'],
                'updated_by must be byte-intact'
            );
            if ($row['tenant_id'] === null) {
                $this->assertNull($got['tenant_id'], 'GLOBAL row tenant_id must stay NULL');
            } else {
                $this->assertSame(
                    $row['tenant_id'],
                    (int) $got['tenant_id'],
                    'TENANT row tenant_id must be byte-intact'
                );
            }
        }

        // Replay must be a clean no-op once the column is already absent.
        $drop($db);
        $this->assertFalse(
            $this->settingsHasTypeColumn($db, $driver),
            'replay of 3.30.0-drop-settings-type must remain a no-op'
        );
        $replayCountRow = $db->query('SELECT COUNT(*) AS c FROM settings')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($replayCountRow);
        $this->assertSame(
            count($seedRows),
            (int) $replayCountRow['c'],
            'replay must not change row count'
        );
    }

    /**
     * Finding 3 (architecture review 2026-05-16) — the speculative `subtype`
     * and `validator` columns were dropped from the as-built schema. The
     * seeded table must carry neither on any engine.
     */
    #[DataProvider('driverProvider')]
    public function testSubtypeAndValidatorColumnsAreAbsent(string $driver): void
    {
        $db = $this->openConnection($driver);
        $migration = $this->loadMigration();
        $migration($db);

        $cols = $this->settingDefinitionColumns($db, $driver);
        $this->assertNotContains('subtype', $cols, 'subtype column must be dropped (Finding 3)');
        $this->assertNotContains('validator', $cols, 'validator column must be dropped (Finding 3)');
        // The replacement metadata columns must still be present.
        $this->assertContains('min_value', $cols);
        $this->assertContains('max_value', $cols);
    }

    /**
     * Finding 6 (architecture review 2026-05-16) — the v3.31.0 encrypt-at-rest
     * pipeline depends on `is_sensitive = 1` IFF `type = 'secret'`. Assert the
     * invariant holds across the freshly seeded table. If the seed data
     * violates it this test FAILS (a finding to report) rather than the data
     * being silently patched.
     */
    #[DataProvider('driverProvider')]
    public function testIsSensitiveIffSecretInvariant(string $driver): void
    {
        $db = $this->openConnection($driver);
        $migration = $this->loadMigration();
        $migration($db);

        $keyCol = $driver === 'mysql' ? '`key`' : ($driver === 'pgsql' ? '"key"' : 'key');
        $rows = $db->query("SELECT {$keyCol} AS k, type, is_sensitive FROM setting_definitions")
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertIsArray($rows);
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $isSecret    = ($row['type'] ?? null) === 'secret';
            $isSensitive = (int) ($row['is_sensitive'] ?? 0) === 1;
            $this->assertSame(
                $isSecret,
                $isSensitive,
                sprintf(
                    "is_sensitive must equal (type=='secret') for key '%s' (type=%s, is_sensitive=%s)",
                    (string) ($row['k'] ?? ''),
                    (string) ($row['type'] ?? ''),
                    (string) ($row['is_sensitive'] ?? '')
                )
            );
        }
    }

    /**
     * Finding 5 (architecture review 2026-05-16) — seed-fallback lifecycle.
     * With NO setting_definitions table present, ipam_setting_definitions()
     * returns exactly the frozen v3.29.0 seed key set; with the table seeded
     * it returns the table's contents. Machine-checks the "seed is frozen"
     * contract.
     */
    public function testSeedFallbackReturnsSeedKeySetWhenTableAbsent(): void
    {
        $db = $this->openConnection('sqlite');
        $db->exec('DROP TABLE IF EXISTS setting_definitions');
        ipam_setting_cache_bust();

        $defs = ipam_setting_definitions();
        $seed = ipam_setting_definitions_seed();
        $this->assertSame(
            array_keys($seed),
            array_keys($defs),
            'with the table absent, the fallback must return exactly the seed key set'
        );
        // The returned shape must already be normalised: storage_type present,
        // bare `type` absent.
        foreach ($defs as $key => $def) {
            $this->assertArrayHasKey('storage_type', $def, "{$key} must carry storage_type");
            $this->assertArrayHasKey('logical_type', $def, "{$key} must carry logical_type");
            $this->assertArrayNotHasKey('type', $def, "{$key} must not carry a bare type key");
        }
    }

    public function testDbBackedRegistryReturnsTableContentsWhenSeeded(): void
    {
        $db = $this->openConnection('sqlite');
        $migration = $this->loadMigration();
        $migration($db);
        ipam_setting_cache_bust();

        $defs = ipam_setting_definitions();
        $keyCol = 'key';
        $tableKeys = $db->query("SELECT {$keyCol} FROM setting_definitions ORDER BY ordering ASC, {$keyCol} ASC")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertIsArray($tableKeys);
        $this->assertSame(
            $tableKeys,
            array_keys($defs),
            'with the table seeded, the registry must return the table contents'
        );
    }

    /**
     * Engine-portable column-name lookup for setting_definitions.
     *
     * @return list<string>
     */
    private function settingDefinitionColumns(PDO $db, string $driver): array
    {
        if ($driver === 'sqlite') {
            $cols = $db->query('PRAGMA table_info(setting_definitions)')->fetchAll(PDO::FETCH_ASSOC);
            $out  = [];
            foreach ($cols as $col) {
                if (is_string($col['name'] ?? null)) {
                    $out[] = $col['name'];
                }
            }
            return $out;
        }
        $schemaFn = $driver === 'mysql' ? 'DATABASE()' : 'CURRENT_SCHEMA()';
        $rows = $db->query(
            "SELECT column_name FROM information_schema.columns "
            . "WHERE table_name = 'setting_definitions' AND table_schema = {$schemaFn}"
        )->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (is_string($r)) {
                $out[] = $r;
            }
        }
        return $out;
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

    /**
     * @return \Closure(PDO): void
     */
    private function loadDropMigration(): \Closure
    {
        $migs = ipam_migrations();
        $this->assertArrayHasKey('3.30.0-drop-settings-type', $migs);
        /** @var \Closure(PDO): void $closure */
        $closure = $migs['3.30.0-drop-settings-type'];
        return $closure;
    }

    /**
     * Create the pre-v3.30.0 settings table (WITH the legacy `type` column)
     * so the drop migration has something to operate on.
     */
    private function createLegacySettingsTable(PDO $db, string $driver): void
    {
        $db->query('DROP TABLE IF EXISTS settings');
        if ($driver === 'sqlite') {
            $db->exec(
                "CREATE TABLE settings ("
                . "  tenant_id  INTEGER,"
                . "  key        TEXT NOT NULL,"
                . "  value      TEXT,"
                . "  type       TEXT NOT NULL DEFAULT 'string'"
                . "             CHECK(type IN ('string','int','bool','json')),"
                . "  updated_at TEXT NOT NULL DEFAULT (datetime('now')),"
                . "  updated_by INTEGER"
                . ")"
            );
            $db->exec("CREATE UNIQUE INDEX uq_settings_global ON settings (key) WHERE tenant_id IS NULL");
            $db->exec("CREATE UNIQUE INDEX uq_settings_tenant ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL");
        } elseif ($driver === 'mysql') {
            $db->exec(
                "CREATE TABLE settings ("
                . "  tenant_id  INT NULL,"
                . "  `key`      VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,"
                . "  value      TEXT NULL,"
                . "  type       VARCHAR(16) NOT NULL DEFAULT 'string',"
                . "  updated_at DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),"
                . "  updated_by BIGINT UNSIGNED NULL,"
                . "  CONSTRAINT settings_type_check CHECK (type IN ('string','int','bool','json')),"
                . "  UNIQUE KEY uq_settings_tenant_key (tenant_id, `key`)"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );
        } else { // pgsql
            $db->exec(
                "CREATE TABLE settings ("
                . "  tenant_id  INTEGER NULL,"
                . "  \"key\"      TEXT COLLATE \"C\" NOT NULL,"
                . "  value      TEXT NULL,"
                . "  type       TEXT NOT NULL DEFAULT 'string',"
                . "  updated_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),"
                . "  updated_by BIGINT NULL,"
                . "  CONSTRAINT settings_type_check CHECK (type IN ('string','int','bool','json'))"
                . ")"
            );
            $db->exec("CREATE UNIQUE INDEX uq_settings_global ON settings (\"key\") WHERE tenant_id IS NULL");
            $db->exec("CREATE UNIQUE INDEX uq_settings_tenant ON settings (tenant_id, \"key\") WHERE tenant_id IS NOT NULL");
        }
    }

    private function settingsHasTypeColumn(PDO $db, string $driver): bool
    {
        if ($driver === 'sqlite') {
            $cols = $db->query('PRAGMA table_info(settings)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                if (($col['name'] ?? null) === 'type') {
                    return true;
                }
            }
            return false;
        }
        $schemaFn = $driver === 'mysql' ? 'DATABASE()' : 'CURRENT_SCHEMA()';
        $st = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.columns "
            . "WHERE table_name = 'settings' AND column_name = 'type' "
            . "AND table_schema = {$schemaFn}"
        );
        $st->execute();
        return (int) $st->fetchColumn() > 0;
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
