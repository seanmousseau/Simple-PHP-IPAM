<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden — this script must be run from the command line.\n";
    exit(1);
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/dialects/Dialect.php';
require_once __DIR__ . '/dialects/SqliteDialect.php';
require_once __DIR__ . '/dialects/MysqlDialect.php';
require_once __DIR__ . '/dialects/PgsqlDialect.php';

$COPY_ORDER = [
    'users', 'sites', 'vrfs', 'vlans', 'vlan_ranges', 'contacts',
    'subnets', 'addresses', 'subnet_tags', 'address_tags',
    'site_contacts', 'subnet_contacts',
    'address_history', 'alert_state', 'api_keys',
    'scan_schedules', 'scan_results',
    'login_attempts', 'settings', 'schema_migrations',
];

$APPEND_ONLY_TABLES = ['audit_log'];

$BINARY_COLUMNS = [
    'subnets'    => ['network_bin'],
    'addresses'  => ['ip_bin'],
    'aggregates' => ['network_bin'],
];

function usage(): void
{
    fwrite(STDERR, <<<'USAGE'
Usage: php migrate_db.php --from=<driver> --from-dsn=<dsn> [--from-user=X --from-pass=Y]
                          --to=<driver>   --to-dsn=<dsn>   [--to-user=X   --to-pass=Y]
                          [--force] [--batch-size=1000] [--dry-run]

Drivers: sqlite, mysql, pgsql
Supports all 6 direction pairs: sqlite↔mysql, sqlite↔pgsql, mysql↔pgsql

USAGE);
    exit(2);
}

$opts = getopt('', [
    'from:', 'from-dsn:', 'from-user::', 'from-pass::',
    'to:',   'to-dsn:',   'to-user::',   'to-pass::',
    'force', 'batch-size::', 'dry-run',
]);

$fromDriver = to_str($opts['from'] ?? '');
$fromDsn    = to_str($opts['from-dsn'] ?? '');
$fromUser   = to_str($opts['from-user'] ?? '');
$fromPass   = to_str($opts['from-pass'] ?? '');
$toDriver   = to_str($opts['to'] ?? '');
$toDsn      = to_str($opts['to-dsn'] ?? '');
$toUser     = to_str($opts['to-user'] ?? '');
$toPass     = to_str($opts['to-pass'] ?? '');
$force      = isset($opts['force']);
$batchSize  = max(1, to_int($opts['batch-size'] ?? 1000));
$dryRun     = isset($opts['dry-run']);

if (!in_array($fromDriver, ['sqlite', 'mysql', 'pgsql'], true)
    || !in_array($toDriver, ['sqlite', 'mysql', 'pgsql'], true)
    || $fromDsn === '' || $toDsn === '') {
    usage();
}

function connect(string $driver, string $dsn, string $user, string $pass): PDO
{
    $pdo = new PDO($dsn, $user ?: null, $pass ?: null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 10000');
    }
    return $pdo;
}

function dialect_for(string $driver): Dialect
{
    return match ($driver) {
        'mysql' => new MysqlDialect(),
        'pgsql' => new PgsqlDialect(),
        default => new SqliteDialect(),
    };
}

function table_exists(PDO $db, string $driver, string $table): bool
{
    if ($driver === 'sqlite') {
        $st = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='" . $table . "'");
        return $st !== false && (bool)$st->fetch();
    }
    if ($driver === 'mysql') {
        $st = $db->query("SHOW TABLES LIKE '" . $table . "'");
        return $st !== false && (bool)$st->fetch();
    }
    $st = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_name = :t AND table_schema = current_schema()");
    $st->execute([':t' => $table]);
    return (bool)$st->fetch();
}

function row_count(PDO $db, string $table): int
{
    $st = $db->query("SELECT COUNT(*) FROM \"{$table}\"");
    return $st !== false ? (int)$st->fetchColumn() : 0;
}

$info = function (string $msg): void { fwrite(STDOUT, $msg . "\n"); };
$err  = function (string $msg): void { fwrite(STDERR, "ERROR: " . $msg . "\n"); };

$info("migrate_db.php — Simple PHP IPAM v" . IPAM_VERSION);
$info("From: {$fromDriver} ({$fromDsn})");
$info("To:   {$toDriver} ({$toDsn})");
if ($dryRun) $info("DRY RUN — no writes to target");
$info('');

try {
    $srcDb = connect($fromDriver, $fromDsn, $fromUser, $fromPass);
} catch (\Throwable $e) {
    $err("Cannot connect to source: " . $e->getMessage());
    exit(1);
}

try {
    $dstDb = connect($toDriver, $toDsn, $toUser, $toPass);
} catch (\Throwable $e) {
    $err("Cannot connect to target: " . $e->getMessage());
    exit(1);
}

if ($fromDriver === 'sqlite') {
    $walFile = str_replace('sqlite:', '', $fromDsn) . '-wal';
    if (is_file($walFile) && filesize($walFile) > 0) {
        $err("Source SQLite WAL file is non-empty ({$walFile}). Stop Apache first.");
        exit(1);
    }
}

if (!$force) {
    $checkTables = ['subnets', 'addresses', 'users'];
    foreach ($checkTables as $ct) {
        if (table_exists($dstDb, $toDriver, $ct) && row_count($dstDb, $ct) > 0) {
            $err("Target has data in '{$ct}'. Use --force to overwrite.");
            exit(1);
        }
    }
}

$dstDialect = dialect_for($toDriver);

$allTables = array_merge($COPY_ORDER, $APPEND_ONLY_TABLES);
$srcCounts = [];
$dstCounts = [];

foreach ($allTables as $table) {
    if (!table_exists($srcDb, $fromDriver, $table)) {
        $info("SKIP {$table} (not in source)");
        continue;
    }

    $srcCount = row_count($srcDb, $table);
    $srcCounts[$table] = $srcCount;

    if ($srcCount === 0) {
        $info("SKIP {$table} (0 rows)");
        $dstCounts[$table] = 0;
        continue;
    }

    $info("COPY {$table}: {$srcCount} rows ...");

    $binCols = $BINARY_COLUMNS[$table] ?? [];

    $colSt = $srcDb->query("SELECT * FROM \"{$table}\" LIMIT 0");
    if ($colSt === false) continue;
    $colCount = $colSt->columnCount();
    $columns = [];
    for ($i = 0; $i < $colCount; $i++) {
        $meta = $colSt->getColumnMeta($i);
        if ($meta !== false) {
            $columns[] = to_str($meta['name']);
        }
    }
    if (!$columns) {
        $info("  WARNING: could not read column metadata for {$table}, skipping");
        continue;
    }

    $quotedCols = array_map(fn($c) => '"' . $c . '"', $columns);
    $placeholders = array_map(fn($c) => ':' . $c, $columns);

    $selectSql = "SELECT " . implode(', ', $quotedCols) . " FROM \"{$table}\"";
    $insertSql = "INSERT INTO \"{$table}\" (" . implode(', ', $quotedCols) . ") VALUES (" . implode(', ', $placeholders) . ")";

    if ($dryRun) {
        $info("  DRY RUN: would copy {$srcCount} rows");
        $dstCounts[$table] = $srcCount;
        continue;
    }

    if ($force && table_exists($dstDb, $toDriver, $table)) {
        $fkOff = $dstDialect->pragma_foreign_keys(false);
        if ($fkOff !== null) $dstDb->exec($fkOff);
        $dstDb->exec("DELETE FROM \"{$table}\"");
        $fkOn = $dstDialect->pragma_foreign_keys(true);
        if ($fkOn !== null) $dstDb->exec($fkOn);
    }

    $readSt = $srcDb->prepare($selectSql);
    $readSt->execute();

    $writeSt = $dstDb->prepare($insertSql);
    $copied = 0;
    $dstDb->beginTransaction();

    while ($row = $readSt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) break;
        foreach ($columns as $col) {
            $val = $row[$col] ?? null;
            if (in_array($col, $binCols, true) && is_string($val)) {
                $writeSt->bindValue(':' . $col, $val, PDO::PARAM_LOB);
            } else {
                $writeSt->bindValue(':' . $col, $val);
            }
        }
        $writeSt->execute();
        $copied++;

        if ($copied % $batchSize === 0) {
            $dstDb->commit();
            $dstDb->beginTransaction();
            fwrite(STDERR, "  {$copied}/{$srcCount}\r");
        }
    }

    if ($dstDb->inTransaction()) $dstDb->commit();
    $info("  copied {$copied} rows");
    $dstCounts[$table] = $copied;
}

if (!$dryRun) {
    $info('');
    $info('Creating audit_log append-only triggers on target ...');
    $triggers = $dstDialect->append_only_trigger('audit_log');
    foreach ($triggers as $sql) {
        $dstDb->exec($sql);
    }
    $info('  done');
}

$info('');
$info('--- Verification ---');
$mismatch = false;
foreach ($allTables as $table) {
    if (!isset($srcCounts[$table])) continue;
    $src = $srcCounts[$table];
    $dst = $dryRun ? ($dstCounts[$table] ?? 0) : (table_exists($dstDb, $toDriver, $table) ? row_count($dstDb, $table) : 0);
    $ok = $src === $dst;
    $status = $ok ? 'OK' : 'MISMATCH';
    $info(sprintf("  %-25s src=%-6d dst=%-6d %s", $table, $src, $dst, $status));
    if (!$ok) $mismatch = true;
}

if ($mismatch && !$dryRun) {
    $err("Row count mismatch detected! Target may be incomplete.");
    exit(2);
}

if (!$dryRun) {
    $info('');
    $info('=== Migration complete ===');
    $info('');
    $info('Next steps:');
    $info("  1. Update config.php to point at the new database:");
    $info("       'db_driver' => '{$toDriver}',");
    $info("       'db_dsn'    => '{$toDsn}',");
    if ($toUser !== '') $info("       'db_user'   => '{$toUser}',");
    if ($toPass !== '') $info("       'db_pass'   => '***',");
    $info("  2. Restart Apache / PHP-FPM");
    $info("  3. Verify admin login works");
    $info("  4. Run: php migrate.php   (should be a no-op)");
} else {
    $info('');
    $info('Dry run complete. No data was written to the target.');
}

exit(0);
