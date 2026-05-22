<?php
declare(strict_types=1);

return [
    'db_driver'    => 'sqlite',
    'db_path'      => __DIR__ . '/data/ipam.sqlite',
    'session_name' => 'IPAMSESSID',
    'force_https'  => true,
    'bootstrap_admin' => [
        'username' => 'admin',
        'password' => 'ChangeMeNow!12345',
    ],
    'demo_mode' => [
        'enabled'    => false,
        'gate'       => NULL,
        'site_key'   => '',
        'secret_key' => '',
    ],
    'app_secret' => 'Q2a5kQjN14WH2PiZs6VIIM82bfjFij5aXoKzRtm5wvc=',
];
