<?php
declare(strict_types=1);
/** @var int    $step */
/** @var string $secret   only step 1 */
/** @var string $uri      only step 1 */
/** @var string $error */
/** @var array<int,string> $codes  only step 3 */
/** @var string $appName */
?>
<?php if ($step === 3): ?>
<?php page_header('Two-Factor Authentication — Backup Codes'); ?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="change_password.php">Account</a><span class="sep">›</span>
  <span>Backup Codes</span>
</div>
<h1>Save Your Backup Codes</h1>
<div class="card">
  <p class="warning">Store these somewhere safe. Each code can only be used once. If you lose access to your authenticator app, these codes are the only way to recover your account.</p>
  <div class="totp-backup-grid">
    <?php foreach ($codes as $c): ?>
      <div class="totp-backup-code"><?= e(to_str($c)) ?></div>
    <?php endforeach; ?>
  </div>
  <p class="muted">You have <?= count($codes) ?> backup codes. Keep them private.</p>
  <a href="change_password.php" class="btn">Done — go to Account</a>
</div>
<?php page_footer(); ?>
<?php else: ?>
<?php page_header('Two-Factor Authentication Setup'); ?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="change_password.php">Account</a><span class="sep">›</span>
  <span>Enable 2FA</span>
</div>
<h1>Secure Your Account</h1>
<?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>
<?php if ($secret === ''): ?>
  <p class="warning">Two-factor authentication is unavailable because <code>app_secret</code> is not configured.</p>
<?php else: ?>
<div class="card">
  <p>Scan the QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.), then enter the 6-digit code to confirm.</p>
  <div class="totp-qr-container">
    <div id="totp-qr" data-uri="<?= e($uri) ?>"></div>
    <p class="muted">Can't scan? Enter this code manually:</p>
    <div class="totp-secret-box"><?= e($secret) ?></div>
  </div>
  <form method="post" action="totp_enroll.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="step" value="2">
    <div class="row">
      <label>Verification code<br>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7"
               placeholder="000000" autocomplete="one-time-code" autofocus required
               style="font-family:var(--font-mono);letter-spacing:0.15em;max-width:140px;">
      </label>
    </div>
    <p><button type="submit">Verify and Enable 2FA</button></p>
  </form>
</div>
<script src="assets/vendor/qrcode.min.js?v=3.6.0"></script>
<?php endif; ?>
<?php page_footer(); ?>
<?php endif; ?>
