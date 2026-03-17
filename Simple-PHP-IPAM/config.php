<?php
declare(strict_types=1);

return [
    // RECOMMENDED: set to a path outside web root, e.g. /var/lib/ipam/ipam.sqlite
    // For simple installs, keeping it under data/ is acceptable if protected by web server rules.
    'db_path' => __DIR__ . '/data/ipam.sqlite',

    'session_name' => 'IPAMSESSID',

    // If true, trust reverse-proxy headers for HTTPS detection.
    // Enable ONLY when:
    //  - your app is behind a trusted proxy/load balancer
    //  - your web server/proxy prevents client spoofing of these headers
    'proxy_trust' => false,

    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],
];
