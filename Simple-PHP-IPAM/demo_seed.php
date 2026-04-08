<?php
/**
 * CLI seeder: populates the database with demo data.
 * Usage: php demo_seed.php
 *
 * This script truncates all data tables and seeds fresh demo data.
 * Only intended for development/demo environments.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/lib.php';

$config = require __DIR__ . '/config.php';

if (!($config['demo_mode']['enabled'] ?? false)) {
    echo "Demo mode is not enabled in config.php. Aborting.\n";
    echo "Set 'demo_mode' => ['enabled' => true] to use this script.\n";
    exit(1);
}

$db = ipam_db($config);
ipam_db_init($db);

echo "Resetting database to demo data...\n";
demo_reset_db($db);
file_put_contents(__DIR__ . '/data/demo_last_reset.txt', (string)time());
echo "Done. Demo data loaded successfully.\n";
