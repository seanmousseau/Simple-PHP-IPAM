<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/Dialect.php';
require_once dirname(__DIR__) . '/Simple-PHP-IPAM/dialects/MysqlDialect.php';

/**
 * Unit tests for MysqlDialect (#382).
 *
 * Pure string-output tests. These do NOT require a running MySQL server —
 * every assertion is against the exact SQL fragment MysqlDialect emits,
 * which is deterministic and stateless. End-to-end DDL validation against
 * a real MySQL 8.0 service container is wired up by #384 (per-commit CI
 * job) and #433 (nightly Playwright MySQL matrix).
 *
 * Structure mirrors DialectTest so that when a new interface method is
 * added, both files get the parallel coverage.
 */
final class MysqlDialectTest extends TestCase
{
    private MysqlDialect $mysql;

    protected function setUp(): void
    {
        $this->mysql = new MysqlDialect();
    }

    public function testDriverNameIsMysql(): void
    {
        $this->assertSame('mysql', $this->mysql->driver_name());
    }

    public function testNowReturnsUtcTimestamp(): void
    {
        // UTC_TIMESTAMP() is always UTC regardless of session timezone,
        // matching SQLite's datetime('now') semantics. NOW() would drift
        // across servers and must not be used.
        $this->assertSame('UTC_TIMESTAMP()', $this->mysql->now());
    }

    public function testAutoincrementIsBigintUnsigned(): void
    {
        $this->assertSame(
            'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            $this->mysql->autoincrement()
        );
    }

    public function testIndexedTextTypeDefaultsTo191(): void
    {
        // 191 fits the 767-byte historical InnoDB key prefix under utf8mb4
        // (191 * 4 = 764 bytes). Anything larger needs innodb_large_prefix
        // which v2.10.0 does not require.
        $this->assertSame('VARCHAR(191)', $this->mysql->indexed_text_type());
    }

    public function testIndexedTextTypeAcceptsCustomLength(): void
    {
        $this->assertSame('VARCHAR(255)', $this->mysql->indexed_text_type(255));
        $this->assertSame('VARCHAR(64)', $this->mysql->indexed_text_type(64));
    }

    public function testBinaryTypeUsesVarbinaryWithLength(): void
    {
        $this->assertSame('VARBINARY(16)', $this->mysql->binary_type(16));
        $this->assertSame('VARBINARY(4)', $this->mysql->binary_type(4));
    }

    public function testCaseSensitiveCollationIsUtf8mb4Bin(): void
    {
        // utf8mb4_bin is byte-wise comparison, matching SQLite's default
        // BINARY collation. Cross-engine string equality checks (usernames,
        // CIDRs, hostnames) stay consistent with SQLite.
        $this->assertSame('COLLATE utf8mb4_bin', $this->mysql->case_sensitive_collation());
    }

    public function testPragmaForeignKeysOn(): void
    {
        // MySQL uses SET FOREIGN_KEY_CHECKS = 1 session-level. Same semantics
        // as SQLite's PRAGMA foreign_keys toggle.
        $this->assertSame('SET FOREIGN_KEY_CHECKS = 1', $this->mysql->pragma_foreign_keys(true));
    }

    public function testPragmaForeignKeysOff(): void
    {
        $this->assertSame('SET FOREIGN_KEY_CHECKS = 0', $this->mysql->pragma_foreign_keys(false));
    }

    public function testUpsertSingleConflictColumn(): void
    {
        // On MySQL ON DUPLICATE KEY UPDATE references VALUES(col). The
        // conflict column list is ignored by the server (MySQL scopes on
        // any unique key hit) but the caller still passes it so the same
        // call site compiles on SQLite and Postgres unchanged.
        $sql = $this->mysql->upsert('users', ['username'], ['email', 'updated_at']);
        $this->assertSame(
            'ON DUPLICATE KEY UPDATE email = VALUES(email), updated_at = VALUES(updated_at)',
            $sql
        );
    }

    public function testUpsertCompositeConflictColumns(): void
    {
        $sql = $this->mysql->upsert('subnet_tags', ['subnet_id', 'tag_id'], ['created_at']);
        $this->assertSame(
            'ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)',
            $sql
        );
    }

    public function testUpsertRequiresAtLeastOneConflictColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mysql->upsert('users', [], ['email']);
    }

    public function testUpsertRequiresAtLeastOneUpdateColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mysql->upsert('users', ['username'], []);
    }

    public function testUpsertOrIgnoreBacktickQuotesFirstConflictColumn(): void
    {
        // ON DUPLICATE KEY UPDATE col = col is MySQL's idiom for "leave
        // existing row alone on conflict" — the self-assign is a no-op.
        // Column name is backtick-quoted so reserved words (e.g.
        // settings.key) do not trigger ER_PARSE_ERROR at execution time.
        $sql = $this->mysql->upsert_or_ignore('settings', ['key']);
        $this->assertSame('ON DUPLICATE KEY UPDATE `key` = `key`', $sql);
    }

    public function testUpsertOrIgnoreCompositeStillUsesFirstColumn(): void
    {
        // MySQL's ON DUPLICATE KEY UPDATE is not scoped to a specific
        // conflict target anyway, so we only need any one column to form
        // the self-assign. First conflict column is the stable choice.
        $sql = $this->mysql->upsert_or_ignore('subnet_tags', ['subnet_id', 'tag_id']);
        $this->assertSame('ON DUPLICATE KEY UPDATE `subnet_id` = `subnet_id`', $sql);
    }

    public function testUpsertOrIgnoreRequiresAtLeastOneConflictColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->mysql->upsert_or_ignore('settings', []);
    }

    public function testAppendOnlyTriggerReturnsExactlyTwoStatements(): void
    {
        // Two BEFORE triggers (UPDATE, DELETE), matching the SQLite shape.
        $stmts = $this->mysql->append_only_trigger('audit_log');
        $this->assertCount(2, $stmts);
    }

    public function testAppendOnlyTriggerUsesSignalSqlstate(): void
    {
        $stmts = $this->mysql->append_only_trigger('audit_log');
        foreach ($stmts as $stmt) {
            $this->assertStringContainsString('audit_log', $stmt);
            $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", $stmt);
            $this->assertStringContainsString("audit_log is append-only", $stmt);
            $this->assertStringContainsString("FOR EACH ROW", $stmt);
            // v2.10.0 #502: the trigger body is a compound BEGIN ... END
            // block wrapping the SIGNAL in an IF guard on the session
            // variable @ipam_bypass_append_only. Housekeeping routines
            // set the variable to 1, DELETE, then unset it — other
            // connections continue to be blocked because session
            // variables are per-connection. PDO::exec() handles compound
            // CREATE TRIGGER bodies fine without DELIMITER directives.
            $this->assertStringContainsString('BEGIN', $stmt);
            $this->assertStringContainsString('END', $stmt);
            $this->assertStringContainsString('@ipam_bypass_append_only', $stmt);
            $this->assertStringContainsString('IF', $stmt);
        }
    }

    public function testAppendOnlyTriggerCoversBothUpdateAndDelete(): void
    {
        $stmts = $this->mysql->append_only_trigger('audit_log');
        $joined = implode("\n", $stmts);
        $this->assertStringContainsString('BEFORE UPDATE', $joined);
        $this->assertStringContainsString('BEFORE DELETE', $joined);
    }

    public function testAppendOnlyTriggerStatementsAreIdempotent(): void
    {
        // CREATE TRIGGER IF NOT EXISTS is supported on MySQL 8.0.29+, which
        // v2.10.0 effectively targets (the bootstrap path calls the
        // self-heal on every request).
        $stmts = $this->mysql->append_only_trigger('audit_log');
        foreach ($stmts as $stmt) {
            $this->assertStringContainsString('IF NOT EXISTS', $stmt);
        }
    }

    public function testAppendOnlyTriggerEmbedsGivenTableName(): void
    {
        $stmts = $this->mysql->append_only_trigger('some_other_table');
        foreach ($stmts as $stmt) {
            $this->assertStringContainsString('some_other_table', $stmt);
            $this->assertStringNotContainsString('audit_log', $stmt);
        }
    }
}
