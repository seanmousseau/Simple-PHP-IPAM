<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/migrations.php';

use PHPUnit\Framework\TestCase;

/**
 * v3.33.0 Task C9 (#930) — unit tests for the ipam_query_or_throw() helper.
 *
 * Verifies the helper returns a PDOStatement on success and throws a
 * RuntimeException (with the failing SQL in the message) when query() returns
 * false.  The second test deliberately opens the connection WITHOUT
 * ERRMODE_EXCEPTION so that query() returns false rather than throwing a
 * PDOException — the helper must detect the false return itself.
 */
final class MigrationHelperTest extends TestCase
{
    public function test_ipam_query_or_throw_returns_statement_on_success(): void
    {
        $db = new PDO('sqlite::memory:');
        $st = ipam_query_or_throw($db, 'SELECT 1 AS one');
        $this->assertSame(1, (int)$st->fetchColumn());
    }

    public function test_ipam_query_or_throw_throws_with_sql_in_message(): void
    {
        // PHP 8.0+ defaults PDO to ERRMODE_EXCEPTION, which would cause query()
        // to throw a PDOException directly — bypassing the helper's own
        // `if ($st === false)` detection branch.  ERRMODE_SILENT forces
        // query() to return false so the helper's code path is actually exercised.
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/NOPE/');
        ipam_query_or_throw($db, 'SELECT * FROM NOPE_no_such_table');
    }
}
