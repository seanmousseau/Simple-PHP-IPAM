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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $clientDataJSON    = to_str($_POST['clientDataJSON']    ?? '');
    $authenticatorData = to_str($_POST['authenticatorData'] ?? '');
    $signature         = to_str($_POST['signature']         ?? '');
    $credentialId      = to_str($_POST['credentialId']      ?? '');

    if ($clientDataJSON === '' || $authenticatorData === '' || $signature === '' || $credentialId === '') {
        $error = 'Incomplete passkey response. Please try again.';
    } else {
        $credIdBin = base64_decode(strtr($credentialId, '-_', '+/'));
        $cred = ipam_passkey_find_by_credential_id($db, $credIdBin);

        if (!$cred || to_int($cred['user_id']) !== $uid) {
            ipam_record_2fa_failure($db, $uid, $config);
            $error = 'Passkey not recognised. Please try again.';
        } else {
            try {
                $challenge = new \lbuchs\WebAuthn\Binary\ByteBuffer(
                    to_str($_SESSION['passkey_challenge'])
                );
                $publicKey  = to_str($cred['public_key']);
                $prevCount  = to_int($cred['sign_count']);
                $webAuthn = ipam_passkey_webauthn();
                $webAuthn->processGet(
                    $clientDataJSON,
                    $authenticatorData,
                    $signature,
                    $publicKey,
                    $challenge,
                    $prevCount,
                    false,
                    true
                );

                unset($_SESSION['passkey_pending_uid'], $_SESSION['passkey_challenge'], $_SESSION['passkey_assertion_options']);
                $newSignCount = $webAuthn->getSignatureCounter() ?? $prevCount;
                ipam_passkey_update_sign_count($db, to_int($cred['id']), $newSignCount);
                login_user($uid, $username, $role, $db);

                $db->prepare("UPDATE users SET last_login_at = " . ipam_dialect()->now() . " WHERE id = :id")
                   ->execute([':id' => $uid]);
                audit($db, 'auth.passkey_login', 'user', $uid, 'passkey authentication ok');

                header('Location: dashboard.php');
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

  <p class="muted" id="passkey-status" style="font-size:.85rem;margin-top:.75rem"></p>
</div>
<?php page_footer(); ?>
