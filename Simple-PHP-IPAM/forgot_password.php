<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

if (is_logged_in()) { header('Location: dashboard.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim(to_str($_POST['identifier'] ?? ''));

    if ($identifier === '') {
        $error = 'Please enter your username or email address.';
    } else {
        // Look up user by username OR email
        $st = $db->prepare(
            "SELECT id, email, name FROM users
              WHERE is_active = 1
                AND (username = :id1 OR email = :id2)
              LIMIT 1"
        );
        $st->execute([':id1' => $identifier, ':id2' => $identifier]);
        /** @var array<string, mixed>|false $user */
        $user = $st->fetch();

        if ($user && to_str($user['email']) !== '') {
            $rawToken = ipam_create_reset_token($db, to_int($user['id']));
            if ($rawToken !== null) {
                ipam_send_reset_email(
                    to_str($user['email']),
                    to_str($user['name'] ?? ''),
                    $rawToken
                );
            }
            // Rate-limit exceeded is intentionally indistinguishable from success
        }

        // Always show the same confirmation to prevent user enumeration
        $sent = true;
    }
}

$appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
page_header('Forgot Password', ['no_sidebar' => true]);
?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <a href="login.php" class="nav-brand">Simple<span class="nav-brand-php">PHP</span>IPAM</a>
  </div>
  <h1>Reset Password</h1>

  <?php if ($sent): ?>
    <p class="success">If an account with that username or email exists, a reset link has been sent. Check your inbox (and spam folder).</p>
    <p><a href="login.php">← Back to login</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="forgot_password.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="row">
        <label>Username or email address<br>
          <input name="identifier" type="text" required autocomplete="username">
        </label>
      </div>
      <p><button type="submit">Send reset link</button></p>
    </form>
    <p class="muted mt-12"><a href="login.php">← Back to login</a></p>
  <?php endif; ?>
</div>
</div>
<?php page_footer();
