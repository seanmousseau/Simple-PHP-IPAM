<?php
declare(strict_types=1);

/**
 * @module auth_password
 *
 * Password-complexity validation and password-reset token/email helpers
 * extracted from lib.php in v3.30.0 (ADR-004 Phase 6 Task 6.2, sub of #907).
 * Functions stay in the global namespace per ADR-004 Option E.
 *
 * Responsibilities:
 *  - Password-policy enforcement (validate_password_complexity).
 *  - Password-reset token lifecycle: minting with a per-user hourly rate
 *    cap (ipam_create_reset_token) and single-use atomic consumption
 *    (ipam_consume_reset_token).
 *  - Password-reset email delivery (ipam_send_reset_email).
 *
 * Inclusion rule: functions whose primary job is validating a password
 * against policy, or minting/consuming/delivering a password-reset token.
 * Deliberately NOT moved here:
 *  - ipam_argon2id_derive() — the backup-vault key-derivation KDF
 *    (IPAMBKP3 #836). Despite the Task 6.2 anchor list, it is not a
 *    password/login concern; it stays in lib.php with backup/vault code.
 *  - ipam_app_base_url() — a generic canonical-URL helper shared by both
 *    password-reset and email-change verification; stays in lib.php and is
 *    called cross-module from here (lazy resolution at call time).
 *  - ipam_send_email_verification() — email-*change* verification, not a
 *    password concern; stays in lib.php.
 *  - Login rate-limiting (Task 6.3) and reCAPTCHA (Task 6.4) stay behind.
 *
 * ADR-003: this module performs no `global $config` / `$GLOBALS['config']`
 * reads — validate_password_complexity() takes its policy array as a
 * caller-passed parameter, and the reset helpers derive all configuration
 * from ipam_setting() and the cross-module ipam_app_base_url(). The
 * `global $db` handle is passed as a PDO parameter to the token helpers, so
 * no signatures change.
 *
 * Dependencies: lib/utils.php (to_int / to_str / e), lib/db.php (PDO via
 * caller-passed handle; reset-token helpers also call ipam_dialect()),
 * lib/settings.php (ipam_setting()). Cross-module calls (resolved lazily at
 * call time, never at include time): ipam_app_base_url() and ipam_send_mail()
 * from lib.php. This module has no side-effects on load.
 */

/* ---------------- Password policy ---------------- */

/**
 * Validate a password against the configured policy.
 * Returns an empty array on success, or an array of all violation messages.
 *
 * @param array<string, mixed> $policy
 * @return list<string>
 */
function validate_password_complexity(string $password, array $policy): array
{
    $errors = [];
    $min = max(1, to_int($policy['min_length'] ?? 12));
    if (mb_strlen($password) < $min) {
        $errors[] = "Password must be at least {$min} characters.";
    }
    if (!empty($policy['require_uppercase']) && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter (A–Z).';
    }
    if (!empty($policy['require_lowercase']) && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter (a–z).';
    }
    if (!empty($policy['require_number']) && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number (0–9).';
    }
    if (!empty($policy['require_symbol']) && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character.';
    }
    return $errors;
}

/* ---------------- Password-reset tokens ---------------- */

/**
 * Create a password-reset token for the given user.
 * Returns the raw (unhashed) token to embed in the reset link.
 *
 * Enforces max 3 active tokens per user per hour (rate limit).
 * Token hash is stored with 1-hour expiry; raw token is returned to caller.
 */
function ipam_create_reset_token(PDO $db, int $userId): ?string
{
    $rawToken  = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
    $rateWindowStart = gmdate('Y-m-d H:i:s', time() - 3600);

    $db->beginTransaction();
    try {
        // On MySQL/Postgres, lock the user row so concurrent reset requests for
        // the same user are serialized. SQLite serializes via its write lock.
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $db->prepare("SELECT id FROM users WHERE id = :uid FOR UPDATE")
               ->execute([':uid' => $userId]);
        }

        $rateSt = $db->prepare(
            "SELECT COUNT(*) FROM password_reset_tokens
              WHERE user_id = :uid AND created_at > :window AND used_at IS NULL"
        );
        $rateSt->execute([':uid' => $userId, ':window' => $rateWindowStart]);
        if ((int)$rateSt->fetchColumn() >= 3) {
            $db->rollBack();
            return null;
        }

        $db->prepare(
            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, :exp)"
        )->execute([':uid' => $userId, ':hash' => $tokenHash, ':exp' => $expiresAt]);

        $db->commit();
    } catch (\Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return $rawToken;
}

/**
 * Validate and consume a password-reset token.
 * Returns the user_id if the token is valid, not expired, and not used.
 * Marks the token as used (single-use).
 */
function ipam_consume_reset_token(PDO $db, string $rawToken): ?int
{
    $tokenHash = hash('sha256', $rawToken);
    $now       = gmdate('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        $st = $db->prepare(
            "SELECT id, user_id FROM password_reset_tokens
              WHERE token_hash = :hash
                AND used_at IS NULL
                AND expires_at > :now"
        );
        $st->execute([':hash' => $tokenHash, ':now' => $now]);
        /** @var array<string, mixed>|false $row */
        $row = $st->fetch();

        if (!$row) {
            $db->rollBack();
            return null;
        }

        $upd = $db->prepare(
            "UPDATE password_reset_tokens SET used_at = " . ipam_dialect()->now() . " WHERE id = :id AND used_at IS NULL"
        );
        $upd->execute([':id' => to_int($row['id'])]);

        if ($upd->rowCount() !== 1) {
            $db->rollBack();
            return null;
        }

        $db->commit();
        return to_int($row['user_id']);
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/* ---------------- Password-reset email ---------------- */

/**
 * Send a password-reset link email to the given address.
 * Returns true on success.
 */
function ipam_send_reset_email(string $toAddress, string $toName, string $rawToken): bool
{
    $appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
    $base = ipam_app_base_url();
    if ($base === '') {
        error_log('ipam_send_reset_email: config.base_url is not a valid https:// URL.');
        return false;
    }
    $link    = $base . '/reset_password.php?token=' . rawurlencode($rawToken);

    $subject = $appName . ' — Password Reset';

    $text = "Hi " . ($toName ?: $toAddress) . ",\n\n"
        . "A password reset was requested for your " . $appName . " account.\n\n"
        . "Reset link (valid for 1 hour):\n" . $link . "\n\n"
        . "If you did not request this, you can safely ignore this email.\n\n"
        . "— " . $appName;

    $html = "<p>Hi " . e($toName ?: $toAddress) . ",</p>"
        . "<p>A password reset was requested for your <strong>" . e($appName) . "</strong> account.</p>"
        . "<p><a href=\"" . e($link) . "\">Reset your password</a> (link valid for 1 hour).</p>"
        . "<p>If you did not request this, you can safely ignore this email.</p>"
        . "<p>— " . e($appName) . "</p>";

    $result = ipam_send_mail($toAddress, $subject, $text, $html);
    return $result['success'];
}
