<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

// Guard: must have a pending TOTP session from login.php
if (empty($_SESSION['totp_pending_uid']) || empty($_SESSION['totp_pending_username']) || empty($_SESSION['totp_pending_role'])) {
    header('Location: login.php');
    exit;
}

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error     = '';
$username  = to_str($_SESSION['totp_pending_username']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $uid  = to_int($_SESSION['totp_pending_uid']);
    $role = to_str($_SESSION['totp_pending_role']);

    $userSt = $db->prepare("SELECT totp_secret_enc, totp_enabled FROM users WHERE id = :id AND is_active = 1");
    $userSt->execute([':id' => $uid]);
    /** @var array<string, mixed>|false $userRow */
    $userRow = $userSt->fetch();

    if (!$userRow || to_int($userRow['totp_enabled']) !== 1) {
        // TOTP got disabled between password check and here — complete login normally
        unset($_SESSION['totp_pending_uid'], $_SESSION['totp_pending_username'], $_SESSION['totp_pending_role']);
        login_user($uid, $username, $role, $db);
        $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
           ->execute([':id' => $uid]);
        audit($db, 'auth.login', 'user', $uid, 'totp bypassed (disabled)');
        header('Location: dashboard.php');
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
        unset($_SESSION['totp_pending_uid'], $_SESSION['totp_pending_username'], $_SESSION['totp_pending_role']);
        login_user($uid, $username, $role, $db);
        $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
           ->execute([':id' => $uid]);
        audit($db, 'auth.login', 'user', $uid, 'totp ok');
        header('Location: dashboard.php');
        exit;
    }

    // Failed: record the failure and check for persistent lockout
    ipam_record_2fa_failure($db, $uid, $config);
    audit($db, 'auth.totp_failed', 'user', $uid, '');

    if (ipam_is_persistently_locked($db, $uid)) {
        unset($_SESSION['totp_pending_uid'], $_SESSION['totp_pending_username'], $_SESSION['totp_pending_role']);
        header('Location: login.php?reason=locked');
        exit;
    }

    $error = 'Code did not match. Try again or use a backup code.';
}

require __DIR__ . '/views/totp_verify.php';
