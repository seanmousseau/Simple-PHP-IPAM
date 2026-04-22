<?php
declare(strict_types=1);

return [
    'db_driver'    => 'sqlite',
    'db_path'      => __DIR__ . '/data/ipam.sqlite',
    'session_name' => 'IPAMSESSID',
    'force_https'  => true,

    // Required for TOTP 2FA: change this to a long random secret before enabling 2FA.
    // Generate with: php -r "echo bin2hex(random_bytes(32));"
    'app_secret'   => '',

    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],

    // Session security (#420)
    'session' => [
        'absolute_lifetime_minutes' => 480,  // 8 hours; 0 = disabled
    ],

    // Authentication lockout for 2FA failures (#418); login failures use the security.* DB settings
    'auth' => [
        'lockout_after_failures'   => 10,   // persistent lockout after N consecutive 2FA failures
        'lockout_duration_minutes' => 30,   // how long the lockout lasts
    ],

    // API rate limiting per key — sliding window defaults (#419)
    // Runtime values come from the DB settings table (api.rate_limit_*); these are reference defaults.
    'api' => [
        'rate_limit_window_seconds' => 60,   // window size in seconds
        'rate_limit_requests'       => 300,  // max requests per window per key
    ],

    'demo_mode' => [
        'enabled'    => false,
        'gate'       => NULL,
        'site_key'   => '',
        'secret_key' => '',
    ],
];
