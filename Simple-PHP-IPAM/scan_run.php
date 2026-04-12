<?php
declare(strict_types=1);
/**
 * scan_run.php — CLI network scanner
 *
 * Usage:
 *   php scan_run.php --all                  Scan all subnets with an active schedule due to run
 *   php scan_run.php --subnet-id=N          Scan a specific subnet immediately
 *   php scan_run.php --all --dry-run        Print what would be scanned, do not run
 *   php scan_run.php --subnet-id=N --method=icmp --port=22 --dry-run
 *
 * Options:
 *   --all             Scan all subnets whose active schedule is due
 *   --subnet-id=N     Scan one specific subnet regardless of schedule
 *   --method=icmp|tcp|both  Override scan method (default: use scheduled method or 'icmp')
 *   --port=N          Override TCP port
 *   --dry-run         Print what would run without actually scanning
 *   --stale-threshold=N  Consecutive misses before marking stale (default: 3)
 *
 * Designed for cron:
 *   *\/15 * * * * php /path/to/Simple-PHP-IPAM/scan_run.php --all >> /var/log/ipam-scan.log 2>&1
 *
 * Outputs one JSON object per scanned subnet to stdout, then a summary object.
 */

// Reject web requests
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "403 Forbidden — this script must be run from the command line.\n";
    exit(1);
}

$scriptDir = __DIR__;
require $scriptDir . '/config.php';
/** @var array<string, mixed> $config */
require $scriptDir . '/lib.php';

// ---------------------------------------------------------------------------
// Parse CLI arguments
// ---------------------------------------------------------------------------
$opts = getopt('', ['all', 'subnet-id:', 'method:', 'port:', 'dry-run', 'stale-threshold:']);
if ($opts === false) {
    fwrite(STDERR, "Error: could not parse arguments.\n");
    exit(1);
}

$runAll         = array_key_exists('all', $opts);
$specificId     = isset($opts['subnet-id'])       ? to_int(is_array($opts['subnet-id'])       ? $opts['subnet-id'][0]       : $opts['subnet-id'])       : 0;
$methodOverride = isset($opts['method'])          ? to_str(is_array($opts['method'])          ? $opts['method'][0]          : $opts['method'])          : null;
$portOverride   = isset($opts['port'])            ? to_int(is_array($opts['port'])            ? $opts['port'][0]            : $opts['port'])            : null;
$dryRun         = array_key_exists('dry-run', $opts);
$staleThresh    = isset($opts['stale-threshold']) ? max(1, to_int(is_array($opts['stale-threshold']) ? $opts['stale-threshold'][0] : $opts['stale-threshold'])) : 3;

if (!$runAll && $specificId <= 0) {
    fwrite(STDERR, "Usage: php scan_run.php --all | --subnet-id=N [--method=icmp|tcp|both] [--port=N] [--dry-run]\n");
    exit(1);
}

if ($methodOverride !== null && !in_array($methodOverride, ['icmp', 'tcp', 'both'], true)) {
    fwrite(STDERR, "Error: --method must be icmp, tcp, or both.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Open the database
// ---------------------------------------------------------------------------
$dbPath = $scriptDir . '/data/ipam.sqlite';
if (!file_exists($dbPath)) {
    fwrite(STDERR, "Error: database not found at $dbPath\n");
    exit(1);
}
$db = ipam_db($dbPath);

// ---------------------------------------------------------------------------
// Gather subnets to scan
// ---------------------------------------------------------------------------
/** @var list<array<string, mixed>> $toScan */
$toScan = [];

if ($specificId > 0) {
    // One specific subnet — fetch its schedule if present for defaults
    $st = $db->prepare("
        SELECT s.id, s.cidr, s.description, s.ip_version,
               ss.method, ss.tcp_port, ss.interval_minutes, ss.is_active
        FROM subnets s
        LEFT JOIN scan_schedules ss ON ss.subnet_id = s.id
        WHERE s.id = :id
    ");
    $st->execute([':id' => $specificId]);
    $row = $st->fetch();
    if (!$row) {
        fwrite(STDERR, "Error: subnet ID $specificId not found.\n");
        exit(1);
    }
    $toScan[] = $row;
} else {
    // All subnets with an active schedule that is due
    $st = $db->prepare("
        SELECT s.id, s.cidr, s.description, s.ip_version,
               ss.method, ss.tcp_port, ss.interval_minutes, ss.is_active
        FROM subnets s
        JOIN scan_schedules ss ON ss.subnet_id = s.id
        WHERE ss.is_active = 1
          AND (ss.last_run_at IS NULL
               OR datetime(ss.last_run_at, '+' || ss.interval_minutes || ' minutes') <= datetime('now'))
        ORDER BY ss.last_run_at ASC
    ");
    $st->execute();
    $toScan = $st->fetchAll();
}

if (count($toScan) === 0) {
    echo json_encode(['status' => 'no_work', 'message' => 'No subnets due for scanning.', 'ts' => date('c')]) . "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Run scans
// ---------------------------------------------------------------------------
$summary = ['scanned_subnets' => 0, 'total_hosts' => 0, 'total_up' => 0, 'total_down' => 0, 'total_stale_marked' => 0];
$updateLastRun = $db->prepare("UPDATE scan_schedules SET last_run_at = datetime('now'), updated_at = datetime('now') WHERE subnet_id = :sid");

foreach ($toScan as $subnet) {
    $subnetId   = (int) $subnet['id'];
    $cidr       = is_string($subnet['cidr']) ? $subnet['cidr'] : '';
    $method     = $methodOverride ?? (is_string($subnet['method']) ? $subnet['method'] : 'icmp');
    $tcpPort    = $portOverride   ?? (isset($subnet['tcp_port']) ? (int) $subnet['tcp_port'] : null);
    if (!in_array($method, ['icmp', 'tcp', 'both'], true)) $method = 'icmp';

    if ($dryRun) {
        echo json_encode([
            'dry_run'    => true,
            'subnet_id'  => $subnetId,
            'cidr'       => $cidr,
            'method'     => $method,
            'tcp_port'   => $tcpPort,
        ]) . "\n";
        continue;
    }

    $start  = microtime(true);
    $stats  = ipam_scan_subnet($db, $subnetId, $method, $tcpPort);
    $elapsed = round(microtime(true) - $start, 2);

    // Update last_run_at if there's a schedule row
    $updateLastRun->execute([':sid' => $subnetId]);

    $result = [
        'subnet_id'    => $subnetId,
        'cidr'         => $cidr,
        'method'       => $method,
        'tcp_port'     => $tcpPort,
        'scanned'      => $stats['scanned'],
        'up'           => $stats['up'],
        'down'         => $stats['down'],
        'stale_marked' => $stats['stale_marked'],
        'elapsed_sec'  => $elapsed,
        'ts'           => date('c'),
    ];
    echo json_encode($result) . "\n";

    $summary['scanned_subnets']++;
    $summary['total_hosts']       += $stats['scanned'];
    $summary['total_up']          += $stats['up'];
    $summary['total_down']        += $stats['down'];
    $summary['total_stale_marked'] += $stats['stale_marked'];
}

if (!$dryRun) {
    $summary['status'] = 'done';
    $summary['ts']     = date('c');
    echo json_encode($summary) . "\n";
}

exit(0);
