<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

// Guard: must have a pending TOTP session from login.php
if (empty($_SESSION['totp_pending_uid'])) {
    header('Location: login.php');
    exit;
}

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$uid = to_int($_SESSION['totp_pending_uid']);

// Reload user from DB — don't trust session-stored username/role
$userSt = $db->prepare(
    "SELECT username, role, totp_secret_enc, totp_enabled FROM users WHERE id = :id AND is_active = 1"
);
$userSt->execute([':id' => $uid]);
/** @var array<string, mixed>|false $userRow */
$userRow = $userSt->fetch();

if (!$userRow) {
    // User was disabled or deleted between password step and 2FA step
    unset($_SESSION['totp_pending_uid']);
    header('Location: login.php');
    exit;
}

$username = to_str($userRow['username']);
$role     = to_str($userRow['role']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // Enforce persistent lockout before processing any submitted code (#421)
    if (ipam_is_persistently_locked($db, $uid)) {
        unset($_SESSION['totp_pending_uid']);
        header('Location: login.php?reason=locked');
        exit;
    }

    if (to_int($userRow['totp_enabled']) !== 1) {
        // TOTP was disabled between password check and here — complete login normally
        unset($_SESSION['totp_pending_uid']);
        login_user($uid, $username, $role, $db);
        $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
           ->execute([':id' => $uid]);
        audit($db, 'auth.login', 'user', $uid, 'totp bypassed (disabled)');
        header('Location: ' . ipam_post_login_redirect_consume());
        exit;
    }

    $code      = to_str(preg_replace('/\s+/', '', to_str($_POST['code'] ?? '')));
    $useBackup = !empty($_POST['use_backup']);
    $verified  = false;

    if ($useBackup) {
        $verified = ipam_totp_verify_backup_code($db, $uid, $code);
        if ($verified) {
            audit($db, 'auth.totp_backup_used', 'user', $uid, '');
        }
    } else {
        $appSecret = to_str($config['app_secret'] ?? '');
        if ($appSecret !== '') {
            $plain = ipam_totp_decrypt_secret(to_str($userRow['totp_secret_enc']), $appSecret);
            if ($plain !== '') {
                $verified = ipam_totp_verify($plain, $code);
            }
        }
    }

    if ($verified) {
        unset($_SESSION['totp_pending_uid']);
        ipam_clear_persistent_lockout($db, $uid);
        login_user($uid, $username, $role, $db);
        $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
           ->execute([':id' => $uid]);
        audit($db, 'auth.login', 'user', $uid, 'totp ok');
        header('Location: ' . ipam_post_login_redirect_consume());
        exit;
    }

    // Failed: record the failure and check for persistent lockout
    ipam_record_2fa_failure($db, $uid, $config);
    audit($db, 'auth.totp_failed', 'user', $uid, '');

    if (ipam_is_persistently_locked($db, $uid)) {
        unset($_SESSION['totp_pending_uid']);
        header('Location: login.php?reason=locked');
        exit;
    }

    $error = 'Code did not match. Try again or use a backup code.';
}

require __DIR__ . '/views/totp_verify.php';
