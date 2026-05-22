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
        // Token-level assertion: the actual $scheduleId variable must be
        // assigned into $dest['schedule_id'] — substring presence isn't
        // sufficient since either token could appear in dead/manual-only
        // branches without ever wiring up. Regex tolerates whitespace and
        // double-quoted vs single-quoted key.
        $this->assertMatchesRegularExpression(
            '/\$dest\[[\'"]schedule_id[\'"]\]\s*=\s*\$scheduleId\b/',
            $src,
            'orchestrator must assign $scheduleId into $dest[\'schedule_id\']'
        );
    }

    public function testDispatcherCallsResolverForScheduledEvents(): void
    {
        $src = $this->functionSource('ipam_backup_notify_dispatch');
        // Pin the actual call shape: resolver name + the boolean column name
        // as a literal string argument. Catches reordered args / partial
        // wires that a substring check would miss.
        $this->assertMatchesRegularExpression(
            '/ipam_backup_notify_resolve_pref\s*\([^)]*[\'"]notify_on_failure[\'"]/',
            $src,
            'dispatcher must invoke resolve_pref with the notify_on_failure column for failure_scheduled'
        );
        $this->assertMatchesRegularExpression(
            '/ipam_backup_notify_resolve_pref\s*\([^)]*[\'"]notify_on_success[\'"]/',
            $src,
            'dispatcher must invoke resolve_pref with the notify_on_success column for success_scheduled'
        );
    }

    public function testDispatcherCallsRecipientResolver(): void
    {
        $src = $this->functionSource('ipam_backup_notify_dispatch');
        // Recipient resolver must take both $scheduleId and the prior $recipients
        // result (or its global fallback). Pin the call shape rather than a
        // bare substring.
        $this->assertMatchesRegularExpression(
            '/ipam_backup_notify_resolve_recipients\s*\([^)]*\$scheduleId[^)]*\$recipients\s*\)/',
            $src,
            'dispatcher must invoke resolve_recipients with both $scheduleId and the global $recipients list'
        );
    }
}
