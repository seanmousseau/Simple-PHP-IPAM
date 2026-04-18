<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

$subnetId = to_int($_GET['subnet_id'] ?? 0);
$days     = max(1, min(365, to_int($_GET['days'] ?? 90)));
$cutoff   = gmdate('Y-m-d H:i:s', time() - $days * 86400);

audit($db, 'export.utilization_history', 'utilization_snapshots', $subnetId ?: null,
    "days={$days}" . ($subnetId > 0 ? " subnet_id={$subnetId}" : ''));

$filename = safe_export_filename('ipam-utilization-history');
csv_download_headers($filename);

csv_out(['subnet_id', 'cidr', 'snapped_at', 'used_count', 'free_count', 'total_hosts', 'utilization_pct']);

$where  = 'us.snapped_at >= :cutoff';
$params = [':cutoff' => $cutoff];
if ($subnetId > 0) {
    $where .= ' AND us.subnet_id = :sid';
    $params[':sid'] = $subnetId;
}

$st = $db->prepare(
    "SELECT us.subnet_id, s.cidr, us.snapped_at, us.used_count, us.free_count, us.total_hosts
     FROM utilization_snapshots us
     JOIN subnets s ON s.id = us.subnet_id
     WHERE {$where}
     ORDER BY us.subnet_id, us.snapped_at DESC"
);
$st->execute($params);

/** @var array<string, mixed> $r */
while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
    $total  = to_int($r['total_hosts']);
    $used   = to_int($r['used_count']);
    $pct    = $total > 0 ? round($used / $total * 100, 2) : 0.0;
    csv_out([
        (string)to_int($r['subnet_id']),
        to_str($r['cidr']),
        to_str($r['snapped_at']),
        (string)$used,
        (string)to_int($r['free_count']),
        (string)$total,
        (string)$pct,
    ]);
}
