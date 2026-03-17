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
];
