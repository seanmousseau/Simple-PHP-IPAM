<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$subnetId = (int)($_GET['subnet_id'] ?? 0);

if ($subnetId <= 0) {
    // Cross-subnet export — requires write access
    require_write_access();

    $filename = safe_export_filename('ipam-addresses-all');
    csv_download_headers($filename);

    csv_out(['subnet_cidr', 'site', 'ip', 'hostname', 'owner', 'group', 'status', 'note', 'updated_at']);

    $st = $db->query("
        SELECT s.cidr AS subnet_cidr, COALESCE(si.name, '') AS site_name,
               a.ip, a.hostname, a.owner, a.grp, a.status, a.note, a.updated_at
        FROM addresses a
        JOIN subnets s ON s.id = a.subnet_id
        LEFT JOIN sites si ON si.id = s.site_id
        ORDER BY s.network_bin, a.ip_bin
    ");
    foreach ($st as $r) {
        csv_out([
            (string)$r['subnet_cidr'],
            (string)$r['site_name'],
            (string)$r['ip'],
            (string)$r['hostname'],
            (string)$r['owner'],
            (string)$r['grp'],
            (string)$r['status'],
            (string)$r['note'],
            (string)$r['updated_at'],
        ]);
    }

    audit_export($db, 'all_addresses', 'all subnets');
    exit;
}

$st = $db->prepare("SELECT id, cidr FROM subnets WHERE id = :id");
$st->execute([':id' => $subnetId]);
$subnet = $st->fetch();
if (!$subnet) {
    http_response_code(404);
    exit('Subnet not found');
}

$filename = safe_export_filename('ipam-addresses-subnet-' . $subnetId);
csv_download_headers($filename);

csv_out(['subnet_cidr', 'ip', 'hostname', 'owner', 'group', 'status', 'note', 'updated_at']);

$st = $db->prepare("
    SELECT a.ip, a.hostname, a.owner, a.grp AS grp, a.status, a.note, a.updated_at
    FROM addresses a
    WHERE a.subnet_id = :sid
    ORDER BY a.ip_bin ASC
");
$st->execute([':sid' => $subnetId]);

foreach ($st as $r) {
    csv_out([
        (string)$subnet['cidr'],
        (string)$r['ip'],
        (string)$r['hostname'],
        (string)$r['owner'],
        (string)$r['grp'],
        (string)$r['status'],
        (string)$r['note'],
        (string)$r['updated_at'],
    ]);
}

audit_export($db, 'addresses', "subnet_id=$subnetId cidr=" . (string)$subnet['cidr']);
exit;
