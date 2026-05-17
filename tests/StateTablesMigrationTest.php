<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * v3.28.0 #1143 / #1159 — the `3.28.0-state-tables` migration closure.
 *
 * Exercises the closure directly against a minimal in-memory schema:
 *   - creates rate_limit_dampener (PRIMARY KEY (action, ip)) and
 *     backup_state (PRIMARY KEY (scope, k)) when absent;
 *   - is a no-op when the tables already exist (fresh-install replay);
 *   - backfills backup_state from the legacy backup.destination_health /
 *     backup.schedule_overdue_state JSON settings, once.
 */
final class StateTablesMigrationTest extends TestCase
{
    private PDO $db;

    /** @var callable(PDO): void */
    private $migration;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $kc = ipam_key_col();
        $this->db->exec(
            "CREATE TABLE settings ("
            . "tenant_id INTEGER, {$kc} TEXT NOT NULL, value TEXT, "
            . "type TEXT NOT NULL DEFAULT 'string', "
            . "updated_at TEXT NOT NULL DEFAULT (datetime('now')), updated_by INTEGER)"
        );
        $this->db->exec("CREATE UNIQUE INDEX uq_settings_global ON settings ({$kc}) WHERE tenant_id IS NULL");
        $this->db->exec(
            "CREATE TABLE schema_migrations ("
            . "id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT NOT NULL, "
            . "applied_at TEXT NOT NULL DEFAULT (datetime('now')))"
        );

        $GLOBALS['db']     = $this->db;
        $GLOBALS['config'] = [];
        ipam_setting_cache_bust();

        $migs = ipam_migrations();
        $this->assertArrayHasKey('3.28.0-state-tables', $migs);
        $this->migration = $migs['3.28.0-state-tables'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        ipam_setting_cache_bust();
    }

    private function tableExists(string $name): bool
    {
        $st = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :n");
        $st->execute([':n' => $name]);
        return (bool) $st->fetchColumn();
    }

    private function seedSetting(string $key, mixed $value): void
    {
        $kc = ipam_key_col();
        $st = $this->db->prepare("INSERT INTO settings (tenant_id, {$kc}, value) VALUES (NULL, :k, :v)");
        $st->execute([':k' => $key, ':v' => is_string($value) ? $value : (string) json_encode($value)]);
        ipam_setting_cache_bust();
    }

    public function testCreatesTablesWhenAbsent(): void
    {
        $this->assertFalse($this->tableExists('rate_limit_dampener'));
        $this->assertFalse($this->tableExists('backup_state'));

        ($this->migration)($this->db);

        $this->assertTrue($this->tableExists('rate_limit_dampener'));
        $this->assertTrue($this->tableExists('backup_state'));

        // PRIMARY KEY (action, ip) enforced.
        $this->db->exec("INSERT INTO rate_limit_dampener (action, ip, unlock_at) VALUES ('login', '203.0.113.5', 999)");
        $this->expectException(\PDOException::class);
        $this->db->exec("INSERT INTO rate_limit_dampener (action, ip, unlock_at) VALUES ('login', '203.0.113.5', 1000)");
    }

    public function testNoOpWhenTablesAlreadyExist(): void
    {
        $this->db->exec("CREATE TABLE rate_limit_dampener (action TEXT NOT NULL, ip TEXT NOT NULL, unlock_at INTEGER NOT NULL, PRIMARY KEY (action, ip))");
        $this->db->exec("CREATE TABLE backup_state (scope TEXT NOT NULL, k TEXT NOT NULL, payload_json TEXT NOT NULL DEFAULT '{}', updated_at TEXT NOT NULL DEFAULT (datetime('now')), PRIMARY KEY (scope, k))");
        $this->db->exec("INSERT INTO backup_state (scope, k, payload_json) VALUES ('destination_health', '7', '{\"status\":\"ok\"}')");

        ($this->migration)($this->db);

        // Existing row untouched, no duplicate creation error.
        $rows = ipam_backup_state_get_all($this->db, 'destination_health');
        $this->assertSame(['7' => ['status' => 'ok']], $rows);
    }

    public function testBackfillsBackupStateFromLegacySettings(): void
    {
        $this->seedSetting('backup.destination_health', ['7' => ['status' => 'failing', 'last_failed_at' => '2026-05-01T00:00:00Z']]);
        $this->seedSetting('backup.schedule_overdue_state', ['3' => ['alerted_for' => '2026-05-01 02:00:00']]);

        ($this->migration)($this->db);

        $health = ipam_backup_state_get_all($this->db, 'destination_health');
        $this->assertArrayHasKey('7', $health);
        $this->assertSame('failing', $health['7']['status'] ?? null);

        $overdue = ipam_backup_state_get_all($this->db, 'schedule_overdue');
        $this->assertArrayHasKey('3', $overdue);
        $this->assertSame('2026-05-01 02:00:00', $overdue['3']['alerted_for'] ?? null);

        // Idempotent: a second run does not double-insert.
        ($this->migration)($this->db);
        $this->assertCount(1, ipam_backup_state_get_all($this->db, 'destination_health'));
        $this->assertCount(1, ipam_backup_state_get_all($this->db, 'schedule_overdue'));
    }
}
