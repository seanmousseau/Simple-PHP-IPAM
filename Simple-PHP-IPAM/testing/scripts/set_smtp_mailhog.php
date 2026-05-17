<?php
/**
 * Test-only CLI helper: configures SMTP to use the MailHog trap container.
 * Called from Playwright beforeEach in the Alerts SMTP job so email_otp
 * enrollment tests have working SMTP regardless of test-file execution order
 * (alerts-smtp.spec.ts afterAll wipes SMTP settings before this spec runs).
 *
 * Usage: php set_smtp_mailhog.php
 *
 * Only runs from CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$configPath = __DIR__ . '/../../Simple-PHP-IPAM/config.php';
if (!file_exists($configPath)) {
    $configPath = '/var/www/html/config.php';
}
if (!file_exists($configPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(3);
}

$config = require $configPath;

$driver = $config['db_driver'] ?? 'sqlite';

if ($driver === 'sqlite') {
    $dbPath = $config['db_path'] ?? (__DIR__ . '/../../Simple-PHP-IPAM/data/ipam.sqlite');
    if (!file_exists($dbPath)) {
        $dbPath = '/var/www/html/data/ipam.sqlite';
    }
    $db = new PDO('sqlite:' . $dbPath);
} elseif ($driver === 'mysql' || $driver === 'pgsql') {
    $dsn  = $config['db_dsn']  ?? '';
    $user = $config['db_user'] ?? 'ipam';
    $pass = $config['db_pass'] ?? '';
    if ($dsn === '' && $driver === 'mysql') {
        $host   = $config['db_host'] ?? 'localhost';
        $port   = $config['db_port'] ?? 3306;
        $dbName = $config['db_name'] ?? 'ipam';
        $dsn    = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    } elseif ($dsn === '') {
        $host   = $config['db_host'] ?? 'localhost';
        $port   = $config['db_port'] ?? 5432;
        $dbName = $config['db_name'] ?? 'ipam';
        $dsn    = "pgsql:host={$host};port={$port};dbname={$dbName}";
    }
    $db = new PDO($dsn, $user, $pass);
} else {
    fwrite(STDERR, "Unknown db_driver: {$driver}\n");
    exit(4);
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$settings = [
    'smtp.enabled'      => '1',
    'smtp.host'         => 'mailhog',
    'smtp.port'         => '1025',
    'smtp.encryption'   => 'none',
    'smtp.from_address' => 'ipam@test.local',
    'smtp.from_name'    => 'IPAM Test',
];

$upd = $db->prepare(
    "UPDATE settings SET value = :v WHERE tenant_id IS NULL AND `key` = :k"
);
$ins = $db->prepare(
    "INSERT INTO settings (`key`, value) VALUES (:k, :v)"
);

foreach ($settings as $key => $value) {
    $upd->execute([':k' => $key, ':v' => $value]);
    if ($upd->rowCount() === 0) {
        $ins->execute([':k' => $key, ':v' => $value]);
    }
}

echo "ok\n";
