<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$siteId    = to_int($_GET['site_id'] ?? 0);
$ipVersion = to_int($_GET['ip_version'] ?? 0);
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
csv_out(['cidr', 'description', 'notes', 'alerts_enabled', 'ip_version', 'vlan_id', 'site_name', 'address_count', 'created_at', 'updated_at']);

$st = $db->prepare("
    SELECT s.cidr, s.description, s.notes, s.alerts_enabled, s.ip_version, s.vlan_id,
           COALESCE(si.name, '') AS site_name,
           COUNT(a.id) AS addr_count,
           s.created_at, s.updated_at
    FROM subnets s
    LEFT JOIN sites si ON si.id = s.site_id
    LEFT JOIN addresses a ON a.subnet_id = s.id
    $whereSql
    GROUP BY s.id, si.name
    ORDER BY s.ip_version ASC, s.network_bin ASC
");
$st->execute($params);

foreach ($st->fetchAll() as $r) {
    csv_out([
        to_str($r['cidr']),
        to_str($r['description']),
        to_str($r['notes']),
        to_int($r['alerts_enabled'] ?? 1) ? '1' : '0',
        to_str($r['ip_version']),
        to_str($r['vlan_id'] ?? ''),
        to_str($r['site_name']),
        to_str($r['addr_count']),
        to_str($r['created_at']),
        to_str($r['updated_at']),
    ]);
}

audit_export($db, 'subnets', "site_id=$siteId ip_version=$ipVersion");
exit;
