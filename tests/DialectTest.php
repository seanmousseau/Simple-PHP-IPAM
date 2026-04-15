<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/Dialect.php';
require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/SqliteDialect.php';

/**
 * Unit tests for the Dialect interface and its SQLite implementation (#378).
 *
 * Boots the dialect directly without going through init.php so the tests stay
 * pure — no $config, no PDO, no session. Once v2.10.0 / v2.11.0 add
 * MysqlDialect / PgsqlDialect, they should be added as additional fixtures
 * here, ideally via a dataProvider that asserts the same shape on each engine
 * where the contract requires identical output.
 */
final class DialectTest extends TestCase
{
    private SqliteDialect $sqlite;

    protected function setUp(): void
    {
        $this->sqlite = new SqliteDialect();
    }

    public function testDriverNameIsSqlite(): void
    {
        $this->assertSame('sqlite', $this->sqlite->driver_name());
    }

    public function testNowReturnsSqliteDatetimeExpression(): void
    {
        $this->assertSame("datetime('now')", $this->sqlite->now());
    }

    public function testAutoincrementMatchesSqliteIdiom(): void
    {
        $this->assertSame('INTEGER PRIMARY KEY AUTOINCREMENT', $this->sqlite->autoincrement());
    }

    public function testBinaryTypeIgnoresLengthOnSqlite(): void
    {
        $this->assertSame('BLOB', $this->sqlite->binary_type(16));
        $this->assertSame('BLOB', $this->sqlite->binary_type(4));
        $this->assertSame('BLOB', $this->sqlite->binary_type(255));
    }

    public function testIndexedTextTypeIsPlainTextOnSqlite(): void
    {
        // SQLite has no key-length limit on TEXT columns, so the maxLen
        // argument is a no-op here. MysqlDialect will emit VARCHAR($maxLen)
        // on the same call to satisfy MySQL 8.0's "BLOB/TEXT column used
        // in key specification without a key length" error.
        $this->assertSame('TEXT', $this->sqlite->indexed_text_type());
        $this->assertSame('TEXT', $this->sqlite->indexed_text_type(191));
        $this->assertSame('TEXT', $this->sqlite->indexed_text_type(255));
    }

    public function testCaseSensitiveCollationIsNullOnSqlite(): void
    {
        $this->assertNull($this->sqlite->case_sensitive_collation());
    }

    public function testPragmaForeignKeysOn(): void
    {
        $this->assertSame('PRAGMA foreign_keys = ON', $this->sqlite->pragma_foreign_keys(true));
    }

    public function testPragmaForeignKeysOff(): void
    {
        $this->assertSame('PRAGMA foreign_keys = OFF', $this->sqlite->pragma_foreign_keys(false));
    }

    public function testUpsertSingleConflictColumn(): void
    {
        $sql = $this->sqlite->upsert('users', ['username'], ['email', 'updated_at']);
        $this->assertSame(
            'ON CONFLICT(username) DO UPDATE SET email = excluded.email, updated_at = excluded.updated_at',
            $sql
        );
    }

    public function testUpsertCompositeConflictColumns(): void
    {
        $sql = $this->sqlite->upsert('subnet_tags', ['subnet_id', 'tag_id'], ['created_at']);
        $this->assertSame(
            'ON CONFLICT(subnet_id, tag_id) DO UPDATE SET created_at = excluded.created_at',
            $sql
        );
    }

    public function testUpsertRequiresAtLeastOneConflictColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sqlite->upsert('users', [], ['email']);
    }

    public function testUpsertRequiresAtLeastOneUpdateColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sqlite->upsert('users', ['username'], []);
    }

    public function testUpsertOrIgnoreSingleConflictColumn(): void
    {
        $sql = $this->sqlite->upsert_or_ignore('settings', ['key']);
        $this->assertSame('ON CONFLICT(key) DO NOTHING', $sql);
    }

    public function testUpsertOrIgnoreCompositeConflictColumns(): void
    {
        $sql = $this->sqlite->upsert_or_ignore('subnet_tags', ['subnet_id', 'tag_id']);
        $this->assertSame('ON CONFLICT(subnet_id, tag_id) DO NOTHING', $sql);
    }

    public function testUpsertOrIgnoreRequiresAtLeastOneConflictColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sqlite->upsert_or_ignore('settings', []);
    }

    public function testUpsertOrIgnoreComposesIntoValidSqliteInsert(): void
    {
        // End-to-end: the fragment the dialect returns must compose into a
        // real INSERT that a real SQLite DB will accept and that leaves an
        // existing row untouched on conflict.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->query("CREATE TABLE kv (k TEXT PRIMARY KEY, v TEXT NOT NULL)");
        $pdo->prepare("INSERT INTO kv (k, v) VALUES (:k, :v)")
            ->execute([':k' => 'greeting', ':v' => 'hello']);

        $ignore = $this->sqlite->upsert_or_ignore('kv', ['k']);
        $st = $pdo->prepare("INSERT INTO kv (k, v) VALUES (:k, :v) $ignore");
        $st->execute([':k' => 'greeting', ':v' => 'overwritten']);

        $row = $pdo->query("SELECT v FROM kv WHERE k = 'greeting'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('hello', $row['v']);
    }

    public function testAppendOnlyTriggerReturnsExactlyTwoStatements(): void
    {
        // SQLite needs two BEFORE triggers; MySQL needs two SIGNAL triggers;
        // Postgres needs one function plus one trigger. The interface returns
        // a list to accommodate this. A third statement on SQLite would
        // break the documented contract.
        $stmts = $this->sqlite->append_only_trigger('audit_log');
        $this->assertCount(2, $stmts);
    }

    public function testAppendOnlyTriggerEmbedsTableName(): void
    {
        $stmts = $this->sqlite->append_only_trigger('audit_log');
        foreach ($stmts as $stmt) {
            $this->assertStringContainsString('audit_log', $stmt);
            $this->assertStringContainsString('RAISE(ABORT', $stmt);
        }
    }

    public function testAppendOnlyTriggerCoversBothUpdateAndDelete(): void
    {
        $stmts = $this->sqlite->append_only_trigger('audit_log');
        $joined = implode("\n", $stmts);
        $this->assertStringContainsString('BEFORE UPDATE', $joined);
        $this->assertStringContainsString('BEFORE DELETE', $joined);
    }

    public function testAppendOnlyTriggerStatementsAreIdempotent(): void
    {
        // Both triggers use IF NOT EXISTS so re-running the migration that
        // creates them is safe.
        $stmts = $this->sqlite->append_only_trigger('audit_log');
        foreach ($stmts as $stmt) {
            $this->assertStringContainsString('IF NOT EXISTS', $stmt);
        }
    }

    public function testAppendOnlyTriggerActuallyBlocksUpdateOnRealSqlite(): void
    {
        // End-to-end: the strings the dialect returns must be valid SQLite
        // DDL that successfully blocks UPDATE on a real DB.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE log (id INTEGER PRIMARY KEY, msg TEXT)");
        $pdo->exec("INSERT INTO log (msg) VALUES ('hello')");

        foreach ($this->sqlite->append_only_trigger('log') as $stmt) {
            $pdo->exec($stmt);
        }

        $this->expectException(PDOException::class);
        $pdo->exec("UPDATE log SET msg = 'tampered' WHERE id = 1");
    }
}
