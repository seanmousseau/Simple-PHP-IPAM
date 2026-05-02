<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests for ipam_backup_detect_overdue_schedules() — the schedule-overdue
 * detector extracted from cron.php Task 6d (originally inline in commit
 * 5a26a95). Lives in Simple-PHP-IPAM/lib/backup.php.
 *
 * The detector reads:
 *   - backup.notify_schedule_overdue       (bool, gates email dispatch)
 *   - backup.notify_overdue_grace_minutes  (int, default 60, floor 5)
 *   - backup.schedule_overdue_state        (json map keyed by schedule_id)
 *
 * For every active (schedule + destination) pair whose next_run_at predates
 * (now − grace_minutes), the detector:
 *   1. Writes a `backup.schedule_overdue` audit row (always, regardless of
 *      the notify toggle — the toggle gates email dispatch only).
 *   2. Updates schedule_overdue_state[schedule_id] = {alerted_for: next_run_at,
 *      last_alerted_at: now}.
 *   3. (When toggle ON) calls ipam_backup_notify('schedule_overdue', ...).
 *
 * Cooldown is keyed by next_run_at — once a schedule has been alerted for a
 * given expected_at, no re-alert until next_run_at advances (i.e., the
 * schedule fires successfully and the cron task moves it forward).
 *
 * Schema strategy: hand-written CREATE TABLE for the four tables the
 * detector touches (settings, audit_log, backup_schedules,
 * backup_destinations) plus a stub `users` for ipam_resolve_alert_recipients
 * (called transitively via ipam_backup_notify on the notify-on path). No
 * full migration replay; same approach as BackupReaperTest.
 */
class OverdueDetectorTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->db->exec("
            CREATE TABLE settings (
                tenant_id  INTEGER,
                key        TEXT NOT NULL,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string' CHECK(type IN ('string','int','bool','json')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER
            )
        ");
        $this->db->exec("CREATE UNIQUE INDEX uq_settings_global ON settings (key) WHERE tenant_id IS NULL");
        $this->db->exec("CREATE UNIQUE INDEX uq_settings_tenant ON settings (tenant_id, key) WHERE tenant_id IS NOT NULL");

        $this->db->exec("
            CREATE TABLE audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                user_id     INTEGER,
                username    TEXT,
                action      TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id   INTEGER,
                ip          TEXT,
                user_agent  TEXT,
                details     TEXT
            )
        ");

        $this->db->exec("
            CREATE TABLE backup_destinations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL,
                type       TEXT    NOT NULL,
                config     TEXT    NOT NULL DEFAULT '{}',
                encrypt    INTEGER NOT NULL DEFAULT 1,
                is_active  INTEGER NOT NULL DEFAULT 1,
                created_at TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("
            CREATE TABLE backup_schedules (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                destination_id  INTEGER NOT NULL,
                cron_expr       TEXT    NOT NULL DEFAULT '0 2 * * *',
                is_active       INTEGER NOT NULL DEFAULT 1,
                next_run_at     TEXT,
                last_run_at     TEXT,
                last_status     TEXT,
                created_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at      TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ipam_resolve_alert_recipients() (called via the notify path) reads
        // from this table. We never seed it in these tests, so resolution
        // returns [] and the dispatcher returns at the empty-recipients
        // guard — keeping these tests focused on the detector, not the
        // mail send path.
        $this->db->exec("
            CREATE TABLE users (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                username  TEXT NOT NULL,
                email     TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                role      TEXT NOT NULL DEFAULT 'admin'
            )
        ");

        $GLOBALS['db']     = $this->db;
        $GLOBALS['config'] = [];
        $_SESSION          = [];

        ipam_setting_cache_bust();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        ipam_setting_cache_storage('__CLEAR__', true);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function q(string $sql): PDOStatement
    {
        $stmt = $this->db->query($sql);
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        return $stmt;
    }

    private function seedDestination(string $name = 'd1', int $isActive = 1): int
    {
        $st = $this->db->prepare(
            "INSERT INTO backup_destinations (name, type, is_active)
             VALUES (:n, 'local', :a)"
        );
        $st->execute([':n' => $name, ':a' => $isActive]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a schedule whose next_run_at is `$minutesAgo` minutes before
     * the test's reference $nowTs. Pass $nowTs through so callers can
     * align expectations precisely.
     */
    private function seedSchedule(int $destId, int $minutesAgoOrFromNow, int $nowTs, int $isActive = 1): int
    {
        $nextTs = $nowTs - ($minutesAgoOrFromNow * 60);
        $nextStr = gmdate('Y-m-d H:i:s', $nextTs);
        $st = $this->db->prepare(
            "INSERT INTO backup_schedules (destination_id, is_active, next_run_at)
             VALUES (:d, :a, :n)"
        );
        $st->execute([':d' => $destId, ':a' => $isActive, ':n' => $nextStr]);
        return (int) $this->db->lastInsertId();
    }

    private function overdueAuditCount(): int
    {
        return (int) $this->q(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'backup.schedule_overdue'"
        )->fetchColumn();
    }

    private function setOverdueGrace(int $minutes): void
    {
        ipam_setting_set($this->db, 'backup.notify_overdue_grace_minutes', $minutes);
    }

    /**
     * Advance the stored next_run_at of a schedule to simulate it firing
     * successfully (cron Task 7 would normally do this).
     */
    private function advanceNextRun(int $scheduleId, int $newTs): void
    {
        $st = $this->db->prepare(
            "UPDATE backup_schedules SET next_run_at = :n WHERE id = :id"
        );
        $st->execute([':n' => gmdate('Y-m-d H:i:s', $newTs), ':id' => $scheduleId]);
    }

    // ---------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------

    /**
     * next_run_at = 30 min ago, grace = 60 → still inside the grace window,
     * detector must not flag the schedule and must not write any audit.
     */
    public function testScheduleWithinGraceIsNotOverdue(): void
    {
        $now = 1_750_000_000; // arbitrary fixed reference
        $this->setOverdueGrace(60);
        $destId = $this->seedDestination();
        $this->seedSchedule($destId, 30, $now);

        $result = ipam_backup_detect_overdue_schedules($this->db, $now);

        $this->assertSame(0, $result['overdue']);
        $this->assertSame([], $result['alerted']);
        $this->assertSame(0, $this->overdueAuditCount());
    }

    /**
     * next_run_at = 90 min ago, grace = 60 → past the grace window, must
     * audit + record cooldown state.
     */
    public function testScheduleBeyondGraceIsOverdue(): void
    {
        $now = 1_750_000_000;
        $this->setOverdueGrace(60);
        $destId = $this->seedDestination();
        $schedId = $this->seedSchedule($destId, 90, $now);

        $result = ipam_backup_detect_overdue_schedules($this->db, $now);

        $this->assertSame(1, $result['overdue']);
        $this->assertSame([$schedId], $result['alerted']);
        $this->assertSame(1, $this->overdueAuditCount());

        $audit = $this->q(
            "SELECT entity_type, entity_id, details FROM audit_log
              WHERE action = 'backup.schedule_overdue' LIMIT 1"
        )->fetch();
        $this->assertIsArray($audit);
        $this->assertSame('schedule', $audit['entity_type']);
        $this->assertSame($schedId, to_int($audit['entity_id']));
        $this->assertIsString($audit['details']);
        $this->assertStringContainsString('overdue_minutes=', $audit['details']);

        // Cooldown state recorded under the schedule id.
        $stateRaw = to_str(ipam_setting('backup.schedule_overdue_state', '{}'));
        $state = json_decode($stateRaw, true);
        $this->assertIsArray($state);
        $this->assertArrayHasKey((string) $schedId, $state);
        $this->assertIsArray($state[(string) $schedId]);
        $this->assertArrayHasKey('alerted_for', $state[(string) $schedId]);
    }

    /**
     * Calling the detector twice for the same overdue schedule whose
     * next_run_at has not advanced must NOT double-alert.
     */
    public function testCooldownPreventsRepeatAlertOnSameMissedFiring(): void
    {
        $now = 1_750_000_000;
        $this->setOverdueGrace(60);
        $destId = $this->seedDestination();
        $schedId = $this->seedSchedule($destId, 90, $now);

        $first = ipam_backup_detect_overdue_schedules($this->db, $now);
        $this->assertSame([$schedId], $first['alerted']);
        $this->assertSame(1, $this->overdueAuditCount());

        // Second call, same wall clock, same next_run_at → cooldown holds.
        $second = ipam_backup_detect_overdue_schedules($this->db, $now + 60);
        $this->assertSame(1, $second['overdue'], 'still detected as overdue');
        $this->assertSame([], $second['alerted'], 'but cooldown suppresses re-alert');
        $this->assertSame(1, $this->overdueAuditCount(), 'no new audit entry');
    }

    /**
     * After a schedule "fires" (next_run_at advances), a subsequent miss
     * must alert again — cooldown is per-firing, not per-schedule.
     */
    public function testNewMissedFiringTriggersAnotherAlert(): void
    {
        $now = 1_750_000_000;
        $this->setOverdueGrace(60);
        $destId = $this->seedDestination();
        $schedId = $this->seedSchedule($destId, 90, $now);

        // First miss → alert.
        ipam_backup_detect_overdue_schedules($this->db, $now);
        $this->assertSame(1, $this->overdueAuditCount());

        // Schedule fires successfully — next_run_at moves forward to
        // (now + 1d) — i.e. 24h after our first $now anchor. Then we
        // simulate "a day later, the schedule missed again" by passing
        // now2 = now + 24h + 90min (90min past the new next_run_at).
        $newNextTs = $now + 86_400;
        $this->advanceNextRun($schedId, $newNextTs);
        $now2 = $newNextTs + (90 * 60);

        $result = ipam_backup_detect_overdue_schedules($this->db, $now2);

        $this->assertSame([$schedId], $result['alerted']);
        $this->assertSame(2, $this->overdueAuditCount(), 'second miss writes a fresh audit');
    }

    /**
     * is_active = 0 → schedule is skipped entirely. is_active = 0 on the
     * destination also skips the row (covered by the JOIN's WHERE clause).
     */
    public function testInactiveScheduleNotChecked(): void
    {
        $now = 1_750_000_000;
        $this->setOverdueGrace(60);

        $destId = $this->seedDestination();
        $this->seedSchedule($destId, 90, $now, isActive: 0); // schedule inactive
        $destId2 = $this->seedDestination('d2', isActive: 0);
        $this->seedSchedule($destId2, 90, $now, isActive: 1); // dest inactive

        $result = ipam_backup_detect_overdue_schedules($this->db, $now);
        $this->assertSame(0, $result['overdue']);
        $this->assertSame(0, $this->overdueAuditCount());
    }

    /**
     * The notify toggle gates EMAIL DISPATCH only — the detector still
     * audits and records cooldown state when the toggle is OFF. This is
     * the contract the cron implementation in 5a26a95 wires up: the
     * audit entry is the operator-visible canary, the email is the
     * operator-receivable canary, and they're independently controlled.
     */
    public function testNotificationOffStillAuditsButDoesNotEmail(): void
    {
        $now = 1_750_000_000;
        $this->setOverdueGrace(60);
        ipam_setting_set($this->db, 'backup.notify_schedule_overdue', false);

        $destId = $this->seedDestination();
        $schedId = $this->seedSchedule($destId, 90, $now);

        $result = ipam_backup_detect_overdue_schedules($this->db, $now);

        $this->assertSame(1, $result['overdue']);
        $this->assertSame([$schedId], $result['alerted']);
        $this->assertSame(
            1,
            $this->overdueAuditCount(),
            'audit fires regardless of notify toggle (operator visibility)'
        );

        // No users seeded → ipam_resolve_alert_recipients() = [] → mail
        // path is a no-op even when the toggle would otherwise be ON.
        // The contract here: detector executes its full state-machine
        // (audit + cooldown) without depending on the email path.
        $stateRawMixed = ipam_setting('backup.schedule_overdue_state', '{}');
        $stateRaw = is_string($stateRawMixed) ? $stateRawMixed : '{}';
        $state = json_decode($stateRaw, true);
        $this->assertIsArray($state);
        $this->assertArrayHasKey((string) $schedId, $state);
    }
}
