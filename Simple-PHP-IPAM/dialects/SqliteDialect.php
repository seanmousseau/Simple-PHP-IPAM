<?php
declare(strict_types=1);

/**
 * SQLite implementation of the Dialect interface.
 *
 * Stateless — every method is a pure function over its arguments. The class
 * exists only to satisfy the interface contract and give the type checker
 * something to bind against.
 *
 * Every string returned here is exactly what the project currently hardcodes
 * across lib.php, migrations.php, and the page handlers. v2.9.0's #379 / #380
 * refactor will route those call sites through `ipam_dialect()->...()` so
 * v2.10.0 can swap in MysqlDialect without touching application code.
 */
final class SqliteDialect implements Dialect
{
    public function now(): string
    {
        return "datetime('now')";
    }

    /**
     * SQLite supports `INSERT ... ON CONFLICT(col, ...) DO UPDATE SET col = excluded.col`
     * since 3.24.0 (2018). The project requires SQLite 3.x, so this is always available.
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
        $target = '(' . implode(', ', $conflictCols) . ')';
        $assignments = [];
        foreach ($updateCols as $col) {
            $assignments[] = "$col = excluded.$col";
        }
        return "ON CONFLICT$target DO UPDATE SET " . implode(', ', $assignments);
    }

    /**
     * SQLite's `ON CONFLICT(col, ...) DO NOTHING` is the direct native idiom.
     *
     * @param string[] $conflictCols
     */
    public function upsert_or_ignore(string $table, array $conflictCols): string
    {
        if ($conflictCols === []) {
            throw new InvalidArgumentException('upsert_or_ignore() requires at least one conflict column');
        }
        DialectValidator::assertBareIdentifiers($conflictCols, 'SqliteDialect::upsert_or_ignore');
        return 'ON CONFLICT(' . implode(', ', $conflictCols) . ') DO NOTHING';
    }

    public function autoincrement(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    /**
     * SQLite has no key-length limit on TEXT columns in indexes or unique
     * constraints. The $maxLen argument is ignored on this engine but kept
     * in the signature so MySQL can use it for strict typing.
     */
    public function indexed_text_type(int $maxLen = 191): string
    {
        return 'TEXT';
    }

    /**
     * SQLite's typing is loose — BLOB is the affinity, not a fixed-length
     * type. The $length argument is ignored on this engine but kept in the
     * signature so MySQL/Postgres can use it for strict typing.
     */
    public function binary_type(int $length): string
    {
        return 'BLOB';
    }

    /**
     * SQLite's default text collation is BINARY (byte-wise comparison), so
     * no extra clause is needed for case-sensitive columns.
     */
    public function case_sensitive_collation(): ?string
    {
        return null;
    }

    /**
     * Two BEFORE triggers using RAISE(ABORT). The SELECT raises an exception
     * before the UPDATE/DELETE can execute, so the table is observably
     * append-only at the storage layer.
     *
     * Trigger names embed the table name so multiple append-only tables
     * (audit_log, future ones) coexist without collisions.
     *
     * @return list<string>
     */
    public function append_only_trigger(string $table): array
    {
        return [
            "CREATE TRIGGER IF NOT EXISTS {$table}_no_update "
            . "BEFORE UPDATE ON {$table} "
            . "BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END",

            "CREATE TRIGGER IF NOT EXISTS {$table}_no_delete "
            . "BEFORE DELETE ON {$table} "
            . "BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END",
        ];
    }

    /**
     * SQLite always supports the FK pragma toggle, so the SQLite implementation
     * narrows the interface's `?string` return to `string`. PHP allows this
     * covariance and PHPStan-level-9 enforces the narrowing.
     */
    public function pragma_foreign_keys(bool $on): string
    {
        return 'PRAGMA foreign_keys = ' . ($on ? 'ON' : 'OFF');
    }

    public function null_safe_eq(string $column, string $placeholder): string
    {
        return "$column IS $placeholder";
    }

    public function driver_name(): string
    {
        return 'sqlite';
    }

    public function lower_expr(string $column): string
    {
        // No-op: SQLite's default LIKE is already case-insensitive on ASCII
        // A–Z, and SQLite has no built-in Unicode case folding (would need
        // the ICU extension). Pass-through pairs with case_fold_value()
        // returning the value unchanged, preserving SQLite's default LIKE
        // semantics. See lower_expr() docs on Dialect interface.
        return $column;
    }

    public function case_fold_value(string $value): string
    {
        return $value;
    }
}
