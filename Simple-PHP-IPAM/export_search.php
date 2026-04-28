<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$q = substr(trim(to_str($_GET['q'] ?? '')), 0, 500);
$status = trim(to_str($_GET['status'] ?? ''));
$subnetId  = to_int($_GET['subnet_id'] ?? 0);
$siteId    = to_int($_GET['site_id'] ?? 0);
$ipVersion = to_int($_GET['ip_version'] ?? 0);
if (!in_array($ipVersion, [0, 4, 6], true)) $ipVersion = 0;

$allowedStatus = ['','used','reserved','free'];
if (!in_array($status, $allowedStatus, true)) $status = '';

$where = [];
$params = [];

if ($q !== '') {
    // Distinct :q1..:q6 placeholders for PDO native-prepared safety.
    // See api.php::api_search() for the full rationale.
    // #750: case-insensitive across all engines via dialect-aware LOWER(col).
    // See search.php / api.php for the full rationale; PgsqlDialect overrides
    // COLLATE "C" so non-ASCII folding works on every engine.
    $d = ipam_dialect();
    $where[] = '(' . $d->lower_expr('a.ip')       . " LIKE :q1 ESCAPE '!' OR "
             .       $d->lower_expr('a.hostname') . " LIKE :q2 ESCAPE '!' OR "
             .       $d->lower_expr('a.owner')    . " LIKE :q3 ESCAPE '!' OR "
             .       $d->lower_expr('a.note')     . " LIKE :q4 ESCAPE '!' OR "
             .       $d->lower_expr('a.grp')      . " LIKE :q5 ESCAPE '!' OR "
             .       $d->lower_expr('a.mac')      . " LIKE :q6 ESCAPE '!')";
    $qLike = '%' . like_escape($d->case_fold_value($q)) . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
    $params[':q6'] = $qLike;
}
if ($status !== '') {
    $where[] = "a.status = :st";
    $params[':st'] = $status;
}
if ($subnetId > 0) {
    $where[] = "a.subnet_id = :sid";
    $params[':sid'] = $subnetId;
}
if ($siteId > 0) {
    $where[] = "s.site_id = :site_id";
    $params[':site_id'] = $siteId;
}
if ($ipVersion > 0) {
    $where[] = "s.ip_version = :ipver";
    $params[':ipver'] = $ipVersion;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$filename = safe_export_filename('ipam-search-results');
csv_download_headers($filename);
csv_out(['subnet_cidr', 'ip', 'hostname', 'owner', 'group', 'mac', 'expires_at', 'status', 'note', 'updated_at']);

$st = $db->prepare("
    SELECT s.cidr AS subnet_cidr, a.ip, a.hostname, a.owner, a.grp AS grp, a.mac, a.expires_at, a.status, a.note, a.updated_at
    FROM addresses a
    JOIN subnets s ON s.id = a.subnet_id
    $whereSql
    ORDER BY s.cidr ASC, a.ip_bin ASC
");
$st->execute($params);

foreach ($st->fetchAll() as $r) {
    csv_out([
        to_str($r['subnet_cidr']),
        to_str($r['ip']),
        to_str($r['hostname']),
        to_str($r['owner']),
        to_str($r['grp']),
        to_str($r['mac']),
        to_str($r['expires_at'] ?? ''),
        to_str($r['status']),
        to_str($r['note']),
        to_str($r['updated_at']),
    ]);
}

audit_export($db, 'search', "q=$q status=$status subnet_id=$subnetId site_id=$siteId ip_version=$ipVersion");
exit;
