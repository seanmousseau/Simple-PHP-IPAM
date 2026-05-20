<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

// B.P3 (#950): the role set is a fixed-shape compile-time constant. Lifting
// it to a file-scope `const` removes the per-POST-handler re-declaration and
// avoids re-allocating the array on every request hot path.
const USERS_VALID_ROLES = ['admin', 'netops', 'readonly'];

$errors = [];
$msg    = '';
$self   = current_user();
// B18: PDO may return integer columns as strings depending on engine + driver
// options (notably MySQL ATTR_STRINGIFY_FETCHES). The self-protection guards
// below use strict equality against to_int($_POST['id']), so $self['id'] must
// also be an int — otherwise '5' === 5 fails and a user could silently
// disable/unlink/delete their own account.
$selfId = to_int($self['id']);
// Form data preserved across failed create attempts (non-sensitive fields only)
$formData = ['username' => '', 'name' => '', 'email' => '', 'role' => 'readonly', 'sso_only' => false, 'oidc_sub' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = to_str($_POST['action'] ?? '');

    $pwPolicy   = [
        'min_length'            => to_int(ipam_setting('password_policy.min_length')),
        'require_uppercase'     => (bool)ipam_setting('password_policy.require_uppercase'),
        'require_lowercase'     => (bool)ipam_setting('password_policy.require_lowercase'),
        'require_number'        => (bool)ipam_setting('password_policy.require_number'),
        'require_symbol'        => (bool)ipam_setting('password_policy.require_symbol'),
        'max_password_age_days' => to_int(ipam_setting('password_policy.max_password_age_days')),
    ];

    // Demo mode: block all mutations
    if (demo_mode_enabled() && in_array($action, ['create', 'delete', 'toggle_active', 'set_role'], true)) {
        $errors[] = 'This action is disabled in demo mode.';
        $action = '';
    }

    if ($action === 'create') {
        $username = trim(to_str($_POST['username'] ?? ''));
        $role     = to_str($_POST['role']     ?? 'readonly');
        $name     = substr(trim(to_str($_POST['name']  ?? '')), 0, 255);
        $email    = substr(trim(to_str($_POST['email'] ?? '')), 0, 255);
        $ssoOnly  = !empty($_POST['sso_only']);
        $oidcSub  = trim(to_str($_POST['oidc_sub'] ?? ''));

        // Preserve submitted values for re-populating the form on failure
        $formData = ['username' => $username, 'name' => $name, 'email' => $email,
                     'role' => $role, 'sso_only' => $ssoOnly, 'oidc_sub' => $oidcSub];

        if ($username === '' || !preg_match('~^[a-zA-Z0-9_.\-@]{3,64}$~', $username)) {
            $errors[] = 'Username must be 3–64 chars (letters, numbers, _ . - @).';
        } elseif (!in_array($role, USERS_VALID_ROLES, true)) {
            $errors[] = 'Invalid role.';
        }
        $password = '';
        if (!$ssoOnly && !$errors) {
            $password = to_str($_POST['password'] ?? '');
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
                         VALUES (:u,:h,:r,1,:n,:e," . ipam_dialect()->now() . ")"
                    );
                    $st->execute([':u' => $username, ':h' => $hash, ':r' => $role, ':n' => $name, ':e' => $email]);
                    $details = "username=$username role=$role";
                }
                audit($db, 'user.create', 'user', ipam_last_insert_id($db, 'users'), $details);
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
        $id = to_int($_POST['id'] ?? 0);
        if ($id === $selfId) {
            $errors[] = 'You cannot disable your own account.';
        } else {
            // Last-active-admin guard: prevent disabling the last active admin
            $tSt = $db->prepare("SELECT role, is_active FROM users WHERE id = :id");
            $tSt->execute([':id' => $id]);
            /** @var array<string, mixed>|false $target */
            $target = $tSt->fetch();
            if ($target && $target['role'] === 'admin' && to_int($target['is_active']) === 1) {
                $cntSt = $db->prepare(
                    "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id != :id"
                );
                $cntSt->execute([':id' => $id]);
                if ((int)$cntSt->fetchColumn() === 0) {
                    $errors[] = 'Cannot disable the last active admin account.';
                }
            }
            if (!$errors) {
                $db->prepare("UPDATE users SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id = :id")
                   ->execute([':id' => $id]);
                audit($db, 'user.toggle_active', 'user', $id, '');
                $msg = 'User updated.';
            }
        }

    } elseif ($action === 'set_role') {
        $id   = to_int($_POST['id']   ?? 0);
        $role = to_str($_POST['role'] ?? '');
        if ($id === $selfId) {
            $errors[] = 'You cannot change your own role.';
        } elseif (!in_array($role, USERS_VALID_ROLES, true)) {
            $errors[] = 'Invalid role.';
        } else {
            // Last-active-admin guard: prevent demoting the last active admin
            if ($role !== 'admin') {
                $tSt = $db->prepare("SELECT role, is_active FROM users WHERE id = :id");
                $tSt->execute([':id' => $id]);
                /** @var array<string, mixed>|false $target */
                $target = $tSt->fetch();
                if ($target && $target['role'] === 'admin' && to_int($target['is_active']) === 1) {
                    $cntSt = $db->prepare(
                        "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id != :id"
                    );
                    $cntSt->execute([':id' => $id]);
                    if ((int)$cntSt->fetchColumn() === 0) {
                        $errors[] = 'Cannot demote the last active admin account.';
                    }
                }
            }
            if (!$errors) {
                $db->prepare("UPDATE users SET role = :r WHERE id = :id")
                   ->execute([':r' => $role, ':id' => $id]);
                audit($db, 'user.set_role', 'user', $id, "role=$role");
                // Bug T (Pass A 2026-05-08, v3.27.1) — KNOWN LIMITATION:
                // auth-model.md "Step-up auth" lists role-downgrade as a sudo-grant
                // invalidation event. We cannot fully honour that contract
                // here: ipam_sudo_invalidate() clears $_SESSION on the
                // CURRENT request only, but the demoted user is necessarily
                // a DIFFERENT user (the self-protection guard above blocks
                // self-demotion). Cross-user session invalidation requires
                // a per-user session-marker mechanism (e.g. a
                // users.sudo_invalidate_after timestamp consulted by
                // ipam_sudo_active()) which is out of scope for this hotfix.
                // Tracked for v3.28.0. The same limitation applies to
                // link_oidc/unlink_oidc admin actions below.
                $msg = 'Role updated.';
            }
        }

    } elseif ($action === 'update_profile') {
        $id    = to_int($_POST['id']    ?? 0);
        $name  = substr(trim(to_str($_POST['name']  ?? '')), 0, 255);
        $email = substr(trim(to_str($_POST['email'] ?? '')), 0, 255);
        $db->prepare("UPDATE users SET name = :n, email = :e WHERE id = :id")
           ->execute([':n' => $name, ':e' => $email, ':id' => $id]);
        audit($db, 'user.update_profile', 'user', $id, '');
        $msg = 'Profile updated.';

    } elseif ($action === 'reset_password') {
        $id     = to_int($_POST['id'] ?? 0);
        $pw     = to_str($_POST['new_password'] ?? '');
        $pwErrs = validate_password_complexity($pw, $pwPolicy);
        if ($pwErrs) {
            $errors = $pwErrs;
        } else {
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = :h, password_changed_at = " . ipam_dialect()->now() . " WHERE id = :id")
               ->execute([':h' => $hash, ':id' => $id]);
            audit($db, 'user.reset_password', 'user', $id, 'admin reset');
            $msg = 'Password reset.';
        }

    } elseif ($action === 'link_oidc') {
        $id  = to_int($_POST['id']       ?? 0);
        $sub = trim(to_str($_POST['oidc_sub'] ?? ''));
        if ($sub === '') {
            $errors[] = 'OIDC subject ID is required.';
        } else {
            try {
                $db->prepare("UPDATE users SET oidc_sub = :sub WHERE id = :id")
                   ->execute([':sub' => $sub, ':id' => $id]);
                audit($db, 'user.oidc_link', 'user', $id, 'manual sub=' . $sub);
                $msg = 'OIDC subject linked.';
            } catch (PDOException $e) {
                $errors[] = 'Could not link: subject ID may already be assigned to another user.';
            }
        }

    } elseif ($action === 'unlink_oidc') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id === $selfId) {
            $errors[] = 'You cannot unlink your own SSO account from this page. Use your profile settings.';
        } else {
            $db->prepare("UPDATE users SET oidc_sub = NULL WHERE id = :id")
               ->execute([':id' => $id]);
            audit($db, 'user.oidc_unlink', 'user', $id, '');
            $msg = 'OIDC link removed.';
        }

    } elseif ($action === 'unlock_account') {
        $id = to_int($_POST['id'] ?? 0);
        $tSt = $db->prepare("SELECT username FROM users WHERE id = :id");
        $tSt->execute([':id' => $id]);
        /** @var array<string, mixed>|false $target */
        $target = $tSt->fetch();
        if ($target) {
            clear_account_lockout($db, to_str($target['username']));
            ipam_clear_persistent_lockout($db, $id);
            audit($db, 'user.unlock', 'user', $id, '');
            $msg = 'Account unlocked.';
        }

    } elseif ($action === 'reset_totp') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE users SET totp_enabled=0, totp_secret_enc=NULL WHERE id=:id")
               ->execute([':id' => $id]);
            $db->prepare("DELETE FROM totp_backup_codes WHERE user_id=:uid")
               ->execute([':uid' => $id]);
            ipam_clear_persistent_lockout($db, $id);
            audit($db, 'user.totp_reset', 'user', $id, 'admin_reset');
            $msg = 'Two-factor authentication reset for user.';
        }

    } elseif ($action === 'email_otp_reset') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id === $selfId) {
            flash_set('Use the Account page to manage your own Email OTP.', 'warning');
            header('Location: users.php');
            exit;
        }
        if ($id > 0) {
            $db->prepare(
                "UPDATE users SET email_otp_enabled = 0, email_otp_hash = NULL,
                                  email_otp_expires_at = NULL, email_otp_attempts = 0
                  WHERE id = :id"
            )->execute([':id' => $id]);
            audit($db, 'user.email_otp_reset', 'user', $id, 'Admin reset Email OTP enrollment');
            flash_set('Email OTP enrollment reset.', 'success');
            header('Location: users.php');
            exit;
        }

    } elseif ($action === 'passkey_reset') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id === $selfId) {
            flash_set('Use the Account page to manage your own passkeys.', 'warning');
            header('Location: users.php');
            exit;
        }
        if ($id > 0) {
            $targetStmt = $db->prepare("SELECT username FROM users WHERE id = :id");
            $targetStmt->execute([':id' => $id]);
            /** @var array<string,mixed>|false $targetRow */
            $targetRow = $targetStmt->fetch();
            if ($targetRow) {
                ipam_passkey_delete_all($db, $id);
                audit($db, 'user.passkey_reset', 'user', $id, 'admin_reset by ' . to_str($self['username']));
                flash_set('Passkeys cleared for ' . to_str($targetRow['username']), 'success');
            }
            header('Location: users.php');
            exit;
        }

    } elseif ($action === 'delete') {
        $id = to_int($_POST['id'] ?? 0);
        if ($id === $selfId) {
            $errors[] = 'You cannot delete your own account.';
        } else {
            $tSt = $db->prepare("SELECT role, is_active FROM users WHERE id = :id");
            $tSt->execute([':id' => $id]);
            /** @var array<string, mixed>|false $target */
            $target = $tSt->fetch();
            // Only guard active admins — deleting an inactive admin can never remove the last active one
            if ($target && $target['role'] === 'admin' && to_int($target['is_active']) === 1) {
                $cntSt = $db->prepare(
                    "SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1 AND id != :id"
                );
                $cntSt->execute([':id' => $id]);
                if ((int)$cntSt->fetchColumn() === 0) {
                    $errors[] = 'Cannot delete the last active admin account.';
                }
            }
            if (!$errors) {
                $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
                audit($db, 'user.delete', 'user', $id, '');
                $msg = 'User deleted.';
            }
        }
    }
}

$userSortCols = ['username' => 'username', 'role' => 'role', 'last_login' => 'last_login_at'];
$userSort = parse_sort($userSortCols, 'username');

$st = $db->prepare(
    "SELECT id, username, name, email, role, is_active, created_at, updated_at, oidc_sub, last_login_at,
            totp_enabled, email_otp_enabled, locked_until, lock_reason, failed_auth_count
     FROM users ORDER BY {$userSort['sql']}"
);
$st->execute();
/** @var list<array<string, mixed>> $users */
$users = $st->fetchAll();

$pkCounts = [];
$userIds  = array_column($users, 'id');
if ($userIds !== []) {
    $in     = implode(',', array_fill(0, count($userIds), '?'));
    $pkStmt = $db->prepare("SELECT user_id, COUNT(*) AS cnt FROM webauthn_credentials WHERE user_id IN ($in) GROUP BY user_id");
    $pkStmt->execute($userIds);
    foreach ($pkStmt->fetchAll() as $pkRow) {
        $pkCounts[to_int($pkRow['user_id'])] = to_int($pkRow['cnt']);
    }
}

$acctMaxAttempts = to_int(ipam_setting('security.account_lockout_max_attempts'));
$acctLockoutSecs = to_int(ipam_setting('security.account_lockout_seconds'));
$lockedUsers = [];
$cutoff = date('Y-m-d H:i:s', time() - $acctLockoutSecs);
$lockSt = $db->prepare(
    "SELECT username, COUNT(*) AS c FROM login_attempts
     WHERE username IS NOT NULL AND attempted_at >= :cutoff
     GROUP BY username HAVING COUNT(*) >= :max"
);
$lockSt->execute([':cutoff' => $cutoff, ':max' => $acctMaxAttempts]);
foreach ($lockSt->fetchAll() as $lr) {
    $lockedUsers[to_str($lr['username'])] = true;
}

page_header('Users');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <a href="#">Admin</a><span class="sep">›</span>
  <span>Users</span>
</div>

<h1>Users</h1>
<?php if ($errors): ?>
  <ul class="danger list-indent">
    <?php foreach ($errors as $e_msg): ?><li><?= e(to_str($e_msg)) ?></li><?php endforeach; // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>
  </ul>
<?php endif; ?>
<?php if ($msg): ?><p class="success"><?= e($msg) ?></p><?php endif; ?>

<div class="page-actions">
  <button class="action-pill" data-drawer-title="Create User" data-drawer-tpl="tpl-create-user"><?= icon('plus') ?> Create User</button>
</div>
<div id="tpl-create-user" style="display:none"><?= ipam_render_string('user_form', [ // nosemgrep
    'formData' => $formData,
    'config'   => $config,
]) ?></div>

<h2 class="mt-24">Existing users</h2>
<div class="table-wrap">
<table>
  <thead>
    <tr>
      <?php $usrQs = '?';
            echo sort_th('username',   'Username',   $userSort['col'], $userSort['dir'], '?');
      ?>
      <th>Name</th>
      <th>Email</th>
      <?php echo sort_th('role',       'Role',       $userSort['col'], $userSort['dir'], '?'); ?>
      <th>Active</th>
      <th>Locked</th>
      <th>2FA</th>
      <th>Passkeys</th>
      <th>SSO</th>
      <?php echo sort_th('last_login', 'Last Login', $userSort['col'], $userSort['dir'], '?'); ?>
      <th>Created</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e(to_str($u['username'])) ?></td>
      <td><?= e(to_str($u['name'])) ?></td>
      <td><?= e(to_str($u['email'])) ?></td>
      <td><?= e(to_str($u['role'])) ?></td>
      <td><?= (to_int($u['is_active']) === 1) ? 'yes' : 'no' ?></td>
      <td><?php
        $isTimeLocked    = isset($lockedUsers[to_str($u['username'])]);
        $lockedUntilStr  = to_str($u['locked_until'] ?? '');
        $isPersistLocked = $lockedUntilStr !== '' && strtotime($lockedUntilStr . ' UTC') > time();
        $isLocked        = $isTimeLocked || $isPersistLocked;
        $lockReason      = $isPersistLocked ? to_str($u['lock_reason'] ?? '') : '';
      ?>
        <?php if ($isLocked): ?>
          <span class="danger font-sm">Locked<?= $lockReason === 'failed_2fa' ? ' (2FA)' : '' ?></span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td><?= to_int($u['totp_enabled'] ?? 0) === 1 ? '<span class="badge badge--success">On</span>' : '<span class="muted">—</span>' ?></td>
      <td><?php $pkN = $pkCounts[to_int($u['id'])] ?? 0; echo $pkN > 0 ? '<span class="badge badge--success">' . $pkN . '</span>' : '<span class="muted">—</span>'; ?></td>
      <td>
        <?php if ($u['oidc_sub'] !== null): ?>
          <span class="success" title="<?= e(to_str($u['oidc_sub'])) ?>">linked</span>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td class="muted"><?= $u['last_login_at'] ? e(ipam_format_datetime(to_str($u['last_login_at']))) : '<span class="muted">never</span>' ?></td>
      <td class="muted"><?= e(ipam_format_datetime(to_str($u['created_at']))) ?></td>
      <td>
        <details>
          <summary class="muted cursor-pointer font-sm">Actions ▾</summary>
          <div class="flex-col gap-8 mt-8">

            <?php if (to_int($u['id']) !== $selfId): ?>
            <form method="post" action="users.php" class="row gap-6">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <input type="hidden" name="action" value="toggle_active">
              <button type="submit"><?= (to_int($u['is_active']) === 1) ? 'Disable' : 'Enable' ?></button>
            </form>

            <form method="post" action="users.php" class="row gap-6">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="set_role">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <select name="role">
                <option value="readonly" <?= $u['role']==='readonly'?'selected':'' ?>>readonly</option>
                <option value="netops"   <?= $u['role']==='netops'  ?'selected':'' ?>>netops</option>
                <option value="admin"    <?= $u['role']==='admin'   ?'selected':'' ?>>admin</option>
              </select>
              <button type="submit">Set role</button>
            </form>
            <?php endif; ?>

            <form method="post" action="users.php" class="row gap-6">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <input name="name"  placeholder="Full name"  aria-label="Full name" value="<?= e(to_str($u['name'])) ?>" maxlength="255">
              <input type="email" name="email" placeholder="Email" aria-label="Email" value="<?= e(to_str($u['email'])) ?>" maxlength="255">
              <button type="submit">Save profile</button>
            </form>

            <form method="post" action="users.php" class="row gap-6">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <input type="password" name="new_password" placeholder="New password (12+ chars)" aria-label="New password" required autocomplete="new-password">
              <button type="submit">Reset PW</button>
            </form>

            <?php if ($u['oidc_sub'] !== null): ?>
              <?php if (to_int($u['id']) !== $selfId): ?>
              <form method="post" action="users.php" class="row gap-6"
                    data-confirm="Remove SSO link for <?= e(to_str($u['username'])) ?>?">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="unlink_oidc">
                <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
                <button type="submit" class="button-secondary">Unlink SSO</button>
              </form>
              <?php else: ?>
              <span class="muted font-sm">SSO linked (manage in your own profile)</span>
              <?php endif; ?>
            <?php else: ?>
              <form method="post" action="users.php" class="row gap-6">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="link_oidc">
                <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
                <input name="oidc_sub" placeholder="IdP subject ID (sub claim)" aria-label="IdP subject ID" class="mw-220">
                <button type="submit" class="button-secondary">Link SSO</button>
              </form>
            <?php endif; ?>

            <?php if ($isLocked): ?>
            <form method="post" action="users.php" class="row gap-6">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="unlock_account">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <button type="submit" class="button-secondary">Unlock</button>
            </form>
            <?php endif; ?>

            <?php if (to_int($u['totp_enabled'] ?? 0) === 1 && to_int($u['id']) !== $selfId): ?>
            <form method="post" action="users.php" class="row gap-6"
                  data-confirm="Reset 2FA for <?= e(to_str($u['username'])) ?>?">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="reset_totp">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <button type="submit" class="action-pill">
                Reset 2FA
              </button>
            </form>
            <?php endif; ?>

            <?php if (to_int($u['email_otp_enabled'] ?? 0) === 1 && to_int($u['id']) !== $selfId): ?>
            <form method="post" action="users.php" class="row gap-6"
                  data-confirm="Reset Email OTP for <?= e(to_str($u['username'])) ?>?">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="email_otp_reset">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <button type="submit" class="action-pill">
                Reset Email OTP
              </button>
            </form>
            <?php endif; ?>

            <?php if (($pkCounts[to_int($u['id'])] ?? 0) > 0 && to_int($u['id']) !== $selfId): ?>
            <form method="post" action="users.php" class="row gap-6"
                  data-confirm="Delete ALL passkeys for <?= e(to_str($u['username'])) ?>?">
              <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="passkey_reset">
              <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
              <button type="submit" class="action-pill button-danger">
                <?= icon('key') ?> Reset Passkeys
              </button>
            </form>
            <?php endif; ?>

            <?php if (to_int($u['id']) !== $selfId): ?>
              <form method="post" action="users.php"
                    data-confirm="Permanently delete user <?= e(to_str($u['username'])) ?>?">
                <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id"     value="<?= to_int($u['id']) ?>">
                <button type="submit" class="button-danger">Delete user</button>
              </form>
            <?php endif; ?>

            <a class="button-secondary" href="<?= e('audit.php?user_id=' . to_int($u['id']) . '&action=auth.login') ?>">Login history</a>

          </div>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php page_footer();
