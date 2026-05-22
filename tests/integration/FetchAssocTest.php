<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ipam_fetch_assoc().
 *
 * Verifies the three safety contracts: null statement returns [], a real row
 * is returned as an associative array, and an exhausted result also returns [].
 */
class FetchAssocTest extends TestCase
{
    public function testNullStatementReturnsEmpty(): void
    {
        $this->assertSame([], ipam_fetch_assoc(null));
    }

    public function testRealRowReturnsAssocArray(): void
    {
        $db = new PDO('sqlite::memory:');
        $st = $db->query('SELECT 7 AS c');
        $this->assertInstanceOf(PDOStatement::class, $st);
        $this->assertSame(['c' => 7], ipam_fetch_assoc($st));
    }

    public function testExhaustedResultReturnsEmpty(): void
    {
        $db = new PDO('sqlite::memory:');
        $st = $db->query('SELECT 1 AS c WHERE 1=0');
        $this->assertInstanceOf(PDOStatement::class, $st);
        $this->assertSame([], ipam_fetch_assoc($st));
    }
}
