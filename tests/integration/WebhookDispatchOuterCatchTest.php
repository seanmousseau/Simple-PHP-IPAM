<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #1150 (Pass C S-006): ipam_webhook_dispatch()'s outer
 * catch must not silently swallow throwables — it leaves an operator-recoverable
 * line in the server log. The function still never surfaces the error to the
 * user (a webhook failure must not break the originating action).
 */
class WebhookDispatchOuterCatchTest extends TestCase
{
    public function testDispatchOuterCatchSwallowsButLogs(): void
    {
        // Redirect error_log to a fresh temp file for the duration of the test.
        // (Left for the OS to reap — small, in the system temp dir; matches how
        // other tests in this suite handle scratch log files.)
        $logFile = tempnam(sys_get_temp_dir(), 'iperr_webhook_');
        $oldErrorLog = ini_set('error_log', $logFile);
        try {
            // A handle with no application schema: the very first query inside
            // ipam_webhook_dispatch() (SELECT ... FROM webhooks) throws
            // "no such table: webhooks", which must reach the outer catch.
            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $GLOBALS['db'] = $db; // ipam_dialect() reads the global handle

            // Must NOT throw.
            ipam_webhook_dispatch($db, 'subnet.created', ['id' => 1], []);

            $logged = (string) file_get_contents($logFile);
            $this->assertStringContainsString('[webhook_dispatch]', $logged);
        } finally {
            ini_set('error_log', $oldErrorLog === false ? '' : $oldErrorLog);
            unset($GLOBALS['db']);
        }
    }
}
