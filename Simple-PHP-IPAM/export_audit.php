<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

$filename = safe_export_filename('ipam-audit-log');
csv_download_headers($filename);
csv_out(['created_at', 'username', 'action', 'entity_type', 'entity_id', 'client_ip', 'details']);

$st = $db->prepare("
    SELECT created_at, username, action, entity_type, entity_id, ip, details
    FROM audit_log
    ORDER BY id DESC
");
$st->execute();

foreach ($st->fetchAll() as $r) {
    csv_out([
        to_str($r['created_at']),
        to_str($r['username'] ?? ''),
        to_str($r['action']),
        to_str($r['entity_type']),
        to_str($r['entity_id'] ?? ''),
        to_str($r['ip'] ?? ''),
        to_str($r['details'] ?? ''),
    ]);
}

audit_export($db, 'audit', 'full_export');
exit;
