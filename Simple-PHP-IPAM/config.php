<?php
declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/data/ipam.sqlite',
    'session_name' => 'IPAMSESSID',
    'proxy_trust' => false,

    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // CSV import max upload size (MB). Allowed range: 5..50
    'import_csv_max_mb' => 5,

    // Temp upload cleanup (seconds). Files older than this are eligible for cleanup.
    // Default: 24 hours.
    'tmp_cleanup_ttl_seconds' => 86400,

    // Lazy housekeeping: runs on normal site access at most once per interval.
    'housekeeping' => [
        'enabled' => true,
        'interval_seconds' => 86400, // once per day
    ],
];
