<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Helpers\InMemoryDb;

final class InMemoryDbTest extends TestCase
{
    public function testFreshOpensSqliteInMemory(): void
    {
        $pdo = InMemoryDb::fresh();
        $this->assertSame('sqlite', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testFreshHasCoreTables(): void
    {
        $pdo = InMemoryDb::fresh();
        foreach (['users', 'subnets', 'addresses', 'audit_log', 'schema_migrations'] as $table) {
            $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :n");
            $st->execute([':n' => $table]);
            $this->assertSame($table, $st->fetchColumn(), "expected core table $table to exist in fresh()");
        }
    }

    public function testWithMigrationsAppliesEveryRegisteredMigration(): void
    {
        require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';
        require_once __DIR__ . '/../../Simple-PHP-IPAM/migrations.php';
        $pdo = InMemoryDb::withMigrations();
        $applied = (int) $pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
        $this->assertSame(
            \ipam_migrations_count(),
            $applied,
            'schema_migrations row count must equal ipam_migrations_count() (#1102) after withMigrations()'
        );
    }
}
