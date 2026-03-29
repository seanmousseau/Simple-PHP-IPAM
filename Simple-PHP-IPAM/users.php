<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$errors = [];
$msg    = '';
$self   = current_user();
// Form data preserved across failed create attempts (non-sensitive fields only)
$formData = ['username' => '', 'name' => '', 'email' => '', 'role' => 'readonly', 'sso_only' => false, 'oidc_sub' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    $validRoles = ['admin', 'netops', 'readonly'];
    $pwPolicy   = (array)(($config ?? [])['password_policy'] ?? []);

    if ($action === 'create') {
        $username = trim((string)($_POST['username'] ?? ''));
        $role     = (string)($_POST['role']     ?? 'readonly');
        $name     = substr(trim((string)($_POST['name']  ?? '')), 0, 255);
        $email    = substr(trim((string)($_POST['email'] ?? '')), 0, 255);
        $ssoOnly  = !empty($_POST['sso_only']);
        $oidcSub  = trim((string)($_POST['oidc_sub'] ?? ''));

        // Preserve submitted values for re-populating the form on failure
        $formData = ['username' => $username, 'name' => $name, 'email' => $email,
                     'role' => $role, 'sso_only' => $ssoOnly, 'oidc_sub' => $oidcSub];

        if ($username === '' || !preg_match('~^[a-zA-Z0-9_.\-@]{3,64}$~', $username)) {
            $errors[] = 'Username must be 3–64 chars (letters, numbers, _ . - @).';
        } elseif (!in_array($role, $validRoles, true)) {
            $errors[] = 'Invalid role.';
        }
        if (!$ssoOnly && !$errors) {
            $password = (string)($_POST['password'] ?? '');
            $errors   = array_merge($errors, validate_password_complexity($password, $pwPolicy));
        }

        if (!$errors) {
            try {
                if ($ssoOnly) {
                    // Unusable hash — password_verify() will always return false
                    $hash    = '!' . bin2hex(random_bytes(16));
                    $subVal  = $oidcSub !== '' ? $oidcSub : null;
                    $st = $db->prepare(
                        "INSERT INTO users (username, password_hash, role, is_active, name, email, oidc_sub)
                         VALUES (:u,:h,:r,1,:n,:e,:sub)"
                    );
                    $st->execute([':u' => $username, ':h' => $hash, ':r' => $role,
                                  ':n' => $name, ':e' => $email, ':sub' => $subVal]);
                    $details = "username=$username role=$role sso_only=true" . ($subVal ? " sub=$subVal" : '');
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $st = $db->prepare(
                        "INSERT INTO users (username, password_hash, role, is_active, name, email, password_changed_at)
                         VALUES (:u,:h,:r,1,:n,:e,datetime('now'))"
                    );
                    $st->execute([':u' => $username, ':h' => $hash, ':r' => $role, ':n' => $name, ':e' => $email]);
                    $details = "username=$username role=$role";
                }
                audit($db, 'user.create', 'user', (int)$db->lastInsertId(), $details);
                $msg = 'User created.';
                // Reset form data after successful creation
                $formData = ['username' => '', 'name' => '', 'email' => '', 'role' => 'readonly', 'sso_only' => false, 'oidc_sub' => ''];
            } catch (PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'UNIQUE')
                    ? 'A user with that username already exists.'
                    : 'Could not create user. Please try again.';
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $self['id']) {
            $err = 'You cannot disable your own account.';
        } else {
            $db->prepare("UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id = :id")
               ->execute([':id' => $id]);
            audit($db, 'user.toggle_active', 'user', $id, '');
            $msg = 'User updated.';
        }

    } elseif ($action === 'set_role') {
        $id   = (int)($_POST['id']   ?? 0);
        $role = (string)($_POST['role'] ?? '');
        if ($id === $self['id']) {
            $err = 'You cannot change your own role.';
        } elseif (!in_array($role, $validRoles, true)) {
            $err = 'Invalid role.';
        } else {
            $db->prepare("UPDATE users SET role = :r WHERE id = :id")
               ->execute([':r' => $role, ':id' => $id]);
            audit($db, 'user.set_role', 'user', $id, "role=$role");
            $msg = 'Role updated.';
        }

    } elseif ($action === 'update_profile') {
        $id    = (int)($_POST['id']    ?? 0);
        $name  = substr(trim((string)($_POST['name']  ?? '')), 0, 255);
        $email = substr(trim((string)($_POST['email'] ?? '')), 0, 255);
        $db->prepare("UPDATE users SET name = :n, email = :e WHERE id = :id")
           ->execute([':n' => $name, ':e' => $email, ':id' => $id]);
        audit($db, 'user.update_profile', 'user', $id, '');
        $msg = 'Profile updated.';

    } elseif ($action === 'reset_password') {
        $id     = (int)($_POST['id'] ?? 0);
        $pw     = (string)($_POST['new_password'] ?? '');
        $pwErrs = validate_password_complexity($pw, $pwPolicy);
        if ($pwErrs) {
            $errors = $pwErrs;
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = :h, password_changed_at = datetime('now') WHERE id = :id")
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
        if ($id === $self['id']) {
            $err = 'You cannot unlink your own SSO account from this page. Use your profile settings.';
        } else {
            $db->prepare("UPDATE users SET oidc_sub = NULL WHERE id = :id")
               ->execute([':id' => $id]);
            audit($db, 'user.oidc_unlink', 'user', $id, '');
            $msg = 'OIDC link removed.';
        }

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
    "SELECT id, username, name, email, role, is_active, created_at, updated_at, oidc_sub, last_login_at
     FROM users ORDER BY username ASC"
);
$st->execute();
$users = $st->fetchAll();

page_header('Users');
?>
<h1>Users</h1>
<?php if ($errors): ?>
  <ul class="danger" style="margin:0 0 12px;padding-left:1.4em">
    <?php foreach ($errors as $e_msg): ?><li><?= e($e_msg) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<h2>Create user</h2>
<form method="post" action="users.php" id="create-user-form">
  <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>Username<br><input name="username" required value="<?= e($formData['username']) ?>"></label>
    <label>Full name<br><input name="name" placeholder="Jane Smith" maxlength="255" value="<?= e($formData['name']) ?>"></label>
    <label>Email<br><input type="email" name="email" placeholder="jane@example.com" maxlength="255" value="<?= e($formData['email']) ?>"></label>
    <label id="pw-field">Password<br><input type="password" name="password" id="create-pw-input"></label>
    <?php if (oidc_enabled($config)): ?>
    <label id="sub-field" style="display:none">Subject (sub)<br>
      <input name="oidc_sub" id="create-sub-input" placeholder="IdP sub claim (optional)" value="<?= e($formData['oidc_sub']) ?>">
    </label>
    <?php endif; ?>
    <label>Role<br>
      <select name="role">
        <?php foreach (['readonly', 'netops', 'admin'] as $r): ?>
          <option value="<?= e($r) ?>"<?= $formData['role'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (oidc_enabled($config)): ?>
    <label style="align-self:flex-end;padding-bottom:6px">
      <input type="checkbox" name="sso_only" id="sso-only-toggle" value="1"<?= $formData['sso_only'] ? ' checked' : '' ?>>
      SSO-only account
    </label>
    <?php endif; ?>
    <button type="submit">Create</button>
  </div>
</form>
<?php if (oidc_enabled($config)): ?>
<script>
(function(){
  var toggle = document.getElementById('sso-only-toggle');
  var pwField = document.getElementById('pw-field');
  var pwInput = document.getElementById('create-pw-input');
  var subField = document.getElementById('sub-field');
  if (!toggle) return;
  function applySsoState() {
    var sso = toggle.checked;
    pwField.style.display = sso ? 'none' : '';
    pwInput.required = !sso;
    subField.style.display = sso ? '' : 'none';
  }
  toggle.addEventListener('change', applySsoState);
  // Apply on load so a re-rendered form with sso_only preserved shows correctly
  applySsoState();
}());
</script>
<?php endif; ?>

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
      <th>Last Login</th>
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
      <td class="muted"><?= $u['last_login_at'] ? e($u['last_login_at']) : '<span class="muted">never</span>' ?></td>
      <td class="muted"><?= e($u['created_at']) ?></td>
      <td>
        <details>
          <summary class="muted" style="cursor:pointer;font-size:.9em">Actions ▾</summary>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:8px">

            <?php if ((int)$u['id'] !== $self['id']): ?>
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
                <option value="netops"   <?= $u['role']==='netops'  ?'selected':'' ?>>netops</option>
                <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>admin</option>
              </select>
              <button type="submit">Set role</button>
            </form>
            <?php endif; ?>

            <form method="post" action="users.php" class="row" style="gap:6px">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
              <input name="name"  placeholder="Full name"  value="<?= e((string)$u['name']) ?>" maxlength="255">
              <input type="email" name="email" placeholder="Email" value="<?= e((string)$u['email']) ?>" maxlength="255">
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
              <?php if ((int)$u['id'] !== $self['id']): ?>
              <form method="post" action="users.php" class="row" style="gap:6px"
                    onsubmit="return confirm('Remove SSO link for <?= e((string)$u['username']) ?>?')">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlink_oidc">
                <input type="hidden" name="id"     value="<?= (int)$u['id'] ?>">
                <button type="submit" class="button-secondary">Unlink SSO</button>
              </form>
              <?php else: ?>
              <span class="muted" style="font-size:.9em">SSO linked (manage in your own profile)</span>
              <?php endif; ?>
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
