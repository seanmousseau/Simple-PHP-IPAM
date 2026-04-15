<?php
declare(strict_types=1);

/**
 * Dedicated POST endpoint for scan-schedule save / delete from scan_history.php.
 *
 * Extracted from scan_history.php in v2.9.0 CR sweep — `scan_history.php` is a
 * read-only history view by project convention (`require_login()` only, no
 * mutation). subnets.php has its own copy of this handler with different field
 * names (`method` / `interval_minutes` vs `scan_method` / `scan_interval`);
 * consolidating the two handlers is out of scope for the v2.9.0 release.
 */
require __DIR__ . '/init.php';
/** @var \PDO $db */

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

require_write_access();
csrf_require();

$action = to_str($_POST['action'] ?? '');
$sid    = to_int($_POST['subnet_id'] ?? 0);

if ($sid <= 0) {
    flash_set('Missing or invalid subnet id.');
    header('Location: scan_history.php');
    exit;
}

if ($action === 'save_scan_schedule') {
    $method       = to_str($_POST['scan_method'] ?? 'icmp');
    $tcpPort      = to_int($_POST['scan_tcp_port'] ?? 0) ?: null;
    $intervalMins = max(1, to_int($_POST['scan_interval'] ?? 60));
    $isActive     = isset($_POST['scan_active']) ? 1 : 0;

    if (!in_array($method, ['icmp', 'tcp', 'both'], true)) $method = 'icmp';
    if ($method === 'icmp') {
        $tcpPort = null;
    } elseif ($tcpPort === null || $tcpPort < 1 || $tcpPort > 65535) {
        flash_set('TCP port must be between 1 and 65535 when method is tcp or both.');
        header('Location: scan_history.php?subnet_id=' . $sid);
        exit;
    }

    // #380: dialect-routed upsert so future engines pick up the right idiom.
    $d = ipam_dialect();
    $upsertClause = $d->upsert('scan_schedules', ['subnet_id'], ['method', 'tcp_port', 'interval_minutes', 'is_active', 'updated_at']);
    $db->prepare("
        INSERT INTO scan_schedules (subnet_id, method, tcp_port, interval_minutes, is_active, updated_at)
        VALUES (:sid, :method, :port, :interval, :active, {$d->now()})
        $upsertClause
    ")->execute([
        ':sid'      => $sid,
        ':method'   => $method,
        ':port'     => $tcpPort,
        ':interval' => $intervalMins,
        ':active'   => $isActive,
    ]);
    audit($db, 'scan.schedule_update', 'subnet', $sid,
        "method=$method interval={$intervalMins}m active=$isActive");
    flash_set('Scan schedule saved.');

} elseif ($action === 'delete_scan_schedule') {
    $db->prepare("DELETE FROM scan_schedules WHERE subnet_id = :sid")->execute([':sid' => $sid]);
    audit($db, 'scan.schedule_delete', 'subnet', $sid, '');
    flash_set('Scan schedule removed.');
}

header('Location: scan_history.php?subnet_id=' . $sid);
exit;
