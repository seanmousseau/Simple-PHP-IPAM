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

$deleted = cleanup_tmp_import_files($ttl);
echo "Deleted $deleted old temp file(s).\n";
exit(0);
