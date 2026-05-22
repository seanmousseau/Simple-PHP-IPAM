<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ipam_format_datetime() — accepts string|int|null, formats in user TZ.
 *
 * The session-user lookup falls back to UTC when no $_SESSION is wired (the
 * unit-test harness has none), so the assertions compare against UTC output.
 * The string→TZ conversion logic itself is exercised in higher-level tests.
 */
final class FormatDatetimeTest extends TestCase
{
    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', ipam_format_datetime(''));
    }

    public function testNullReturnsEmpty(): void
    {
        $this->assertSame('', ipam_format_datetime(null));
    }

    public function testZeroEpochReturnsEmpty(): void
    {
        $this->assertSame('', ipam_format_datetime(0));
    }

    public function testFormatsUtcStringWithDefaultFormat(): void
    {
        $out = ipam_format_datetime('2026-04-30 12:34:56', 'Y-m-d H:i:s');
        $this->assertSame('2026-04-30 12:34:56', $out);
    }

    public function testFormatsIso8601StringWithZSuffix(): void
    {
        $out = ipam_format_datetime('2026-04-30T12:34:56Z', 'Y-m-d H:i:s');
        $this->assertSame('2026-04-30 12:34:56', $out);
    }

    public function testFormatsIntEpoch(): void
    {
        // 1777552496 = 2026-04-30 12:34:56 UTC (gmmktime).
        $out = ipam_format_datetime(1777552496, 'Y-m-d H:i:s');
        $this->assertSame('2026-04-30 12:34:56', $out);
    }

    public function testReturnsRawOnUnparseableString(): void
    {
        $out = ipam_format_datetime('not-a-date');
        $this->assertSame('not-a-date', $out);
    }

    public function testCustomFormatHonoured(): void
    {
        $out = ipam_format_datetime('2026-04-30 00:00:00', 'd/m/Y');
        $this->assertSame('30/04/2026', $out);
    }
}
