<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$MAX_ASSIGNABLE      = 4096;
$MAX_UNASSIGNED_IPV6 = 256;
$subnetId = to_int($_GET['subnet_id'] ?? 0);

if ($subnetId <= 0) {
    http_response_code(400);
    exit('Missing subnet_id');
}

$st = $db->prepare("SELECT id, cidr, ip_version, network, prefix, network_bin FROM subnets WHERE id = :id");
$st->execute([':id' => $subnetId]);
/** @var array<string, mixed>|false $sub */
$sub = $st->fetch();

if (!$sub) {
    http_response_code(404);
    exit('Subnet not found');
}

$prefix  = to_int($sub['prefix']);
$version = to_int($sub['ip_version']);

$filename = safe_export_filename('ipam-unassigned-subnet-' . $subnetId);
csv_download_headers($filename);
csv_out(['subnet_cidr', 'ip']);

if ($version === 4) {
    $netInt   = ipv4_bin_to_int(to_str($sub['network_bin']));
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
    foreach ($st->fetchAll() as $r) $assigned[to_str($r['ip'])] = true;

    for ($i = $first; $i <= $last; $i++) {
        $ip = ipv4_int_to_text($i);
        if (!isset($assigned[$ip])) {
            csv_out([to_str($sub['cidr']), $ip]);
        }
    }
} else {
    // IPv6 — enumerate first N unassigned (capped)
    $ips = ipv6_enumerate_first_n($db, $subnetId, to_str($sub['network_bin']), $prefix, $MAX_UNASSIGNED_IPV6);
    foreach ($ips as $ip) {
        csv_out([to_str($sub['cidr']), $ip]);
    }
}

audit_export($db, 'unassigned', "subnet_id=$subnetId cidr=" . to_str($sub['cidr']));
exit;
