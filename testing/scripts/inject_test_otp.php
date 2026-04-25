<?php
/**
 * Test-only CLI helper: sets a known Email OTP for a given user by overwriting
 * the stored bcrypt hash with password_hash(known_code).
 * Supports SQLite, MySQL, and PostgreSQL via config.php.
 *
 * Usage: php inject_test_otp.php <username> <6-digit-code>
 *
 * Only runs from CLI. Used by Playwright tests via docker exec.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$username  = $argv[1] ?? '';
$knownCode = $argv[2] ?? '';

if ($username === '' || !preg_match('/^\d{6}$/', $knownCode)) {
    fwrite(STDERR, "Usage: inject_test_otp.php <username> <6-digit-code>\n");
    exit(2);
}

// Bootstrap just enough to open the correct DB connection.
$configPath = __DIR__ . '/../../Simple-PHP-IPAM/config.php';
if (!file_exists($configPath)) {
    $configPath = '/var/www/html/config.php';
}
if (!file_exists($configPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(3);
}

$config = [];
require $configPath;

$driver = $config['db_driver'] ?? 'sqlite';

if ($driver === 'sqlite') {
    $dbPath = $config['db_path'] ?? (__DIR__ . '/../../Simple-PHP-IPAM/data/ipam.sqlite');
    if (!file_exists($dbPath)) {
        $dbPath = '/var/www/html/data/ipam.sqlite';
    }
    if (!file_exists($dbPath)) {
        fwrite(STDERR, "DB not found at {$dbPath}\n");
        exit(3);
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

$hash = password_hash($knownCode, PASSWORD_DEFAULT);

// OTP expiry uses driver-appropriate now+10min expression.
if ($driver === 'sqlite') {
    $nowExpr = "datetime('now', '+10 minutes')";
} elseif ($driver === 'mysql') {
    $nowExpr = 'DATE_ADD(NOW(), INTERVAL 10 MINUTE)';
} else {
    $nowExpr = "NOW() + INTERVAL '10 minutes'";
}

$st = $db->prepare(
    "UPDATE users
        SET email_otp_hash       = :hash,
            email_otp_expires_at = {$nowExpr},
            email_otp_attempts   = 0
      WHERE username = :u"
);
$st->execute([':hash' => $hash, ':u' => $username]);

if ($st->rowCount() === 0) {
    fwrite(STDERR, "User '{$username}' not found\n");
    exit(5);
}

echo "ok\n";
