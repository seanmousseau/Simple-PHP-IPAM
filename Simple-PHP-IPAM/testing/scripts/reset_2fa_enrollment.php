<?php
/**
 * Test-only CLI helper: re-seeds the 2FA test user's totp_enabled=1,
 * totp_secret_enc (RFC 6238 vector JBSWY3DPEHPK3PXP), and the eight
 * known-plaintext backup codes that totp.spec.ts relies on.
 *
 * Used by Playwright's totp.spec.ts beforeAll so the spec is robust to
 * any upstream test that may have flipped the user's TOTP state during
 * a full-suite run. Bootstrap-time seeding alone is fragile because
 * tests later in the alphabetical order can mutate this user.
 *
 * Supports SQLite, MySQL, and PostgreSQL via config.php.
 *
 * Usage: php reset_2fa_enrollment.php <username>
 *
 * Only runs from CLI.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Usage: reset_2fa_enrollment.php <username>\n");
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

$libPath = __DIR__ . '/../../Simple-PHP-IPAM/lib.php';
if (!file_exists($libPath)) {
    $libPath = '/var/www/html/lib.php';
}
require_once $libPath;

$appSecret = (string)($config['app_secret'] ?? '');
if ($appSecret === '') {
    fwrite(STDERR, "app_secret missing in config.php\n");
    exit(4);
}

$db = ipam_db($config);

$testSecret = 'JBSWY3DPEHPK3PXP'; // standard RFC 6238 test vector (base32)
$encSecret  = ipam_totp_encrypt_secret($testSecret, $appSecret);

// ipam_totp_save_backup_codes() manages its own transaction, so the user-row
// update runs unwrapped and the backup-code refresh runs inside that helper's
// transaction. Two writes; if the second fails the first stays committed,
// which is acceptable for a test fixture.
try {
    $st = $db->prepare(
        "UPDATE users
            SET totp_enabled    = 1,
                totp_secret_enc = :ts
          WHERE username = :u"
    );
    $st->execute([':ts' => $encSecret, ':u' => $username]);

    if ($st->rowCount() === 0) {
        fwrite(STDERR, "User '{$username}' not found\n");
        exit(5);
    }

    $uidStmt = $db->prepare("SELECT id FROM users WHERE username = :u");
    $uidStmt->execute([':u' => $username]);
    $uid = (int)($uidStmt->fetchColumn() ?: 0);
    if ($uid === 0) {
        fwrite(STDERR, "Could not resolve uid for '{$username}'\n");
        exit(6);
    }

    ipam_totp_save_backup_codes($db, $uid, [
        'AAAA1111-BBBB2222', 'CCCC3333-DDDD4444', 'EEEE5555-FFFF6666',
        'GGGG7777-HHHH8888', 'IIII9999-JJJJAAAA', 'KKKK1111-LLLL2222',
        'MMMM3333-NNNN4444', 'OOOO5555-PPPP6666',
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, "reset failed: " . $e->getMessage() . "\n");
    exit(7);
}

echo "ok\n";
