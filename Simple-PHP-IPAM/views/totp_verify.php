<?php
declare(strict_types=1);
/** @var string $error */
/** @var string $username */
?>
<?php page_header('Two-Factor Authentication'); ?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <a href="login.php" class="nav-brand">Simple<span class="nav-brand-php">PHP</span>IPAM</a>
  </div>
  <h1>Two-Factor Authentication</h1>
  <p class="muted">Enter the 6-digit code from your authenticator app for <strong><?= e($username) ?></strong>.</p>
  <?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>

  <form method="post" action="totp_verify.php" autocomplete="off" id="totp-verify-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="use_backup" value="0" id="use-backup-hidden">
    <div class="row" id="totp-code-row">
      <label>Authentication code<br>
        <input type="text" name="code" id="totp-code-input"
               inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
               placeholder="000000" autocomplete="one-time-code" autofocus required
               style="font-family:var(--font-mono);letter-spacing:0.15em;max-width:140px;">
      </label>
    </div>
    <div class="row hidden" id="backup-code-row">
      <label>Backup code<br>
        <input type="text" name="code" id="backup-code-input"
               maxlength="19" placeholder="XXXXXXXX-XXXXXXXX" autocomplete="off"
               disabled
               style="font-family:var(--font-mono);max-width:200px;">
      </label>
    </div>
    <p><button type="submit">Verify</button></p>
  </form>

  <p style="margin-top:12px;">
    <a href="#" id="toggle-backup" class="muted font-sm">Use a backup code instead</a>
  </p>
  <p><a href="login.php" class="muted font-sm">Cancel — back to login</a></p>
</div>
</div>
<?php page_footer(); ?>
