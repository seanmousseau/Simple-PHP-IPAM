<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$err = '';
$msg = '';
$self = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = (string)($_POST['role']     ?? 'readonly');
        $name     = trim((string)($_POST['name']  ?? ''));
        $email    = trim((string)($_POST['email'] ?? ''));

        if ($username === '' || !preg_match('~^[a-zA-Z0-9_.\-@]{3,64}$~', $username)) {
            $err = 'Username must be 3–64 chars (letters, numbers, _ . - @).';
        } elseif (strlen($password) < 12) {
            $err = 'Password must be at least 12 characters.';
        } elseif (!in_array($role, ['admin', 'readonly'], true)) {
            $err = 'Invalid role.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $st = $db->prepare(
                    "INSERT INTO users (username, password_hash, role, is_active, name, email)
                     VALUES (:u,:h,:r,1,:n,:e)"
                );
                $st->execute([':u' => $username, ':h' => $hash, ':r' => $role, ':n' => $name, ':e' => $email]);
                audit($db, 'user.create', 'user', (int)$db->lastInsertId(), "username=$username role=$role");
                $msg = 'User created.';
            } catch (PDOException $e) {
                $err = 'Could not create user (duplicate username?).';
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id = :id")
           ->execute([':id' => $id]);
        audit($db, 'user.toggle_active', 'user', $id, '');
        $msg = 'User updated.';

    } elseif ($action === 'set_role') {
        $id   = (int)($_POST['id']   ?? 0);
        $role = (string)($_POST['role'] ?? '');
        if (!in_array($role, ['admin', 'readonly'], true)) {
            $err = 'Invalid role.';
        } else {
            $db->prepare("UPDATE users SET role = :r WHERE id = :id")
               ->execute([':r' => $role, ':id' => $id]);
            audit($db, 'user.set_role', 'user', $id, "role=$role");
            $msg = 'Role updated.';
        }

    } elseif ($action === 'update_profile') {
        $id    = (int)($_POST['id']    ?? 0);
        $name  = trim((string)($_POST['name']  ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $db->prepare("UPDATE users SET name = :n, email = :e WHERE id = :id")
           ->execute([':n' => $name, ':e' => $email, ':id' => $id]);
        audit($db, 'user.update_profile', 'user', $id, '');
        $msg = 'Profile updated.';

    } elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $pw = (string)($_POST['new_password'] ?? '');
        if (strlen($pw) < 12) {
            $err = 'Password must be at least 12 characters.';
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id")
               ->execute([':h' => $hash, ':id' => $id]);
            audit($db, 'user.reset_password', 'user', $id, 'admin reset');
            $msg = 'Password reset.';
        }

    } elseif ($action === 'link_oidc') {
        $id  = (int)($_POST['id']       ?? 0);
        $sub = trim((string)($_POST['oidc_sub'] ?? ''));
        if ($sub === '') {
            $err = 'OIDC subject ID is required.';
        } else {
            try {
                $db->prepare("UPDATE users SET oidc_sub = :sub WHERE id = :id")
                   ->execute([':sub' => $sub, ':id' => $id]);
                audit($db, 'user.oidc_link', 'user', $id, 'manual sub=' . $sub);
                $msg = 'OIDC subject linked.';
            } catch (PDOException $e) {
                $err = 'Could not link: subject ID may already be assigned to another user.';
            }
        }

    } elseif ($action === 'unlink_oidc') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare("UPDATE users SET oidc_sub = NULL WHERE id = :id")
           ->execute([':id' => $id]);
        audit($db, 'user.oidc_unlink', 'user', $id, '');
        $msg = 'OIDC link removed.';

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $self['id']) {
            $err = 'You cannot delete your own account.';
        } else {
            $tSt = $db->prepare("SELECT role, is_active FROM users WHERE id = :id");
            $tSt->execute([':id' => $id]);
            $target = $tSt->fetch();
            // Only guard active admins — deleting an inactive admin can never remove the last active one
            if ($target && $target['role'] === 'admin' && (int)$target['is_active'] === 1) {
                $cntSt = $db->prepare(
                    "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id != :id"
                );
                $cntSt->execute([':id' => $id]);
                if ((int)$cntSt->fetchColumn() === 0) {
                    $err = 'Cannot delete the last active admin account.';
                }
            }
            if (!$err) {
                $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
                audit($db, 'user.delete', 'user', $id, '');
                $msg = 'User deleted.';
            }
        }
    }
}

$st = $db->prepare(
    "SELECT id, username, name, email, role, is_active, created_at, updated_at, oidc_sub
     FROM users ORDER BY username ASC"
);
$st->execute();
$users = $st->fetchAll();

page_header('Users');
?>
<h1>Users</h1>
<?php if ($err): ?><p class="danger"><?= e($err) ?></p><?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<h2>Create user</h2>
<form method="post" action="users.php">
  <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>Username<br><input name="username" required></label>
    <label>Full name<br><input name="name" placeholder="Jane Smith"></label>
    <label>Email<br><input type="email" name="email" placeholder="jane@example.com"></label>
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

<h2 style="margin-top:24px">Existing users</h2>
<table>
  <thead>
    <tr>
      <th>Username</th>
      <th>Name</th>
      <th>Email</th>
      <th>Role</th>
      <th>Active</th>
      <th>SSO</th>
      <th>Created</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['username']) ?></td>
      <td><?= e((string)$u['name']) ?></td>
      <td><?= e((string)$u['email']) ?></td>
      <td><?= e($u['role']) ?></td>
      <td><?= ((int)$u['is_active'] === 1) ? 'yes' : 'no' ?></td>
      <td>
        <?php if ($u['oidc_sub'] !== null): ?>
          <span class="success" title="<?= e((string)$u['oidc_sub']) ?>">linked</span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td class="muted"><?= e($u['created_at']) ?></td>
      <td>
        <details>
          <summary class="muted" style="cursor:pointer;font-size:.9em">Actions ▾</summary>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">

            <form method="post" action="users.php" class="row" style="gap:6px">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="action" value="toggle_active">
              <button type="submit"><?= ((int)$u['is_active'] === 1) ? 'Disable' : 'Enable' ?></button>
            </form>

            <form method="post" action="users.php" class="row" style="gap:6px">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_role">
              <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
              <select name="role">
                <option value="readonly" <?= $u['role']==='readonly'?'selected':'' ?>>readonly</option>
                <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>admin</option>
              </select>
              <button type="submit">Set role</button>
            </form>

            <form method="post" action="users.php" class="row" style="gap:6px">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
              <input name="name"  placeholder="Full name"  value="<?= e((string)$u['name']) ?>">
              <input type="email" name="email" placeholder="Email" value="<?= e((string)$u['email']) ?>">
              <button type="submit">Save profile</button>
            </form>

            <form method="post" action="users.php" class="row" style="gap:6px">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
              <input type="password" name="new_password" placeholder="New password (12+ chars)" required>
              <button type="submit">Reset PW</button>
            </form>

            <?php if ($u['oidc_sub'] !== null): ?>
              <form method="post" action="users.php" class="row" style="gap:6px"
                    onsubmit="return confirm('Remove SSO link for <?= e((string)$u['username']) ?>?')">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlink_oidc">
                <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
                <button type="submit" class="button-secondary">Unlink SSO</button>
              </form>
            <?php else: ?>
              <form method="post" action="users.php" class="row" style="gap:6px">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="link_oidc">
                <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
                <input name="oidc_sub" placeholder="IdP subject ID (sub claim)" style="min-width:220px">
                <button type="submit" class="button-secondary">Link SSO</button>
              </form>
            <?php endif; ?>

            <?php if ((int)$u['id'] !== $self['id']): ?>
              <form method="post" action="users.php"
                    onsubmit="return confirm('Permanently delete user <?= e((string)$u['username']) ?>?')">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
                <button type="submit" class="button-danger">Delete user</button>
              </form>
            <?php endif; ?>

          </div>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php page_footer();
