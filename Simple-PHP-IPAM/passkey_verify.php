<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

// Guard: must have a pending passkey session set by login.php
if (empty($_SESSION['passkey_pending_uid']) || empty($_SESSION['passkey_challenge'])) {
    header('Location: login.php');
    exit;
}

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$uid = to_int($_SESSION['passkey_pending_uid']);

// Reload user — never trust session-stored fields
$userSt = $db->prepare(
    "SELECT id, username, role FROM users WHERE id = :id AND is_active = 1"
);
$userSt->execute([':id' => $uid]);
/** @var array<string,mixed>|false $userRow */
$userRow = $userSt->fetch(PDO::FETCH_ASSOC);

if (!$userRow) {
    unset($_SESSION['passkey_pending_uid'], $_SESSION['passkey_challenge'], $_SESSION['passkey_assertion_options']);
    header('Location: login.php');
    exit;
}

$username = to_str($userRow['username']);
$role     = to_str($userRow['role']);
$error    = '';

// Detect alternate-method enrolment so the view can offer switch-method links (#746).
$totpAvailable = (bool)to_int(ipam_setting('mfa.totp_enabled', true));
if ($totpAvailable) {
    $tStmt = $db->prepare("SELECT totp_enabled FROM users WHERE id = :id");
    $tStmt->execute([':id' => $uid]);
    $totpAvailable = (int)$tStmt->fetchColumn() === 1;
}
$emailOtpAvailable = (bool)to_int(ipam_setting('mfa.email_otp_enabled', false));
if ($emailOtpAvailable) {
    $eStmt = $db->prepare("SELECT email_otp_enabled FROM users WHERE id = :id");
    $eStmt->execute([':id' => $uid]);
    $emailOtpAvailable = (int)$eStmt->fetchColumn() === 1;
}

$action = to_str($_POST['action'] ?? '');

// User chose to verify with TOTP instead of a passkey (#746).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'switch_to_totp') {
    csrf_require();
    if (!$totpAvailable) {
        header('Location: passkey_verify.php');
        exit;
    }
    unset(
        $_SESSION['passkey_pending_uid'],
        $_SESSION['passkey_challenge'],
        $_SESSION['passkey_assertion_options'],
        $_SESSION['passkey_challenge_issued_at']
    );
    $_SESSION['totp_pending_uid'] = $uid;
    audit($db, 'auth.mfa_method_switch', 'user', $uid, 'passkey -> totp');
    header('Location: totp_verify.php');
    exit;
}

// User chose to verify with Email OTP instead of a passkey (#746).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'switch_to_email') {
    csrf_require();
    if (!$emailOtpAvailable) {
        header('Location: passkey_verify.php');
        exit;
    }
    $code = ipam_email_otp_generate($db, $uid);
    if (!ipam_email_otp_send($db, $uid, $code)) {
        ipam_email_otp_clear($db, $uid, 'email_send_failed');
        $error = 'Could not send verification code. Please try a passkey instead.';
    } else {
        unset(
            $_SESSION['passkey_pending_uid'],
            $_SESSION['passkey_challenge'],
            $_SESSION['passkey_assertion_options'],
            $_SESSION['passkey_challenge_issued_at']
        );
        $_SESSION['email_otp_pending_uid'] = $uid;
        audit($db, 'auth.mfa_method_switch', 'user', $uid, 'passkey -> email_otp');
        header('Location: email_otp_verify.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $action !== 'switch_to_totp'
    && $action !== 'switch_to_email') {
    csrf_require();

    // Consume the challenge immediately — single-use regardless of outcome.
    $challengeBin = to_str($_SESSION['passkey_challenge']);
    $issuedAt     = to_int($_SESSION['passkey_challenge_issued_at'] ?? 0);
    unset(
        $_SESSION['passkey_pending_uid'],
        $_SESSION['passkey_challenge'],
        $_SESSION['passkey_assertion_options'],
        $_SESSION['passkey_challenge_issued_at']
    );

    // Reject expired challenges (60-second TTL).
    if ($challengeBin === '' || $issuedAt < (time() - 60)) {
        header('Location: login.php');
        exit;
    }

    // JS sends ArrayBuffer values as base64url; lbuchs processGet expects raw binary.
    $clientDataJSONRaw    = base64_decode(strtr(to_str($_POST['clientDataJSON']    ?? ''), '-_', '+/'));
    $authenticatorDataRaw = base64_decode(strtr(to_str($_POST['authenticatorData'] ?? ''), '-_', '+/'));
    $signatureRaw         = base64_decode(strtr(to_str($_POST['signature']         ?? ''), '-_', '+/'));
    $credentialId         = to_str($_POST['credentialId'] ?? '');

    if ($clientDataJSONRaw === '' || $authenticatorDataRaw === '' || $signatureRaw === '' || $credentialId === '') {
        $error = 'Incomplete passkey response. Please try again.';
    } else {
        $credIdBin = base64_decode(strtr($credentialId, '-_', '+/'));
        $cred = ipam_passkey_find_by_credential_id($db, $credIdBin);

        if (!$cred || to_int($cred['user_id']) !== $uid) {
            ipam_record_2fa_failure($db, $uid, $config);
            $error = 'Passkey not recognised. Please try again.';
        } else {
            try {
                // Load lbuchs autoloader before any ByteBuffer / WebAuthn usage.
                $webAuthn   = ipam_passkey_webauthn();
                $challenge  = new \lbuchs\WebAuthn\Binary\ByteBuffer($challengeBin);
                $publicKey  = to_str($cred['public_key']);
                $prevCount  = to_int($cred['sign_count']);
                $webAuthn->processGet(
                    $clientDataJSONRaw,
                    $authenticatorDataRaw,
                    $signatureRaw,
                    $publicKey,
                    $challenge,
                    $prevCount,
                    false,
                    true
                );

                $newSignCount = $webAuthn->getSignatureCounter() ?? $prevCount;
                ipam_passkey_update_sign_count($db, to_int($cred['id']), $newSignCount);
                login_user($uid, $username, $role, $db);

                $db->prepare("UPDATE users SET last_login_at = " . ipam_dialect()->now() . " WHERE id = :id")
                   ->execute([':id' => $uid]);
                audit($db, 'auth.passkey_login', 'user', $uid, 'passkey authentication ok');

                header('Location: ' . ipam_post_login_redirect_consume());
                exit;

            } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
                ipam_record_2fa_failure($db, $uid, $config);
                audit($db, 'auth.passkey_fail', 'user', $uid, substr($e->getMessage(), 0, 200));
                $error = 'Passkey verification failed. Please try again.';
            }
        }
    }
}

// Read pre-generated assertion options from session
$assertionOptsJson = to_str($_SESSION['passkey_assertion_options'] ?? '{}');

page_header('Sign in with Passkey');
?>
<div class="card" style="max-width:420px;margin:4rem auto">
  <h2 style="margin-top:0">Sign in with Passkey</h2>
  <p class="muted">Use your security key or device biometric to verify your identity, <strong><?= e($username) ?></strong>.</p>

  <?php if ($error !== ''): ?>
    <p class="danger" role="alert"><?= e($error) ?></p>
  <?php endif ?>

  <form id="passkey-form" method="post" action="passkey_verify.php" novalidate>
    <input type="hidden" name="csrf"              value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="clientDataJSON"    id="f-clientDataJSON">
    <input type="hidden" name="authenticatorData" id="f-authenticatorData">
    <input type="hidden" name="signature"         id="f-signature">
    <input type="hidden" name="credentialId"      id="f-credentialId">

    <button type="button" id="btn-passkey" class="btn"
            data-assert-opts="<?= e($assertionOptsJson) ?>"
            style="width:100%;margin-bottom:.75rem">
      <?= icon('finger-print') ?> Verify with Passkey
    </button>
    <a href="login.php" class="button-secondary" style="display:block;text-align:center">Cancel</a>
  </form>

  <?php if (!empty($totpAvailable)): ?>
  <form method="post" action="passkey_verify.php" style="margin-top:.75rem;">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="switch_to_totp">
    <button type="submit" class="link-button muted font-sm"
            style="background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;">
      Use my authenticator app instead
    </button>
  </form>
  <?php endif ?>
  <?php if (!empty($emailOtpAvailable)): ?>
  <form method="post" action="passkey_verify.php" style="margin-top:.5rem;">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="switch_to_email">
    <button type="submit" class="link-button muted font-sm"
            style="background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;">
      Send a code to my email instead
    </button>
  </form>
  <?php endif ?>

  <p class="muted" id="passkey-status" style="font-size:.85rem;margin-top:.75rem"></p>
</div>
<?php page_footer(); ?>
