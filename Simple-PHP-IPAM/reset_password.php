<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

if (is_logged_in()) { header('Location: dashboard.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

// A valid token is always 64 hex chars from bin2hex(random_bytes(32))
$rawToken = trim(to_str($_GET['token'] ?? ''));
if (!preg_match('/^[0-9a-f]{64}$/i', $rawToken)) {
    $rawToken = '';
}

$errors = [];
$done   = false;

// Peek validity without consuming (for both GET and POST display)
$tokenValid = false;
if ($rawToken !== '') {
    $peekHash = hash('sha256', $rawToken);
    $peekSt   = $db->prepare(
        "SELECT id FROM password_reset_tokens
          WHERE token_hash = :hash AND used_at IS NULL
            AND expires_at > datetime('now')"
    );
    $peekSt->execute([':hash' => $peekHash]);
    $tokenValid = (bool)$peekSt->fetch();
}

$pwPolicy = [
    'min_length'        => to_int(ipam_setting('password_policy.min_length', 12)),
    'require_uppercase' => (bool)ipam_setting('password_policy.require_uppercase', false),
    'require_lowercase' => (bool)ipam_setting('password_policy.require_lowercase', false),
    'require_number'    => (bool)ipam_setting('password_policy.require_number', false),
    'require_symbol'    => (bool)ipam_setting('password_policy.require_symbol', false),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new1 = to_str($_POST['new_password']  ?? '');
    $new2 = to_str($_POST['new_password2'] ?? '');

    if (!$tokenValid) {
        $errors[] = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif ($new1 === '') {
        $errors[] = 'New password cannot be empty.';
    } elseif ($new1 !== $new2) {
        $errors[] = 'Passwords do not match.';
    } else {
        $pwErrors = validate_password_complexity($new1, $pwPolicy);
        if ($pwErrors) {
            $errors = $pwErrors;
        } else {
            // Consume the token (single-use) — only happens after all other validation passes
            $consumedUserId = ipam_consume_reset_token($db, $rawToken);
            if ($consumedUserId === null) {
                $errors[] = 'This reset link is invalid or has already been used.';
            } else {
                $hash = password_hash($new1, PASSWORD_DEFAULT);
                $db->prepare(
                    "UPDATE users SET password_hash = :h,
                                      password_changed_at = " . ipam_dialect()->now() . "
                      WHERE id = :id"
                )->execute([':h' => $hash, ':id' => $consumedUserId]);
                audit($db, 'user.reset_password', 'user', $consumedUserId, 'via email reset token');
                $done = true;
            }
        }
    }
}

$appName  = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
$minLen = max(1, to_int(ipam_setting('password_policy.min_length', 12)));

page_header('Reset Password', ['no_sidebar' => true]);
?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <a href="login.php" class="nav-brand">Simple<span class="nav-brand-php">PHP</span>IPAM</a>
  </div>
  <h1>Set New Password</h1>

  <?php if ($done): ?>
    <p class="success">Your password has been updated. You can now log in with your new password.</p>
    <p><a href="login.php">← Log in</a></p>

  <?php elseif (!$tokenValid && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
    <p class="danger">This reset link is invalid or has expired.</p>
    <p><a href="forgot_password.php">Request a new reset link</a></p>

  <?php else: ?>
    <?php if ($errors): ?>
      <ul class="danger list-indent">
        <?php foreach ($errors as $errItem): ?><li><?= e($errItem) // nosemgrep ?></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form method="post" action="reset_password.php?token=<?php echo e($rawToken); // nosemgrep ?>" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row">
        <label>New password<br><input type="password" name="new_password" required autocomplete="new-password"></label>
        <label>Repeat new password<br><input type="password" name="new_password2" required autocomplete="new-password"></label>
      </div>
      <p class="muted">Minimum <?= (int)$minLen ?> characters<?php
          $reqs = [];
          if (!empty($pwPolicy['require_uppercase'])) $reqs[] = 'uppercase letter';
          if (!empty($pwPolicy['require_lowercase'])) $reqs[] = 'lowercase letter';
          if (!empty($pwPolicy['require_number']))    $reqs[] = 'number';
          if (!empty($pwPolicy['require_symbol']))    $reqs[] = 'special character';
          echo $reqs ? ', plus at least one: ' . implode(', ', $reqs) : '';
      ?>.</p>
      <p><button type="submit">Set new password</button></p>
    </form>
    <p class="muted mt-12"><a href="login.php">← Back to login</a></p>
  <?php endif; ?>
</div>
</div>
<?php page_footer();
