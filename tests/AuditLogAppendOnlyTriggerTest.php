<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Helpers\InMemoryDb;

/**
 * v3.29.0 #890 — audit_log append-only trigger enforcement.
 *
 * Pins the SQLite-side BEFORE UPDATE / BEFORE DELETE triggers
 * (audit_log_no_update, audit_log_no_delete) that make audit_log
 * tamper-evident at the storage layer. Schema definition is in
 * Simple-PHP-IPAM/schema.sql; equivalent triggers are emitted by
 * MysqlDialect::append_only_trigger() and PgsqlDialect::append_only_trigger()
 * for the other engines. SQLite is exercised here because it is the
 * cheapest driver to bootstrap in PHPUnit (no docker network).
 *
 * Engine-portable equivalents (not exercised in-memory):
 *   - MySQL    — BEFORE UPDATE/DELETE trigger raises SIGNAL SQLSTATE '45000'.
 *   - Postgres — BEFORE UPDATE/DELETE trigger function calls RAISE EXCEPTION.
 *
 * The legitimate trigger-bypass paths (demo_reset_db() via rename+drop,
 * and the SET @ipam_bypass_append_only / SET LOCAL ipam.bypass_append_only
 * session flags consulted by the MySQL/Postgres trigger bodies) are NOT
 * the contract pinned here. This test pins the trigger CONSTRAINT — a
 * plain UPDATE or DELETE against audit_log must throw.
 */
final class AuditLogAppendOnlyTriggerTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = InMemoryDb::withMigrations();
        $this->db->exec(
            "INSERT INTO audit_log (action, entity_type, entity_id, username) "
            . "VALUES ('seed', 'test', 1, 'tester')"
        );
    }

    public function testInsertSucceeds(): void
    {
        $this->db->exec(
            "INSERT INTO audit_log (action, entity_type, entity_id, username) "
            . "VALUES ('insert', 'test', 2, 'tester')"
        );
        $count = (int)$this->db->query("SELECT COUNT(*) AS c FROM audit_log")->fetch()['c'];
        $this->assertSame(2, $count, 'INSERT must succeed; append-only does not block adds.');
    }

    public function testUpdateIsBlockedByTrigger(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/i');
        $this->db->exec("UPDATE audit_log SET action = 'mutated' WHERE id = 1");
    }

    public function testDeleteByIdIsBlockedByTrigger(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/i');
        $this->db->exec("DELETE FROM audit_log WHERE id = 1");
    }

    /**
     * Belt-and-braces: DELETE without WHERE is the SQLite analogue of
     * TRUNCATE (SQLite has no TRUNCATE statement). The BEFORE DELETE
     * trigger fires per-row, so this is blocked the same way the
     * single-row delete is.
     */
    public function testDeleteWithoutWhereIsBlockedByTrigger(): void
    {
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/append-only/i');
        $this->db->exec("DELETE FROM audit_log");
    }

    public function testRowSurvivesBlockedMutation(): void
    {
        try {
            $this->db->exec("UPDATE audit_log SET action = 'mutated' WHERE id = 1");
            $this->fail('UPDATE should have been blocked.');
        } catch (\PDOException) {
            // Expected — trigger raised ABORT.
        }
        $row = $this->db->query("SELECT action FROM audit_log WHERE id = 1")->fetch();
        $this->assertIsArray($row);
        $this->assertSame('seed', $row['action'], 'Original row must be untouched after blocked UPDATE.');
    }
}
