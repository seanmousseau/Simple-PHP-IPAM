<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$subnetId = (int)($_GET['subnet_id'] ?? 0);
if ($subnetId <= 0) {
    http_response_code(400);
    exit('Missing subnet_id');
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

csv_out(['subnet_cidr', 'ip', 'hostname', 'owner', 'status', 'note', 'updated_at']);

$st = $db->prepare("
    SELECT a.ip, a.hostname, a.owner, a.status, a.note, a.updated_at
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
        (string)$r['status'],
        (string)$r['note'],
        (string)$r['updated_at'],
    ]);
}

audit_export($db, 'addresses', "subnet_id=$subnetId cidr=" . (string)$subnet['cidr']);
exit;
