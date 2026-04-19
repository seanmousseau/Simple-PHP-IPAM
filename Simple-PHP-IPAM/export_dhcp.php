<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();
require_write_access();

// Validate format — allowlist prevents any injection or misuse
$format  = in_array($_GET['format'] ?? '', ['dhcpd', 'kea'], true) ? to_str($_GET['format']) : 'dhcpd';
$preview = !empty($_GET['preview']);

// Parse optional subnet filter: ?subnet_id=N or ?subnets=N,N,N
$subnetIds = [];
if (!empty($_GET['subnet_id'])) {
    $sid = to_int($_GET['subnet_id']);
    if ($sid > 0) $subnetIds[] = $sid;
} elseif (!empty($_GET['subnets'])) {
    foreach (explode(',', to_str($_GET['subnets'])) as $raw) {
        $sid = to_int(trim($raw));
        if ($sid > 0) $subnetIds[] = $sid;
    }
}

if ($format === 'dhcpd') {
    $output   = ipam_render_dhcpd_conf($db, $subnetIds);
    $mime     = 'text/plain';
    $filename = 'dhcpd.conf';
    $auditKey = 'export.dhcp';
} else {
    $output   = ipam_render_kea_json($db, $subnetIds);
    $mime     = 'application/json';
    $filename = 'kea-dhcp4.json';
    $auditKey = 'export.kea';
}

$subnetLabel = empty($subnetIds) ? 'all' : implode(',', $subnetIds);
audit($db, $auditKey, 'dhcp_config', null, "format={$format} subnets={$subnetLabel}");

if ($preview) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
} else {
    $safe = safe_export_filename($filename);
    header("Content-Type: {$mime}; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"{$safe}\"");
    header('X-Content-Type-Options: nosniff');
}
echo $output;
