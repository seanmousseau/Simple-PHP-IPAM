<?php
/**
 * Test-only CLI helper: sets a user's email_otp_enabled flag to 1 so
 * login challenge and admin-controls tests can assume an enrolled user.
 * Supports SQLite, MySQL, and PostgreSQL via config.php.
 *
 * Usage: php ensure_email_otp_enrolled.php <username>
 *
 * Only runs from CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Usage: ensure_email_otp_enrolled.php <username>\n");
    exit(2);
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

// Verify the user exists with an explicit SELECT before issuing the UPDATE.
// Relying on rowCount() of UPDATE is engine-dependent: MySQL's PDO returns
// the number of rows actually CHANGED (not matched), so a no-op UPDATE
// against an already-enrolled user reports rowCount=0 and would trigger the
// "User not found" error path even though the user exists.
$check = $db->prepare("SELECT 1 FROM users WHERE username = :u LIMIT 1");
$check->execute([':u' => $username]);
if ($check->fetchColumn() === false) {
    fwrite(STDERR, "User '{$username}' not found\n");
    exit(5);
}

$st = $db->prepare(
    "UPDATE users
        SET email_otp_enabled    = 1,
            email_otp_hash       = NULL,
            email_otp_expires_at = NULL,
            email_otp_attempts   = 0
      WHERE username = :u"
);
$st->execute([':u' => $username]);

echo "ok\n";
