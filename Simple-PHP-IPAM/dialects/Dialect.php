<?php
declare(strict_types=1);

/**
 * Per-engine SQL portability contract for Simple PHP IPAM.
 *
 * Introduced in v2.9.0 (#378) as the entry point for multi-engine support.
 * v2.9.0 ships SqliteDialect only; v2.10.0 adds MysqlDialect (#384) and
 * v2.11.0 adds PgsqlDialect (#388). Every method on this interface returns
 * a SQL fragment or string that can be interpolated into a query — never a
 * full statement (with the exception of append_only_trigger which by
 * necessity returns DDL).
 *
 * The interface is deliberately small. New methods get added only when a
 * concrete refactor in #379 / #380 / #391 needs them; we do not pre-design
 * for engines we cannot test.
 *
 * No namespace is used. The project has zero namespaces today and adding
 * one would force hand-rolled autoloading. See CLAUDE.md → "When to use
 * classes vs functions".
 */
interface Dialect
{
    /**
     * SQL expression that resolves to the current UTC timestamp at query time.
     *
     *  - SQLite: `datetime('now')`
     *  - MySQL : `UTC_TIMESTAMP()`
     *  - Postgres: `(NOW() AT TIME ZONE 'utc')`
     *
     * Always interpolate, never bind: this is server-side SQL, not a value.
     */
    public function now(): string;

    /**
     * Returns an INSERT ... ON CONFLICT / ON DUPLICATE KEY UPDATE fragment.
     *
     * The returned string is the *suffix* applied after the column list and
     * VALUES tuple — callers compose the full INSERT themselves. This keeps
     * the dialect ignorant of the project's shifting table shapes.
     *
     * @param string   $table        Target table (driver may need it for RETURNING/EXCLUDED scope).
     * @param string[] $conflictCols Columns that form the conflict target (PK or UNIQUE).
     * @param string[] $updateCols   Columns to update on conflict.
     */
    public function upsert(string $table, array $conflictCols, array $updateCols): string;

    /**
     * Returns an INSERT ... ON CONFLICT DO NOTHING fragment.
     *
     * Semantically distinct from upsert(): this variant leaves any existing
     * row untouched on conflict, rather than updating it. Used by migrations
     * and bootstrap code that want "insert if absent, otherwise noop" without
     * racing against concurrent writers.
     *
     *  - SQLite  : `ON CONFLICT(col, ...) DO NOTHING`
     *  - MySQL   : `ON DUPLICATE KEY UPDATE col = col` (no-op self-assign)
     *  - Postgres: `ON CONFLICT (col, ...) DO NOTHING`
     *
     * MySQL note: `ON DUPLICATE KEY UPDATE` fires on *any* unique-key
     * conflict, not just the columns named in $conflictCols. For the call
     * sites this method was introduced for (single PK or single UNIQUE
     * constraint), that difference is invisible. If a future caller has
     * multiple unique keys on the same table and needs conflict-target
     * scoping, use upsert() with a self-assign updateCols list instead.
     *
     * @param string[] $conflictCols
     */
    public function upsert_or_ignore(string $table, array $conflictCols): string;

    /**
     * Column definition for an auto-incrementing integer primary key.
     *
     *  - SQLite: `INTEGER PRIMARY KEY AUTOINCREMENT`
     *  - MySQL : `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`
     *  - Postgres: `BIGSERIAL PRIMARY KEY` (or `GENERATED ALWAYS AS IDENTITY`)
     */
    public function autoincrement(): string;

    /**
     * Column type for a TEXT-like column that will be used in an INDEX,
     * UNIQUE constraint, or primary key.
     *
     *  - SQLite  : `TEXT` (no key-length limit on loosely-typed BLOB/TEXT)
     *  - MySQL   : `VARCHAR($maxLen)` — MySQL 8.0 rejects "BLOB/TEXT column
     *              used in key specification without a key length", so
     *              indexed text columns must be VARCHAR with an explicit
     *              length. 191 is the utf8mb4 default that fits the
     *              historical 767-byte index-key limit.
     *  - Postgres: `TEXT` (native B-tree indexes on TEXT are unrestricted)
     *
     * Use for any TEXT column that appears in a CREATE INDEX, UNIQUE, or
     * PRIMARY KEY declaration. Free-form text columns (descriptions, notes,
     * etc.) that are never indexed should stay as the literal `TEXT`.
     */
    public function indexed_text_type(int $maxLen = 191): string;

    /**
     * Column type for a fixed-length binary blob.
     *
     *  - SQLite: `BLOB`
     *  - MySQL : `VARBINARY(N)`
     *  - Postgres: `BYTEA` (length is informational)
     *
     * Callers pass the *maximum* length they need (16 for IP storage). SQLite
     * ignores it; other engines may use it for strict typing.
     */
    public function binary_type(int $length): string;

    /**
     * Collation clause to append to TEXT columns that must be case-sensitive,
     * or null on engines whose default collation already is.
     *
     *  - SQLite: null (BINARY collation is the default)
     *  - MySQL : `COLLATE utf8mb4_bin`
     *  - Postgres: null (case-sensitive by default)
     */
    public function case_sensitive_collation(): ?string;

    /**
     * SQL statements that make $table append-only (reject UPDATE and DELETE).
     *
     * Returned as a list because different engines need different numbers of
     * statements:
     *
     *  - SQLite: two triggers (BEFORE UPDATE, BEFORE DELETE) using RAISE(ABORT)
     *  - MySQL : two triggers using SIGNAL SQLSTATE '45000'
     *  - Postgres: one PL/pgSQL function plus one trigger binding
     *              UPDATE OR DELETE
     *
     * Callers execute the returned statements in order. Getting the return
     * type wrong here would force an interface change in v2.11.0, so the
     * list shape is locked in v2.9.0 — see #378 design discussion.
     *
     * @return list<string>
     */
    public function append_only_trigger(string $table): array;

    /**
     * Statement to toggle FK enforcement on the current connection, or null
     * on engines without per-connection control.
     *
     *  - SQLite: `PRAGMA foreign_keys = ON|OFF`
     *  - MySQL : `SET FOREIGN_KEY_CHECKS = 1|0`
     *  - Postgres: null (use deferred constraints instead)
     *
     * Callers that need to disable FKs around a destructive migration must
     * always check for null and fall back to engine-appropriate strategies
     * (deferred constraints, recreate tables, etc.) rather than assuming
     * the toggle exists.
     */
    public function pragma_foreign_keys(bool $on): ?string;

    /**
     * SQL fragment for a null-safe equality comparison between a column
     * and a placeholder — both NULLs should compare equal.
     *
     *  - SQLite  : `col IS :ph`   (SQLite's `IS` is null-safe)
     *  - MySQL   : `col <=> :ph`  (MySQL's spaceship operator)
     *  - Postgres: `col IS NOT DISTINCT FROM :ph`
     *
     * Introduced in v2.10.0 (#384) for the duplicate-CIDR / duplicate-VLAN
     * checks that need to treat `vrf_id IS NULL` as equal to `:vrf = NULL`.
     * SQLite's `IS :ph` syntax is SQLite-specific and must not leak into
     * any query that might run on MySQL or Postgres.
     */
    public function null_safe_eq(string $column, string $placeholder): string;

    /**
     * Driver identifier, for logging and one-off branching.
     *
     * @return 'sqlite'|'mysql'|'pgsql'
     */
    public function driver_name(): string;
}
