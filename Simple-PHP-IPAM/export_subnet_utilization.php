<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$filename = safe_export_filename('ipam-subnet-utilization');
csv_download_headers($filename);

csv_out(['cidr', 'description', 'site', 'ip_version', 'vlan_id', 'used', 'reserved', 'free', 'total', 'utilization_pct']);

$st = $db->query("
    SELECT s.cidr, s.description, s.ip_version, s.prefix, s.vlan_id,
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
if ($st === false) exit;

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
        $pct = 0.0; // IPv6 — subnet too large for meaningful percentage
    }

    csv_out([
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
    ]);
}

audit_export($db, 'subnet_utilization', 'all subnets');
exit;
