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

    // ── #830 corruption detection (truncated input) ─────────────────────────

    public function testUnterminatedSingleQuotedStringThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated single-quoted string/');
        $this->split("INSERT INTO foo VALUES ('partial");
    }

    public function testUnterminatedDoubleQuotedIdentifierThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated double-quoted identifier/');
        $this->split('SELECT "halfway');
    }

    public function testUnterminatedBacktickIdentifierThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated backtick identifier/');
        $this->split('SELECT `halfway');
    }

    public function testUnterminatedBlockCommentThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated .* block comment/');
        $this->split("SELECT 1; /* truncated comment");
    }

    public function testUnterminatedDollarQuotedStringThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated dollar-quoted string \$body\$/');
        $this->split('CREATE FUNCTION f() RETURNS void AS $body$ BEGIN partial');
    }

    public function testUnclosedBeginEndBlockThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated BEGIN…END block/');
        $this->split("CREATE TRIGGER t BEFORE INSERT ON foo BEGIN INSERT INTO log VALUES (1);");
        // Trailing END would close the depth; absence of END = truncated procedure body.
    }

    public function testUnterminatedLineCommentIsAllowed(): void
    {
        // Line comments at EOF without trailing \n are semantically valid
        // and must NOT throw — the regression-guarded path stays permissive
        // for legitimate trailing comments. The lexer yields the trailing
        // comment text as a separate "statement"; ipam_restore_apply filters
        // these via `str_starts_with(ltrim($stmt), '--')` so they're a
        // no-op at the exec level.
        $out = $this->split("SELECT 1; -- trailing note");
        $this->assertSame('SELECT 1;', trim($out[0]));
        // Real-statement count after the apply-style comment filter:
        $real = array_values(array_filter(
            $out,
            static fn(string $s): bool => !str_starts_with(ltrim($s), '--')
        ));
        $this->assertCount(1, $real, 'after comment filter, only the real statement remains');
    }

    // ── #829 stage 3: streaming memory bound ─────────────────────────────────

    /**
     * Synthetic large-dump streaming test. Feeds the splitter from a generator
     * that lazily produces N INSERT statements (one chunk per yield, no array
     * intermediate) and asserts the peak memory delta stays an order of
     * magnitude below the total input size — proving the splitter's GC
     * actually drops consumed bytes rather than accumulating them.
     *
     * Acceptance for #829 (issue body): "1 GB synthetic dump restore completes
     * within memory_limit=128M". The architecture guarantees this by
     * construction (Stage 2 chains gzgets → splitter → exec without buffering);
     * this test pins the property so a future regression that re-introduces a
     * full-input buffer can't slip past the gate.
     *
     * Sized at 50K rows / ~6.5MB synthetic input — large enough to dwarf the
     * splitter's intrinsic state by 10×+, small enough to run in the unit-test
     * gate without slowing it noticeably (~150ms on commodity dev hardware).
     */
    public function testSplitterMemoryStaysBoundedOverLargeStream(): void
    {
        $rowCount = 50_000;
        $payload  = str_repeat('x', 100); // ~130 bytes/line including INSERT envelope
        $perLineBytes = strlen("INSERT INTO foo VALUES (99999, '$payload');\n");
        $estInputBytes = $rowCount * $perLineBytes;

        $genChunks = static function () use ($rowCount, $payload): \Generator {
            yield "CREATE TABLE foo (id INTEGER, val TEXT);\n";
            for ($i = 0; $i < $rowCount; $i++) {
                yield sprintf("INSERT INTO foo VALUES (%d, '%s');\n", $i, $payload);
            }
        };

        $startMem = memory_get_usage();
        memory_reset_peak_usage();

        $count = 0;
        foreach (ipam_restore_split_sql_statements($genChunks()) as $stmt) {
            // Don't accumulate $stmt — that would defeat the bounded-memory test.
            $this->assertNotSame('', $stmt);
            $count++;
        }

        $peakDelta = memory_get_peak_usage() - $startMem;

        // Statements yielded: 1 CREATE + N INSERTs.
        $this->assertSame(1 + $rowCount, $count, 'unexpected statement count');

        // Peak memory delta must be << total input size. Empirically the
        // splitter peaks well under 200KB on this workload; assert <1MB to
        // give substantial headroom while still failing loudly if a future
        // change re-introduces full-input buffering.
        $this->assertLessThan(
            1_048_576,
            $peakDelta,
            sprintf(
                'splitter peak memory %d B exceeded 1 MiB on a %d B input — streaming property regressed',
                $peakDelta,
                $estInputBytes
            )
        );
    }
}
