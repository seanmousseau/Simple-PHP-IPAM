<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ipam_restore_dry_run() corruption detection (#830).
 *
 * Stages real fixture files under Simple-PHP-IPAM/data/tmp/ (the only
 * directory ipam_restore_canonicalize_staged accepts) and exercises the
 * dry-run path end-to-end against an in-memory SQLite. Asserts that
 * truncated dumps fail dry-run BEFORE the apply path commits to a
 * transaction (#830 acceptance criteria 1, 2, 4).
 */
final class RestoreDryRunTest extends TestCase
{
    private string $tmpDir;
    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->tmpDir = realpath(__DIR__ . '/../../Simple-PHP-IPAM/data/tmp')
            ?: throw new RuntimeException('Simple-PHP-IPAM/data/tmp must exist for restore tests');
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $f) {
            // Defence-in-depth: re-validate that the path resolves under the
            // staging directory before unlinking. The staging helpers already
            // build $f from $this->tmpDir, but checking realpath here makes
            // the safety property local to the unlink call.
            $real = realpath($f);
            if ($real === false) continue;
            if (!str_starts_with($real, $this->tmpDir . '/')) continue;
            if (is_file($real)) @unlink($real);
        }
        $this->fixtures = [];
    }

    private function newDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    }

    /** Write a gzip-compressed fixture under data/tmp/, return absolute path. */
    private function stageGzipFixture(string $name, string $sql): string
    {
        $path = $this->tmpDir . '/' . $name;
        $compressed = gzencode($sql);
        if ($compressed === false) {
            $this->fail('gzencode failed in fixture setup');
        }
        file_put_contents($path, $compressed);
        $this->fixtures[] = $path;
        return $path;
    }

    /** Write a plain-text fixture under data/tmp/, return absolute path. */
    private function stagePlainFixture(string $name, string $sql): string
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $sql);
        $this->fixtures[] = $path;
        return $path;
    }

    public function testValidDumpPassesDryRun(): void
    {
        $sql = "CREATE TABLE foo (id INTEGER, name TEXT);\n"
             . "INSERT INTO foo VALUES (1, 'alpha');\n"
             . "INSERT INTO foo VALUES (2, 'bravo');\n";
        $path = $this->stageGzipFixture('test-valid-dryrun-' . uniqid() . '.sql.gz', $sql);

        $result = ipam_restore_dry_run($this->newDb(), $path);

        $this->assertSame(2, $result['total_statements'], 'two INSERTs counted');
        $this->assertCount(1, $result['tables']);
        $this->assertSame('foo', $result['tables'][0]['name']);
        $this->assertSame(2, $result['tables'][0]['backup_rows']);
    }

    public function testTruncatedSingleQuotedStringFailsDryRun(): void
    {
        // Dump ends mid-string-literal — splitter must detect at EOF.
        $sql = "CREATE TABLE foo (id INTEGER, name TEXT);\n"
             . "INSERT INTO foo VALUES (1, 'partial";
        $path = $this->stageGzipFixture('test-truncated-string-' . uniqid() . '.sql.gz', $sql);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated single-quoted string/');
        ipam_restore_dry_run($this->newDb(), $path);
    }

    public function testTruncatedBlockCommentFailsDryRun(): void
    {
        $sql = "SELECT 1;\n/* truncated multi-line\nblock comment ";
        $path = $this->stageGzipFixture('test-truncated-comment-' . uniqid() . '.sql.gz', $sql);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unterminated .* block comment/');
        ipam_restore_dry_run($this->newDb(), $path);
    }

    // Note: truncated-gzip-stream detection is intentionally not unit-tested
    // here. PHP's zlib API conflates "corrupt-EOF" with "clean-EOF" for some
    // truncation patterns — gzgets can return false with gzeof() also true,
    // so the read_staged_sql corruption check doesn't fire deterministically
    // on artificially-truncated gzip streams. The splitter-level corruption
    // tests (testTruncatedSingleQuotedStringFailsDryRun /
    // testTruncatedBlockCommentFailsDryRun) cover the realistic case where a
    // user uploads a partially-downloaded SQL dump; the splitter's
    // unterminated-state detection trips before any of the gzip-EOF
    // ambiguity matters.

    public function testValidPlainSqlDumpPassesDryRun(): void
    {
        // Cover the non-.gz path through fopen/fgets.
        $sql = "CREATE TABLE bar (id INTEGER);\nINSERT INTO bar VALUES (42);\n";
        $path = $this->stagePlainFixture('test-valid-plain-' . uniqid() . '.sql', $sql);

        $result = ipam_restore_dry_run($this->newDb(), $path);

        $this->assertSame(1, $result['total_statements']);
        $this->assertCount(1, $result['tables']);
        $this->assertSame('bar', $result['tables'][0]['name']);
    }

    /**
     * Bug S regression — IPAMBKL1 archive must NOT be piped through the SQL
     * splitter. The dry-run path historically read any staged file as SQL.
     * When fed an IPAMBKL1 archive containing real bcrypt password hashes
     * (`$2y$12$...`), the splitter's dollar-quote handler interpreted those
     * `$tag$` patterns as PostgreSQL dollar-quote openers and threw
     * "unterminated dollar-quoted string" at EOF — making every IPAMBKL1
     * backup containing user data un-restorable on v3.27.0.
     *
     * Pass A repro: 2026-05-08, dev-direct SQLite test instance.
     * See `releases/ipam-3.27.1/regression-evidence/passA/operator-followup-checklist.md` §J.12.
     */
    public function testIpambkl1ArchiveDoesNotErrorThroughSqlSplitter(): void
    {
        $src = new PDO('sqlite::memory:');
        $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $src->exec('PRAGMA foreign_keys = ON');
        ipam_db_init($src);

        // Construct a bcrypt-SHAPED string programmatically. Not a real
        // hash; not a credential. The test exists to verify the parser
        // handles strings beginning with `$<chars>$<chars>$...` (the shape
        // PostgreSQL would interpret as a dollar-quote opener) when those
        // strings appear inside JSON-encoded IPAMBKL1 body data.
        $bcryptHash = '$' . '2y' . '$' . '12' . '$' . str_repeat('a', 53);
        $stmt = $src->prepare(
            "INSERT INTO users (username, password_hash, role, is_active) VALUES (?, ?, 'admin', 1)"
        );
        $stmt->execute(['rt-bugS-user', $bcryptHash]);

        $fixturePath = $this->tmpDir . '/test-bugS-ipambkl1-' . uniqid() . '.ipambkl1.gz';
        ipam_backup_logical_dump($src, $fixturePath);
        $this->fixtures[] = $fixturePath;

        // Before the fix: throws RuntimeException with "unterminated dollar-quoted string".
        // After the fix: returns logical-format dry-run metadata.
        $result = ipam_restore_dry_run($this->newDb(), $fixturePath);

        // The seeded source DB has at least one user row — total_statements
        // (logical-format row count) must be positive.
        $this->assertGreaterThan(0, $result['total_statements'], 'Dry-run should report at least one backed-up row from the seeded source DB');

        // Per-table row counts should be present and positive for tables we seeded.
        $rowsByTable = [];
        foreach ($result['tables'] as $t) {
            $rowsByTable[$t['name']] = $t['backup_rows'];
        }
        $this->assertArrayHasKey('users', $rowsByTable, 'users table should appear in dry-run summary');
        $this->assertGreaterThan(0, $rowsByTable['users'], 'users table should have at least the seeded row');
    }
}
