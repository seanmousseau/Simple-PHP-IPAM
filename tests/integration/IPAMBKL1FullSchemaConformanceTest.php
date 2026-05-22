<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * IPAMBKL1 full-schema conformance — pulled forward from v3.28.0 for #1124.
 *
 * The column-kind classifier is suffix-based; pre-v3.27.2, columns it
 * misclassified silently dropped on dump. v3.27.2 closed the silent-drop
 * (writer throws on json_encode false) and patched two known offenders
 * (webauthn_credentials.credential_id, public_key). This test enforces
 * the schema covenant going forward.
 *
 * Two narrow contracts are tested per shipped column:
 *
 *   1. Every BLOB-declared column round-trips arbitrary binary cleanly.
 *      (Off-by-N + checksum drift = a misclassification of a column
 *      whose schema type is itself binary.)
 *
 *   2. Every TEXT-declared column on the explicit binary-content allow-
 *      list (production really does store binary there) likewise
 *      round-trips. This is the override-map covenant.
 *
 * The test deliberately does NOT try to inject binary into every TEXT
 * column. The schema convention is "TEXT means UTF-8 string" — pushing
 * non-UTF-8 into TEXT columns we don't write binary to in production
 * would test something the schema doesn't promise. The runtime writer-
 * throw guard at lib/backup.php's body loop is the safety net for any
 * future regression that introduces a new binary-in-TEXT column.
 *
 * Fail signal: a known-binary column trips json_encode or produces row-
 * count drift. Remediation: add (or correct) the override map entry in
 * ipam_logical_column_kind().
 *
 * If you add a NEW shipped column that production writes non-UTF-8 binary
 * into AND the column is not BLOB-declared, you must:
 *   (a) add an override entry in ipam_logical_column_kind(), AND
 *   (b) extend self::textColumnsHoldingBinary() below.
 * Skipping (b) makes the new column a bypass of this conformance test.
 */
final class IPAMBKL1FullSchemaConformanceTest extends TestCase
{
    /** @var array<string,array<string,true>> */
    private array $skipColumns = [
        'audit_log'            => ['id' => true],
        'schema_migrations'    => ['id' => true, 'version' => true, 'applied_at' => true],
        'sqlite_sequence'      => ['name' => true, 'seq' => true],
    ];

    /**
     * TEXT-declared columns where production actually stores non-UTF-8
     * binary. These MUST be classified 'binary' by the override map.
     * Update both this list and ipam_logical_column_kind()'s override
     * map together.
     *
     * @return array<string,bool>
     */
    private static function textColumnsHoldingBinary(): array
    {
        return [
            // WebAuthn COSE-encoded public key. Schema declares TEXT but the
            // bytes lbuchs/webauthn writes are raw COSE; non-UTF-8.
            'webauthn_credentials.public_key' => true,
        ];
    }

    /** @return array<string,array{0:string,1:string,2:string}> */
    public static function tablesAndColumns(): array
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql'));
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);

        $tables = $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' " .
            "AND name NOT LIKE 'sqlite_%' AND name != 'schema_migrations' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $textBinary = self::textColumnsHoldingBinary();
        $cases = [];
        foreach ($tables as $table) {
            $cols = $db->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $col) {
                $name = (string) $col['name'];
                $type = strtoupper((string) $col['type']);
                $key  = $table . '.' . $name;
                $isBlobDecl = in_array($type, ['BLOB', 'VARBINARY', 'BYTEA'], true);
                $isTextHoldingBinary = $type === 'TEXT' && isset($textBinary[$key]);
                if (!$isBlobDecl && !$isTextHoldingBinary) continue;
                $kind = ipam_logical_column_kind($name, (string) $table);
                $cases[$key] = [(string) $table, $name, $kind];
            }
        }
        return $cases;
    }

    /**
     * @dataProvider tablesAndColumns
     */
    public function testColumnRoundTripsNonUtf8Binary(string $table, string $column, string $kind): void
    {
        if (isset($this->skipColumns[$table][$column])) {
            $this->markTestSkipped("$table.$column is auto-managed, skipped");
        }

        $db = $this->freshDb();
        $userId = $this->ensureFkAnchorsForTable($db, $table);

        $colMeta = $db->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_ASSOC);
        $payloads = [];
        $bin = "\x00\x01\xff\xfe" . str_repeat("\xc3\x28", 16);  // documented binary trap
        foreach ($colMeta as $cm) {
            $cn = (string) $cm['name'];
            $isPk = (int)($cm['pk'] ?? 0) > 0;
            if ($isPk && stripos((string)$cm['type'], 'INTEGER') !== false) continue;
            if ($cn === $column) {
                $payloads[$cn] = $bin;
                continue;
            }
            $payloads[$cn] = $this->safeDefault($table, $cn, $cm, $userId);
        }

        // Index column types so we can bind BLOB columns as LOB (otherwise
        // SQLite's loose affinity may coerce a binary placeholder string
        // into integer-typed storage on read-back).
        $colTypes = [];
        foreach ($colMeta as $cm) {
            $colTypes[(string) $cm['name']] = strtoupper((string) $cm['type']);
        }

        $cols = array_keys($payloads);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = "INSERT INTO \"$table\" (" . implode(',', array_map(fn($c) => "\"$c\"", $cols)) . ") VALUES (" . implode(',', $placeholders) . ")";
        $st = $db->prepare($sql);
        foreach ($cols as $c) {
            $param = ':' . $c;
            $val = $payloads[$c];
            $isBlobDecl = in_array($colTypes[$c] ?? '', ['BLOB', 'VARBINARY', 'BYTEA'], true);
            if ($c === $column || ($isBlobDecl && is_string($val))) {
                $st->bindValue($param, $val, PDO::PARAM_LOB);
            } elseif ($val === null) {
                $st->bindValue($param, null, PDO::PARAM_NULL);
            } elseif (is_int($val) || is_bool($val)) {
                $st->bindValue($param, (int) $val, PDO::PARAM_INT);
            } else {
                $st->bindValue($param, (string) $val);
            }
        }
        // CodeRabbit, PR #1125: do not silently skip on insert pre-condition
        // failure. A future schema change adding a NOT NULL or FK requirement
        // that safeDefault()/ensureFkAnchorsForTable() doesn't cover would
        // quietly remove that column from supposed full-schema coverage.
        // Fail hard with the offending column and PDOException context so
        // the maintainer must update the test fixture or extend the explicit
        // allowlist below before the column re-enters coverage.
        try {
            $st->execute();
        } catch (PDOException $e) {
            $this->fail(
                "$table.$column: insert pre-condition failed (extend safeDefault() / ensureFkAnchorsForTable() to cover this column's schema requirements). " .
                "PDOException: " . $e->getMessage()
            );
        }

        $fixture = tempnam(sys_get_temp_dir(), 'ipambkl1_conf_');
        $this->assertNotFalse($fixture);

        try {
            ipam_backup_logical_dump($db, $fixture);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'json_encode failed')) {
                $this->fail(
                    "$table.$column: writer json_encode failed on non-UTF-8 binary. " .
                    "Add an override entry: 'ipam_logical_column_kind() => $table.$column => binary'. " .
                    "Underlying: $msg"
                );
            }
            throw $e;
        }

        $dryRun = ipam_restore_logical_dry_run($db, $fixture);
        $warnings = $dryRun['warnings'] ?? [];
        $relevant = array_filter((array) $warnings, static fn($w) =>
            is_string($w) && (
                str_contains($w, 'Body checksum does not match footer') ||
                str_contains($w, 'disagrees with footer total_rows')
            )
        );
        $this->assertSame([], array_values($relevant),
            "$table.$column dump+dry-run must produce no checksum or row-count drift");
    }

    /**
     * Runtime safety net (#1124, v3.27.2): if a future change introduces a
     * new TEXT column that production writes binary into and the author
     * forgets to add an override entry, the writer must throw a clear
     * exception naming the offending table+column — never silently drop
     * the row. This test simulates that scenario by writing binary into
     * a known scalar TEXT column (audit_log.details — a free-text column
     * production never writes binary into) and asserting the throw.
     */
    public function testWriterThrowsOnUnclassifiedBinaryTextColumn(): void
    {
        $db = $this->freshDb();
        $db->exec("INSERT INTO users (username, password_hash, role, is_active) VALUES ('throw-test', '!disabled', 'admin', 1)");
        // Insert a row whose `details` column carries non-UTF-8 binary. We
        // pin the row's primary key explicitly so the test can assert that
        // the writer-throw exception names it — protecting the row-level-
        // context contract against silent regressions (CodeRabbit, PR #1125).
        $bin = "\x00\x01\xff\xfe" . str_repeat("\xc3\x28", 16);
        $offendingPk = 4242;
        $st = $db->prepare("INSERT INTO audit_log (id, action, entity_type, entity_id, user_id, username, details, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->bindValue(1, $offendingPk, PDO::PARAM_INT);
        $st->bindValue(2, 'synthetic.binary');
        $st->bindValue(3, 'test');
        $st->bindValue(4, 1, PDO::PARAM_INT);
        $st->bindValue(5, 1, PDO::PARAM_INT);
        $st->bindValue(6, 'throw-test');
        $st->bindValue(7, $bin, PDO::PARAM_LOB);
        $st->bindValue(8, '127.0.0.1');
        $st->bindValue(9, 'curl/8');
        $st->execute();

        $fixture = tempnam(sys_get_temp_dir(), 'ipambkl1_throw_');
        $this->assertNotFalse($fixture);
        try {
            ipam_backup_logical_dump($db, $fixture);
            $this->fail('Writer must throw on json_encode failure for unclassified binary TEXT column');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('json_encode failed', $msg,
                'Writer-throw must name the json_encode failure mode so operators can attribute it');
            $this->assertStringContainsString('audit_log', $msg,
                'Writer-throw must name the offending table');
            $this->assertStringContainsString((string) $offendingPk, $msg,
                'Writer-throw must include the offending row PK so operators can locate the row in the live DB');
            $this->assertStringContainsString('override map', $msg,
                'Writer-throw must point operators at the remediation (override map)');
        }
    }

    private function freshDb(): PDO
    {
        ipam_setting_cache_bust(null);
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql'));
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        $GLOBALS['db'] = $db;
        return $db;
    }

    private function ensureFkAnchorsForTable(PDO $db, string $table): int
    {
        $db->exec("INSERT INTO users (username, password_hash, role, is_active) VALUES ('conf-anchor', '!disabled', 'admin', 1)");
        $userId = (int) $db->lastInsertId();

        $needs = [
            'addresses'             => ['subnet'],
            'address_tags'          => ['address', 'tag'],
            'address_history'       => ['address', 'subnet'],
            'alert_state'           => ['subnet'],
            'backup_runs'           => ['destination'],
            'backup_schedules'      => ['destination'],
            'device_interfaces'     => ['device'],
            'pd_delegations'        => ['pdpool'],
            'pd_pools'              => ['v6subnet'],
            'scan_results'          => ['subnet'],
            'scan_schedules'        => ['subnet'],
            'site_contacts'         => ['site', 'contact'],
            'subnet_contacts'       => ['subnet', 'contact'],
            'subnet_tags'           => ['subnet', 'tag'],
            'utilization_snapshots' => ['subnet'],
            'webhook_deliveries'    => ['webhook'],
        ];
        foreach (($needs[$table] ?? []) as $what) {
            switch ($what) {
                case 'subnet':
                    $db->exec("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix) VALUES ('10.0.0.0/24', 4, '10.0.0.0', x'0a000000', 24)");
                    break;
                case 'v6subnet':
                    $db->exec("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix) VALUES ('2001:db8::/32', 6, '2001:db8::', x'20010db8000000000000000000000000', 32)");
                    break;
                case 'address':
                    $db->exec("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix) VALUES ('10.0.0.0/24', 4, '10.0.0.0', x'0a000000', 24)");
                    $db->exec("INSERT INTO addresses (subnet_id, ip, ip_bin, status) VALUES (1, '10.0.0.1', x'0a000001', 'used')");
                    break;
                case 'tag':
                    $db->exec("INSERT INTO tags (name, colour) VALUES ('conf-tag', '#888')");
                    break;
                case 'destination':
                    $db->exec("INSERT INTO backup_destinations (name, type, config, encrypt) VALUES ('conf-dest', 'local', '{}', 0)");
                    break;
                case 'device':
                    $db->exec("INSERT INTO devices (name, type) VALUES ('conf-dev', 'router')");
                    break;
                case 'pdpool':
                    $db->exec("INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix) VALUES ('2001:db8::/32', 6, '2001:db8::', x'20010db8000000000000000000000000', 32)");
                    $db->exec("INSERT INTO pd_pools (parent_subnet_id, delegation_prefix) VALUES (1, 56)");
                    break;
                case 'site':
                    $db->exec("INSERT INTO sites (name) VALUES ('conf-site')");
                    break;
                case 'contact':
                    $db->exec("INSERT INTO contacts (name) VALUES ('conf-contact')");
                    break;
                case 'webhook':
                    $db->exec("INSERT INTO webhooks (name, url, secret, events, is_active) VALUES ('conf-hook', 'https://example.org/hook', 's', '[]', 1)");
                    break;
            }
        }
        return $userId;
    }

    private function safeDefault(string $table, string $column, array $cm, int $userId): mixed
    {
        $name = (string) $cm['name'];
        $type = strtoupper((string) $cm['type']);
        $notNull = (int)($cm['notnull'] ?? 0) === 1;
        // Nullable + no special override → null is fine.
        if (!$notNull) return null;

        // NOT NULL: must bind a schema-coherent value regardless of default.
        // Type comes BEFORE the _id-suffix heuristic — webauthn_credentials.
        // credential_id ends with _id but is BLOB-declared, so the suffix
        // would falsely classify it as an integer FK.
        if (in_array($type, ['BLOB', 'VARBINARY', 'BYTEA'], true)) return "\xde\xad\xbe\xef";
        if (str_contains($type, 'INT')) {
            if (str_ends_with($name, '_id') && $name !== 'tenant_id') {
                return $name === 'user_id' ? $userId : 1;
            }
            return 0;
        }
        // Timestamps: classifier expects ipam_logical_normalise_timestamp-
        // compatible format. Use ISO-8601 UTC.
        if (str_ends_with($name, '_at')) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        switch ("$table.$column") {
            case 'subnets.cidr':           return '10.99.0.0/24';
            case 'subnets.network':        return '10.99.0.0';
            case 'subnets.network_bin':    return "\x0a\x63\x00\x00";
            case 'addresses.ip':           return '10.99.0.1';
            case 'addresses.ip_bin':       return "\x0a\x63\x00\x01";
            case 'addresses.status':       return 'used';
            case 'aggregates.cidr':        return '10.99.0.0/24';
            case 'aggregates.network':     return '10.99.0.0';
            case 'aggregates.network_bin': return "\x0a\x63\x00\x00";
            case 'pd_delegations.cidr':    return '2001:db8:1::/56';
            case 'webhooks.events':        return '[]';
        }
        return 'placeholder';
    }
}
