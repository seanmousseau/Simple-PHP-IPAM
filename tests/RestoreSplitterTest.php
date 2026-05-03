<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * T13 — property tests for ipam_restore_split_sql_statements().
 *
 * Filed against #810 / acceptance gate for #806 (B-P0-4 in
 * docs/internal/backup_overhaul.md §B). The pre-v3.21.0 splitter is
 * line-oriented + regex-based and silently mis-splits the failure modes
 * encoded below the first time an admin uploads a non-trivial dump.
 *
 * Each test asserts the splitter returns the right number of top-level
 * statements and preserves the bytes between top-level semicolons. Tests
 * land before the lexer rewrite so the rewrite has a green target to hit.
 */
final class RestoreSplitterTest extends TestCase
{
    /** @return list<string> */
    private function split(string $sql): array
    {
        // Splitter is generator-shaped (#829, v3.23.0); wrap input string as
        // a single chunk and materialise the generator for assertSame().
        return iterator_to_array(
            ipam_restore_split_sql_statements([$sql]),
            false
        );
    }

    // ── Baseline: trivial cases ──────────────────────────────────────────────

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->split(''));
    }

    public function testWhitespaceOnlyReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->split("\n\n   \n"));
    }

    public function testSingleStatementWithTerminator(): void
    {
        $out = $this->split("SELECT 1;");
        $this->assertCount(1, $out);
        $this->assertSame('SELECT 1;', trim($out[0]));
    }

    public function testSingleStatementNoTerminator(): void
    {
        $out = $this->split("SELECT 1");
        $this->assertCount(1, $out);
        $this->assertSame('SELECT 1', trim($out[0]));
    }

    public function testTwoStatementsOnSeparateLines(): void
    {
        $out = $this->split("SELECT 1;\nSELECT 2;");
        $this->assertCount(2, $out);
        $this->assertSame('SELECT 1;', trim($out[0]));
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testTwoStatementsOnSameLine(): void
    {
        // Compact dumps emit `INSERT ...; INSERT ...;` on a single line.
        $out = $this->split("SELECT 1; SELECT 2;");
        $this->assertCount(2, $out);
        $this->assertSame('SELECT 1;', trim($out[0]));
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    // ── Failure-mode fixtures (these fail against the legacy splitter) ───────

    public function testInlineDashCommentWithSemicolonDoesNotSplit(): void
    {
        // The ; inside `-- comment` must not be a terminator. Per standard
        // client semantics (mysql, psql, sqlite shell) a trailing comment
        // after a ; rolls forward into the next statement — count must be
        // exactly 2, not 3.
        $sql = "SELECT 1; -- a trailing ; comment\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertSame('SELECT 1;', trim($out[0]));
        $this->assertStringContainsString('-- a trailing ; comment', $out[1]);
        $this->assertStringContainsString('SELECT 2;', $out[1]);
    }

    public function testBlockCommentSpanningSemicolonDoesNotSplit(): void
    {
        // /* ... */ may legitimately contain ; mid-content.
        $sql = "/* keep ; together */ SELECT 1;\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('/* keep ; together */', $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testMultiLineBlockCommentWithSemicolons(): void
    {
        $sql = "/* line1\n; line2\n*/ SELECT 1;\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('/* line1', $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testSingleQuotedStringWithSemicolon(): void
    {
        // ; inside a single-quoted string literal must not terminate.
        $sql = "INSERT INTO x VALUES('semi;colon'); SELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("'semi;colon'", $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testMultiLineSingleQuotedStringWithSemicolon(): void
    {
        // Opening quote on one line, closing quote and semicolon on another.
        // The intermediate ; bytes are inside the literal.
        $sql = "INSERT INTO x VALUES('multi\nline ; with ;\nsemis');\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('multi', $out[0]);
        $this->assertStringContainsString('semis', $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testEscapedSingleQuoteInsideString(): void
    {
        // SQL standard: '' inside a single-quoted string = one literal '.
        // Splitter must not treat the inner '' as a string close.
        $sql = "INSERT INTO x VALUES('it''s a ; test'); SELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("'it''s a ; test'", $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testDoubleQuotedIdentifierWithSemicolon(): void
    {
        // ANSI / PostgreSQL identifier quotes — ; inside the identifier is data.
        $sql = "CREATE TABLE \"weird;name\" (id INT);\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('"weird;name"', $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testMultiLineDoubleQuotedIdentifier(): void
    {
        $sql = "INSERT INTO \"weird;\nname\" VALUES (1);\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("\"weird;\nname\"", $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testBacktickQuotedIdentifierWithSemicolon(): void
    {
        // MySQL identifier quote.
        $sql = "CREATE TABLE `weird;name` (id INT);\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('`weird;name`', $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testMultiLineBacktickIdentifier(): void
    {
        $sql = "INSERT INTO `weird;\ntable` VALUES (1);\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("`weird;\ntable`", $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testTriggerBodyWithMultipleInternalSemicolons(): void
    {
        // Classic SQLite trigger — internal ; must not split, only the outer END;.
        $sql = <<<SQL
CREATE TRIGGER tr AFTER INSERT ON t BEGIN
    INSERT INTO log(msg) VALUES('one');
    INSERT INTO log(msg) VALUES('two');
END;
SELECT 1;
SQL;
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('CREATE TRIGGER', $out[0]);
        $this->assertStringContainsString("INSERT INTO log(msg) VALUES('one');", $out[0]);
        $this->assertStringContainsString("INSERT INTO log(msg) VALUES('two');", $out[0]);
        $this->assertStringContainsString('END;', $out[0]);
        $this->assertSame('SELECT 1;', trim($out[1]));
    }

    public function testBeginInsideStringLiteralIsNotABlockStart(): void
    {
        // BEGIN appearing inside a string must not increment depth.
        $sql = "INSERT INTO x VALUES('this BEGIN is just text END;');\nSELECT 2;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("'this BEGIN is just text END;'", $out[0]);
        $this->assertSame('SELECT 2;', trim($out[1]));
    }

    public function testPostgresDollarQuotedFunctionBody(): void
    {
        // $$ ... $$ delimits a Postgres function body. Inner ; are not terminators.
        $sql = <<<SQL
CREATE FUNCTION f() RETURNS void AS \$\$
SELECT 1;
SELECT 2;
\$\$ LANGUAGE plpgsql;
SELECT 3;
SQL;
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('CREATE FUNCTION', $out[0]);
        $this->assertStringContainsString("\$\$ LANGUAGE plpgsql;", $out[0]);
        $this->assertSame('SELECT 3;', trim($out[1]));
    }

    public function testPostgresTaggedDollarQuotedBody(): void
    {
        // $tag$...$tag$ — tag must match. Other $$ inside is data.
        $sql = <<<SQL
CREATE FUNCTION f() RETURNS text AS \$body\$
    SELECT 'inner \$\$ marker; here';
\$body\$ LANGUAGE plpgsql;
SELECT 1;
SQL;
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString("\$body\$", $out[0]);
        $this->assertSame('SELECT 1;', trim($out[1]));
    }

    public function testCaseInsensitiveBeginEnd(): void
    {
        $sql = "create trigger tr after insert on t begin\n  insert into log values('x');\nend;\nselect 1;";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertStringContainsString('begin', $out[0]);
        $this->assertStringContainsString('end;', $out[0]);
        $this->assertSame('select 1;', trim($out[1]));
    }

    public function testBeginTransactionIsNotABlockStart(): void
    {
        // BEGIN TRANSACTION / BEGIN; is the SQL transaction-start, not a compound block.
        // Must not push depth — otherwise the next END never balances.
        $sql = "BEGIN TRANSACTION;\nINSERT INTO x VALUES (1);\nCOMMIT;";
        $out = $this->split($sql);
        $this->assertCount(3, $out);
        $this->assertSame('BEGIN TRANSACTION;', trim($out[0]));
        $this->assertSame('INSERT INTO x VALUES (1);', trim($out[1]));
        $this->assertSame('COMMIT;', trim($out[2]));
    }

    // ── Round-trip property: re-joining splits reproduces the input ─────────

    public function testRoundTripPreservesAllStatements(): void
    {
        // Real-world dump fragment — every byte of every statement must survive.
        $sql = "PRAGMA foreign_keys=OFF;\n"
             . "CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT);\n"
             . "INSERT INTO t VALUES(1,'alpha');\n"
             . "INSERT INTO t VALUES(2,'has ; semi');\n"
             . "CREATE TRIGGER tr AFTER INSERT ON t BEGIN\n"
             . "  INSERT INTO log(msg) VALUES('hi');\n"
             . "END;\n"
             . "COMMIT;\n";
        $out = $this->split($sql);
        $this->assertCount(6, $out);
        $rejoined = '';
        foreach ($out as $stmt) {
            $rejoined .= rtrim($stmt) . "\n";
        }
        // Every distinguishing fragment from the source must round-trip.
        $this->assertStringContainsString('PRAGMA foreign_keys=OFF;', $rejoined);
        $this->assertStringContainsString("'has ; semi'", $rejoined);
        $this->assertStringContainsString('CREATE TRIGGER tr', $rejoined);
        $this->assertStringContainsString("INSERT INTO log(msg) VALUES('hi');", $rejoined);
        $this->assertStringContainsString('END;', $rejoined);
        $this->assertStringContainsString('COMMIT;', $rejoined);
    }

    public function testTrailingWhitespaceAndBlankLinesIgnored(): void
    {
        $sql = "SELECT 1;\n\n\nSELECT 2;\n\n";
        $out = $this->split($sql);
        $this->assertCount(2, $out);
        $this->assertSame('SELECT 1;', trim($out[0]));
        $this->assertSame('SELECT 2;', trim($out[1]));
    }
}
