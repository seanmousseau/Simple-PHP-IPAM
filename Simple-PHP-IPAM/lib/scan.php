<?php
declare(strict_types=1);

/**
 * @module scan
 *
 * Shared scan-loop helpers extracted from scan_run.php and cron.php in
 * v3.35.0 (#1291).  Both entry-points now call these shared functions and
 * differ only in their entry-point concerns (CLI flags vs cron dispatch).
 *
 * Functions stay in the global namespace per ADR-004 Option E.
 *
 * Dependencies:
 *   lib/db.php     (ipam_dialect)
 *   lib/utils.php  (to_str, to_int)
 *   lib/audit.php  (audit)
 *   lib.php        (ipam_scan_subnet -- scanner core; already extracted to
 *                   lib.php pre-#1291)
 *
 * Locking note: cron.php holds data/cron.lock (LOCK_EX | LOCK_NB) for the
 * entire tick; scan_run.php has no lock.  Both conventions are the
 * entry-point's responsibility and are NOT moved here.
 *
 * Audit-tag note: the `(cron)` suffix in audit details was introduced in
 * #1161 (PASS-C F-S2-04) to disambiguate scheduled scans from CLI/API runs
 * in the audit log UI.  Callers pass $source='cron' | 'cli' to preserve this.
 */

// ---------------------------------------------------------------------------
// ipam_scan_select_due_subnets()
// ---------------------------------------------------------------------------

/**
 * Return every active scan_schedule row whose interval has elapsed.
 *
 * The "is due" comparison is done in PHP rather than SQL so the query stays
 * engine-agnostic — SQLite's `datetime(col, '+N minutes')` string concat and
 * the `||` operator are not portable to MySQL / Postgres.
 *
 * @param  PDO             $db
 * @param  int|null        $now  Unix timestamp to compare against (null = time()).
 *                               Injection point for unit tests; production callers
 *                               always pass null.
 * @return list<array<string, mixed>>  Due subnet rows, ordered by last_run_at ASC.
 */
function ipam_scan_select_due_subnets(PDO $db, ?int $now = null): array
{
    $nowTs = $now ?? time();

    $stmt = $db->query("
        SELECT s.id, s.cidr, ss.method, ss.tcp_port, ss.interval_minutes, ss.last_run_at
        FROM subnets s
        JOIN scan_schedules ss ON ss.subnet_id = s.id
        WHERE ss.is_active = 1
        ORDER BY ss.last_run_at ASC
    ");
    if ($stmt === false) {
        throw new \RuntimeException('ipam_scan_select_due_subnets: query failed');
    }

    /** @var list<array<string, mixed>> $candidates */
    $candidates = $stmt->fetchAll();

    $due = [];
    foreach ($candidates as $row) {
        $lastRun = $row['last_run_at'] ?? null;
        if ($lastRun === null || $lastRun === '') {
            $due[] = $row;
            continue;
        }
        $lastTs = strtotime(to_str($lastRun) . ' UTC');
        if ($lastTs === false) {
            continue;
        }
        $intervalMinutes = to_int($row['interval_minutes'] ?? 0);
        if (($nowTs - $lastTs) >= ($intervalMinutes * 60)) {
            $due[] = $row;
        }
    }

    return $due;
}

// ---------------------------------------------------------------------------
// ipam_scan_run_for_subnet()
// ---------------------------------------------------------------------------

/**
 * Execute a scan for one subnet and handle all shared bookkeeping:
 *   1. Run ipam_scan_subnet() (the scan-core function in lib.php).
 *   2. Update scan_schedules.last_run_at.
 *   3. Write a scan.run audit row when $source='cron' (with the (cron) tag
 *      mandated by #1161).  CLI runs do NOT audit here — scan_run.php outputs
 *      JSON to stdout and auditing is the caller's concern.
 *
 * @param  PDO                  $db
 * @param  array<string, mixed> $subnet      Row from ipam_scan_select_due_subnets()
 *                                           or a single-subnet fetch.  Must have:
 *                                           'id', 'method', and optionally 'tcp_port'.
 * @param  string               $source      'cron' | 'cli'  — controls audit emission.
 * @param  int                  $staleThresh Consecutive misses before stale-marking.
 * @return array<string, mixed>  Keys: scanned, up, down, stale_marked, elapsed_sec.
 */
function ipam_scan_run_for_subnet(
    PDO $db,
    array $subnet,
    string $source,
    int $staleThresh = 3
): array {
    $subnetId = to_int($subnet['id'] ?? 0);
    $method   = to_str($subnet['method'] ?? 'icmp');
    $tcpPort  = isset($subnet['tcp_port']) ? to_int($subnet['tcp_port']) : null;

    if (!in_array($method, ['icmp', 'tcp', 'both'], true)) {
        $method = 'icmp';
    }

    $start  = microtime(true);
    $stats  = ipam_scan_subnet($db, $subnetId, $method, $tcpPort, $staleThresh);
    $elapsed = round(microtime(true) - $start, 2);

    // Update last_run_at so the schedule-due calculation is correct next tick.
    $db->prepare(
        "UPDATE scan_schedules
            SET last_run_at = " . ipam_dialect()->now() . ",
                updated_at  = " . ipam_dialect()->now() . "
          WHERE subnet_id = :sid"
    )->execute([':sid' => $subnetId]);

    // Cron-driven scans emit a scan.run audit row with the (cron) tag so they
    // are distinguishable from API / CLI runs in the Audit page (#1161).
    if ($source === 'cron') {
        audit(
            $db,
            'scan.run',
            'subnet',
            $subnetId,
            sprintf(
                'method=%s scanned=%d up=%d down=%d stale_marked=%d (cron)',
                $method,
                $stats['scanned'],
                $stats['up'],
                $stats['down'],
                $stats['stale_marked']
            )
        );
    }

    return [
        'scanned'      => $stats['scanned'],
        'up'           => $stats['up'],
        'down'         => $stats['down'],
        'stale_marked' => $stats['stale_marked'],
        'elapsed_sec'  => $elapsed,
    ];
}
