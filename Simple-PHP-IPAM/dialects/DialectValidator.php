<?php
declare(strict_types=1);

/**
 * v3.29.0 #1105 — Shared validation helpers for Dialect implementations.
 *
 * The Dialect interface (Dialect.php) declares the contract; each engine's
 * concrete impl uses these helpers to enforce shape invariants before
 * emitting SQL. Lives outside the interface because PHP interfaces can't
 * carry concrete methods, and the project doesn't use abstract base
 * classes here (CLAUDE.md → "When to use classes vs functions").
 */
final class DialectValidator
{
    /**
     * Reject anything that isn't a bare safe identifier. Closes the
     * #1100 retro footgun where a caller could (in theory) pass quoted
     * or pre-quoted column names and have them interpolated raw into
     * the emitted ON CONFLICT clause on SQLite/Postgres.
     *
     * Bare identifiers only — `[a-zA-Z_][a-zA-Z0-9_]*`. If a caller
     * needs to reference a reserved word, the right move is to rename
     * the column in the schema, not to thread quoting through this
     * helper.
     *
     * @param array<string> $cols
     * @throws InvalidArgumentException
     */
    public static function assertBareIdentifiers(array $cols, string $context): void
    {
        foreach ($cols as $col) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $col)) {
                throw new InvalidArgumentException(
                    "{$context}: column name must be a bare identifier "
                    . "(matching /^[a-zA-Z_][a-zA-Z0-9_]*\$/), got "
                    . var_export($col, true) . '. '
                    . 'Reserved-word columns should be renamed in the schema, '
                    . 'not pre-quoted at the call site.'
                );
            }
        }
    }
}
