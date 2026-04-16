<?php
declare(strict_types=1);
return [
    'db_path' => __DIR__ . '/data/ipam.sqlite',
    'session_name' => 'IPAMSESSID',
    'proxy_trust' => false,
    'app_name' => 'Simple PHP IPAM (staging)',
    'bootstrap_admin' => [
        'username' => '__BOOTSTRAP_ADMIN_USER__',
        'password' => '__BOOTSTRAP_ADMIN_PASS__',
    ],
    'session_idle_seconds' => 1800,
    'login_max_attempts' => 5,
    'login_lockout_seconds' => 900,
];
