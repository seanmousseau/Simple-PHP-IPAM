<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_restore.php';

/**
 * Tests for ipam_restore_browse_sort_newest_first() — the Restore-tab
 * backup picker must default to newest-first ordering (#v3.27.9).
 */
class RestoreBrowseSortTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function entries(): array
    {
        return [
            ['name' => 'old.ipambkp3',    'last_modified' => '2026-01-02T03:04:05Z'],
            ['name' => 'newest.ipambkp3', 'last_modified' => '2026-05-10T12:00:00Z'],
            ['name' => 'middle.ipambkp3', 'last_modified' => '2026-03-15 08:30:00'], // S3-style format
        ];
    }

    public function testSortsNewestFirst(): void
    {
        $sorted = ipam_restore_browse_sort_newest_first($this->entries());
        $names  = array_column($sorted, 'name');
        $this->assertSame(['newest.ipambkp3', 'middle.ipambkp3', 'old.ipambkp3'], $names);
    }

    public function testUnparseableTimestampsSortToEnd(): void
    {
        $entries = [
            ['name' => 'bad',  'last_modified' => 'not-a-date'],
            ['name' => 'good', 'last_modified' => '2026-05-10T12:00:00Z'],
            ['name' => 'missing'], // no last_modified key at all
        ];
        $sorted = ipam_restore_browse_sort_newest_first($entries);
        $this->assertSame('good', $sorted[0]['name']);
    }

    public function testEmptyListIsUnchanged(): void
    {
        $this->assertSame([], ipam_restore_browse_sort_newest_first([]));
    }
}
