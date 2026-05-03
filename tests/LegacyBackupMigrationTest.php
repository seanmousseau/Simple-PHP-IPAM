<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * v3.23.0 #1058 / E5 — one-shot legacy backup config migration.
 *
 * The helper turns `backup.enabled = true` plus the three companion
 * settings (`backup.dir`, `backup.frequency`, `backup.retention`) into a
 * `backup_destinations` row of type=local and a `backup_schedules` row,
 * exactly once per install. Subsequent invocations must be no-ops so a
 * page-load hook can call it unconditionally.
 */
class LegacyBackupMigrationTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        // ipam_setting() memoises across tests in the same PHPUnit process;
        // wipe to keep per-test state isolated from prior fixtures.
        ipam_setting_cache_bust();

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($this->db);
        apply_migrations($this->db);

        // ipam_setting() reads $GLOBALS['db'] — wire it up like SettingsTest /
        // NotificationDispatcherTest do, since the helper goes through the
        // public ipam_setting() API rather than taking a $db argument.
        $GLOBALS['db']     = $this->db;
        $GLOBALS['config'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        ipam_setting_cache_bust();
    }

    public function testNoOpWhenLegacyDisabled(): void
    {
        ipam_setting_set($this->db, 'backup.enabled', false);

        ipam_legacy_backup_migrate_if_due($this->db);

        $this->assertSame(0, $this->countDestinations());
        $this->assertSame(0, $this->countSchedules());
        // Sentinel still gets stamped so subsequent calls short-circuit early.
        $this->assertTrue((bool) ipam_setting('backup.legacy_migrated_v3_23_0'));
    }

    public function testCreatesDestinationAndScheduleFromLegacyDailyConfig(): void
    {
        ipam_setting_set($this->db, 'backup.enabled', true);
        ipam_setting_set($this->db, 'backup.frequency', 'daily');
        ipam_setting_set($this->db, 'backup.retention', 5);
        ipam_setting_set($this->db, 'backup.dir', '/tmp/legacy-backups');

        ipam_legacy_backup_migrate_if_due($this->db);

        $dest = $this->fetchOne("SELECT * FROM backup_destinations LIMIT 1");
        $this->assertNotNull($dest);
        $this->assertSame('local', $dest['type']);
        $this->assertSame('Legacy local backups', $dest['name']);
        $config = json_decode((string) $dest['config'], true);
        $this->assertSame('/tmp/legacy-backups', $config['path'] ?? null);

        $sched = $this->fetchOne("SELECT * FROM backup_schedules LIMIT 1");
        $this->assertNotNull($sched);
        $this->assertSame('daily',     $sched['frequency']);
        $this->assertSame(5,           (int) $sched['retention_daily']);
        $this->assertSame(0,           (int) $sched['retention_weekly']);
        $this->assertNull($sched['day_of_week']);
        $this->assertSame(1,           (int) $sched['is_active']);

        $this->assertTrue((bool) ipam_setting('backup.legacy_migrated_v3_23_0'));
    }

    public function testWeeklyMapsToWeeklyScheduleWithSundayDow(): void
    {
        ipam_setting_set($this->db, 'backup.enabled', true);
        ipam_setting_set($this->db, 'backup.frequency', 'weekly');
        ipam_setting_set($this->db, 'backup.retention', 3);

        ipam_legacy_backup_migrate_if_due($this->db);

        $sched = $this->fetchOne("SELECT * FROM backup_schedules LIMIT 1");
        $this->assertNotNull($sched);
        $this->assertSame('weekly', $sched['frequency']);
        $this->assertSame(0,        (int) $sched['day_of_week']);  // Sunday
        $this->assertSame(3,        (int) $sched['retention_weekly']);
    }

    public function testIdempotentOnRepeatCalls(): void
    {
        ipam_setting_set($this->db, 'backup.enabled', true);
        ipam_setting_set($this->db, 'backup.frequency', 'daily');
        ipam_setting_set($this->db, 'backup.retention', 7);

        ipam_legacy_backup_migrate_if_due($this->db);
        ipam_legacy_backup_migrate_if_due($this->db);
        ipam_legacy_backup_migrate_if_due($this->db);

        $this->assertSame(1, $this->countDestinations());
        $this->assertSame(1, $this->countSchedules());
    }

    public function testLegacySettingsNotRemoved(): void
    {
        ipam_setting_set($this->db, 'backup.enabled', true);
        ipam_setting_set($this->db, 'backup.frequency', 'daily');
        ipam_setting_set($this->db, 'backup.retention', 7);
        ipam_setting_set($this->db, 'backup.dir', '/tmp/x');

        ipam_legacy_backup_migrate_if_due($this->db);

        // Legacy keys MUST stay readable for one release as a fallback,
        // per #1058 acceptance ("Do NOT touch the existing Settings keys yet").
        $this->assertTrue((bool) ipam_setting('backup.enabled'));
        $this->assertSame('daily', ipam_setting('backup.frequency'));
        $this->assertSame('/tmp/x', ipam_setting('backup.dir'));
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    private function fetchOne(string $sql): ?array
    {
        $row = $this->db->query($sql)?->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function countDestinations(): int
    {
        $r = $this->db->query("SELECT COUNT(*) FROM backup_destinations");
        return $r ? (int) $r->fetchColumn() : 0;
    }

    private function countSchedules(): int
    {
        $r = $this->db->query("SELECT COUNT(*) FROM backup_schedules");
        return $r ? (int) $r->fetchColumn() : 0;
    }
}
