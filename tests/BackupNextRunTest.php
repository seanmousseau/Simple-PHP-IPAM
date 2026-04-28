<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class BackupNextRunTest extends TestCase
{
    public function testHourlyAlignedAfterRunTime(): void {
        $now = strtotime('2026-04-28 01:23:00 UTC');
        $next = ipam_backup_next_run_at(['frequency' => 'hourly'], $now);
        $this->assertSame('2026-04-28 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testDailyAlignedAfterRunTime(): void {
        $now = strtotime('2026-04-28 03:00:00 UTC');
        $next = ipam_backup_next_run_at(['frequency' => 'daily', 'time_of_day' => '02:00'], $now);
        $this->assertSame('2026-04-29 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testDailyBeforeRunTime(): void {
        $now = strtotime('2026-04-28 01:00:00 UTC');
        $next = ipam_backup_next_run_at(['frequency' => 'daily', 'time_of_day' => '02:00'], $now);
        $this->assertSame('2026-04-28 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testWeeklyMondayAfter(): void {
        // 2026-04-28 is a Tuesday
        $now = strtotime('2026-04-28 10:00:00 UTC');
        $next = ipam_backup_next_run_at([
            'frequency' => 'weekly', 'day_of_week' => 1, 'time_of_day' => '02:00',
        ], $now);
        // Next Monday is 2026-05-04
        $this->assertSame('2026-05-04 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testMonthlyDay15After(): void {
        $now = strtotime('2026-04-28 12:00:00 UTC');
        $next = ipam_backup_next_run_at([
            'frequency' => 'monthly', 'day_of_month' => 15, 'time_of_day' => '02:00',
        ], $now);
        $this->assertSame('2026-05-15 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testMonthlyDay15Before(): void {
        $now = strtotime('2026-04-10 12:00:00 UTC');
        $next = ipam_backup_next_run_at([
            'frequency' => 'monthly', 'day_of_month' => 15, 'time_of_day' => '02:00',
        ], $now);
        $this->assertSame('2026-04-15 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testHourlyExactlyOnHour(): void {
        $now = strtotime('2026-04-28 02:00:00 UTC');
        $next = ipam_backup_next_run_at(['frequency' => 'hourly'], $now);
        $this->assertSame('2026-04-28 03:00:00', gmdate('Y-m-d H:i:s', $next));
    }

    public function testInvalidFrequencyDefaultsToDaily(): void {
        $now = strtotime('2026-04-28 03:00:00 UTC');
        $next = ipam_backup_next_run_at(['frequency' => 'unknown', 'time_of_day' => '02:00'], $now);
        $this->assertSame('2026-04-29 02:00:00', gmdate('Y-m-d H:i:s', $next));
    }
}
