<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
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

foreach ($st as $r) {
    csv_out([
        (string)$r['created_at'],
        (string)($r['username'] ?? ''),
        (string)$r['action'],
        (string)$r['entity_type'],
        (string)($r['entity_id'] ?? ''),
        (string)($r['ip'] ?? ''),
        (string)($r['details'] ?? ''),
    ]);
}

audit_export($db, 'audit', 'full_export');
exit;
