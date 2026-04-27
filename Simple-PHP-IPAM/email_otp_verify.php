<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

// Guard: must have a pending Email OTP session set by login.php
if (empty($_SESSION['email_otp_pending_uid'])) {
    header('Location: login.php');
    exit;
}

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$uid = to_int($_SESSION['email_otp_pending_uid']);

// Reload user from DB — don't trust session-stored data
$userSt = $db->prepare(
    "SELECT id, username, role, email_otp_attempts FROM users WHERE id = :id AND is_active = 1"
);
$userSt->execute([':id' => $uid]);
/** @var array<string, mixed>|false $userRow */
$userRow = $userSt->fetch();

if (!$userRow) {
    // User was disabled or deleted between password step and OTP step
    unset($_SESSION['email_otp_pending_uid']);
    header('Location: login.php');
    exit;
}

$username = to_str($userRow['username']);
$role     = to_str($userRow['role']);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $submittedCode = trim(to_str($_POST['otp_code'] ?? ''));

    if ($submittedCode === '') {
        $error = 'Please enter the verification code.';
    } elseif (ipam_email_otp_verify($db, $uid, $submittedCode)) {
        // Code correct — complete the login
        unset($_SESSION['email_otp_pending_uid']);
        login_user($uid, $username, $role, $db);
        $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
           ->execute([':id' => $uid]);
        audit($db, 'auth.email_otp_login', 'user', $uid, 'Email OTP 2FA passed');
        header('Location: ' . ipam_post_login_redirect_consume());
        exit;
    } else {
        // Refresh attempts count after ipam_email_otp_verify() incremented it
        $attSt = $db->prepare("SELECT email_otp_attempts FROM users WHERE id = :id");
        $attSt->execute([':id' => $uid]);
        /** @var array<string, mixed>|false $attRow */
        $attRow = $attSt->fetch();
        $attempts = to_int(($attRow ?: [])['email_otp_attempts'] ?? 0);

        audit($db, 'auth.email_otp_failed', 'user', $uid, 'Invalid or expired Email OTP code');

        if ($attempts >= 5) {
            ipam_email_otp_clear($db, $uid);
            unset($_SESSION['email_otp_pending_uid']);
            audit($db, 'auth.email_otp_failed', 'user', $uid, 'Email OTP max attempts reached — challenge aborted');
            session_destroy();
            session_start();
            session_regenerate_id(true);
            header('Location: login.php?reason=otp_locked');
            exit;
        }

        $error = 'Invalid or expired code. Please try again.';
    }
}

require __DIR__ . '/views/email_otp_verify.php';
