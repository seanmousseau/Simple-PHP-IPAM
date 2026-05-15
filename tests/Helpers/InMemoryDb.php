<?php
declare(strict_types=1);

namespace Tests\Helpers;

/**
 * v3.29.0 #903 — engine-portable PDO bootstrap for unit tests.
 *
 * Tests that exercise migration logic or production helpers reading
 * via $db should reach for InMemoryDb::fresh() (schema-only) or
 * InMemoryDb::withMigrations() (schema + apply_migrations()) rather
 * than re-inventing the bootstrap dance per test class.
 *
 * MySQL / PostgreSQL coverage lives in the docker-bootstrapped
 * containers (testing/playwright/bootstrap-app.sh) — in-memory is
 * sqlite-only by definition.
 */
final class InMemoryDb
{
    /**
     * Fresh PDO with the canonical schema.sql applied. No migrations
     * run; the schema is the "fresh install" baseline.
     */
    public static function fresh(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON");
        $schemaPath = __DIR__ . '/../../Simple-PHP-IPAM/schema.sql';
        $schema = @file_get_contents($schemaPath);
        if ($schema === false) {
            throw new \RuntimeException("InMemoryDb: cannot read $schemaPath");
        }
        $pdo->exec($schema);
        return $pdo;
    }

    /**
     * Fresh PDO with schema + every registered migration applied. Use
     * when the test exercises post-upgrade behaviour that needs the
     * current cumulative schema state.
     */
    public static function withMigrations(): \PDO
    {
        $pdo = self::fresh();
        require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';
        require_once __DIR__ . '/../../Simple-PHP-IPAM/migrations.php';
        \apply_migrations($pdo);
        return $pdo;
    }
}
