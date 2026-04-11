<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

$addressId = to_int($_GET['address_id'] ?? 0);
if ($addressId <= 0) {
    http_response_code(400);
    exit('Missing address_id');
}

// Resolve address info (may already be deleted)
$st = $db->prepare("SELECT ip FROM addresses WHERE id = :id");
$st->execute([':id' => $addressId]);
/** @var array<string, mixed>|false $addr */
$addr = $st->fetch();

if (!$addr) {
    $st = $db->prepare("SELECT ip FROM address_history WHERE address_id = :id ORDER BY id DESC LIMIT 1");
    $st->execute([':id' => $addressId]);
    /** @var array<string, mixed>|false $fallback */
    $fallback = $st->fetch();
    if (!$fallback) {
        http_response_code(404);
        exit('Address not found');
    }
    $addr = $fallback;
}

$ip = to_str($addr['ip']);

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

foreach ($st->fetchAll() as $r) {
    $before = $r['before_json'] !== null ? json_decode(to_str($r['before_json']), true) : [];
    $after  = $r['after_json']  !== null ? json_decode(to_str($r['after_json']),  true) : [];
    if (!is_array($before)) $before = [];
    if (!is_array($after))  $after  = [];

    csv_out([
        to_str($r['created_at']),
        to_str($r['action']),
        $ip,
        to_str($r['username']  ?? ''),
        to_str($r['client_ip'] ?? ''),
        to_str($before['hostname'] ?? ''),
        to_str($after['hostname']  ?? ''),
        to_str($before['owner']    ?? ''),
        to_str($after['owner']     ?? ''),
        to_str($before['status']   ?? ''),
        to_str($after['status']    ?? ''),
        to_str($before['note']     ?? ''),
        to_str($after['note']      ?? ''),
        to_str($before['grp']      ?? ''),
        to_str($after['grp']       ?? ''),
    ]);
}

audit_export($db, 'address_history', "address_id=$addressId ip=$ip");
exit;
