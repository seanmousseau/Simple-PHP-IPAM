<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$MAX_ASSIGNABLE = 4096;
$subnetId = (int)($_GET['subnet_id'] ?? 0);

if ($subnetId <= 0) {
    http_response_code(400);
    exit('Missing subnet_id');
}

$st = $db->prepare("SELECT id, cidr, ip_version, network, prefix, network_bin FROM subnets WHERE id = :id");
$st->execute([':id' => $subnetId]);
$sub = $st->fetch();

if (!$sub) {
    http_response_code(404);
    exit('Subnet not found');
}
if ((int)$sub['ip_version'] !== 4) {
    http_response_code(400);
    exit('Unassigned export is IPv4-only');
}

$prefix = (int)$sub['prefix'];
$netInt = ipv4_bin_to_int((string)$sub['network_bin']);
$bcastInt = ipv4_broadcast_int($netInt, $prefix);

if ($prefix <= 30) {
    $first = $netInt + 1;
    $last  = $bcastInt - 1;
} else {
    $first = $netInt;
    $last  = $bcastInt;
}

$assignable = ipv4_assignable_count($prefix);
if ($assignable > $MAX_ASSIGNABLE) {
    http_response_code(400);
    exit('Subnet too large to export unassigned addresses safely');
}

$st = $db->prepare("SELECT ip FROM addresses WHERE subnet_id = :sid");
$st->execute([':sid' => $subnetId]);
$assigned = [];
foreach ($st->fetchAll() as $r) {
    $assigned[(string)$r['ip']] = true;
}

$filename = safe_export_filename('ipam-unassigned-subnet-' . $subnetId);
csv_download_headers($filename);
csv_out(['subnet_cidr', 'ip']);

for ($i = $first; $i <= $last; $i++) {
    $ip = ipv4_int_to_text($i);
    if (!isset($assigned[$ip])) {
        csv_out([(string)$sub['cidr'], $ip]);
    }
}

audit_export($db, 'unassigned', "subnet_id=$subnetId cidr=" . (string)$sub['cidr']);
exit;
