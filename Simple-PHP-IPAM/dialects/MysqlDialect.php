<?php
declare(strict_types=1);

/**
 * MySQL 8.0+ implementation of the Dialect interface (#382).
 *
 * Stateless — every method is a pure function over its arguments. Mirrors
 * SqliteDialect structurally. v2.10.0 is the first release to ship this
 * class; users opt in via `db_driver = 'mysql'` in config.php. The driver
 * is labelled experimental in v2.10.0 and promoted to stable in v3.0.0.
 *
 * MySQL minimum version: 8.0. Lower versions are rejected at connection
 * time with a clear error — see the version check in lib.php::ipam_db().
 * Reasons for the 8.0 floor:
 *
 *   - utf8mb4 default charset and larger index key prefix (3072 bytes
 *     vs 767 on 5.7) without needing innodb_large_prefix
 *   - JSON column type native support
 *   - CTE / window function support used by some migration closures
 *   - Role-based privileges
 *
 * Storage conventions (match SQLite and Postgres for cross-engine
 * compatibility):
 *
 *   - ip_bin / network_bin: VARBINARY(16), native length (4 bytes for
 *     IPv4, 16 bytes for IPv6, never left-padded). All three engines do
 *     byte-wise memcmp ordering on native-length VARBINARY / BLOB /
 *     BYTEA values, so ORDER BY ip_bin is equivalent across engines.
 *     Locked in during v2.8.0 planning (#410).
 *   - Indexed text columns: VARCHAR(191), utf8mb4, fits the 767-byte
 *     key prefix limit that still applies to non-utf8mb4 indexes and
 *     is safe for every MySQL 8.0 default install.
 *   - Case-sensitive columns (usernames, CIDRs, hostnames): COLLATE
 *     utf8mb4_bin. Byte-comparison, ASCII-safe, matches SQLite's
 *     default BINARY collation so user-visible comparisons stay
 *     equivalent.
 *
 * No namespace is used; see CLAUDE.md "When to use classes vs functions".
 */
final class MysqlDialect implements Dialect
{
    /**
     * MySQL's UTC_TIMESTAMP() is always UTC regardless of session
     * timezone, matching SQLite's datetime('now') and Postgres's
     * (NOW() AT TIME ZONE 'utc'). NOW() returns session-local time
     * and would drift across servers, so avoid it.
     */
    public function now(): string
    {
        return 'UTC_TIMESTAMP()';
    }

    /**
     * MySQL's INSERT ... ON DUPLICATE KEY UPDATE fires on any unique-key
     * conflict, not scoped to the columns in $conflictCols. For the call
     * sites this method was introduced for (single PK or single UNIQUE)
     * that is invisible. Uses VALUES(col) to reference the would-be
     * inserted value, which is the idiomatic form on MySQL 8.0 and still
     * works post-8.0.20 (where the newer alias form is also available).
     *
     * @param string[] $conflictCols
     * @param string[] $updateCols
     */
    public function upsert(string $table, array $conflictCols, array $updateCols): string
    {
        if ($conflictCols === []) {
            throw new InvalidArgumentException('upsert() requires at least one conflict column');
        }
        if ($updateCols === []) {
            throw new InvalidArgumentException('upsert() requires at least one update column');
        }
        $assignments = [];
        foreach ($updateCols as $col) {
            $assignments[] = "$col = VALUES($col)";
        }
        return 'ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
    }

    /**
     * INSERT ... ON DUPLICATE KEY UPDATE col=col is the MySQL idiom for
     * "leave the existing row untouched on conflict" — the self-assign
     * is a no-op. Matches SQLite's ON CONFLICT(...) DO NOTHING semantics
     * for single-PK/UNIQUE call sites. See interface doc for the note on
     * MySQL's wider conflict-target semantics.
     *
     * @param string[] $conflictCols
     */
    public function upsert_or_ignore(string $table, array $conflictCols): string
    {
        if ($conflictCols === []) {
            throw new InvalidArgumentException('upsert_or_ignore() requires at least one conflict column');
        }
        $first = $conflictCols[0];
        return "ON DUPLICATE KEY UPDATE $first = $first";
    }

    /**
     * BIGINT UNSIGNED matches the signed 64-bit range SQLite uses for
     * INTEGER PRIMARY KEY but with the zero-based unsigned range. Any
     * ID column in the schema is an opaque row handle that never goes
     * negative, so the signedness difference is invisible to callers.
     */
    public function autoincrement(): string
    {
        return 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    /**
     * VARCHAR($maxLen) so indexed / unique / PK text columns fit under
     * MySQL's key length limit without needing an explicit index prefix.
     * 191 is chosen because 191 * 4 bytes (utf8mb4) = 764 bytes which
     * fits the historical 767-byte limit that still applies to non-
     * InnoDB-large-prefix configurations. See interface doc.
     */
    public function indexed_text_type(int $maxLen = 191): string
    {
        return "VARCHAR($maxLen)";
    }

    /**
     * VARBINARY($length) stores binary IP values natively (4 bytes for
     * IPv4, 16 bytes for IPv6, never padded). ipam_bind_binary() uses
     * PDO::PARAM_LOB so high bytes and embedded nulls round-trip cleanly.
     */
    public function binary_type(int $length): string
    {
        return "VARBINARY($length)";
    }

    /**
     * utf8mb4_bin is byte-wise comparison under utf8mb4, which matches
     * SQLite's default BINARY collation. Use utf8mb4_general_ci on
     * descriptive columns where case-insensitive comparison is desired.
     *
     * MySQL always needs the collation clause, so this narrows the
     * interface's `?string` return to `string`. PHP allows covariance;
     * PHPStan level 9 enforces the narrowing.
     */
    public function case_sensitive_collation(): string
    {
        return 'COLLATE utf8mb4_bin';
    }

    /**
     * Two BEFORE triggers using SIGNAL SQLSTATE '45000' — MySQL's idiom for
     * raising a generic user-defined exception. MYSQL_ERRNO 1644 is the
     * standard code for "Unhandled user-defined exception condition", the
     * closest MySQL equivalent to SQLite's RAISE(ABORT).
     *
     * Single-statement trigger bodies (no BEGIN/END wrapping) so each CREATE
     * TRIGGER is one complete statement — PDO::exec() can dispatch them
     * without any multi-statement parsing concerns, and the schema file
     * stays parseable without DELIMITER directives.
     *
     * Trigger names embed the table name for namespace safety.
     *
     * Note: CREATE TRIGGER IF NOT EXISTS requires MySQL 8.0.22+. Earlier
     * 8.0 minor versions will fail on re-run because ensure_audit_log_table()
     * is called idempotently on every bootstrap. v2.10.0 effectively targets
     * 8.0.22+.
     *
     * @return list<string>
     */
    public function append_only_trigger(string $table): array
    {
        $msg = "$table is append-only";
        $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$msg', MYSQL_ERRNO = 1644";
        return [
            "CREATE TRIGGER IF NOT EXISTS {$table}_no_update "
            . "BEFORE UPDATE ON {$table} FOR EACH ROW $body",

            "CREATE TRIGGER IF NOT EXISTS {$table}_no_delete "
            . "BEFORE DELETE ON {$table} FOR EACH ROW $body",
        ];
    }

    /**
     * MySQL uses SET FOREIGN_KEY_CHECKS at the session level rather than
     * PRAGMA. Same semantics as SQLite's PRAGMA foreign_keys toggle: when
     * OFF, FK constraints are not enforced for the duration of the
     * current session or until re-enabled.
     */
    public function pragma_foreign_keys(bool $on): string
    {
        return 'SET FOREIGN_KEY_CHECKS = ' . ($on ? '1' : '0');
    }

    public function driver_name(): string
    {
        return 'mysql';
    }
}
