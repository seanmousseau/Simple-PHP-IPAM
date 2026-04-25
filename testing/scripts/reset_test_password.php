<?php
/**
 * Test-only CLI helper: bypasses password policy and sets a user's password hash
 * directly in the database. Supports all three DB drivers (SQLite, MySQL, PostgreSQL).
 *
 * Usage: php reset_test_password.php <username> <new_password>
 *
 * Only runs from CLI. Used by Playwright afterEach to restore passwords that
 * were changed during policy-enforcement tests (ADMIN_PASS='demo' is 4 chars and
 * cannot be set via change_password.php while any min_length policy is active).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$username    = $argv[1] ?? '';
$newPassword = $argv[2] ?? '';

if ($username === '' || $newPassword === '') {
    fwrite(STDERR, "Usage: reset_test_password.php <username> <new_password>\n");
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
    $db = new PDO('sqlite:' . $dbPath);
} elseif ($driver === 'mysql') {
    $host   = $config['db_host']   ?? 'localhost';
    $port   = $config['db_port']   ?? 3306;
    $dbName = $config['db_name']   ?? 'ipam';
    $user   = $config['db_user']   ?? 'ipam';
    $pass   = $config['db_pass']   ?? '';
    $db = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $user, $pass);
} elseif ($driver === 'pgsql') {
    $host   = $config['db_host']   ?? 'localhost';
    $port   = $config['db_port']   ?? 5432;
    $dbName = $config['db_name']   ?? 'ipam';
    $user   = $config['db_user']   ?? 'ipam';
    $pass   = $config['db_pass']   ?? '';
    $db = new PDO("pgsql:host={$host};port={$port};dbname={$dbName}", $user, $pass);
} else {
    fwrite(STDERR, "Unknown db_driver: {$driver}\n");
    exit(4);
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$st   = $db->prepare("UPDATE users SET password_hash = :h WHERE username = :u");
$st->execute([':h' => $hash, ':u' => $username]);

if ($st->rowCount() === 0) {
    fwrite(STDERR, "User '{$username}' not found\n");
    exit(5);
}

// Clear any rate-limiting state left by test login failures.
$db->exec("DELETE FROM login_attempts");

echo "ok\n";
