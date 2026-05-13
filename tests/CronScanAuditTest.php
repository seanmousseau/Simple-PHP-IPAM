<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression test pinning that cron.php emits a `scan.run`
 * audit row per scanned subnet.
 *
 * Scheduled scans were previously invisible in the audit log — api_scan_run
 * and scan_run.php both audit, but cron.php did not. (#1161, PASS-C F-S2-04)
 *
 * A functional test would require executing cron.php end-to-end with a
 * full app bootstrap, which is heavy. The source-level test pins the
 * emission location, action string, entity type, and the (cron) tag that
 * disambiguates the surface in the audit log.
 */
final class CronScanAuditTest extends TestCase
{
    private string $cronSource = '';

    protected function setUp(): void
    {
        $path = __DIR__ . '/../Simple-PHP-IPAM/cron.php';
        $src = file_get_contents($path);
        $this->assertNotFalse($src, 'cron.php must be readable');
        $this->cronSource = $src;
    }

    public function testEmitsAuditScanRun(): void
    {
        $this->assertMatchesRegularExpression(
            "/audit\\(\\s*\\\$db,\\s*'scan\\.run',\\s*'subnet',\\s*\\\$subnetId,/",
            $this->cronSource,
            "cron.php must call audit(\$db, 'scan.run', 'subnet', \$subnetId, ...) inside the scanner loop"
        );
    }

    public function testAuditDetailsHaveCronTag(): void
    {
        // The (cron) suffix disambiguates the surface from api_scan_run and
        // scan_run.php in the audit log UI.
        $this->assertStringContainsString(
            '(cron)',
            $this->cronSource,
            'cron-emitted scan.run rows must carry a (cron) tag in details'
        );
    }

    public function testAuditCallSitsInsideDueSubnetsLoop(): void
    {
        $loopStart = strpos($this->cronSource, 'foreach ($dueSubnets as $sub)');
        $this->assertNotFalse($loopStart, 'expected foreach ($dueSubnets as $sub) loop in cron.php');

        $auditPos = strpos($this->cronSource, "'scan.run'", $loopStart);
        $this->assertNotFalse(
            $auditPos,
            'audit call for scan.run must appear after the foreach ($dueSubnets) opening'
        );

        // Confirm it's still inside that block by checking it comes before the
        // 'task' => 'scan' emit which is the next discriminator inside the loop.
        $emitPos = strpos($this->cronSource, "'task'         => 'scan'", $loopStart);
        $this->assertNotFalse($emitPos);
        $this->assertLessThan(
            $emitPos,
            $auditPos,
            'audit call must precede the per-subnet $emit so summary and audit stay coupled'
        );
    }
}
