<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$includeTrend = !empty($_GET['include_trend']);
$trendDays    = max(1, min(365, to_int($_GET['trend_days'] ?? 30)));

$filename = safe_export_filename('ipam-subnet-utilization');
csv_download_headers($filename);

$header = ['cidr', 'description', 'site', 'ip_version', 'vlan_id', 'used', 'reserved', 'free', 'total', 'utilization_pct'];
if ($includeTrend) {
    $header[] = 'used_' . $trendDays . 'd_ago';
    $header[] = 'free_' . $trendDays . 'd_ago';
    $header[] = 'delta_used';
    $header[] = 'delta_pct';
}
csv_out($header);

$sql = "
    SELECT s.id AS subnet_id, s.cidr, s.description, s.ip_version, s.prefix, s.vlan_id,
           COALESCE(si.name, '') AS site_name,
           COALESCE(SUM(CASE WHEN a.status = 'used'     THEN 1 ELSE 0 END), 0) AS used_count,
           COALESCE(SUM(CASE WHEN a.status = 'reserved' THEN 1 ELSE 0 END), 0) AS reserved_count,
           COALESCE(SUM(CASE WHEN a.status = 'free'     THEN 1 ELSE 0 END), 0) AS free_count,
           COUNT(a.id) AS total_count
    FROM subnets s
    LEFT JOIN sites si ON si.id = s.site_id
    LEFT JOIN addresses a ON a.subnet_id = s.id
    GROUP BY s.id, si.name
    ORDER BY s.ip_version, s.network_bin
";

$st = $db->query($sql);
if ($st === false) exit;

/** @var array<string, array<string, mixed>> $snapCache */
$snapCache = [];

if ($includeTrend) {
    // Batch-load the closest snapshot per subnet at or before the cutoff date
    $cutoff    = gmdate('Y-m-d H:i:s', time() - $trendDays * 86400);
    $snapQuery = $db->prepare(
        "SELECT snap.subnet_id, snap.used_count AS snap_used, snap.free_count AS snap_free,
                snap.total_hosts AS snap_total
           FROM utilization_snapshots snap
          WHERE snap.snapped_at = (
              SELECT MAX(s2.snapped_at)
                FROM utilization_snapshots s2
               WHERE s2.subnet_id = snap.subnet_id
                 AND s2.snapped_at <= :cutoff
          )"
    );
    $snapQuery->execute([':cutoff' => $cutoff]);
    foreach ($snapQuery->fetchAll() as $sr) {
        $snapCache[to_str($sr['subnet_id'])] = $sr;
    }
}

foreach ($st->fetchAll() as $r) {
    $used     = to_int($r['used_count']);
    $reserved = to_int($r['reserved_count']);
    $free     = to_int($r['free_count']);
    $total    = to_int($r['total_count']);
    $prefix   = to_int($r['prefix']);
    $ipVer    = to_int($r['ip_version']);

    if ($ipVer === 4) {
        $rawHosts = to_int(2 ** (32 - $prefix));
        $capacity = $prefix >= 31 ? $rawHosts : max(1, $rawHosts - 2);
        $pct      = round(($used + $reserved) / $capacity * 100, 2);
    } else {
        $pct = 0.0;
    }

    $row = [
        to_str($r['cidr']),
        to_str($r['description']),
        to_str($r['site_name']),
        to_str($r['ip_version']),
        $r['vlan_id'] !== null ? to_str($r['vlan_id']) : '',
        (string)$used,
        (string)$reserved,
        (string)$free,
        (string)$total,
        number_format($pct, 2),
    ];

    if ($includeTrend) {
        $sid  = to_str($r['subnet_id']);
        $snap = $snapCache[$sid] ?? null;
        if ($snap !== null) {
            $snapUsed  = to_int($snap['snap_used']);
            $snapFree  = to_int($snap['snap_free']);
            $snapTotal = to_int($snap['snap_total']);
            $deltaUsed = $used - $snapUsed;
            $deltaPct  = $snapTotal > 0
                ? number_format(round($deltaUsed / $snapTotal * 100, 2), 2)
                : '';
            $row[] = (string)$snapUsed;
            $row[] = (string)$snapFree;
            $row[] = (string)$deltaUsed;
            $row[] = $deltaPct;
        } else {
            $row[] = '';
            $row[] = '';
            $row[] = '';
            $row[] = '';
        }
    }

    csv_out($row);
}

audit_export($db, 'subnet_utilization', 'all subnets');
exit;
