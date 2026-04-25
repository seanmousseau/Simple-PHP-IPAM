<?php
/**
 * Test-only CLI helper: sets a known Email OTP for a given user by overwriting
 * the stored bcrypt hash with password_hash(known_code).
 *
 * Usage: php inject_test_otp.php <username> <code>
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

$dbPath = __DIR__ . '/../../Simple-PHP-IPAM/data/ipam.sqlite';
if (!file_exists($dbPath)) {
    $dbPath = '/var/www/html/data/ipam.sqlite';
}

if (!file_exists($dbPath)) {
    fwrite(STDERR, "DB not found at {$dbPath}\n");
    exit(3);
}

$db   = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$hash = password_hash($knownCode, PASSWORD_DEFAULT);
$st   = $db->prepare(
    "UPDATE users
        SET email_otp_hash       = :hash,
            email_otp_expires_at = datetime('now', '+10 minutes'),
            email_otp_attempts   = 0
      WHERE username = :u"
);
$st->execute([':hash' => $hash, ':u' => $username]);
if ($st->rowCount() === 0) {
    fwrite(STDERR, "User '{$username}' not found\n");
    exit(4);
}
echo "ok\n";
