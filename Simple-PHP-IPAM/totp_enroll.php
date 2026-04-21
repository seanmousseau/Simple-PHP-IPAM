<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_login();

$cur     = current_user();
$appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
$error   = '';
$step    = 1;

// Guard: if already enrolled, send back to Account
$checkSt = $db->prepare("SELECT totp_enabled FROM users WHERE id = :id");
$checkSt->execute([':id' => $cur['id']]);
/** @var array<string, mixed>|false $checkRow */
$checkRow = $checkSt->fetch();
if ($checkRow && to_int($checkRow['totp_enabled']) === 1) {
    flash_set('Two-factor authentication is already enabled.');
    header('Location: change_password.php');
    exit;
}

$appSecret = to_str($config['app_secret'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $step = to_int($_POST['step'] ?? 1);

    if ($step === 2) {
        if ($appSecret === '') {
            $error = 'app_secret is not configured in config.php. Contact your administrator.';
            $step  = 1;
        } else {
            $secret = to_str($_SESSION['totp_pending_secret'] ?? '');
            $code   = preg_replace('/\s+/', '', to_str($_POST['code'] ?? ''));

            if ($secret === '') {
                $error = 'Enrollment session expired. Please start again.';
                $step  = 1;
            } elseif (!preg_match('/^\d{6}$/', $code)) {
                $error = 'Enter the 6-digit code from your authenticator app.';
                $step  = 1;
            } elseif (!ipam_totp_verify($secret, $code)) {
                $error = 'Code did not match. Make sure your device clock is correct and try again.';
                $step  = 1;
            } else {
                $enc   = ipam_totp_encrypt_secret($secret, $appSecret);
                $db->prepare("UPDATE users SET totp_secret_enc=:enc, totp_enabled=1 WHERE id=:id")
                   ->execute([':enc' => $enc, ':id' => $cur['id']]);
                $codes = ipam_totp_generate_backup_codes(8);
                ipam_totp_save_backup_codes($db, to_int($cur['id']), $codes);
                $_SESSION['totp_new_backup_codes'] = $codes;
                audit($db, 'auth.totp_enroll', 'user', to_int($cur['id']), '');
                unset($_SESSION['totp_pending_secret']);
                header('Location: totp_enroll.php?step=3');
                exit;
            }
        }
    }
}

// GET with step=3: show backup codes (one-time display)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && to_int($_GET['step'] ?? 0) === 3) {
    $step  = 3;
    $codes = (array)($_SESSION['totp_new_backup_codes'] ?? []);
    if (!$codes) {
        // Already dismissed — redirect to Account
        header('Location: change_password.php');
        exit;
    }
    unset($_SESSION['totp_new_backup_codes']);
    require __DIR__ . '/views/totp_enroll.php';
    exit;
}

// Step 1: generate secret if not already pending
if ($appSecret === '') {
    $error  = 'app_secret is not configured in config.php. Contact your administrator.';
    $secret = '';
    $uri    = '';
} else {
    if (empty($_SESSION['totp_pending_secret'])) {
        $_SESSION['totp_pending_secret'] = ipam_totp_generate_secret();
    }
    $secret = to_str($_SESSION['totp_pending_secret']);
    $uri    = ipam_totp_get_uri($secret, $appName, to_str($cur['username']));
}

require __DIR__ . '/views/totp_enroll.php';
