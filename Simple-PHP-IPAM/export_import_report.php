<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

$mode = to_str($_GET['mode'] ?? 'plan');

$wiz = is_array($_SESSION['csv_import'] ?? null) ? $_SESSION['csv_import'] : [];

if ($mode === 'plan') {
    $path = to_str($wiz['plan_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('No import plan found');
    }

    $plan = load_import_plan($path);
    $rows = is_array($plan['rows'] ?? null) ? $plan['rows'] : [];

    $filename = safe_export_filename('ipam-import-dry-run-report');
    csv_download_headers($filename);
    csv_out(['row_num','ip_or_raw','action','subnet_or_cidr','reason']);

    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        csv_out([
            to_str($r['row_num'] ?? ''),
            to_str($r['ip'] ?? $r['ip_raw'] ?? ''),
            to_str($r['display_action'] ?? $r['final_action'] ?? ''),
            to_str($r['resolved_subnet_id'] ?? $r['resolved_cidr'] ?? ''),
            to_str($r['reason'] ?? ''),
        ]);
    }

    audit_export($db, 'import_report', 'dry_run_plan');
    exit;
}

if ($mode === 'result') {
    $path = to_str($wiz['result_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('No import result found');
    }

    $res = ipam_csv_import_load_result($path); // B13 (#925): renamed from load_result_file()
    $rows = is_array($res['rows'] ?? null) ? $res['rows'] : [];

    $filename = safe_export_filename('ipam-import-result-report');
    csv_download_headers($filename);
    csv_out(['row_num','ip','result','reason']);

    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        csv_out([
            to_str($r['row_num'] ?? ''),
            to_str($r['ip'] ?? ''),
            to_str($r['final_result'] ?? ''),
            to_str($r['reason'] ?? ''),
        ]);
    }

    audit_export($db, 'import_report', 'apply_result');
    exit;
}

http_response_code(400);
exit('Unsupported mode');
