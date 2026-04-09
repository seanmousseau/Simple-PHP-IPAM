<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

$addressId = (int)($_GET['address_id'] ?? 0);
if ($addressId <= 0) {
    http_response_code(400);
    exit('Missing address_id');
}

// Resolve address info (may already be deleted)
$st = $db->prepare("SELECT ip FROM addresses WHERE id = :id");
$st->execute([':id' => $addressId]);
$addr = $st->fetch();

if (!$addr) {
    $st = $db->prepare("SELECT ip FROM address_history WHERE address_id = :id ORDER BY id DESC LIMIT 1");
    $st->execute([':id' => $addressId]);
    $fallback = $st->fetch();
    if (!$fallback) {
        http_response_code(404);
        exit('Address not found');
    }
    $addr = $fallback;
}

$ip = (string)$addr['ip'];

$filename = safe_export_filename('ipam-address-history-' . str_replace(['.', ':'], '-', $ip));
csv_download_headers($filename);

csv_out(['date', 'action', 'ip', 'changed_by', 'client_ip',
         'hostname_before', 'hostname_after',
         'owner_before', 'owner_after',
         'status_before', 'status_after',
         'note_before', 'note_after',
         'group_before', 'group_after']);

$st = $db->prepare("
    SELECT created_at, action, username, client_ip, before_json, after_json
    FROM address_history
    WHERE address_id = :aid
    ORDER BY id ASC
");
$st->execute([':aid' => $addressId]);

foreach ($st as $r) {
    $before = $r['before_json'] !== null ? json_decode((string)$r['before_json'], true) : [];
    $after  = $r['after_json']  !== null ? json_decode((string)$r['after_json'],  true) : [];
    if (!is_array($before)) $before = [];
    if (!is_array($after))  $after  = [];

    csv_out([
        (string)$r['created_at'],
        (string)$r['action'],
        $ip,
        (string)($r['username']  ?? ''),
        (string)($r['client_ip'] ?? ''),
        (string)($before['hostname'] ?? ''),
        (string)($after['hostname']  ?? ''),
        (string)($before['owner']    ?? ''),
        (string)($after['owner']     ?? ''),
        (string)($before['status']   ?? ''),
        (string)($after['status']    ?? ''),
        (string)($before['note']     ?? ''),
        (string)($after['note']      ?? ''),
        (string)($before['grp']      ?? ''),
        (string)($after['grp']       ?? ''),
    ]);
}

audit_export($db, 'address_history', "address_id=$addressId ip=$ip");
exit;
