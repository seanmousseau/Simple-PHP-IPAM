<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

$mode = (string)($_GET['mode'] ?? 'plan');

$wiz = $_SESSION['csv_import'] ?? [];

if ($mode === 'plan') {
    $path = (string)($wiz['plan_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('No import plan found');
    }

    $plan = load_import_plan($path);
    $rows = $plan['rows'] ?? [];

    $filename = safe_export_filename('ipam-import-dry-run-report');
    csv_download_headers($filename);
    csv_out(['row_num','ip_or_raw','action','subnet_or_cidr','reason']);

    foreach ($rows as $r) {
        csv_out([
            (string)($r['row_num'] ?? ''),
            (string)($r['ip'] ?? $r['ip_raw'] ?? ''),
            (string)($r['display_action'] ?? $r['final_action'] ?? ''),
            (string)($r['resolved_subnet_id'] ?? $r['resolved_cidr'] ?? ''),
            (string)($r['reason'] ?? ''),
        ]);
    }

    audit_export($db, 'import_report', 'dry_run_plan');
    exit;
}

if ($mode === 'result') {
    $path = (string)($wiz['result_path'] ?? '');
    if ($path === '' || !is_file($path)) {
        http_response_code(404);
        exit('No import result found');
    }

    $res = load_result_file($path);
    $rows = $res['rows'] ?? [];

    $filename = safe_export_filename('ipam-import-result-report');
    csv_download_headers($filename);
    csv_out(['row_num','ip','result','reason']);

    foreach ($rows as $r) {
        csv_out([
            (string)($r['row_num'] ?? ''),
            (string)($r['ip'] ?? ''),
            (string)($r['final_result'] ?? ''),
            (string)($r['reason'] ?? ''),
        ]);
    }

    audit_export($db, 'import_report', 'apply_result');
    exit;
}

http_response_code(400);
exit('Unsupported mode');
