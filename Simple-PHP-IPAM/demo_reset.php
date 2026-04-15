<?php
/**
 * CLI demo reset script — intended for nightly cron use.
 * Usage: php demo_reset.php
 * Cron:  0 0 * * * php /path/to/Simple-PHP-IPAM/demo_reset.php
 *
 * Resets the database to seed data and updates the reset timestamp.
 * No-op if demo_mode is not enabled in config.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/lib.php';

$config = require __DIR__ . '/config.php';

if (!($config['demo_mode']['enabled'] ?? false)) {
    echo "Demo mode not enabled in config.php. Nothing to do.\n";
    exit(0);
}

$db = ipam_db($config);
ipam_db_init($db);

demo_reset_db($db);
file_put_contents(__DIR__ . '/data/demo_last_reset.txt', (string)time());
echo "Demo reset complete at " . date('Y-m-d H:i:s') . "\n";
