<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';

/**
 * Per-schedule notification override resolution (#825 / F21 / E2).
 *
 * Pins the contract used by the dispatcher in E3:
 *
 *   ipam_backup_notify_resolve_pref()
 *   ipam_backup_notify_resolve_recipients()
 *
 * Both must:
 *   - Return the global default when notify_override = 0 or schedule_id is null.
 *   - Return the schedule's column when notify_override = 1 and the column
 *     is set; fall back to global if the override row leaves it NULL.
 *   - Treat a missing schedule (deleted before notify) as "use global" rather
 *     than throwing — notifications are best-effort.
 */
class NotifyOverrideResolverTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec(
            "INSERT INTO backup_destinations (name, type, config, is_active)
             VALUES ('local', 'local', '{}', 1)"
        );
    }

    // -----------------------------------------------------------------------
    // resolve_pref
    // -----------------------------------------------------------------------

    public function testResolvePrefReturnsGlobalWhenScheduleIdNull(): void
    {
        $this->assertTrue(ipam_backup_notify_resolve_pref($this->db, null, 'notify_on_failure', true));
        $this->assertFalse(ipam_backup_notify_resolve_pref($this->db, null, 'notify_on_failure', false));
    }

    public function testResolvePrefReturnsGlobalWhenOverrideOff(): void
    {
        $sid = $this->mkSchedule(['notify_override' => 0, 'notify_on_failure' => 1]);
        // override off — column value is irrelevant
        $this->assertFalse(ipam_backup_notify_resolve_pref($this->db, $sid, 'notify_on_failure', false));
        $this->assertTrue(ipam_backup_notify_resolve_pref($this->db, $sid, 'notify_on_failure', true));
    }

    public function testResolvePrefReturnsScheduleValueWhenOverrideOn(): void
    {
        $sid = $this->mkSchedule(['notify_override' => 1, 'notify_on_failure' => 1, 'notify_on_success' => 0]);
        $this->assertTrue(ipam_backup_notify_resolve_pref($this->db, $sid, 'notify_on_failure', false));
        $this->assertFalse(ipam_backup_notify_resolve_pref($this->db, $sid, 'notify_on_success', true));
    }

    public function testResolvePrefFallsBackToGlobalWhenOverrideRowLeavesColumnNull(): void
    {
        // Override is on but notify_on_success is NULL — admin overrode failure
        // only, leaving success to inherit. Global default must win.
        $sid = $this->mkSchedule(['notify_override' => 1, 'notify_on_failure' => 1, 'notify_on_success' => null]);
        $this->assertTrue(ipam_backup_notify_resolve_pref($this->db, $sid, 'notify_on_success', true));
        $this->assertFalse(ipam_backup_notify_resolve_pref($this->db, $sid, 'notify_on_success', false));
    }

    public function testResolvePrefReturnsGlobalWhenScheduleMissing(): void
    {
        $this->assertTrue(ipam_backup_notify_resolve_pref($this->db, 99999, 'notify_on_failure', true));
    }

    public function testResolvePrefRejectsUnsupportedColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipam_backup_notify_resolve_pref($this->db, 1, 'notify_recipients', false);
    }

    // -----------------------------------------------------------------------
    // resolve_recipients
    // -----------------------------------------------------------------------

    public function testResolveRecipientsReturnsGlobalWhenScheduleIdNull(): void
    {
        $this->assertSame(['ops@example.com'],
            ipam_backup_notify_resolve_recipients($this->db, null, ['ops@example.com']));
    }

    public function testResolveRecipientsReturnsGlobalWhenOverrideOff(): void
    {
        $sid = $this->mkSchedule(['notify_override' => 0, 'notify_recipients' => 'sched@example.com']);
        $this->assertSame(['ops@example.com'],
            ipam_backup_notify_resolve_recipients($this->db, $sid, ['ops@example.com']));
    }

    public function testResolveRecipientsParsesCsvWhenOverrideOn(): void
    {
        $sid = $this->mkSchedule([
            'notify_override' => 1,
            'notify_recipients' => 'a@example.com, b@example.com,c@example.com',
        ]);
        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            ipam_backup_notify_resolve_recipients($this->db, $sid, ['ops@example.com'])
        );
    }

    public function testResolveRecipientsFallsBackWhenOverrideRowHasNullRecipients(): void
    {
        // Override but recipients NULL — admin wanted to override the booleans
        // only, keep recipient list global.
        $sid = $this->mkSchedule(['notify_override' => 1, 'notify_recipients' => null]);
        $this->assertSame(['ops@example.com'],
            ipam_backup_notify_resolve_recipients($this->db, $sid, ['ops@example.com']));
    }

    public function testResolveRecipientsFallsBackWhenOverrideRowHasEmptyCsv(): void
    {
        $sid = $this->mkSchedule(['notify_override' => 1, 'notify_recipients' => '   ']);
        $this->assertSame(['ops@example.com'],
            ipam_backup_notify_resolve_recipients($this->db, $sid, ['ops@example.com']));
    }

    // -----------------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string,int|string|null> $overrides
     */
    private function mkSchedule(array $overrides): int
    {
        $cols = array_merge([
            'destination_id'    => 1,
            'frequency'         => 'daily',
            'time_of_day'       => '02:00',
            'notify_override'   => 0,
            'notify_on_failure' => null,
            'notify_on_success' => null,
            'notify_recipients' => null,
        ], $overrides);

        $stmt = $this->db->prepare(
            "INSERT INTO backup_schedules
                (destination_id, frequency, time_of_day,
                 notify_override, notify_on_failure, notify_on_success, notify_recipients)
             VALUES (:dest, :freq, :tod, :ov, :nf, :ns, :nr)"
        );
        $stmt->execute([
            ':dest' => $cols['destination_id'],
            ':freq' => $cols['frequency'],
            ':tod'  => $cols['time_of_day'],
            ':ov'   => $cols['notify_override'],
            ':nf'   => $cols['notify_on_failure'],
            ':ns'   => $cols['notify_on_success'],
            ':nr'   => $cols['notify_recipients'],
        ]);
        return (int) $this->db->lastInsertId();
    }
}
