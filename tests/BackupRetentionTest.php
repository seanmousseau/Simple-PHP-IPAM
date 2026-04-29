<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * Tests for ipam_gfs_select_for_deletion() — the pure GFS retention engine.
 *
 * GFS = Grandfather-Father-Son: hourly → daily → weekly → monthly tiers.
 * The function is pure: no DB, no filesystem, injectable clock.
 *
 * Covers every scenario from issue #719 plus edge cases from the Phase 3 plan.
 */
final class BackupRetentionTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Build a backup list from a flat array of [id, iso-datetime] pairs.
     *
     * @param array<int, array{0: int, 1: string}> $pairs
     * @return array<int, array{id: int, created_at: string}>
     */
    private function backups(array $pairs): array
    {
        $out = [];
        foreach ($pairs as [$id, $ts]) {
            $out[] = ['id' => $id, 'created_at' => $ts];
        }
        return $out;
    }

    /** Standard config: keep 24 hourly, 7 daily, 4 weekly, 3 monthly. */
    private function defaultConfig(): array
    {
        return ['keep_hourly' => 24, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 3];
    }

    // ── #719 scenario 1 — Empty list ───────────────────────────────────────────

    public function testEmptyListReturnsEmpty(): void
    {
        $result = ipam_gfs_select_for_deletion([], $this->defaultConfig(), strtotime('2026-04-28 12:00:00'));
        $this->assertSame([], $result);
    }

    // ── #719 scenario 2 — Below retention limit → nothing deleted ──────────────

    public function testBelowRetentionLimitNothingDeleted(): void
    {
        // 5 backups, config keeps 24 hourly + 7 daily + 4 weekly + 3 monthly.
        // Total keepers exceed list size → delete list must be empty.
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-28 11:00:00'],
            [2, '2026-04-28 10:00:00'],
            [3, '2026-04-28 09:00:00'],
            [4, '2026-04-27 11:00:00'],
            [5, '2026-04-26 11:00:00'],
        ]);
        $delete = ipam_gfs_select_for_deletion($backups, $this->defaultConfig(), $now);
        $this->assertSame([], $delete);
    }

    // ── #719 scenario 3 — Newest backup always preserved ──────────────────────

    public function testNewestIsAlwaysPreserved(): void
    {
        // Even with a very tight config (1 hourly, 0 others), the single newest
        // entry must survive.
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-28 11:00:00'], // newest
            [2, '2026-04-27 10:00:00'],
        ]);
        $config = ['keep_hourly' => 1, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertNotContains(1, $delete, 'newest backup (id=1) must not be in delete list');
        $this->assertContains(2, $delete, 'older backup (id=2) should be pruned');
    }

    // ── #719 scenario 4 — All zero counts → all but newest deleted ────────────

    public function testAllZeroCountsDeletesAllButNewest(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-27 10:00:00'],
            [2, '2026-04-28 11:00:00'], // newest
        ]);
        $delete = ipam_gfs_select_for_deletion(
            $backups,
            ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0],
            $now
        );
        // Safety guard: newest must survive; id=1 must be pruned.
        $this->assertSame([1], $delete, 'newest backup must always survive');
    }

    // Variant with more backups (from the plan's exact test stub)
    public function testKeepsNewestEvenWhenAllCountsZeroMultiple(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = [
            ['id' => 1, 'created_at' => '2026-04-27 10:00:00'],
            ['id' => 2, 'created_at' => '2026-04-28 11:00:00'],
        ];
        $delete = ipam_gfs_select_for_deletion(
            $backups,
            ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0],
            $now
        );
        $this->assertSame([1], $delete, 'newest backup must always survive');
    }

    // ── #719 scenario 5 — Tie-breaking: two backups in same hour ──────────────

    public function testTwoBackupsInSameHourNewerWins(): void
    {
        // Both backups fall in the "2026-04-28 11" hourly slot.
        // Only the newer one (id=2, 11:45) should win the slot; id=1 (11:05) is pruned.
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-28 11:05:00'],
            [2, '2026-04-28 11:45:00'], // newer in same hour
        ]);
        $config = ['keep_hourly' => 1, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertNotContains(2, $delete, 'newer same-hour backup (id=2) must be kept');
        $this->assertContains(1, $delete, 'older same-hour backup (id=1) must be pruned');
    }

    // ── #719 scenario 6 — Tier promotion ──────────────────────────────────────

    /**
     * Tier promotion: a backup from yesterday is no longer in the current hourly
     * window, but it should still win a daily slot (and optionally a weekly slot).
     * The function must not require a backup to win an hourly slot before it can
     * win a daily slot — each tier is evaluated independently.
     */
    public function testTierPromotion(): void
    {
        // Scenario: hourly limit is 1 (only keeps last 1 hour slot), daily limit 7.
        // id=1 is from yesterday 11:00 — past the single hourly slot.
        // It should still win a daily slot and be kept.
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-27 11:00:00'], // yesterday — past hourly, should win daily
            [2, '2026-04-28 11:00:00'], // today — wins hourly + daily
        ]);
        $config = ['keep_hourly' => 1, 'keep_daily' => 2, 'keep_weekly' => 0, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertSame([], $delete, 'both backups should be kept via different tiers');
    }

    /**
     * Tier promotion part 2: yesterday's backup that exhausted hourly capacity
     * still survives via daily tier.
     */
    public function testYesterdayHourlyPromotesToDaily(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        // 25 hourly backups (one per hour for last 25h): hourly capacity is 24.
        // The 25th (oldest hourly, from yesterday) falls out of hourly but should
        // win a daily slot.
        $backups = [];
        for ($h = 24; $h >= 0; $h--) {
            $backups[] = ['id' => $h + 1, 'created_at' => date('Y-m-d H:i:s', $now - $h * 3600)];
        }
        // id=1 is the oldest (25 hours ago = yesterday)
        $config = ['keep_hourly' => 24, 'keep_daily' => 2, 'keep_weekly' => 0, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        // The oldest (id=1, from 25h ago / yesterday) should be kept as a daily slot winner
        $this->assertNotContains(1, $delete, 'oldest backup should win yesterday daily slot');
    }

    // ── #719 scenario 7 — Year boundary ───────────────────────────────────────

    /**
     * Monthly tier correctly identifies oldest-of-month across Dec→Jan boundary.
     * A backup from December 2025 should win the December monthly slot even when
     * evaluated in January 2026.
     */
    public function testYearBoundaryMonthlyTier(): void
    {
        $now = strtotime('2026-01-15 12:00:00');
        $backups = $this->backups([
            [1, '2025-12-01 02:00:00'], // December — should win Dec monthly slot
            [2, '2026-01-01 02:00:00'], // January — should win Jan monthly slot
            [3, '2026-01-15 11:00:00'], // January newer — also wins Jan monthly; id=2 loses
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 2];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        // Jan newest (id=3) wins Jan monthly; Dec (id=1) wins Dec monthly.
        // id=2 (older Jan) should be pruned.
        $this->assertNotContains(3, $delete, 'newest Jan backup must be kept (monthly winner)');
        $this->assertNotContains(1, $delete, 'Dec backup must be kept (Dec monthly winner)');
        $this->assertContains(2, $delete, 'older Jan backup should be pruned');
    }

    /**
     * Weekly tier identifies ISO weeks correctly across year boundary.
     * ISO week 1 of 2026 starts 2025-12-29 (Mon). A backup on 2025-12-31
     * belongs to ISO week 2026-W01, not 2025-W53.
     */
    public function testYearBoundaryWeeklyTierIsoWeek(): void
    {
        // 2025-12-31 is ISO week 2026-W01. 2025-12-22 is ISO 2025-W52.
        $now = strtotime('2026-01-15 12:00:00');
        $backups = $this->backups([
            [1, '2025-12-22 02:00:00'], // ISO 2025-W52
            [2, '2025-12-31 02:00:00'], // ISO 2026-W01
            [3, '2026-01-05 02:00:00'], // ISO 2026-W02
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 3, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        // All three are in distinct ISO weeks; all should be kept.
        $this->assertSame([], $delete, 'each backup is in a distinct ISO week; all should be kept');
    }

    // ── #719 scenario 8 — Spanning multiple weeks/months/years ────────────────

    public function testSpanningMultipleWeeksMonthsYears(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        // Use tight limits so the oldest backups genuinely exceed all tier capacities.
        // keep_hourly=0 (disabled), keep_daily=3, keep_weekly=2, keep_monthly=2.
        // Distinct months: 2026-04 (ids 5,6,7), 2026-01 (id4), 2025-04 (id3), 2025-01 (id2), 2024-01 (id1).
        // Monthly winners (newest-first per month): id7→2026-04, id4→2026-01. keep_monthly=2 exhausted.
        // Ids 3,2,1 lose monthly. Distinct weeks (W18,W17,W14,W01,W14-2025,W01-2025,W01-2024):
        // Weekly winners newest-first: id7(W18), id6(W17). keep_weekly=2 exhausted.
        // Ids 5,4,3,2,1 lose weekly. Daily newest 3 days: id7(Apr28), id6(Apr20), id5(Apr01). keep_daily=3.
        // Ids 4,3,2,1 lose daily. Safety: id7 always kept.
        // Result: ids 1,2,3,4 pruned; ids 5,6,7 kept.
        $backups = $this->backups([
            [1, '2024-01-01 02:00:00'], // >2y old
            [2, '2025-01-01 02:00:00'], // ~16mo old
            [3, '2025-04-01 02:00:00'], // ~12mo old
            [4, '2026-01-01 02:00:00'], // ~4mo old
            [5, '2026-04-01 02:00:00'], // ~4w old
            [6, '2026-04-20 02:00:00'], // ~1w old
            [7, '2026-04-28 11:00:00'], // today (newest)
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 3, 'keep_weekly' => 2, 'keep_monthly' => 2];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);

        // Newest (id=7) always kept.
        $this->assertNotContains(7, $delete, 'newest must never be deleted');

        // Very old backups (ids 1, 2) exceed all retention tiers.
        $this->assertContains(1, $delete, '2024-01-01 backup should be pruned — beyond all tiers');
        $this->assertContains(2, $delete, '2025-01-01 backup should be pruned — beyond all tiers');

        // Total kept ≤ total backups (sanity: deleted + kept = 7)
        $this->assertLessThan(count($backups), count($delete));
    }

    // ── #719 scenario 9 — Config validation: keep_hourly=0 disables tier ──────

    public function testKeepHourlyZeroDisablesHourlyTier(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        // Two backups in the same hour. With keep_hourly=0 the hourly tier is
        // disabled entirely; neither should be kept/pruned by hourly logic.
        // They both fall through to daily tier (keep_daily=1): newer wins, older pruned.
        $backups = $this->backups([
            [1, '2026-04-28 11:05:00'], // same day, same hour
            [2, '2026-04-28 11:45:00'], // same day, same hour (newer)
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 1, 'keep_weekly' => 0, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertNotContains(2, $delete, 'newer backup (id=2) must be kept as daily winner');
        $this->assertContains(1, $delete, 'older backup (id=1) loses daily slot to newer and is pruned');
    }

    // ── Additional edge cases from the plan ────────────────────────────────────

    /**
     * Single backup — never deleted regardless of config.
     */
    public function testSingleBackupNeverDeleted(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([[42, '2026-04-28 11:00:00']]);
        $delete = ipam_gfs_select_for_deletion(
            $backups,
            ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 0],
            $now
        );
        $this->assertSame([], $delete, 'the only backup must never be deleted');
    }

    /**
     * Two backups in the same daily slot: only the newer wins.
     */
    public function testTwoBackupsInSameDayNewerWins(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-27 08:00:00'], // same day
            [2, '2026-04-27 20:00:00'], // same day, newer
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 1, 'keep_weekly' => 0, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertNotContains(2, $delete, 'newer backup (id=2) must win the daily slot');
        $this->assertContains(1, $delete, 'older backup (id=1) must be pruned');
    }

    /**
     * A backup can win multiple tier slots simultaneously (hourly AND daily AND weekly
     * AND monthly), but it's still only kept once — and it only consumes one slot
     * per tier it matches.
     */
    public function testBackupWinsMultipleTiersSimultaneously(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        // id=1 is the newest. id=2 is from last week. With keep_*=1 everywhere,
        // only 1 slot per tier. id=1 wins hourly+daily+weekly+monthly; id=2 loses all.
        $backups = $this->backups([
            [2, '2026-04-01 02:00:00'],
            [1, '2026-04-28 11:00:00'],
        ]);
        $config = ['keep_hourly' => 1, 'keep_daily' => 1, 'keep_weekly' => 1, 'keep_monthly' => 1];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertNotContains(1, $delete, 'newest backup (id=1) must be kept');
        $this->assertContains(2, $delete, 'older backup (id=2) should be pruned when all slots taken by newer');
    }

    /**
     * Monthly tier keeps the NEWEST backup for each calendar month, not the oldest.
     * Sort is newest-first; first in slot wins.
     */
    public function testMonthlyTierKeepsNewestOfMonth(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2026-04-01 02:00:00'], // April early
            [2, '2026-04-15 02:00:00'], // April mid
            [3, '2026-04-28 11:00:00'], // April latest (newest)
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 0, 'keep_monthly' => 1];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        // Only 1 monthly slot: newest (id=3) wins the April slot.
        $this->assertNotContains(3, $delete, 'newest (id=3) wins the only April monthly slot');
        $this->assertContains(2, $delete, 'id=2 loses to id=3 for the April slot');
        $this->assertContains(1, $delete, 'id=1 loses to id=3 for the April slot');
    }

    /**
     * Weekly tier: keep_weekly=2 preserves the two most-recent ISO-week winners.
     */
    public function testWeeklyTierKeepsConfiguredCount(): void
    {
        $now = strtotime('2026-04-28 12:00:00'); // ISO 2026-W18
        $backups = $this->backups([
            [1, '2026-04-05 02:00:00'], // ISO 2026-W14
            [2, '2026-04-12 02:00:00'], // ISO 2026-W15 (week before last)
            [3, '2026-04-19 02:00:00'], // ISO 2026-W16 (last week)
            [4, '2026-04-26 11:00:00'], // ISO 2026-W17 (this week, newest)
        ]);
        $config = ['keep_hourly' => 0, 'keep_daily' => 0, 'keep_weekly' => 2, 'keep_monthly' => 0];
        $delete = ipam_gfs_select_for_deletion($backups, $config, $now);
        // 2 newest weekly winners are id=4 (W17) and id=3 (W16); id=2 and id=1 are pruned.
        $this->assertNotContains(4, $delete, 'W17 winner (id=4) must be kept');
        $this->assertNotContains(3, $delete, 'W16 winner (id=3) must be kept');
        $this->assertContains(2, $delete, 'W15 backup (id=2) should be pruned beyond keep_weekly=2');
        $this->assertContains(1, $delete, 'W14 backup (id=1) should be pruned beyond keep_weekly=2');
    }

    /**
     * The returned delete-list IDs are all integers present in the input list.
     * No phantom IDs, no duplicates.
     */
    public function testDeleteListContainsOnlyInputIds(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [10, '2026-03-01 02:00:00'],
            [20, '2026-04-01 02:00:00'],
            [30, '2026-04-28 11:00:00'],
        ]);
        $delete = ipam_gfs_select_for_deletion($backups, $this->defaultConfig(), $now);
        $inputIds = array_column($backups, 'id');
        foreach ($delete as $id) {
            $this->assertContains($id, $inputIds, "delete list contains unknown id=$id");
        }
        $this->assertSame(count($delete), count(array_unique($delete)), 'delete list must not contain duplicates');
    }

    /**
     * Determinism: calling with the same inputs twice returns identical results.
     */
    public function testFunctionIsDeterministic(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $backups = $this->backups([
            [1, '2025-06-01 02:00:00'],
            [2, '2025-12-01 02:00:00'],
            [3, '2026-03-01 02:00:00'],
            [4, '2026-04-01 02:00:00'],
            [5, '2026-04-28 11:00:00'],
        ]);
        $config = ['keep_hourly' => 3, 'keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 3];
        $first  = ipam_gfs_select_for_deletion($backups, $config, $now);
        $second = ipam_gfs_select_for_deletion($backups, $config, $now);
        $this->assertSame($first, $second, 'function must be deterministic for identical inputs');
    }

    /**
     * Input order must not affect the result — the function sorts internally.
     */
    public function testInputOrderDoesNotAffectResult(): void
    {
        $now = strtotime('2026-04-28 12:00:00');
        $ordered = $this->backups([
            [1, '2025-06-01 02:00:00'],
            [2, '2026-01-01 02:00:00'],
            [3, '2026-04-28 11:00:00'],
        ]);
        $reversed = array_reverse($ordered);
        $config   = ['keep_hourly' => 1, 'keep_daily' => 1, 'keep_weekly' => 1, 'keep_monthly' => 1];

        $fromOrdered  = ipam_gfs_select_for_deletion($ordered, $config, $now);
        $fromReversed = ipam_gfs_select_for_deletion($reversed, $config, $now);

        sort($fromOrdered);
        sort($fromReversed);
        $this->assertSame($fromOrdered, $fromReversed, 'input order must not change the result');
    }
}
