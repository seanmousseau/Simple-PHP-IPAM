<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$subnetId = (int)($_GET['subnet_id'] ?? 0);

$allowedStatus = ['','used','reserved','free'];
if (!in_array($status, $allowedStatus, true)) $status = '';

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(a.ip LIKE :q ESCAPE '\\' OR a.hostname LIKE :q ESCAPE '\\' OR a.owner LIKE :q ESCAPE '\\' OR a.note LIKE :q ESCAPE '\\')";
    $params[':q'] = '%' . like_escape($q) . '%';
}
if ($status !== '') {
    $where[] = "a.status = :st";
    $params[':st'] = $status;
}
if ($subnetId > 0) {
    $where[] = "a.subnet_id = :sid";
    $params[':sid'] = $subnetId;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$filename = safe_export_filename('ipam-search-results');
csv_download_headers($filename);
csv_out(['subnet_cidr', 'ip', 'hostname', 'owner', 'status', 'note', 'updated_at']);

$st = $db->prepare("
    SELECT s.cidr AS subnet_cidr, a.ip, a.hostname, a.owner, a.status, a.note, a.updated_at
    FROM addresses a
    JOIN subnets s ON s.id = a.subnet_id
    $whereSql
    ORDER BY s.cidr ASC, a.ip_bin ASC
");
$st->execute($params);

foreach ($st as $r) {
    csv_out([
        (string)$r['subnet_cidr'],
        (string)$r['ip'],
        (string)$r['hostname'],
        (string)$r['owner'],
        (string)$r['status'],
        (string)$r['note'],
        (string)$r['updated_at'],
    ]);
}

audit_export($db, 'search', "q=$q status=$status subnet_id=$subnetId");
exit;
