<?php
declare(strict_types=1);

require __DIR__ . '/init.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

$config = require __DIR__ . '/config.php';
$ttl = (int)($config['tmp_cleanup_ttl_seconds'] ?? 86400);
if ($ttl < 3600) $ttl = 3600;

$deletedCsv = cleanup_tmp_import_files($ttl);
$deletedPlans = cleanup_tmp_import_plans($ttl);

echo "Deleted $deletedCsv stale import CSV file(s).\n";
echo "Deleted $deletedPlans stale import plan/result file(s).\n";
exit(0);
