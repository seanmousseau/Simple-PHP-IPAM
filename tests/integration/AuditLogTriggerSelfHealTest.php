<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * audit_log trigger self-heal regression test (PR #1095 CR round 4).
 *
 * The probe-only fast path in ensure_audit_log_table() must restore
 * append-only triggers if they're externally dropped, otherwise
 * audit-row immutability silently weakens. SQLite is the cheapest
 * driver to exercise in PHPUnit (no docker network) and the trigger
 * presence-probe SQL is the same shape on every engine.
 */
class AuditLogTriggerSelfHealTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = sys_get_temp_dir() . '/ipam_audit_heal_' . bin2hex(random_bytes(8)) . '.sqlite';
        $db = new PDO('sqlite:' . $this->tmpFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");
        // Minimum schema_migrations row so apply_migrations is a no-op
        // for everything the dialect helpers do not need.
        $db->exec("CREATE TABLE schema_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, version TEXT, applied_at TEXT)");
        // Bootstrap audit_log + triggers via the public helper, which
        // is the contract this test guards.
        ensure_audit_log_table($db);
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && is_file($this->tmpFile)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- random tempfile path
            @unlink($this->tmpFile);
        }
        $this->tmpFile = '';
    }

    private function openDb(): PDO
    {
        $db = new PDO('sqlite:' . $this->tmpFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    public function testProbeReportsBothTriggersPresent(): void
    {
        $db = $this->openDb();
        $this->assertTrue(ipam_audit_log_triggers_present($db));
    }

    public function testProbeReportsMissingWhenOneDropped(): void
    {
        $db = $this->openDb();
        $db->exec('DROP TRIGGER IF EXISTS audit_log_no_update');
        $this->assertFalse(ipam_audit_log_triggers_present($db));
    }

    public function testFastPathRestoresMissingTriggers(): void
    {
        $db = $this->openDb();
        // Externally drop both triggers — table stays present.
        $db->exec('DROP TRIGGER IF EXISTS audit_log_no_update');
        $db->exec('DROP TRIGGER IF EXISTS audit_log_no_delete');
        $this->assertFalse(ipam_audit_log_triggers_present($db));

        // ensure_audit_log_table()'s fast path should self-heal the
        // triggers without going through the full CREATE TABLE path.
        ensure_audit_log_table($db);
        $this->assertTrue(ipam_audit_log_triggers_present($db));
    }
}
