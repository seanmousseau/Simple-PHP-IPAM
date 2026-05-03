<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * E3a wiring guard for the per-schedule notify-override path (#825 / F21).
 *
 * The full mail pipeline is heavy to exercise in PHPUnit (PHPMailer / mail()
 * / smtp config / multi-user picker), so following the established
 * BackupNotifyWiringTest pattern we assert the wiring at the source level:
 *
 *   - ipam_backup_run_for_destination() must thread $scheduleId into
 *     $dest['schedule_id'] so the dispatcher can resolve overrides.
 *   - ipam_backup_notify_dispatch() must consult ipam_backup_notify_resolve_pref
 *     for the two scheduled-flow events and ipam_backup_notify_resolve_recipients
 *     for the recipient list.
 *
 * If a refactor moves the resolution off these two functions, this test
 * fails and forces the author to confirm overrides still work.
 */
final class NotifyOverrideDispatcherWiringTest extends TestCase
{
    private function functionSource(string $fnName): string
    {
        $rfn = new ReflectionFunction($fnName);
        $file = $rfn->getFileName();
        $start = $rfn->getStartLine();
        $end = $rfn->getEndLine();
        $this->assertNotFalse($file);
        $contents = (string) file_get_contents((string) $file);
        $lines = explode("\n", $contents);
        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    public function testOrchestratorThreadsScheduleIdIntoDest(): void
    {
        $src = $this->functionSource('ipam_backup_run_for_destination');
        $this->assertStringContainsString(
            "\$dest['schedule_id']",
            $src,
            'orchestrator must thread schedule_id into $dest so dispatcher can resolve overrides'
        );
        $this->assertStringContainsString(
            '$scheduleId',
            $src,
            'schedule_id thread must come from the $scheduleId parameter'
        );
    }

    public function testDispatcherCallsResolverForScheduledEvents(): void
    {
        $src = $this->functionSource('ipam_backup_notify_dispatch');
        $this->assertStringContainsString(
            'ipam_backup_notify_resolve_pref',
            $src,
            'dispatcher must call resolve_pref for failure_scheduled / success_scheduled events'
        );
        $this->assertStringContainsString(
            "'notify_on_failure'",
            $src,
            'dispatcher must resolve notify_on_failure for failure_scheduled'
        );
        $this->assertStringContainsString(
            "'notify_on_success'",
            $src,
            'dispatcher must resolve notify_on_success for success_scheduled'
        );
    }

    public function testDispatcherCallsRecipientResolver(): void
    {
        $src = $this->functionSource('ipam_backup_notify_dispatch');
        $this->assertStringContainsString(
            'ipam_backup_notify_resolve_recipients',
            $src,
            'dispatcher must call resolve_recipients to honour per-schedule recipient overrides'
        );
    }
}
