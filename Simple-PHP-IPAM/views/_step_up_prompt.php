<?php
declare(strict_types=1);

/**
 * Shared step-up authentication prompt (v3.27.0 #1107).
 *
 * Rendered by every sensitive admin handler that calls ipam_sudo_verify().
 * Auto-defaults to the user's strongest enrolled method narrowed by the
 * install policy: passkey > totp > email_otp > password > oidc_reauth.
 *
 * Required vars from the including page:
 *   PDO    $db
 *   int    $stepUpUserId        Current user id (typically current_user()['id']).
 *   string $stepUpFormAction    URL to POST proof to (typically $_SERVER['REQUEST_URI']).
 *   array  $stepUpHiddenFields  Extra hidden inputs round-tripping the original action.
 *                               e.g. ['action' => 'vault_reveal', 'destination_id' => '5'].
 *
 * Optional vars (sensible defaults if unset):
 *   string $stepUpError         Error from a prior submission ('' if none).
 *   string $stepUpReturnPath    Safe return path for OIDC re-auth (defaults to REQUEST_URI).
 *   string $stepUpTitle         Override prompt title.
 *   string $stepUpDescription   Sub-text describing the action being authorised.
 *   string $stepUpNotice        Non-error informational message (e.g. "Code sent.").
 *
 * POST fields produced (caller maps via ipam_sudo_proof_from_post()):
 *   _sudo_method              totp | email_otp | webauthn | password | oidc_reauth
 *   _sudo_code                TOTP / Email OTP 6-digit code
 *   _sudo_password            Local password
 *   _sudo_client_data_json    WebAuthn (base64url)
 *   _sudo_authenticator_data  WebAuthn (base64url)
 *   _sudo_signature           WebAuthn (base64url)
 *   _sudo_credential_id       WebAuthn (base64url)
 *   _sudo_send_email_otp      '1' when the user clicks "Send code" (caller intercepts).
 *   _sudo_oidc_reauth         '1' when the user clicks the OIDC re-auth link (caller redirects).
 *
 * Recommended caller pattern in a POST handler:
 *
 *   require __DIR__ . '/lib/auth_step_up.php';
 *   if (!ipam_sudo_active()) {
 *       $stepUpError = '';
 *       $proof = ipam_sudo_proof_from_post();
 *       if ($proof !== null) {
 *           if (!ipam_sudo_verify($db, current_user()['id'], $proof)) {
 *               $stepUpError = 'Verification failed. Please try again.';
 *           }
 *       }
 *       if (!ipam_sudo_active()) {
 *           page_header('Confirm your identity');
 *           $stepUpUserId       = current_user()['id'];
 *           $stepUpFormAction   = $_SERVER['REQUEST_URI'];
 *           $stepUpHiddenFields = ['action' => 'vault_reveal'];
 *           include __DIR__ . '/views/_step_up_prompt.php';
 *           page_footer();
 *           exit;
 *       }
 *   }
 */

/** @var \PDO   $db */
/** @var int    $stepUpUserId */
/** @var string $stepUpFormAction */
/** @var array<string, scalar> $stepUpHiddenFields */

$stepUpError       = isset($stepUpError)       && is_string($stepUpError)       ? $stepUpError       : '';
$stepUpNotice      = isset($stepUpNotice)      && is_string($stepUpNotice)      ? $stepUpNotice      : '';
$stepUpTitle       = isset($stepUpTitle)       && is_string($stepUpTitle)       && $stepUpTitle       !== '' ? $stepUpTitle       : 'Confirm your identity to continue';
$stepUpDescription = isset($stepUpDescription) && is_string($stepUpDescription) && $stepUpDescription !== '' ? $stepUpDescription : 'This action requires re-authentication. Choose how you want to verify your identity.';
$stepUpReturnPath  = isset($stepUpReturnPath)  && is_string($stepUpReturnPath)  && $stepUpReturnPath  !== '' ? $stepUpReturnPath  : (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/destinations.php');

$stepUpAvailable = ipam_sudo_available_methods($db, $stepUpUserId);

// Strongest-method-first default selection.
$preferredOrder = ['webauthn', 'totp', 'email_otp', 'password', 'oidc_reauth'];
$stepUpDefault  = '';
foreach ($preferredOrder as $m) {
    if (in_array($m, $stepUpAvailable, true)) {
        $stepUpDefault = $m;
        break;
    }
}

// WebAuthn challenge is issued at render time so the inline JS has options
// to feed navigator.credentials.get() without an extra round-trip.
$stepUpWebAuthnOptsJson = '';
if (in_array('webauthn', $stepUpAvailable, true)) {
    $challenge = ipam_sudo_issue_webauthn_challenge($db, $stepUpUserId);
    if (!empty($challenge['ok'])) {
        $stepUpWebAuthnOptsJson = $challenge['options'];
    }
}

// OIDC re-auth target (empty string if OIDC is not configured for the install).
$stepUpOidcReauthUrl = in_array('oidc_reauth', $stepUpAvailable, true)
    ? ipam_sudo_oidc_reauth_redirect_url($stepUpReturnPath)
    : '';

$stepUpMethodLabels = [
    'webauthn'    => 'Passkey / security key',
    'totp'        => 'Authenticator app code',
    'email_otp'   => 'Email verification code',
    'password'    => 'Account password',
    'oidc_reauth' => 'Sign in with identity provider again',
];
?>
<div class="card step-up-card" data-step-up-prompt
     style="max-width:480px;margin:2rem auto">
  <h2 style="margin-top:0"><?= e($stepUpTitle) ?></h2>
  <p class="muted"><?= e($stepUpDescription) ?></p>

  <?php if ($stepUpError !== ''): ?>
    <p class="danger" role="alert"><?= e($stepUpError) ?></p>
  <?php endif ?>
  <?php if ($stepUpNotice !== ''): ?>
    <p class="success" role="status"><?= e($stepUpNotice) ?></p>
  <?php endif ?>

  <?php if ($stepUpAvailable === []): ?>
    <p class="danger">
      No re-authentication method is available for your account under the
      current step-up policy. Ask an administrator to enable a method you
      have enrolled (TOTP, email OTP, passkey, or password), or to allow
      provider re-authentication.
    </p>
    <p><a href="<?= e($stepUpReturnPath) ?>" class="button-secondary">Cancel</a></p>
  <?php else: ?>
    <form method="post" action="<?= e($stepUpFormAction) ?>" id="step-up-form" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <?php foreach ($stepUpHiddenFields as $hk => $hv): ?>
        <input type="hidden" name="<?= e((string)$hk) ?>" value="<?= e((string)$hv) ?>">
      <?php endforeach ?>

      <?php if (count($stepUpAvailable) > 1): ?>
        <div class="row" style="margin-bottom:1rem">
          <label for="step-up-method">Method<br>
            <select name="_sudo_method" id="step-up-method" data-step-up-method>
              <?php foreach ($stepUpAvailable as $m): ?>
                <option value="<?= e($m) ?>"<?= $m === $stepUpDefault ? ' selected' : '' ?>>
                  <?= e($stepUpMethodLabels[$m] ?? $m) ?>
                </option>
              <?php endforeach ?>
            </select>
          </label>
        </div>
      <?php else: ?>
        <input type="hidden" name="_sudo_method" value="<?= e($stepUpDefault) ?>" data-step-up-method>
      <?php endif ?>

      <?php if (in_array('webauthn', $stepUpAvailable, true)): ?>
        <div data-step-up-section="webauthn"<?= $stepUpDefault === 'webauthn' ? '' : ' hidden' ?>>
          <input type="hidden" name="_sudo_client_data_json"   id="step-up-cdj">
          <input type="hidden" name="_sudo_authenticator_data" id="step-up-ad">
          <input type="hidden" name="_sudo_signature"          id="step-up-sig">
          <input type="hidden" name="_sudo_credential_id"      id="step-up-cid">
          <p class="muted" style="margin-top:0">
            Use your security key or device biometric to verify your identity.
          </p>
          <button type="button" id="step-up-webauthn-btn"
                  data-step-up-webauthn-opts="<?= e($stepUpWebAuthnOptsJson) ?>"
                  style="width:100%;margin-bottom:.5rem">
            Verify with passkey
          </button>
          <p class="muted" id="step-up-webauthn-status" style="font-size:.85rem;margin:.25rem 0 0;"></p>
        </div>
      <?php endif ?>

      <?php if (in_array('totp', $stepUpAvailable, true)): ?>
        <div data-step-up-section="totp"<?= $stepUpDefault === 'totp' ? '' : ' hidden' ?>>
          <div class="row">
            <label for="step-up-totp-code">6-digit code from your authenticator app<br>
              <input type="text" id="step-up-totp-code" name="_sudo_code"
                     inputmode="numeric" pattern="\d{6}" maxlength="6"
                     placeholder="000000" autocomplete="one-time-code"
                     style="font-family:var(--font-mono);letter-spacing:0.15em;max-width:140px;">
            </label>
          </div>
          <p style="margin-top:1rem">
            <button type="submit">Verify</button>
          </p>
        </div>
      <?php endif ?>

      <?php if (in_array('email_otp', $stepUpAvailable, true)): ?>
        <div data-step-up-section="email_otp"<?= $stepUpDefault === 'email_otp' ? '' : ' hidden' ?>>
          <p class="muted" style="margin-top:0">
            We will email a 6-digit code to the address on your account.
          </p>
          <p>
            <button type="submit" name="_sudo_send_email_otp" value="1" class="button-secondary">
              Send code
            </button>
          </p>
          <div class="row">
            <label for="step-up-email-code">Verification code<br>
              <input type="text" id="step-up-email-code" name="_sudo_code"
                     inputmode="numeric" pattern="\d{6}" maxlength="6"
                     placeholder="000000" autocomplete="one-time-code"
                     style="font-family:var(--font-mono);letter-spacing:0.15em;max-width:140px;">
            </label>
          </div>
          <p style="margin-top:1rem">
            <button type="submit">Verify</button>
          </p>
        </div>
      <?php endif ?>

      <?php if (in_array('password', $stepUpAvailable, true)): ?>
        <div data-step-up-section="password"<?= $stepUpDefault === 'password' ? '' : ' hidden' ?>>
          <div class="row">
            <label for="step-up-password">Account password<br>
              <input type="password" id="step-up-password" name="_sudo_password"
                     autocomplete="current-password">
            </label>
          </div>
          <p style="margin-top:1rem">
            <button type="submit">Verify</button>
          </p>
        </div>
      <?php endif ?>

      <?php if (in_array('oidc_reauth', $stepUpAvailable, true)): ?>
        <div data-step-up-section="oidc_reauth"<?= $stepUpDefault === 'oidc_reauth' ? '' : ' hidden' ?>>
          <p class="muted" style="margin-top:0">
            You will be redirected to your identity provider to confirm
            your identity, then returned here to complete the action.
          </p>
          <?php if ($stepUpOidcReauthUrl !== ''): ?>
            <p>
              <a href="<?= e($stepUpOidcReauthUrl) ?>" class="btn"
                 style="display:inline-block;text-align:center">
                Re-authenticate with identity provider
              </a>
            </p>
          <?php else: ?>
            <p class="danger">
              OIDC is not configured on this install — provider re-authentication is unavailable.
            </p>
          <?php endif ?>
        </div>
      <?php endif ?>

      <p style="margin-top:1rem">
        <a href="<?= e($stepUpReturnPath) ?>" class="muted font-sm">Cancel</a>
      </p>
    </form>

  <?php endif ?>
</div>
