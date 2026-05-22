<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-level regression test pinning that the scan-loop emits a `scan.run`
 * audit row per scheduled subnet with the (cron) tag. (#1161, PASS-C F-S2-04)
 *
 * In v3.35.0 (#1291) the emission moved from cron.php's inline loop into
 * ipam_scan_run_for_subnet() in lib/scan.php. The source-level assertions
 * now target lib/scan.php; the behavioural guarantee (audit row written on
 * source='cron', not written on source='cli') is covered by ScanLoopTest.
 *
 * cron.php is still checked to confirm it delegates to ipam_scan_run_for_subnet()
 * with source='cron' inside its due-subnet loop, so the (cron) tag cannot be
 * accidentally dropped by a future refactor of the entry-point.
 */
final class CronScanAuditTest extends TestCase
{
    private string $scanLibSource = '';
    private string $cronSource    = '';

    protected function setUp(): void
    {
        $libPath = __DIR__ . '/../Simple-PHP-IPAM/lib/scan.php';
        $src = file_get_contents($libPath);
        $this->assertNotFalse($src, 'lib/scan.php must be readable');
        $this->scanLibSource = $src;

        $cronPath = __DIR__ . '/../Simple-PHP-IPAM/cron.php';
        $src = file_get_contents($cronPath);
        $this->assertNotFalse($src, 'cron.php must be readable');
        $this->cronSource = $src;
    }

    /**
     * lib/scan.php must contain the audit(... 'scan.run' ...) call with $subnetId.
     */
    public function testEmitsAuditScanRun(): void
    {
        $this->assertMatchesRegularExpression(
            "/audit\\(\\s*\\\$db,\\s*'scan\\.run',\\s*'subnet',\\s*\\\$subnetId,/",
            $this->scanLibSource,
            "lib/scan.php must call audit(\$db, 'scan.run', 'subnet', \$subnetId, ...) inside ipam_scan_run_for_subnet()"
        );
    }

    /**
     * The audit details in lib/scan.php must carry the (cron) tag.
     */
    public function testAuditDetailsHaveCronTag(): void
    {
        $this->assertMatchesRegularExpression(
            '/audit\(\s*\$db,\s*\'scan\.run\',\s*\'subnet\',\s*\$subnetId,.*\(cron\)/s',
            $this->scanLibSource,
            'lib/scan.php scan.run audit rows must carry a (cron) tag in details'
        );
    }

    /**
     * cron.php must call ipam_scan_run_for_subnet() with source='cron' inside
     * its due-subnet loop so the (cron) tag is preserved after the refactor.
     */
    public function testAuditCallSitsInsideDueSubnetsLoop(): void
    {
        $loopStart = strpos($this->cronSource, 'foreach ($dueSubnets as $sub)');
        $this->assertNotFalse($loopStart, "expected foreach (\$dueSubnets as \$sub) loop in cron.php");

        // cron.php must call ipam_scan_run_for_subnet with 'cron' inside the loop.
        $delegatePos = strpos($this->cronSource, "ipam_scan_run_for_subnet(\$db, \$sub, 'cron')", $loopStart);
        $this->assertNotFalse(
            $delegatePos,
            "cron.php must call ipam_scan_run_for_subnet(\$db, \$sub, 'cron') inside the foreach (\$dueSubnets) loop"
        );

        // Confirm the call precedes the per-subnet \$emit so bookkeeping order is preserved.
        $emitPos = strpos($this->cronSource, "'task'         => 'scan'", $loopStart);
        $this->assertNotFalse($emitPos, "expected per-subnet emit inside the due-subnets loop");
        $this->assertLessThan(
            $emitPos,
            $delegatePos,
            'ipam_scan_run_for_subnet() call must precede the per-subnet $emit'
        );
    }
}
