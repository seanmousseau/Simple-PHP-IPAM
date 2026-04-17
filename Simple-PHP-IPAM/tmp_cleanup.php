<?php
declare(strict_types=1);

// CLI-only guard MUST run before init.php. Otherwise an HTTP hit on
// /tmp_cleanup.php would pass through init.php's full boot before the 403
// fires. See CliGuardTest.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require __DIR__ . '/init.php';

$ttl = to_int(ipam_setting('housekeeping.tmp_cleanup_ttl_seconds'));
if ($ttl < 3600) $ttl = 3600;

$deletedCsv = cleanup_tmp_import_files($ttl);
$deletedPlans = cleanup_tmp_import_plans($ttl);

echo "Deleted $deletedCsv stale import CSV file(s).\n";
echo "Deleted $deletedPlans stale import plan/result file(s).\n";
exit(0);
