<?php
declare(strict_types=1);

/**
 * PDOStatement subclass for the pgsql driver (v2.11.0 #386).
 *
 * Installed in ipam_db() on the pgsql branch via
 * $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PgsqlStatement::class]).
 *
 * **Why this class exists.** pdo_pgsql returns BYTEA columns as PHP stream
 * resources rather than strings, regardless of how they were bound at INSERT
 * time. Without an auto-unwrap, every SELECT of `ip_bin` or `network_bin` in
 * the ~80 call sites across lib.php and the page handlers would either break
 * outright (`strlen` on a resource returns "Resource id #NNN" length) or
 * silently corrupt data. Wrapping every call site in
 * `is_resource($row['ip_bin']) ? stream_get_contents(...) : ...` is
 * intractable.
 *
 * This subclass overrides fetch(), fetchAll(), and fetchColumn() to walk
 * each fetched row and replace any resource value with the result of
 * stream_get_contents(). Columns that aren't BYTEA are unaffected because
 * pdo_pgsql only returns resources for LOB-like column types.
 *
 * Constructor is protected per the PDO::ATTR_STATEMENT_CLASS contract —
 * PDO instantiates subclasses directly without calling user code.
 *
 * No namespace is used; see CLAUDE.md "When to use classes vs functions".
 */
final class PgsqlStatement extends PDOStatement
{
    protected function __construct()
    {
        // PDO::ATTR_STATEMENT_CLASS requires a protected or private
        // constructor. PDO instantiates us internally.
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (is_array($row)) {
            return $this->unwrapRow($row);
        }
        if (is_object($row)) {
            return $this->unwrapObject($row);
        }
        return $row;
    }

    /**
     * The variadic `...$args` must keep the `mixed` type declaration for
     * LSP compatibility with PDOStatement::fetchAll() at runtime. PHPStan's
     * stub narrows it to callable|int|string so we cannot forward `mixed`
     * values through. Every fetchAll() call site in Simple PHP IPAM passes
     * no extra args — a codebase-wide grep confirms this — so the override
     * simply ignores them. If a future call site needs FETCH_CLASS /
     * FETCH_FUNC extras, add a dedicated branch that validates the arg
     * types before forwarding.
     *
     * @return array<int|string, mixed>
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode);
        $out = [];
        foreach ($rows as $k => $row) {
            if (is_array($row)) {
                $out[$k] = $this->unwrapRow($row);
            } elseif (is_object($row)) {
                $out[$k] = $this->unwrapObject($row);
            } else {
                $out[$k] = $row;
            }
        }
        return $out;
    }

    /**
     * Route through fetch(PDO::FETCH_NUM) so the shared unwrap path
     * handles resource columns. Calling parent::fetchColumn() directly
     * returns a value whose type PHPStan narrows to non-resource based
     * on the PDOStatement stub, making the resource unwrap unreachable.
     */
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(PDO::FETCH_NUM);
        if (!is_array($row)) {
            return false;
        }
        return $row[$column] ?? null;
    }

    /**
     * Replace any resource values in $row with the string contents read from
     * the stream. Non-resource values are passed through unchanged.
     *
     * @param  array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    private function unwrapRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_resource($v)) {
                $bytes = stream_get_contents($v);
                $row[$k] = $bytes === false ? '' : $bytes;
            }
        }
        return $row;
    }

    /**
     * PDO::FETCH_OBJ and PDO::FETCH_CLASS return objects; walk the public
     * properties and unwrap any resource-valued ones. Preserves object
     * identity (we mutate the object in place and return it).
     */
    private function unwrapObject(object $row): object
    {
        foreach (get_object_vars($row) as $k => $v) {
            if (is_resource($v)) {
                $bytes = stream_get_contents($v);
                $row->$k = $bytes === false ? '' : $bytes;
            }
        }
        return $row;
    }
}
