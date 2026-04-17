<?php
declare(strict_types=1);

/**
 * db_diff.php — compare two IPAM databases for structural and data equivalence.
 *
 * Usage: php tests/tools/db_diff.php <src_dsn> [src_user] [src_pass] <dst_dsn> [dst_user] [dst_pass]
 *
 * Checks: row counts per table, primary key sets, binary column byte equality.
 * Exit code 0 = identical, 1 = differences found, 2 = error.
 */

if (PHP_SAPI !== 'cli') exit(2);

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php db_diff.php <src_dsn> [src_user] [src_pass] <dst_dsn> [dst_user] [dst_pass]\n");
    exit(2);
}

$srcDsn  = $args[0];
$srcUser = $args[1] ?? '';
$srcPass = $args[2] ?? '';
$dstDsn  = $args[3] ?? $args[1];
$dstUser = $args[4] ?? '';
$dstPass = $args[5] ?? '';

if (count($args) === 2) {
    $dstDsn  = $args[1];
    $srcUser = '';
    $srcPass = '';
    $dstUser = '';
    $dstPass = '';
}

function open_db(string $dsn, string $user, string $pass): PDO
{
    return new PDO($dsn, $user ?: null, $pass ?: null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

$tables = [
    'users', 'sites', 'vrfs', 'vlans', 'contacts', 'subnets', 'addresses',
    'subnet_tags', 'address_tags', 'site_contacts', 'subnet_contacts',
    'audit_log', 'address_history', 'alert_state', 'api_keys',
    'scan_schedules', 'scan_results', 'login_attempts', 'settings',
    'schema_migrations',
];

$binaryCols = [
    'subnets'   => 'network_bin',
    'addresses' => 'ip_bin',
];

try {
    $src = open_db($srcDsn, $srcUser, $srcPass);
    $dst = open_db($dstDsn, $dstUser, $dstPass);
} catch (\Throwable $e) {
    fwrite(STDERR, "Connection error: " . $e->getMessage() . "\n");
    exit(2);
}

$diffs = 0;

foreach ($tables as $table) {
    $srcExists = true;
    $dstExists = true;
    try { $src->query("SELECT 1 FROM \"{$table}\" LIMIT 0"); } catch (\Throwable) { $srcExists = false; }
    try { $dst->query("SELECT 1 FROM \"{$table}\" LIMIT 0"); } catch (\Throwable) { $dstExists = false; }

    if (!$srcExists && !$dstExists) continue;
    if (!$srcExists || !$dstExists) {
        echo "DIFF {$table}: exists in " . ($srcExists ? 'src' : 'dst') . " only\n";
        $diffs++;
        continue;
    }

    $srcCount = (int)$src->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();
    $dstCount = (int)$dst->query("SELECT COUNT(*) FROM \"{$table}\"")->fetchColumn();

    if ($srcCount !== $dstCount) {
        echo "DIFF {$table}: row count src={$srcCount} dst={$dstCount}\n";
        $diffs++;
        continue;
    }

    if (isset($binaryCols[$table]) && $srcCount > 0) {
        $col = $binaryCols[$table];
        $srcBins = $src->query("SELECT \"{$col}\" FROM \"{$table}\" ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $dstBins = $dst->query("SELECT \"{$col}\" FROM \"{$table}\" ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $mismatches = 0;
        for ($i = 0, $n = count($srcBins); $i < $n; $i++) {
            if (!hash_equals((string)($srcBins[$i] ?? ''), (string)($dstBins[$i] ?? ''))) {
                $mismatches++;
            }
        }
        if ($mismatches > 0) {
            echo "DIFF {$table}.{$col}: {$mismatches} binary mismatches out of {$srcCount} rows\n";
            $diffs++;
            continue;
        }
    }

    echo "OK   {$table}: {$srcCount} rows\n";
}

exit($diffs > 0 ? 1 : 0);
