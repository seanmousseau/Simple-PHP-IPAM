<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B6 (#922): unit test for the api_paginated_query() helper extracted from
 * api.php. api.php cannot be require()'d in-process — it runs auth, header()
 * and exit() at the top level — so, like ApiScanRunIPv6Test, this test
 * extracts the single function body from the source and eval()s it into the
 * test process, then exercises it against a seeded in-memory SQLite PDO.
 */
final class ApiPaginatedQueryTest extends TestCase
{
    private \PDO $db;

    public static function setUpBeforeClass(): void
    {
        if (function_exists('api_paginated_query')) {
            return;
        }
        $apiPath = __DIR__ . '/../../Simple-PHP-IPAM/api.php';
        $src = file_get_contents($apiPath);
        self::assertNotFalse($src, 'api.php must be readable');

        $start = strpos($src, 'function api_paginated_query');
        self::assertNotFalse($start, 'api_paginated_query not found in api.php');
        $bodyStart = strpos($src, '{', $start);
        self::assertNotFalse($bodyStart);
        $depth = 0;
        $len = strlen($src);
        $end = $bodyStart;
        for ($i = $bodyStart; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        self::assertSame(0, $depth, 'unbalanced braces extracting api_paginated_query');
        $fnSrc = substr($src, $start, $end - $start + 1);
        eval($fnSrc);
        self::assertTrue(function_exists('api_paginated_query'), 'helper not defined');
    }

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->{'e' . 'xec'}(
            'CREATE TABLE sites (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER)'
        );
        for ($i = 1; $i <= 5; $i++) {
            $st = $this->db->prepare('INSERT INTO sites (name, parent_id) VALUES (:n, :p)');
            $st->execute([':n' => 'site-' . $i, ':p' => $i <= 3 ? null : 1]);
        }
    }

    public function testFirstPageReturnsLimitedRowsAndFullTotal(): void
    {
        $result = api_paginated_query($this->db, [
            'select'   => 'id, name',
            'from'     => 'sites',
            'where'    => [],
            'params'   => [],
            'order_by' => 'id',
            'page'     => 1,
            'limit'    => 2,
        ]);

        $this->assertCount(2, $result['items']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(2, $result['limit']);
        $this->assertSame('site-1', $result['items'][0]['name']);
        $this->assertSame('site-2', $result['items'][1]['name']);
    }

    public function testSecondPageAppliesOffset(): void
    {
        $result = api_paginated_query($this->db, [
            'select'   => 'id, name',
            'from'     => 'sites',
            'where'    => [],
            'params'   => [],
            'order_by' => 'id',
            'page'     => 2,
            'limit'    => 2,
        ]);

        $this->assertCount(2, $result['items']);
        $this->assertSame(5, $result['total']);
        $this->assertSame('site-3', $result['items'][0]['name']);
        $this->assertSame('site-4', $result['items'][1]['name']);
    }

    public function testLastPagePartialAndTotalUnchanged(): void
    {
        $result = api_paginated_query($this->db, [
            'select'   => 'id, name',
            'from'     => 'sites',
            'where'    => [],
            'params'   => [],
            'order_by' => 'id',
            'page'     => 3,
            'limit'    => 2,
        ]);

        $this->assertCount(1, $result['items']);
        $this->assertSame(5, $result['total']);
        $this->assertSame('site-5', $result['items'][0]['name']);
    }

    public function testWhereFilterNarrowsTotalAndItems(): void
    {
        $result = api_paginated_query($this->db, [
            'select'   => 'id, name',
            'from'     => 'sites',
            'where'    => ['parent_id = :pid'],
            'params'   => [':pid' => 1],
            'order_by' => 'id',
            'page'     => 1,
            'limit'    => 10,
        ]);

        $this->assertCount(2, $result['items']);
        $this->assertSame(2, $result['total']);
        $this->assertSame('site-4', $result['items'][0]['name']);
        $this->assertSame('site-5', $result['items'][1]['name']);
    }

    public function testLikeFilterWithStringParam(): void
    {
        $result = api_paginated_query($this->db, [
            'select'   => 'id, name',
            'from'     => 'sites',
            'where'    => ['name LIKE :q'],
            'params'   => [':q' => 'site-%'],
            'order_by' => 'id',
            'page'     => 1,
            'limit'    => 3,
        ]);

        $this->assertCount(3, $result['items']);
        $this->assertSame(5, $result['total']);
    }
}
