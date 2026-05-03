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

    // ── #832 table-driven edge-case suite (T12 / B-P1-21 pre-req) ─────────────

    /**
     * Table-driven coverage for ipam_backup_next_run_at edge cases.
     *
     * Function is UTC-only (gmmktime throughout) — DST does not apply because
     * scheduling never crosses a local TZ boundary. day_of_month is clamped
     * to 1..28 to avoid month-length issues, so leap-day and 31st-in-short-month
     * cases verify the clamp, not month-length math. day_of_week is 0=Sun..6=Sat
     * per the schema, internally converted to PHP gmdate('N') 1=Mon..7=Sun.
     *
     * Each case: [label, schedule, now (ISO UTC), expected (ISO UTC)].
     *
     * @return array<string, array{0: array<string,mixed>, 1: string, 2: string}>
     */
    public static function nextRunCases(): array
    {
        return [
            // Hourly
            'hourly_at_exact_top_of_hour' => [
                ['frequency' => 'hourly'],
                '2026-04-28 02:00:00 UTC',
                '2026-04-28 03:00:00',
            ],
            'hourly_ignores_time_of_day' => [
                ['frequency' => 'hourly', 'time_of_day' => '17:30'],
                '2026-04-28 02:23:00 UTC',
                '2026-04-28 03:00:00',
            ],

            // Daily
            'daily_at_exactly_target_time_advances_one_day' => [
                ['frequency' => 'daily', 'time_of_day' => '02:00'],
                '2026-04-28 02:00:00 UTC',
                '2026-04-29 02:00:00',
            ],
            'daily_invalid_hour_clamps_to_02' => [
                ['frequency' => 'daily', 'time_of_day' => '25:00'],
                '2026-04-28 03:00:00 UTC',
                '2026-04-29 02:00:00',
            ],
            'daily_time_of_day_no_minute_defaults_zero' => [
                ['frequency' => 'daily', 'time_of_day' => '5'],
                '2026-04-28 03:00:00 UTC',
                '2026-04-28 05:00:00',
            ],

            // Weekly — day_of_week schema 0=Sun .. 6=Sat
            'weekly_sunday_schema_zero' => [
                // 2026-04-28 is Tuesday (PHP N=2). Next Sunday is 2026-05-03.
                ['frequency' => 'weekly', 'day_of_week' => 0, 'time_of_day' => '02:00'],
                '2026-04-28 10:00:00 UTC',
                '2026-05-03 02:00:00',
            ],
            'weekly_saturday_schema_six' => [
                // Next Saturday after Tuesday 2026-04-28 is 2026-05-02.
                ['frequency' => 'weekly', 'day_of_week' => 6, 'time_of_day' => '02:00'],
                '2026-04-28 10:00:00 UTC',
                '2026-05-02 02:00:00',
            ],
            'weekly_target_at_exactly_now_advances_one_week' => [
                // 2026-04-27 is a Monday; if now == target Monday 02:00, next is +7d.
                ['frequency' => 'weekly', 'day_of_week' => 1, 'time_of_day' => '02:00'],
                '2026-04-27 02:00:00 UTC',
                '2026-05-04 02:00:00',
            ],
            'weekly_negative_day_of_week_clamps_to_zero_sunday' => [
                ['frequency' => 'weekly', 'day_of_week' => -3, 'time_of_day' => '02:00'],
                '2026-04-28 10:00:00 UTC',
                '2026-05-03 02:00:00',
            ],
            'weekly_overflow_day_of_week_clamps_to_six_saturday' => [
                ['frequency' => 'weekly', 'day_of_week' => 99, 'time_of_day' => '02:00'],
                '2026-04-28 10:00:00 UTC',
                '2026-05-02 02:00:00',
            ],

            // Monthly — day_of_month clamps to 1..28
            'monthly_day_31_clamps_to_28' => [
                ['frequency' => 'monthly', 'day_of_month' => 31, 'time_of_day' => '02:00'],
                '2026-04-10 12:00:00 UTC',
                '2026-04-28 02:00:00',
            ],
            'monthly_day_29_in_feb_nonleap_clamps_to_28' => [
                // 2026 is not a leap year. Day 29 clamps to 28.
                ['frequency' => 'monthly', 'day_of_month' => 29, 'time_of_day' => '02:00'],
                '2026-02-10 12:00:00 UTC',
                '2026-02-28 02:00:00',
            ],
            'monthly_day_29_in_feb_leap_year_still_clamps_to_28' => [
                // 2028 IS a leap year — but the function clamps regardless,
                // so Feb 29 is not reachable via day_of_month.
                ['frequency' => 'monthly', 'day_of_month' => 29, 'time_of_day' => '02:00'],
                '2028-02-10 12:00:00 UTC',
                '2028-02-28 02:00:00',
            ],
            'monthly_day_zero_clamps_to_one' => [
                ['frequency' => 'monthly', 'day_of_month' => 0, 'time_of_day' => '02:00'],
                '2026-04-10 12:00:00 UTC',
                '2026-05-01 02:00:00',
            ],
            'monthly_target_at_exactly_now_advances_one_month' => [
                ['frequency' => 'monthly', 'day_of_month' => 15, 'time_of_day' => '02:00'],
                '2026-04-15 02:00:00 UTC',
                '2026-05-15 02:00:00',
            ],
            'monthly_dec_to_jan_year_boundary' => [
                ['frequency' => 'monthly', 'day_of_month' => 15, 'time_of_day' => '02:00'],
                '2026-12-20 12:00:00 UTC',
                '2027-01-15 02:00:00',
            ],

            // DST documentation — UTC scheduling is unaffected by US/EU DST
            // transitions. 2026-03-08 02:00 EST is the US spring-forward; in UTC
            // the daily run at 02:00 UTC fires normally with no skip.
            'daily_uts_scheduling_ignores_us_dst_spring_forward' => [
                ['frequency' => 'daily', 'time_of_day' => '02:00'],
                '2026-03-08 01:00:00 UTC',
                '2026-03-08 02:00:00',
            ],
            // 2026-11-01 02:00 EDT is the US fall-back. In UTC, no duplicate hour.
            'daily_utc_scheduling_ignores_us_dst_fall_back' => [
                ['frequency' => 'daily', 'time_of_day' => '02:00'],
                '2026-11-01 01:00:00 UTC',
                '2026-11-01 02:00:00',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $schedule
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nextRunCases')]
    public function testNextRunCases(array $schedule, string $nowIso, string $expectedIso): void
    {
        $now = strtotime($nowIso);
        $this->assertNotFalse($now, "fixture parse failed: {$nowIso}");
        $next = ipam_backup_next_run_at($schedule, $now);
        $this->assertSame($expectedIso, gmdate('Y-m-d H:i:s', $next));
    }
}
