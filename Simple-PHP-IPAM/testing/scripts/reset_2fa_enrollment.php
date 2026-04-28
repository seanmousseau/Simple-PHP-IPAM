<?php
/**
 * Test-only CLI helper: re-seeds the 2FA test user's totp_enabled=1,
 * totp_secret_enc (RFC 6238 vector JBSWY3DPEHPK3PXP), and the eight
 * known-plaintext backup codes that totp.spec.ts relies on. Also
 * re-enables the global mfa.totp_enabled setting so that beforeAll
 * fully self-heals after upstream specs (email_otp/passkeys
 * afterEach) wipe the global toggle via group=mfa POSTs with no
 * booleans (#756 cascade).
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

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) {
    $configPath = '/var/www/html/config.php';
}
if (!file_exists($configPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(3);
}

$config = require $configPath;

$libPath = __DIR__ . '/../../lib.php';
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
    // Verify the user exists with an explicit SELECT before issuing the UPDATE.
    // Relying on rowCount() of UPDATE is engine-dependent: MySQL's PDO returns
    // the number of rows actually CHANGED (not matched), so a no-op UPDATE
    // against an already-reset user reports rowCount=0 and would trigger the
    // "User not found" error path even though the user exists.
    $check = $db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
    $check->execute([':u' => $username]);
    $uid = (int)($check->fetchColumn() ?: 0);
    if ($uid === 0) {
        fwrite(STDERR, "User '{$username}' not found\n");
        exit(5);
    }

    $st = $db->prepare(
        "UPDATE users
            SET totp_enabled    = 1,
                totp_secret_enc = :ts
          WHERE username = :u"
    );
    $st->execute([':ts' => $encSecret, ':u' => $username]);

    ipam_totp_save_backup_codes($db, $uid, [
        'AAAA1111-BBBB2222', 'CCCC3333-DDDD4444', 'EEEE5555-FFFF6666',
        'GGGG7777-HHHH8888', 'IIII9999-JJJJAAAA', 'KKKK1111-LLLL2222',
        'MMMM3333-NNNN4444', 'OOOO5555-PPPP6666',
    ]);

    // Self-heal the global mfa.totp_enabled setting. Upstream specs
    // (email_otp.spec.ts / passkeys.spec.ts) afterEach blocks POST
    // group=mfa with no booleans, which the settings handler treats as
    // "absent = false" and writes mfa.totp_enabled = 0. login.php gates
    // TOTP dispatch on this global toggle, so per-user totp_enabled=1
    // alone is not enough — the global must also be true.
    ipam_setting_set($db, 'mfa.totp_enabled', true, $uid);
} catch (\Throwable $e) {
    fwrite(STDERR, "reset failed: " . $e->getMessage() . "\n");
    exit(7);
}

echo "ok\n";
