<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'readonly');

        if ($username === '' || !preg_match('~^[a-zA-Z0-9_.-]{3,32}$~', $username)) $err = 'Username must be 3-32 chars (letters/numbers/._-).';
        elseif (strlen($password) < 12) $err = 'Password must be at least 12 characters.';
        elseif (!in_array($role, ['admin', 'readonly'], true)) $err = 'Invalid role.';
        else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st = $db->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (:u,:h,:r,1)");
                $st->execute([':u' => $username, ':h' => $hash, ':r' => $role]);
                audit($db, 'user.create', 'user', (int)$db->lastInsertId(), "username=$username role=$role");
                $msg = 'User created.';
            } catch (PDOException $e) {
                $err = 'Could not create user (duplicate username?).';
            }
        }
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $db->prepare("UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id = :id");
        $st->execute([':id' => $id]);
        audit($db, 'user.toggle_active', 'user', $id, '');
        $msg = 'User updated.';
    } elseif ($action === 'set_role') {
        $id = (int)($_POST['id'] ?? 0);
        $role = (string)($_POST['role'] ?? '');
        if (!in_array($role, ['admin', 'readonly'], true)) $err = 'Invalid role.';
        else {
            $st = $db->prepare("UPDATE users SET role = :r WHERE id = :id");
            $st->execute([':r' => $role, ':id' => $id]);
            audit($db, 'user.set_role', 'user', $id, "role=$role");
            $msg = 'Role updated.';
        }
    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $pw = (string)($_POST['new_password'] ?? '');
        if (strlen($pw) < 12) $err = 'Password must be at least 12 characters.';
        else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $st = $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
            $st->execute([':h' => $hash, ':id' => $id]);
            audit($db, 'user.reset_password', 'user', $id, 'admin reset');
            $msg = 'Password reset.';
        }
    }
}

$st = $db->prepare("SELECT id, username, role, is_active, created_at, updated_at FROM users ORDER BY username ASC");
$st->execute();
$users = $st->fetchAll();

page_header('Users');
?>
<h1>Users</h1>
<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p><?= e($msg) ?></p><?php endif; ?>

<h2>Create user</h2>
<form method="post" action="users.php">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>Username<br><input name="username" required></label>
    <label>Password<br><input type="password" name="password" required></label>
    <label>Role<br>
      <select name="role">
        <option value="readonly">readonly</option>
        <option value="admin">admin</option>
      </select>
    </label>
    <button type="submit">Create</button>
  </div>
</form>

<h2>Existing users</h2>
<table>
  <thead>
    <tr>
      <th>Username</th><th>Role</th><th>Active</th><th>Created</th><th>Updated</th><th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['username']) ?></td>
      <td><?= e($u['role']) ?></td>
      <td><?= ((int)$u['is_active'] === 1) ? 'yes' : 'no' ?></td>
      <td class="muted"><?= e($u['created_at']) ?></td>
      <td class="muted"><?= e($u['updated_at']) ?></td>
      <td>
        <form method="post" action="users.php" class="row" style="gap:6px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="action" value="toggle_active">
          <button type="submit"><?= ((int)$u['is_active'] === 1) ? 'Disable' : 'Enable' ?></button>
        </form>

        <form method="post" action="users.php" class="row" style="gap:6px; margin-top:6px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="set_role">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <select name="role">
            <option value="readonly" <?= ($u['role']==='readonly')?'selected':'' ?>>readonly</option>
            <option value="admin" <?= ($u['role']==='admin')?'selected':'' ?>>admin</option>
          </select>
          <button type="submit">Set role</button>
        </form>

        <form method="post" action="users.php" class="row" style="gap:6px; margin-top:6px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset_password">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="password" name="new_password" placeholder="New password" required>
          <button type="submit">Reset PW</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php page_footer();
