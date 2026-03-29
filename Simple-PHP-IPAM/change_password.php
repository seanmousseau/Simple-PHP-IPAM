<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$errors = [];
$msg = '';
$cur = current_user();

// Fetch the full user row to check for SSO-only account
$st = $db->prepare("SELECT password_hash, oidc_sub FROM users WHERE id = :id");
$st->execute([':id' => $cur['id']]);
$userRow = $st->fetch();

// SSO-only: unusable hash starts with '!' (password_verify always returns false)
$isSsoOnly = $userRow && str_starts_with((string)$userRow['password_hash'], '!');

$pwPolicy  = (array)(($config ?? [])['password_policy'] ?? []);
$isExpired = isset($_GET['expired']);

if (!$isSsoOnly && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $old  = (string)($_POST['old_password']  ?? '');
    $new1 = (string)($_POST['new_password']  ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if ($new1 !== $new2) {
        $errors[] = 'New passwords do not match.';
    } else {
        $pwErrors = validate_password_complexity($new1, $pwPolicy);
        if ($pwErrors) {
            $errors = $pwErrors;
        } elseif (!$userRow || !password_verify($old, $userRow['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new1, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = :h, password_changed_at = datetime('now') WHERE id = :id")
               ->execute([':h' => $hash, ':id' => $cur['id']]);
            audit($db, 'user.password_change', 'user', $cur['id'], 'self');
            $msg = 'Password updated.';
            $isExpired = false;
        }
    }
}

$minLen = max(1, (int)($pwPolicy['min_length'] ?? 12));

page_header('Change Password');
?>
<h1>Change Password</h1>
<?php if ($isSsoOnly): ?>
  <p class="muted">Your account authenticates via SSO. Password changes are managed through your identity provider.</p>
<?php else: ?>
  <?php if ($isExpired && !$msg): ?>
    <p class="danger">Your password has expired. Please set a new password to continue.</p>
  <?php endif; ?>
  <?php if ($errors): ?>
    <ul class="danger" style="margin:0 0 12px;padding-left:1.4em">
      <?php foreach ($errors as $e_msg): ?><li><?= e($e_msg) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>
  <form method="post" action="change_password.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="row">
      <label>Current password<br><input type="password" name="old_password" required></label>
    </div>
    <div class="row">
      <label>New password<br><input type="password" name="new_password" required></label>
      <label>Repeat new password<br><input type="password" name="new_password2" required></label>
    </div>
    <p class="muted">Minimum <?= (int)$minLen ?> characters<?php
        $reqs = [];
        if (!empty($pwPolicy['require_uppercase'])) $reqs[] = 'uppercase letter';
        if (!empty($pwPolicy['require_lowercase'])) $reqs[] = 'lowercase letter';
        if (!empty($pwPolicy['require_number']))    $reqs[] = 'number';
        if (!empty($pwPolicy['require_symbol']))    $reqs[] = 'special character';
        echo $reqs ? ', plus at least one: ' . implode(', ', $reqs) : '';
    ?>.</p>
    <p><button type="submit">Update</button></p>
  </form>
<?php endif; ?>
<?php page_footer();
