<?php
declare(strict_types=1);
/** @var string $error */
/** @var string $username */
/** @var bool   $totpAvailable */
/** @var bool   $passkeyAvailable */
?>
<?php page_header('Verify Email Code'); ?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <a href="login.php" class="nav-brand">Simple<span class="nav-brand-php">PHP</span>IPAM</a>
  </div>
  <h1>Verify Email Code</h1>
  <p class="muted">Enter the 6-digit code sent to the email address for <strong><?= e($username) ?></strong>.</p>
  <?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>

  <form method="post" action="email_otp_verify.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="row">
      <label>Verification code<br>
        <input type="text" name="otp_code"
               inputmode="numeric" pattern="\d{6}" maxlength="6"
               placeholder="000000" autocomplete="one-time-code" autofocus required
               style="font-family:var(--font-mono);letter-spacing:0.15em;max-width:140px;">
      </label>
    </div>
    <p><button type="submit">Verify</button></p>
  </form>

  <?php if (!empty($totpAvailable)): ?>
  <form method="post" action="email_otp_verify.php" style="margin-top:6px;">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="switch_to_totp">
    <button type="submit" class="link-button muted font-sm"
            style="background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;">
      Use my authenticator app instead
    </button>
  </form>
  <?php endif ?>
  <?php if (!empty($passkeyAvailable)): ?>
  <form method="post" action="email_otp_verify.php" style="margin-top:6px;">
    <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="switch_to_passkey">
    <button type="submit" class="link-button muted font-sm"
            style="background:none;border:0;padding:0;cursor:pointer;text-decoration:underline;">
      Use a passkey instead
    </button>
  </form>
  <?php endif ?>
  <p><a href="login.php" class="muted font-sm">Didn&#8217;t receive a code? Start over</a></p>
</div>
</div>
<?php page_footer(); ?>
