<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #791 — ipam_backup_notify() was wired up but had zero
 * callers in v3.19.x. Since notify itself depends on settings + recipients +
 * the mail() / PHPMailer stack (heavy to mock cleanly in PHPUnit), this test
 * validates the wiring at the source level: both orchestrators must call
 * ipam_backup_notify() on success and failure paths.
 *
 * If a future refactor inlines or renames the dispatch, this test fails and
 * forces the author to confirm the new path still notifies.
 */
final class BackupNotifyWiringTest extends TestCase
{
    private function functionSource(string $fnName): string
    {
        $rfn = new ReflectionFunction($fnName);
        $file = $rfn->getFileName();
        $start = $rfn->getStartLine();
        $end = $rfn->getEndLine();
        $this->assertNotFalse($file, "could not locate file for {$fnName}");
        $this->assertNotFalse($start, "could not locate start line for {$fnName}");
        $this->assertNotFalse($end, "could not locate end line for {$fnName}");
        $contents = file_get_contents((string) $file);
        $this->assertNotFalse($contents, "could not read source for {$fnName}");
        $lines = explode("\n", $contents);
        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }

    public function testIpamBackupRunForDestinationCallsNotifyOnFailureAndSuccess(): void
    {
        $body = $this->functionSource('ipam_backup_run_for_destination');
        $this->assertStringContainsString(
            "ipam_backup_notify(\$db, \$dest, 'failure'",
            $body,
            'failure-path notify dispatch missing in ipam_backup_run_for_destination'
        );
        $this->assertStringContainsString(
            "ipam_backup_notify(\$db, \$dest, 'success'",
            $body,
            'success-path notify dispatch missing in ipam_backup_run_for_destination'
        );
    }

    // v3.26.0 (#1059): the legacy v3.7 run_db_backup_if_due() runner was
    // retired. Notify wiring on the unified surface remains covered by
    // testIpamBackupRunForDestinationCallsNotifyOnFailureAndSuccess() above.

    public function testNotifyDispatchDoesNotPropagateExceptions(): void
    {
        // Dispatch sites must wrap notify in a try/catch so a notification
        // failure (bad mail config, transient SMTP error, etc.) does not
        // mask the real outcome of the backup or surface to operators as a
        // "backup failed" when in fact the backup succeeded but the email
        // bounced.
        $destBody = $this->functionSource('ipam_backup_run_for_destination');
        $this->assertMatchesRegularExpression(
            "/try\\s*\\{\\s*ipam_backup_notify\\(/",
            $destBody,
            'notify dispatch must be wrapped in try{} in ipam_backup_run_for_destination'
        );
        $this->assertStringContainsString(
            "[backup] notify dispatch failed",
            $destBody,
            'notify catch block must error_log a diagnostic'
        );
    }
}
