<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$siteId    = (int)($_GET['site_id'] ?? 0);
$ipVersion = (int)($_GET['ip_version'] ?? 0);
if (!in_array($ipVersion, [0, 4, 6], true)) $ipVersion = 0;

$where = [];
$params = [];

if ($siteId > 0) {
    $where[] = "s.site_id = :site_id";
    $params[':site_id'] = $siteId;
}
if ($ipVersion > 0) {
    $where[] = "s.ip_version = :ipver";
    $params[':ipver'] = $ipVersion;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$filename = safe_export_filename('ipam-subnets');
csv_download_headers($filename);
csv_out(['cidr', 'description', 'ip_version', 'vlan_id', 'site_name', 'address_count', 'created_at', 'updated_at']);

$st = $db->prepare("
    SELECT s.cidr, s.description, s.ip_version, s.vlan_id,
           COALESCE(si.name, '') AS site_name,
           COUNT(a.id) AS addr_count,
           s.created_at, s.updated_at
    FROM subnets s
    LEFT JOIN sites si ON si.id = s.site_id
    LEFT JOIN addresses a ON a.subnet_id = s.id
    $whereSql
    GROUP BY s.id
    ORDER BY s.ip_version ASC, s.network_bin ASC
");
$st->execute($params);

foreach ($st as $r) {
    csv_out([
        (string)$r['cidr'],
        (string)$r['description'],
        (string)$r['ip_version'],
        (string)($r['vlan_id'] ?? ''),
        (string)$r['site_name'],
        (string)$r['addr_count'],
        (string)$r['created_at'],
        (string)$r['updated_at'],
    ]);
}

audit_export($db, 'subnets', "site_id=$siteId ip_version=$ipVersion");
exit;
