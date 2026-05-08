<?php
/**
 * Test-only CLI helper: seeds (or refreshes) an "OIDC-only" admin user used
 * by step-up-oidc-only.spec.ts to regression-test the v3.27.0 step-up flow.
 *
 * "OIDC-only" means: oidc_sub is set, password_hash is the sentinel
 * '!disabled' string (which password_verify() always rejects), and the user
 * has admin role + is_active=1. The password_hash sentinel is what
 * SudoVerifyTest::testLockedPasswordHashIsNeverAcceptedAsProof guards against
 * being treated as a valid step-up proof.
 *
 * Optional flags:
 *   --with-totp  Also enrol the user in TOTP using the same RFC 6238 test
 *                vector ('JBSWY3DPEHPK3PXP') used by reset_2fa_enrollment.php
 *                so the existing TOTP test scaffolding (totp.spec.ts code
 *                generation, etc.) works against this user too.
 *   --no-mfa     Explicitly clear any MFA enrollment (default for fresh
 *                seeds; pass when re-running on an existing user to ensure
 *                the "no methods available" scenario).
 *
 * Idempotent: safe to run repeatedly. The user is upserted by username.
 *
 * Usage:
 *   php seed_oidc_only_admin.php <username> [--with-totp|--no-mfa]
 *
 * CLI-only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$username = $argv[1] ?? '';
$mode     = $argv[2] ?? '--no-mfa';
if ($username === '' || !in_array($mode, ['--with-totp', '--no-mfa'], true)) {
    fwrite(STDERR, "Usage: seed_oidc_only_admin.php <username> [--with-totp|--no-mfa]\n");
    exit(2);
}

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) $configPath = '/var/www/html/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(3);
}
$config = require $configPath;

$libPath = __DIR__ . '/../../lib.php';
if (!file_exists($libPath)) $libPath = '/var/www/html/lib.php';
require_once $libPath;

$appSecret = (string)($config['app_secret'] ?? '');
if ($appSecret === '') {
    fwrite(STDERR, "app_secret missing in config.php\n");
    exit(4);
}

$db = ipam_db($config);

$oidcSub        = 'test-oidc-' . $username;
$disabledHash   = '!disabled'; // password_verify() never matches this
$totpEnabled    = $mode === '--with-totp' ? 1 : 0;
$totpSecretEnc  = $mode === '--with-totp'
    ? ipam_totp_encrypt_secret('JBSWY3DPEHPK3PXP', $appSecret)
    : null;

try {
    $check = $db->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
    $check->execute([':u' => $username]);
    $existingId = (int)($check->fetchColumn() ?: 0);

    if ($existingId === 0) {
        $ins = $db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active,
                                oidc_sub, totp_enabled, totp_secret_enc,
                                email_otp_enabled, email_otp_hash, email_otp_expires_at,
                                name, email)
             VALUES (:u, :h, 'admin', 1,
                     :sub, :te, :ts,
                     0, NULL, NULL,
                     :u, '')"
        );
        $ins->execute([
            ':u'   => $username,
            ':h'   => $disabledHash,
            ':sub' => $oidcSub,
            ':te'  => $totpEnabled,
            ':ts'  => $totpSecretEnc,
        ]);
        $uid = (int)$db->lastInsertId();
    } else {
        $upd = $db->prepare(
            "UPDATE users
                SET password_hash      = :h,
                    role               = 'admin',
                    is_active          = 1,
                    oidc_sub           = :sub,
                    totp_enabled       = :te,
                    totp_secret_enc    = :ts,
                    email_otp_enabled  = 0,
                    email_otp_hash     = NULL,
                    email_otp_expires_at = NULL
              WHERE id = :id"
        );
        $upd->execute([
            ':h'   => $disabledHash,
            ':sub' => $oidcSub,
            ':te'  => $totpEnabled,
            ':ts'  => $totpSecretEnc,
            ':id'  => $existingId,
        ]);
        $uid = $existingId;
    }

    // If --with-totp, also flip the global mfa.totp_enabled toggle so login
    // dispatch + step-up both surface the method. Same self-heal pattern as
    // reset_2fa_enrollment.php.
    if ($mode === '--with-totp') {
        ipam_setting_set($db, 'mfa.totp_enabled', true, $uid);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "seed failed: " . $e->getMessage() . "\n");
    exit(7);
}

echo $uid . "\n";
