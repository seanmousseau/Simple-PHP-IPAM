<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$filename = safe_export_filename('ipam-subnet-utilization');
csv_download_headers($filename);

csv_out(['cidr', 'description', 'site', 'ip_version', 'vlan_id', 'used', 'reserved', 'free', 'total', 'utilization_pct']);

$st = $db->query("
    SELECT s.cidr, s.description, s.ip_version, s.vlan_id,
           COALESCE(si.name, '') AS site_name,
           COALESCE(SUM(a.status = 'used'),     0) AS used_count,
           COALESCE(SUM(a.status = 'reserved'), 0) AS reserved_count,
           COALESCE(SUM(a.status = 'free'),     0) AS free_count,
           COUNT(a.id) AS total_count
    FROM subnets s
    LEFT JOIN sites si ON si.id = s.site_id
    LEFT JOIN addresses a ON a.subnet_id = s.id
    GROUP BY s.id
    ORDER BY s.ip_version, s.network_bin
");

foreach ($st as $r) {
    $used     = (int)$r['used_count'];
    $reserved = (int)$r['reserved_count'];
    $free     = (int)$r['free_count'];
    $total    = (int)$r['total_count'];
    $pct      = $total > 0 ? round(($used + $reserved) / $total * 100, 2) : 0.0;

    csv_out([
        (string)$r['cidr'],
        (string)$r['description'],
        (string)$r['site_name'],
        (string)$r['ip_version'],
        $r['vlan_id'] !== null ? (string)$r['vlan_id'] : '',
        (string)$used,
        (string)$reserved,
        (string)$free,
        (string)$total,
        number_format($pct, 2),
    ]);
}

audit_export($db, 'subnet_utilization', 'all subnets');
exit;
