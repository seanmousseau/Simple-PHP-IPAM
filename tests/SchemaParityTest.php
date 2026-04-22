<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cross-engine schema parity protection (#409).
 *
 * Protects the v2.11.0 three-file schema invariant: schema.sql (SQLite),
 * schema.mysql.sql (MySQL 8.0.29+), and schema.pgsql.sql (Postgres 14+)
 * must describe the same logical database shape. Drift between any two
 * of them is exactly the bug class this test catches: a column added in
 * one file but missed in another, a nullability flip, a FK on-delete
 * action that changed, a UNIQUE constraint that got dropped during a
 * copy-paste.
 *
 * Approach:
 *
 *   1. Boot each engine to a clean state.
 *   2. Load the matching schema.*.sql file via PDO::exec().
 *   3. Read structural metadata back out through PRAGMA / information_schema.
 *   4. Normalise the metadata into a canonical structure per table — a
 *      nested array where semantically-equivalent types fold to the same
 *      key (BLOB/VARBINARY(16)/BYTEA → "binary", etc).
 *   5. Compare the three canonical structures for equality.
 *
 * Gating: SQLite runs unconditionally (in-memory, ~10ms). MySQL runs when
 * IPAM_MYSQL_DSN is set in the environment; Postgres runs when
 * IPAM_PGSQL_DSN is set. Mirrors the MysqlSmokeTest / PgsqlSmokeTest
 * gating pattern so local SQLite-only development stays fast while CI's
 * per-commit matrix slots exercise all three drivers via #388.
 *
 * Scope NOT covered (deliberately — keeps this test tractable and
 * focused on the highest-signal drift):
 *
 *   - CHECK constraint expressions (engines format these differently;
 *     a syntactic comparison is brittle)
 *   - Default value expressions (UTC_TIMESTAMP vs datetime('now') vs
 *     NOW() — these are the dialect's job, not the parity test's)
 *   - Index sets beyond UNIQUE constraints (names and cardinalities
 *     vary naturally)
 *   - Per-column collations (COLLATE "C" / utf8mb4_bin / SQLite BINARY
 *     are dialect-level equivalents already enforced by convention)
 *
 * If one of those bites us in practice, extend the canonicalisation
 * layer with a new field rather than loosening the existing assertions.
 */
final class SchemaParityTest extends TestCase
{
    /**
     * Canonical table shape:
     *   [
     *     'table_name' => [
     *       'columns' => [
     *         'col_name' => ['type_class' => string, 'nullable' => bool],
     *         ...
     *       ],
     *       'primary_key' => ['col1', 'col2'],
     *       'foreign_keys' => [
     *         ['column' => 'col', 'refs_table' => 't', 'refs_column' => 'c', 'on_delete' => 'CASCADE|SET NULL|RESTRICT|NO ACTION'],
     *         ...
     *       ],
     *       'unique_constraints' => [
     *         ['col1', 'col2'],
     *         ...
     *       ],
     *     ],
     *     ...
     *   ]
     */

    private const SQLITE_SCHEMA = __DIR__ . '/../Simple-PHP-IPAM/schema.sql';
    private const MYSQL_SCHEMA  = __DIR__ . '/../Simple-PHP-IPAM/schema.mysql.sql';
    private const PGSQL_SCHEMA  = __DIR__ . '/../Simple-PHP-IPAM/schema.pgsql.sql';

    // -------------------------------------------------------------------------
    // SQLite — always available
    // -------------------------------------------------------------------------

    public function testSqliteSchemaLoadsCleanly(): void
    {
        $db = $this->loadSqliteSchema();
        $tables = $this->sqliteTables($db);
        $this->assertNotEmpty($tables, 'schema.sql must create at least one table');
        $this->assertContains('users',      $tables);
        $this->assertContains('subnets',    $tables);
        $this->assertContains('addresses',  $tables);
        $this->assertContains('audit_log',  $tables);
        $this->assertContains('schema_migrations', $tables);
    }

    public function testSqliteSchemaCanonicalShapeIsStable(): void
    {
        // Baseline sanity: the SQLite canonical shape has the expected
        // anchor columns in place. If schema.sql drifts in a way the
        // canonicalisation layer can't represent, this will flag it early.
        $canonical = $this->canonicalSqlite();

        $this->assertArrayHasKey('addresses', $canonical);
        $this->assertArrayHasKey('ip_bin', $canonical['addresses']['columns']);
        $this->assertSame('binary', $canonical['addresses']['columns']['ip_bin']['type_class']);
        $this->assertFalse($canonical['addresses']['columns']['ip_bin']['nullable']);

        $this->assertArrayHasKey('subnets', $canonical);
        $this->assertArrayHasKey('network_bin', $canonical['subnets']['columns']);
        $this->assertSame('binary', $canonical['subnets']['columns']['network_bin']['type_class']);
    }

    // -------------------------------------------------------------------------
    // Cross-engine parity — gated on the non-SQLite environments
    // -------------------------------------------------------------------------

    public function testSqliteAndMysqlConverge(): void
    {
        if (getenv('IPAM_MYSQL_DSN') === false || getenv('IPAM_MYSQL_DSN') === '') {
            $this->markTestSkipped('IPAM_MYSQL_DSN not set; skipping SQLite↔MySQL parity check');
        }
        $sqlite = $this->canonicalSqlite();
        $mysql  = $this->canonicalMysql();
        $this->assertCanonicalShapesEqual($sqlite, $mysql, 'sqlite', 'mysql');
    }

    public function testSqliteAndPgsqlConverge(): void
    {
        if (getenv('IPAM_PGSQL_DSN') === false || getenv('IPAM_PGSQL_DSN') === '') {
            $this->markTestSkipped('IPAM_PGSQL_DSN not set; skipping SQLite↔Postgres parity check');
        }
        $sqlite = $this->canonicalSqlite();
        $pgsql  = $this->canonicalPgsql();
        $this->assertCanonicalShapesEqual($sqlite, $pgsql, 'sqlite', 'pgsql');
    }

    public function testMysqlAndPgsqlConverge(): void
    {
        if (getenv('IPAM_MYSQL_DSN') === false || getenv('IPAM_MYSQL_DSN') === '') {
            $this->markTestSkipped('IPAM_MYSQL_DSN not set; skipping MySQL↔Postgres parity check');
        }
        if (getenv('IPAM_PGSQL_DSN') === false || getenv('IPAM_PGSQL_DSN') === '') {
            $this->markTestSkipped('IPAM_PGSQL_DSN not set; skipping MySQL↔Postgres parity check');
        }
        $mysql = $this->canonicalMysql();
        $pgsql = $this->canonicalPgsql();
        $this->assertCanonicalShapesEqual($mysql, $pgsql, 'mysql', 'pgsql');
    }

    // -------------------------------------------------------------------------
    // Canonicalisation — SQLite
    // -------------------------------------------------------------------------

    private function loadSqliteSchema(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");
        $schema = file_get_contents(self::SQLITE_SCHEMA);
        $this->assertNotFalse($schema, 'schema.sql must be readable');
        $db->exec($schema);
        return $db;
    }

    /**
     * @return list<string>
     */
    private function sqliteTables(PDO $db): array
    {
        $rows = $db->query(
            "SELECT name FROM sqlite_master "
            . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
            . "ORDER BY name"
        )->fetchAll();
        $names = [];
        foreach ($rows as $r) {
            $names[] = (string)$r['name'];
        }
        return $names;
    }

    /**
     * @return array<string, array{
     *   columns: array<string, array{type_class: string, nullable: bool}>,
     *   primary_key: list<string>,
     *   foreign_keys: list<array{column: string, refs_table: string, refs_column: string, on_delete: string}>,
     *   unique_constraints: list<list<string>>
     * }>
     */
    private function canonicalSqlite(): array
    {
        $db = $this->loadSqliteSchema();
        $out = [];
        foreach ($this->sqliteTables($db) as $table) {
            $columns = [];
            $pk = [];
            // PRAGMA table_info returns pk order via the `pk` column (0 if
            // not part of PK, 1-based rank otherwise).
            $colRows = $db->query("PRAGMA table_info(\"$table\")")->fetchAll();
            $pkOrder = [];
            foreach ($colRows as $row) {
                $name = (string)$row['name'];
                $type = strtoupper((string)$row['type']);
                $notNull = (int)$row['notnull'] === 1;
                $columns[$name] = [
                    'type_class' => $this->sqliteTypeClass($type),
                    'nullable'   => !$notNull,
                ];
                $pkPos = (int)$row['pk'];
                if ($pkPos > 0) {
                    $pkOrder[$pkPos] = $name;
                }
            }
            ksort($pkOrder);
            $pk = array_values($pkOrder);

            $fks = [];
            $fkRows = $db->query("PRAGMA foreign_key_list(\"$table\")")->fetchAll();
            foreach ($fkRows as $row) {
                $fks[] = [
                    'column'      => (string)$row['from'],
                    'refs_table'  => (string)$row['table'],
                    'refs_column' => (string)$row['to'],
                    'on_delete'   => $this->normaliseOnDelete((string)$row['on_delete']),
                ];
            }
            usort($fks, fn($a, $b) => $a['column'] <=> $b['column']);

            $uniques = [];
            $idxRows = $db->query("PRAGMA index_list(\"$table\")")->fetchAll();
            foreach ($idxRows as $idx) {
                if ((int)$idx['unique'] !== 1) {
                    continue;
                }
                // Skip the auto-index that backs the primary key.
                if (str_starts_with((string)$idx['name'], 'sqlite_autoindex_') && $pk !== []) {
                    // Determine whether this auto-index covers exactly the PK.
                    $idxCols = $db->query(
                        "PRAGMA index_info(\"" . (string)$idx['name'] . "\")"
                    )->fetchAll();
                    $idxColNames = array_map(fn($c) => (string)$c['name'], $idxCols);
                    if ($idxColNames === $pk) {
                        continue;
                    }
                }
                $idxCols = $db->query(
                    "PRAGMA index_info(\"" . (string)$idx['name'] . "\")"
                )->fetchAll();
                $cols = [];
                foreach ($idxCols as $c) {
                    $cols[] = (string)$c['name'];
                }
                if ($cols !== []) {
                    $uniques[] = $cols;
                }
            }
            // Sort uniques for stable comparison.
            usort($uniques, fn($a, $b) => implode(',', $a) <=> implode(',', $b));

            // SQLite quirk: `INTEGER PRIMARY KEY AUTOINCREMENT` columns are
            // implicitly NOT NULL (they're ROWID aliases and cannot hold
            // NULL), but `PRAGMA table_info()` reports them as nullable
            // unless the DDL explicitly adds NOT NULL. Force PK columns to
            // non-nullable so the canonical shape matches MySQL's
            // `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT` and Postgres's
            // `BIGINT GENERATED BY DEFAULT AS IDENTITY`.
            foreach ($pk as $pkCol) {
                if (isset($columns[$pkCol])) {
                    $columns[$pkCol]['nullable'] = false;
                }
            }

            ksort($columns);
            $out[$table] = [
                'columns'            => $columns,
                'primary_key'        => $pk,
                'foreign_keys'       => $fks,
                'unique_constraints' => $uniques,
            ];
        }
        ksort($out);
        return $out;
    }

    private function sqliteTypeClass(string $type): string
    {
        // SQLite's type affinity rules, simplified to the classes
        // schema.sql uses.
        if ($type === '' || str_contains($type, 'BLOB')) {
            // Empty column type is BLOB affinity. schema.sql uses plain
            // BLOB for binary so this covers it.
            return $type === '' ? 'binary' : 'binary';
        }
        if (str_contains($type, 'INT')) {
            return 'int';
        }
        if (str_contains($type, 'CHAR') || str_contains($type, 'CLOB') || str_contains($type, 'TEXT')) {
            return 'text';
        }
        if (str_contains($type, 'REAL') || str_contains($type, 'FLOA') || str_contains($type, 'DOUB')) {
            return 'real';
        }
        if (str_contains($type, 'DATE') || str_contains($type, 'TIME')) {
            return 'text'; // SQLite stores dates/times as text
        }
        return 'text';
    }

    // -------------------------------------------------------------------------
    // Canonicalisation — MySQL (gated on IPAM_MYSQL_DSN)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{
     *   columns: array<string, array{type_class: string, nullable: bool}>,
     *   primary_key: list<string>,
     *   foreign_keys: list<array{column: string, refs_table: string, refs_column: string, on_delete: string}>,
     *   unique_constraints: list<list<string>>
     * }>
     */
    private function canonicalMysql(): array
    {
        $dsn  = (string)getenv('IPAM_MYSQL_DSN');
        $user = (string)getenv('IPAM_MYSQL_USER');
        $pass = (string)getenv('IPAM_MYSQL_PASS');
        $dbName = $this->extractDbNameFromDsn($dsn);
        $this->assertNotSame('', $dbName, 'DSN must include dbname');

        $adminDsn = preg_replace('/(^mysql:|;)dbname=[^;]+;?/', '$1', $dsn);
        $adminDsn = rtrim((string)$adminDsn, ';');
        $this->assertIsString($adminDsn);
        $admin = new PDO($adminDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $admin->exec("DROP DATABASE IF EXISTS `$dbName`");
        $admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        unset($admin);

        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $schema = file_get_contents(self::MYSQL_SCHEMA);
        $this->assertNotFalse($schema);
        $db->exec($schema);

        return $this->canonicalInformationSchema($db, $dbName);
    }

    // -------------------------------------------------------------------------
    // Canonicalisation — Postgres (gated on IPAM_PGSQL_DSN)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{
     *   columns: array<string, array{type_class: string, nullable: bool}>,
     *   primary_key: list<string>,
     *   foreign_keys: list<array{column: string, refs_table: string, refs_column: string, on_delete: string}>,
     *   unique_constraints: list<list<string>>
     * }>
     */
    private function canonicalPgsql(): array
    {
        $dsn  = (string)getenv('IPAM_PGSQL_DSN');
        $user = (string)getenv('IPAM_PGSQL_USER');
        $pass = (string)getenv('IPAM_PGSQL_PASS');
        $dbName = $this->extractDbNameFromDsn($dsn);
        $this->assertNotSame('', $dbName, 'DSN must include dbname');

        $adminDsn = (string)preg_replace('/dbname=[^;]+/', 'dbname=postgres', $dsn);
        $admin = new PDO($adminDsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $admin->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity "
            . "WHERE datname = " . $admin->quote($dbName) . " AND pid <> pg_backend_pid()"
        );
        $admin->exec("DROP DATABASE IF EXISTS \"$dbName\"");
        $admin->exec("CREATE DATABASE \"$dbName\"");
        unset($admin);

        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $schema = file_get_contents(self::PGSQL_SCHEMA);
        $this->assertNotFalse($schema);
        $db->exec($schema);

        return $this->canonicalInformationSchema($db, 'public');
    }

    /**
     * Shared information_schema walker for MySQL and Postgres. Both use
     * the same SQL-standard views; the only differences are schema-name
     * placement (database name on MySQL, 'public' on Postgres) and type
     * naming (handled by the per-engine type class mapping).
     *
     * @return array<string, array{
     *   columns: array<string, array{type_class: string, nullable: bool}>,
     *   primary_key: list<string>,
     *   foreign_keys: list<array{column: string, refs_table: string, refs_column: string, on_delete: string}>,
     *   unique_constraints: list<list<string>>
     * }>
     */
    private function canonicalInformationSchema(PDO $db, string $schemaName): array
    {
        // Tables
        $tblStmt = $db->prepare(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = :s AND table_type = 'BASE TABLE' "
            . "ORDER BY table_name"
        );
        $tblStmt->execute([':s' => $schemaName]);
        $tables = [];
        foreach ($tblStmt->fetchAll() as $row) {
            $tables[] = (string)($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
        }

        // Columns (one query for the whole schema, then partition)
        $colStmt = $db->prepare(
            "SELECT table_name, column_name, data_type, is_nullable "
            . "FROM information_schema.columns "
            . "WHERE table_schema = :s "
            . "ORDER BY table_name, ordinal_position"
        );
        $colStmt->execute([':s' => $schemaName]);
        $columnsByTable = [];
        foreach ($colStmt->fetchAll() as $row) {
            $t = (string)($row['table_name'] ?? $row['TABLE_NAME']);
            $c = (string)($row['column_name'] ?? $row['COLUMN_NAME']);
            $typeRaw = strtolower((string)($row['data_type'] ?? $row['DATA_TYPE']));
            $nullable = strtoupper((string)($row['is_nullable'] ?? $row['IS_NULLABLE'])) === 'YES';
            $columnsByTable[$t][$c] = [
                'type_class' => $this->informationSchemaTypeClass($typeRaw),
                'nullable'   => $nullable,
            ];
        }

        // Primary keys
        $pkStmt = $db->prepare(
            "SELECT k.table_name, k.column_name "
            . "FROM information_schema.table_constraints tc "
            . "JOIN information_schema.key_column_usage k "
            . "  ON tc.constraint_name = k.constraint_name "
            . " AND tc.table_schema   = k.table_schema "
            . " AND tc.table_name     = k.table_name "
            . "WHERE tc.table_schema = :s AND tc.constraint_type = 'PRIMARY KEY' "
            . "ORDER BY k.table_name, k.ordinal_position"
        );
        $pkStmt->execute([':s' => $schemaName]);
        $pkByTable = [];
        foreach ($pkStmt->fetchAll() as $row) {
            $t = (string)($row['table_name'] ?? $row['TABLE_NAME']);
            $c = (string)($row['column_name'] ?? $row['COLUMN_NAME']);
            $pkByTable[$t][] = $c;
        }

        // Unique constraints (grouped by constraint name to keep composite
        // uniques as a single entry). Excludes the implicit PK unique.
        $uqStmt = $db->prepare(
            "SELECT tc.constraint_name, k.table_name, k.column_name, k.ordinal_position "
            . "FROM information_schema.table_constraints tc "
            . "JOIN information_schema.key_column_usage k "
            . "  ON tc.constraint_name = k.constraint_name "
            . " AND tc.table_schema   = k.table_schema "
            . " AND tc.table_name     = k.table_name "
            . "WHERE tc.table_schema = :s AND tc.constraint_type = 'UNIQUE' "
            . "ORDER BY tc.constraint_name, k.ordinal_position"
        );
        $uqStmt->execute([':s' => $schemaName]);
        $uqByTable = [];
        foreach ($uqStmt->fetchAll() as $row) {
            $t = (string)($row['table_name'] ?? $row['TABLE_NAME']);
            $cn = (string)($row['constraint_name'] ?? $row['CONSTRAINT_NAME']);
            $c = (string)($row['column_name'] ?? $row['COLUMN_NAME']);
            $uqByTable[$t][$cn][] = $c;
        }

        // Foreign keys. MySQL and Postgres diverge on how
        // information_schema exposes the referenced side:
        //   - MySQL extends key_column_usage with referenced_table_name /
        //     referenced_column_name columns on the same row as the
        //     referencing column. It does NOT ship
        //     information_schema.constraint_column_usage at all.
        //   - Postgres follows the SQL standard: the referenced side lives
        //     in constraint_column_usage and must be joined via the
        //     constraint name.
        // Branch on the driver so each engine uses its native path.
        $driver = (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $fkStmt = $db->prepare(
                "SELECT k.table_name AS local_table, "
                . "       k.column_name AS local_column, "
                . "       k.referenced_table_name AS refs_table, "
                . "       k.referenced_column_name AS refs_column, "
                . "       rc.delete_rule "
                . "FROM information_schema.key_column_usage k "
                . "JOIN information_schema.referential_constraints rc "
                . "  ON rc.constraint_schema = k.constraint_schema "
                . " AND rc.constraint_name   = k.constraint_name "
                . "WHERE k.table_schema = :s AND k.referenced_table_name IS NOT NULL "
                . "ORDER BY k.table_name, k.column_name"
            );
        } else {
            $fkStmt = $db->prepare(
                "SELECT k.table_name AS local_table, "
                . "       k.column_name AS local_column, "
                . "       ccu.table_name AS refs_table, "
                . "       ccu.column_name AS refs_column, "
                . "       rc.delete_rule "
                . "FROM information_schema.referential_constraints rc "
                . "JOIN information_schema.key_column_usage k "
                . "  ON  k.constraint_schema = rc.constraint_schema "
                . " AND  k.constraint_name   = rc.constraint_name "
                . "JOIN information_schema.constraint_column_usage ccu "
                . "  ON  ccu.constraint_schema = rc.unique_constraint_schema "
                . " AND  ccu.constraint_name   = rc.unique_constraint_name "
                . "WHERE rc.constraint_schema = :s "
                . "ORDER BY k.table_name, k.column_name"
            );
        }
        $fkStmt->execute([':s' => $schemaName]);
        $fkByTable = [];
        foreach ($fkStmt->fetchAll() as $row) {
            $t = (string)($row['local_table'] ?? $row['LOCAL_TABLE']);
            $fkByTable[$t][] = [
                'column'      => (string)($row['local_column'] ?? $row['LOCAL_COLUMN']),
                'refs_table'  => (string)($row['refs_table'] ?? $row['REFS_TABLE']),
                'refs_column' => (string)($row['refs_column'] ?? $row['REFS_COLUMN']),
                'on_delete'   => $this->normaliseOnDelete((string)($row['delete_rule'] ?? $row['DELETE_RULE'])),
            ];
        }

        $out = [];
        foreach ($tables as $table) {
            $cols = $columnsByTable[$table] ?? [];
            ksort($cols);
            $pk = $pkByTable[$table] ?? [];

            $uniques = [];
            foreach ($uqByTable[$table] ?? [] as $cols2) {
                $uniques[] = $cols2;
            }
            usort($uniques, fn($a, $b) => implode(',', $a) <=> implode(',', $b));

            $fks = $fkByTable[$table] ?? [];
            usort($fks, fn($a, $b) => $a['column'] <=> $b['column']);

            $out[$table] = [
                'columns'            => $cols,
                'primary_key'        => $pk,
                'foreign_keys'       => $fks,
                'unique_constraints' => $uniques,
            ];
        }
        ksort($out);
        return $out;
    }

    private function informationSchemaTypeClass(string $dataType): string
    {
        // $dataType is lowercased information_schema.columns.data_type.
        // Fold to the same classes the SQLite extractor uses. MySQL and
        // Postgres share the same fold so the engine name is not needed.
        $binary = ['blob', 'varbinary', 'binary', 'longblob', 'mediumblob', 'tinyblob', 'bytea'];
        if (in_array($dataType, $binary, true)) {
            return 'binary';
        }
        $ints = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];
        if (in_array($dataType, $ints, true)) {
            return 'int';
        }
        $reals = ['float', 'double', 'real', 'numeric', 'decimal', 'double precision'];
        if (in_array($dataType, $reals, true)) {
            return 'real';
        }
        $texts = ['char', 'varchar', 'character varying', 'text', 'longtext', 'mediumtext', 'tinytext'];
        if (in_array($dataType, $texts, true)) {
            return 'text';
        }
        $dateLike = ['date', 'datetime', 'timestamp', 'time', 'year', 'timestamp without time zone', 'timestamp with time zone'];
        if (in_array($dataType, $dateLike, true)) {
            return 'text'; // Same fold as SQLite.
        }
        // Fallback: avoid silent passes by returning an explicit marker.
        return "unknown:$dataType";
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function normaliseOnDelete(string $action): string
    {
        $a = strtoupper(trim($action));
        if ($a === '' || $a === 'NO ACTION') {
            return 'NO ACTION';
        }
        return $a;
    }

    private function extractDbNameFromDsn(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m) === 1) {
            return $m[1];
        }
        return '';
    }

    /**
     * @param array<string, array{columns: array<string, array{type_class: string, nullable: bool}>, primary_key: list<string>, foreign_keys: list<array{column: string, refs_table: string, refs_column: string, on_delete: string}>, unique_constraints: list<list<string>>}> $a
     * @param array<string, array{columns: array<string, array{type_class: string, nullable: bool}>, primary_key: list<string>, foreign_keys: list<array{column: string, refs_table: string, refs_column: string, on_delete: string}>, unique_constraints: list<list<string>>}> $b
     */
    private function assertCanonicalShapesEqual(array $a, array $b, string $nameA, string $nameB): void
    {
        // Table set
        $ta = array_keys($a);
        $tb = array_keys($b);
        $this->assertSame(
            $ta,
            $tb,
            "Table set differs between $nameA and $nameB\n"
            . "only in $nameA: " . implode(', ', array_diff($ta, $tb)) . "\n"
            . "only in $nameB: " . implode(', ', array_diff($tb, $ta))
        );

        foreach ($ta as $table) {
            $colsA = $a[$table]['columns'];
            $colsB = $b[$table]['columns'];
            $this->assertSame(
                array_keys($colsA),
                array_keys($colsB),
                "Column set differs on `$table` between $nameA and $nameB\n"
                . "only in $nameA: " . implode(', ', array_diff(array_keys($colsA), array_keys($colsB))) . "\n"
                . "only in $nameB: " . implode(', ', array_diff(array_keys($colsB), array_keys($colsA)))
            );
            foreach ($colsA as $cName => $cA) {
                $cB = $colsB[$cName];
                $this->assertSame(
                    $cA['type_class'],
                    $cB['type_class'],
                    "`$table`.`$cName` type_class differs: $nameA=" . $cA['type_class']
                    . " vs $nameB=" . $cB['type_class']
                );
                $this->assertSame(
                    $cA['nullable'],
                    $cB['nullable'],
                    "`$table`.`$cName` nullability differs: $nameA=" . ($cA['nullable'] ? 'NULL' : 'NOT NULL')
                    . " vs $nameB=" . ($cB['nullable'] ? 'NULL' : 'NOT NULL')
                );
            }

            $this->assertSame(
                $a[$table]['primary_key'],
                $b[$table]['primary_key'],
                "`$table` primary key differs between $nameA and $nameB"
            );

            $this->assertSame(
                $a[$table]['foreign_keys'],
                $b[$table]['foreign_keys'],
                "`$table` foreign keys differ between $nameA and $nameB"
            );

            $this->assertSame(
                $a[$table]['unique_constraints'],
                $b[$table]['unique_constraints'],
                "`$table` unique constraints differ between $nameA and $nameB"
            );
        }
    }
}
