<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 2 Task 4.1 — verifies the DB-layer extraction from lib.php
 * landed cleanly. The functions must (a) still exist in the global namespace
 * and (b) be declared in Simple-PHP-IPAM/lib/db.php rather than lib.php
 * (proves the move was a real move, not a copy).
 *
 * apply_migrations() is invariant #2 from CLAUDE.md (FK-OFF bracket on every
 * migration). The reflection check below proves it relocated; the existing
 * MigrationTest covers behavioural parity.
 *
 * ipam_table_exists() is the #921 (C8) fresh helper — the behavioural check
 * below exercises its SQLite branch against an in-memory handle. The mysql
 * and pgsql branches are covered by the 3-driver smoke in release-prep
 * Phase 12.
 */
final class DbExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function dbFunctions(): array
    {
        return [
            'ipam_dialect',
            'ipam_dialect_from_config',
            'ipam_sql_dump_supported',
            'ipam_last_insert_id',
            'ipam_key_col',
            'ipam_table_exists',
            'ipam_db',
            'ensure_audit_log_table',
            'ensure_audit_log_triggers',
            'ipam_audit_log_triggers_present',
            'ipam_db_init_bootstrap_admin',
            'ipam_db_init',
            'ensure_migrations_table',
            'applied_migrations',
            'apply_migrations',
            'ipam_migrations_count',
            'ipam_db_dump_stream',
            'ipam_db_dump',
        ];
    }

    public function testDbFunctionsAreDefined(): void
    {
        foreach ($this->dbFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testDbFunctionsLiveInDbFile(): void
    {
        foreach ($this->dbFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/db.php',
                (string)$declarer,
                "$fn should be declared in lib/db.php, not " . (string)$declarer
            );
        }
    }

    public function testIpamTableExistsReturnsBool(): void
    {
        $db = new \PDO('sqlite::memory:');
        $this->assertFalse(\ipam_table_exists($db, 'nonexistent_table'));

        $db->exec('CREATE TABLE foo (id INTEGER PRIMARY KEY)');
        $this->assertTrue(\ipam_table_exists($db, 'foo'));
    }
}
