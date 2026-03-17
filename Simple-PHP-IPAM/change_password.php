<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';
$cur = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = (string)($_POST['old_password'] ?? '');
    $new1 = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if ($new1 !== $new2) $err = 'New passwords do not match.';
    elseif (strlen($new1) < 12) $err = 'Password must be at least 12 characters.';
    else {
        $st = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
        $st->execute([':id' => $cur['id']]);
        $row = $st->fetch();

        if (!$row || !password_verify($old, $row['password_hash'])) $err = 'Current password is incorrect.';
        else {
            $hash = password_hash($new1, PASSWORD_DEFAULT);
            $up = $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
            $up->execute([':h' => $hash, ':id' => $cur['id']]);
            audit($db, 'user.password_change', 'user', $cur['id'], 'self');
            $msg = 'Password updated.';
        }
    }
}

page_header('Change Password');
?>
<h1>Change Password</h1>
<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

<form method="post" action="change_password.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="row">
    <label>Current password<br><input type="password" name="old_password" required></label>
  </div>
  <div class="row">
    <label>New password<br><input type="password" name="new_password" required></label>
    <label>Repeat new password<br><input type="password" name="new_password2" required></label>
  </div>
  <p class="muted">Minimum 12 characters.</p>
  <p><button type="submit">Update</button></p>
</form>
<?php page_footer();
