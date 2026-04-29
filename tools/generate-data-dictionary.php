<?php
declare(strict_types=1);

/**
 * generate-data-dictionary.php
 *
 * Generates docs/internal/data-dictionary.md from the three schema files
 * (schema.sql / schema.mysql.sql / schema.pgsql.sql).
 *
 * Sources of truth:
 *   - Table & column structure: SQLite reflection (PRAGMA table_info,
 *     foreign_key_list, index_list) on schema.sql loaded into :memory:.
 *     SchemaParityTest already enforces structural parity across engines,
 *     so reflecting one engine is sufficient for the canonical shape.
 *   - Per-engine column types: regex extraction from each .sql file's
 *     CREATE TABLE block. This is the part that genuinely differs across
 *     engines (BLOB vs VARBINARY(16) vs BYTEA, INTEGER vs BIGINT UNSIGNED,
 *     TEXT vs VARCHAR vs TEXT/CITEXT, etc).
 *   - Inline column documentation: the `-- comment` trailing each column
 *     in schema.sql.
 *
 * Render logic is exposed as data_dictionary_render() so DataDictionaryDriftTest
 * can call it in-process — no subprocess, no shell. CLI mode runs only when this
 * file is invoked directly via `php tools/generate-data-dictionary.php`.
 *
 * CLI usage:
 *   php tools/generate-data-dictionary.php           # writes docs/internal/data-dictionary.md
 *   php tools/generate-data-dictionary.php --check   # exits non-zero if file is stale
 */

const DATA_DICT_REPO_ROOT     = __DIR__ . '/..';
const DATA_DICT_SQLITE_SCHEMA = DATA_DICT_REPO_ROOT . '/Simple-PHP-IPAM/schema.sql';
const DATA_DICT_MYSQL_SCHEMA  = DATA_DICT_REPO_ROOT . '/Simple-PHP-IPAM/schema.mysql.sql';
const DATA_DICT_PGSQL_SCHEMA  = DATA_DICT_REPO_ROOT . '/Simple-PHP-IPAM/schema.pgsql.sql';
const DATA_DICT_OUTPUT_FILE   = DATA_DICT_REPO_ROOT . '/docs/internal/data-dictionary.md';

function data_dict_read_or_throw(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) {
        throw new RuntimeException("missing or unreadable: $path");
    }
    return $s;
}

function data_dict_preg_replace(string $pattern, string $replacement, string $subject): string {
    $r = preg_replace($pattern, $replacement, $subject);
    return $r ?? $subject;
}

/**
 * Parse a CREATE TABLE block out of one .sql file.
 *
 * @return array{columns: array<string, array{type: string, comment: string}>}|null
 */
function data_dict_parse_create_table(string $sql, string $tableName): ?array {
    $pattern = '/CREATE TABLE\s+(?:IF NOT EXISTS\s+)?' . preg_quote($tableName, '/') . '\s*\((.*?)\n\)\s*(?:ENGINE|;)/is';
    if (!preg_match($pattern, $sql, $m)) {
        return null;
    }
    $body = $m[1];

    // Strip line comments before splitting — inline `-- foo, bar` would
    // otherwise leak into the next column when the splitter hits the comma
    // inside the comment text and skips the next chunk as comment-only.
    // We capture the per-line comment in a side map keyed by column name.
    $commentMap = [];
    $bodyLines  = explode("\n", $body);
    $strippedLines = [];
    foreach ($bodyLines as $bl) {
        if (preg_match('/^(\s*([a-zA-Z_][a-zA-Z0-9_]*)\b[^-]*?)\s+--\s*(.*)$/', $bl, $cm) === 1) {
            $colName = $cm[2];
            $upper   = strtoupper($colName);
            if (!in_array($upper, ['PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK', 'CONSTRAINT', 'KEY', 'INDEX'], true)) {
                $commentMap[$colName] = trim($cm[3]);
            }
            $strippedLines[] = rtrim($cm[1]);
        } elseif (preg_match('/^(.*?)\s+--\s.*$/', $bl, $cm2) === 1) {
            $strippedLines[] = rtrim($cm2[1]);
        } else {
            $strippedLines[] = $bl;
        }
    }
    $body = implode("\n", $strippedLines);

    // Split top-level commas — column defs can contain (1, 2) ranges in CHECKs.
    $lines = [];
    $depth = 0;
    $cur   = '';
    $len   = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $ch = $body[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
        }
        if ($ch === ',' && $depth === 0) {
            $lines[] = $cur;
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') {
        $lines[] = $cur;
    }

    $columns = [];
    $constraintKeywords = ['PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY', 'CHECK', 'CONSTRAINT', 'KEY ', 'INDEX '];
    foreach ($lines as $line) {
        $stripped = ltrim($line);
        if ($stripped === '' || str_starts_with($stripped, '--')) {
            continue;
        }
        $upperHead = strtoupper(substr($stripped, 0, 16));
        $isConstraint = false;
        foreach ($constraintKeywords as $kw) {
            if (str_starts_with($upperHead, $kw)) {
                $isConstraint = true;
                break;
            }
        }
        if ($isConstraint) {
            continue;
        }

        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s+(.+)$/s', $stripped, $tm) !== 1) {
            continue;
        }
        $name = $tm[1];
        $type = trim(data_dict_preg_replace('/\s+/', ' ', $tm[2]));
        $comment = $commentMap[$name] ?? '';
        $columns[$name] = ['type' => $type, 'comment' => $comment];
    }
    return ['columns' => $columns];
}

/**
 * @return array<string, array{
 *   columns: list<array{name: string, pk: bool, notnull: bool, default: ?string}>,
 *   fks: array<string, array{refs_table: string, refs_col: string, on_delete: string}>,
 *   uniques: list<array{cols: list<string>, origin: string}>
 * }>
 */
function data_dict_load_sqlite_reflection(string $sql): array {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec($sql);

    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid");
    if ($stmt === false) {
        throw new RuntimeException('failed to enumerate sqlite_master');
    }
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $out = [];
    foreach ($tables as $t) {
        if (!is_string($t)) {
            continue;
        }
        $colsStmt = $db->query("PRAGMA table_info(" . data_dict_escape_ident($t) . ")");
        $fksStmt  = $db->query("PRAGMA foreign_key_list(" . data_dict_escape_ident($t) . ")");
        $idxStmt  = $db->query("PRAGMA index_list(" . data_dict_escape_ident($t) . ")");
        if ($colsStmt === false || $fksStmt === false || $idxStmt === false) {
            throw new RuntimeException("PRAGMA failed for table $t");
        }
        $cols = $colsStmt->fetchAll(PDO::FETCH_ASSOC);
        $fks  = $fksStmt->fetchAll(PDO::FETCH_ASSOC);
        $idx  = $idxStmt->fetchAll(PDO::FETCH_ASSOC);

        $uniques = [];
        foreach ($idx as $ix) {
            if ((int)$ix['unique'] !== 1) {
                continue;
            }
            $infoStmt = $db->query("PRAGMA index_info(" . data_dict_escape_ident((string)$ix['name']) . ")");
            if ($infoStmt === false) {
                continue;
            }
            $info = $infoStmt->fetchAll(PDO::FETCH_ASSOC);
            $cols2 = [];
            foreach ($info as $r) {
                $cols2[] = (string)$r['name'];
            }
            $uniques[] = ['cols' => $cols2, 'origin' => (string)$ix['origin']];
        }

        $fkMap = [];
        foreach ($fks as $fk) {
            $fkMap[(string)$fk['from']] = [
                'refs_table' => (string)$fk['table'],
                'refs_col'   => (string)$fk['to'],
                'on_delete'  => ((string)($fk['on_delete'] ?? '')) !== '' ? (string)$fk['on_delete'] : 'NO ACTION',
            ];
        }

        $colList = [];
        foreach ($cols as $c) {
            $colList[] = [
                'name'    => (string)$c['name'],
                'pk'      => (int)$c['pk'] > 0,
                'notnull' => (int)$c['notnull'] === 1,
                'default' => $c['dflt_value'] === null ? null : (string)$c['dflt_value'],
            ];
        }

        $out[$t] = [
            'columns' => $colList,
            'fks'     => $fkMap,
            'uniques' => $uniques,
        ];
    }
    return $out;
}

function data_dict_escape_ident(string $s): string {
    return '"' . str_replace('"', '""', $s) . '"';
}

function data_dict_fmt_default(?string $d): string {
    if ($d === null) {
        return '';
    }
    $d = trim($d);
    if ($d === "''") {
        return "`''`";
    }
    return '`' . $d . '`';
}

function data_dict_shorten(string $s, int $max = 100): string {
    $s = trim(data_dict_preg_replace('/\s+/', ' ', $s));
    if (strlen($s) <= $max) {
        return $s;
    }
    return substr($s, 0, $max - 1) . '…';
}

function data_dict_escape_pipe(string $s): string {
    return str_replace(['|', "\n"], ['\\|', ' '], $s);
}

function data_dict_clean_type(string $t): string {
    $t = data_dict_preg_replace('/\bNOT NULL\b/i',  '', $t);
    $t = data_dict_preg_replace('/\bNULL\b/i',      '', $t);
    $t = data_dict_preg_replace('/\bUNIQUE\b/i',    '', $t);
    $t = data_dict_preg_replace('/\bPRIMARY KEY\b/i', '', $t);
    $t = data_dict_preg_replace('/\bDEFAULT\s+(\([^()]*(?:\([^()]*\)[^()]*)*\)|\'[^\']*\'|[^\s,]+)/i', '', $t);
    $t = data_dict_preg_replace('/\bREFERENCES\s+\w+\s*\([^)]*\)(\s+ON\s+DELETE\s+\w+(\s+\w+)?)?/i', '', $t);
    $t = data_dict_preg_replace('/\bCHECK\s*\(.*\)/i', '', $t);
    $t = data_dict_preg_replace('/\bCOLLATE\s+\S+/i', '', $t);
    $t = data_dict_preg_replace('/\bAUTOINCREMENT\b/i', '', $t);
    $t = data_dict_preg_replace('/\bAUTO_INCREMENT\b/i', '', $t);
    $t = data_dict_preg_replace('/\bGENERATED\s+(BY DEFAULT|ALWAYS)\s+AS\s+IDENTITY\b/i', '', $t);
    $t = data_dict_preg_replace('/\s+/', ' ', $t);
    return trim($t);
}

/**
 * Build the data-dictionary markdown. Pure function, no I/O side effects.
 */
function data_dictionary_render(): string {
    $sqliteSql = data_dict_read_or_throw(DATA_DICT_SQLITE_SCHEMA);
    $mysqlSql  = data_dict_read_or_throw(DATA_DICT_MYSQL_SCHEMA);
    $pgsqlSql  = data_dict_read_or_throw(DATA_DICT_PGSQL_SCHEMA);

    $reflection = data_dict_load_sqlite_reflection($sqliteSql);

    $out = [];
    $out[] = "# Data dictionary";
    $out[] = "";
    $out[] = "<!-- AUTOGENERATED by tools/generate-data-dictionary.php — do not edit by hand. -->";
    $out[] = "<!-- To refresh: php tools/generate-data-dictionary.php -->";
    $out[] = "";
    $out[] = "Reference for every table, column, foreign key, and uniqueness constraint in the IPAM schema, with side-by-side type info for the three supported engines (**SQLite**, **MySQL 8.0+**, **PostgreSQL 14+**).";
    $out[] = "";
    $out[] = "**Source of truth:** the three schema files (`Simple-PHP-IPAM/schema.sql`, `schema.mysql.sql`, `schema.pgsql.sql`). This file is regenerated from them — never hand-edit it.";
    $out[] = "";
    $out[] = "**Cross-engine drift protection:**";
    $out[] = "- `SchemaParityTest` (PHPUnit) loads each schema file into the matching engine and asserts structural equivalence (column set, type class, nullability, FK targets and ON DELETE actions, UNIQUE constraints).";
    $out[] = "- `DataDictionaryDriftTest` (PHPUnit) re-runs this generator and fails if `docs/internal/data-dictionary.md` is stale.";
    $out[] = "";
    $out[] = "**Where things live:**";
    $out[] = "- Storage conventions (binary IP encoding, BLOB affinity, datetime UTC defaults) — see CLAUDE.md → *Database* and *Binary IP storage*.";
    $out[] = "- Migration patterns and SQLite footguns — see `docs/internal/adding-a-migration.md`.";
    $out[] = "- Schema parity rules (which file changes belong in which PR) — see CLAUDE.md → *Modifying the schema (multi-engine, from v2.9.0 onward)*.";
    $out[] = "";
    $out[] = "## Tables";
    $out[] = "";

    foreach (array_keys($reflection) as $t) {
        $out[] = "- [`$t`](#" . strtolower($t) . ")";
    }
    $out[] = "";

    foreach ($reflection as $tableName => $meta) {
        $out[] = "## `$tableName`";
        $out[] = "";

        $sqliteTbl = data_dict_parse_create_table($sqliteSql, $tableName) ?? ['columns' => []];
        $mysqlTbl  = data_dict_parse_create_table($mysqlSql,  $tableName) ?? ['columns' => []];
        $pgsqlTbl  = data_dict_parse_create_table($pgsqlSql,  $tableName) ?? ['columns' => []];

        $out[] = "| Column | SQLite | MySQL | PostgreSQL | Null | Default | Notes |";
        $out[] = "|---|---|---|---|---|---|---|";

        foreach ($meta['columns'] as $col) {
            $name     = $col['name'];
            $nullable = ($col['notnull'] || $col['pk']) ? 'NO' : 'YES';
            $default  = data_dict_fmt_default($col['default']);
            $sqliteTy = $sqliteTbl['columns'][$name]['type']    ?? '';
            $mysqlTy  = $mysqlTbl ['columns'][$name]['type']    ?? '';
            $pgsqlTy  = $pgsqlTbl ['columns'][$name]['type']    ?? '';
            $comment  = $sqliteTbl['columns'][$name]['comment'] ?? '';

            $sqliteCell = data_dict_escape_pipe(data_dict_clean_type($sqliteTy));
            $mysqlCell  = data_dict_escape_pipe(data_dict_clean_type($mysqlTy));
            $pgsqlCell  = data_dict_escape_pipe(data_dict_clean_type($pgsqlTy));

            $out[] = sprintf(
                '| `%s` | `%s` | `%s` | `%s` | %s | %s | %s |',
                $name,
                $sqliteCell !== '' ? $sqliteCell : '—',
                $mysqlCell  !== '' ? $mysqlCell  : '—',
                $pgsqlCell  !== '' ? $pgsqlCell  : '—',
                $nullable,
                $default,
                data_dict_escape_pipe(data_dict_shorten($comment))
            );
        }

        if (!empty($meta['fks'])) {
            $out[] = "";
            $out[] = "**Foreign keys**";
            $out[] = "";
            foreach ($meta['fks'] as $fromCol => $fk) {
                $out[] = sprintf(
                    "- `%s` → `%s.%s` ON DELETE %s",
                    $fromCol,
                    $fk['refs_table'],
                    $fk['refs_col'],
                    $fk['on_delete']
                );
            }
        }

        $uniques = [];
        foreach ($meta['uniques'] as $u) {
            if ($u['origin'] !== 'pk') {
                $uniques[] = $u;
            }
        }
        if (!empty($uniques)) {
            $out[] = "";
            $out[] = "**Unique constraints**";
            $out[] = "";
            foreach ($uniques as $u) {
                $cols = [];
                foreach ($u['cols'] as $c) {
                    $cols[] = "`$c`";
                }
                $out[] = "- (" . implode(', ', $cols) . ")";
            }
        }
        $out[] = "";
    }

    return implode("\n", $out) . "\n";
}

// ----------------------------------------------------------------------------
// CLI entry — only runs when invoked directly, not when included by a test.
// ----------------------------------------------------------------------------

$scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
if (PHP_SAPI === 'cli' && is_string($scriptFile) && realpath($scriptFile) === __FILE__) {
    try {
        $generated = data_dictionary_render();
    } catch (RuntimeException $e) {
        fwrite(STDERR, "generate-data-dictionary: " . $e->getMessage() . "\n");
        exit(1);
    }

    /** @var array<int, string> $args */
    $args = isset($GLOBALS['argv']) && is_array($GLOBALS['argv']) ? $GLOBALS['argv'] : [];
    $check = in_array('--check', $args, true);

    if ($check) {
        $existing = is_file(DATA_DICT_OUTPUT_FILE) ? (string)file_get_contents(DATA_DICT_OUTPUT_FILE) : '';
        if ($existing !== $generated) {
            fwrite(STDERR, "data-dictionary.md is stale — re-run: php tools/generate-data-dictionary.php\n");
            exit(2);
        }
        echo "data-dictionary.md is up to date.\n";
        exit(0);
    }

    $dir = dirname(DATA_DICT_OUTPUT_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    file_put_contents(DATA_DICT_OUTPUT_FILE, $generated);
    echo "Wrote " . DATA_DICT_OUTPUT_FILE . "\n";
}
