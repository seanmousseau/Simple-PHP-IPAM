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

    // Session idle timeout (seconds). Users are logged out after this much inactivity.
    'session_idle_seconds' => 1800,

    // Login rate limiting: lock out an IP after this many failed attempts within the window.
    'login_max_attempts'    => 5,
    'login_lockout_seconds' => 900,

    // CSV import max upload size (MB). Allowed range: 5..50
    'import_csv_max_mb' => 5,

    // Temp upload cleanup (seconds). Files older than this are eligible for cleanup.
    'tmp_cleanup_ttl_seconds' => 86400,

    // Lazy housekeeping: runs on normal site access at most once per interval.
    'housekeeping' => [
        'enabled' => true,
        'interval_seconds' => 86400, // once per day
    ],
];
