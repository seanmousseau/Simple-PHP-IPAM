<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

if (demo_mode_enabled()) {
    page_header('Change Password');
    echo "<h1>Change Password</h1>";
    echo "<p class='warning'>Password changes are disabled in demo mode.</p>";
    page_footer();
    exit;
}

$errors = [];
$msg = '';
$cur = current_user();

// Handle email verification token (?verify_email=TOKEN)
if (isset($_GET['verify_email'])) {
    $verifyRaw = trim(to_str($_GET['verify_email']));
    if (preg_match('/^[0-9a-f]{64}$/i', $verifyRaw)) {
        $verHash = hash('sha256', $verifyRaw);
        $verSt   = $db->prepare(
            "SELECT id, pending_email FROM users
              WHERE id = :uid
                AND pending_email_token_hash = :hash
                AND pending_email_expires_at > " . ipam_dialect()->now()
        );
        $verSt->execute([':uid' => $cur['id'], ':hash' => $verHash]);
        /** @var array<string, mixed>|false $verRow */
        $verRow = $verSt->fetch();
        $pendingAddr = $verRow ? to_str($verRow['pending_email']) : '';
        if ($verRow && $pendingAddr !== '') {
            $dupCheckSt = $db->prepare(
                "SELECT id FROM users WHERE (email = :email OR pending_email = :email2) AND id != :id"
            );
            $dupCheckSt->execute([':email' => $pendingAddr, ':email2' => $pendingAddr, ':id' => $cur['id']]);
            if (!$dupCheckSt->fetch()) {
                $upd = $db->prepare(
                    "UPDATE users SET email = :email,
                                      pending_email = NULL,
                                      pending_email_token_hash = NULL,
                                      pending_email_expires_at = NULL
                      WHERE id = :id
                        AND NOT EXISTS (
                            SELECT 1 FROM users u2
                             WHERE (u2.email = :email2 OR u2.pending_email = :email3)
                               AND u2.id != :id2
                        )"
                );
                $upd->execute([
                    ':email'  => $pendingAddr,
                    ':email2' => $pendingAddr,
                    ':email3' => $pendingAddr,
                    ':id'     => $cur['id'],
                    ':id2'    => $cur['id'],
                ]);
                if ($upd->rowCount() === 1) {
                    audit($db, 'user.update_email', 'user', to_int($cur['id']), '');
                    flash_set('Email address verified and updated.');
                } else {
                    flash_set('Verification link is invalid or has expired.', 'danger');
                }
            } else {
                flash_set('Verification link is invalid or has expired.', 'danger');
            }
        } else {
            flash_set('Verification link is invalid or has expired.', 'danger');
        }
    } else {
        flash_set('Verification link is invalid.', 'danger');
    }
    header('Location: change_password.php');
    exit;
}

// Fetch the full user row to check for SSO-only account
$st = $db->prepare(
    "SELECT password_hash, oidc_sub, timezone, email,
            pending_email, pending_email_expires_at
       FROM users WHERE id = :id"
);
$st->execute([':id' => $cur['id']]);
/** @var array<string, mixed>|false $userRow */
$userRow = $st->fetch();

// SSO-only: unusable hash starts with '!' (password_verify always returns false)
$isSsoOnly = $userRow && str_starts_with(to_str($userRow['password_hash']), '!');

$pwPolicy  = (array)(($config ?? [])['password_policy'] ?? []);
$isExpired = isset($_GET['expired']);

if (!$isSsoOnly && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $old  = to_str($_POST['old_password']  ?? '');
    $new1 = to_str($_POST['new_password']  ?? '');
    $new2 = to_str($_POST['new_password2'] ?? '');

    if ($new1 !== $new2) {
        $errors[] = 'New passwords do not match.';
    } else {
        $pwErrors = validate_password_complexity($new1, $pwPolicy);
        if ($pwErrors) {
            $errors = $pwErrors;
        } elseif (!$userRow || !password_verify($old, to_str($userRow['password_hash']))) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $hash = password_hash($new1, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = :h, password_changed_at = " . ipam_dialect()->now() . " WHERE id = :id")
               ->execute([':h' => $hash, ':id' => $cur['id']]);
            audit($db, 'user.change_password', 'user', to_int($cur['id']), 'self');
            $msg = 'Password updated.';
            $isExpired = false;
        }
    }
}

// Timezone preference update (available to all users, including SSO-only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('timezone', $_POST)) {
    $newTz = to_str($_POST['timezone']);
    if ($newTz === '' || in_array($newTz, timezone_identifiers_list(), true)) {
        $db->prepare("UPDATE users SET timezone = :tz WHERE id = :id")
           ->execute([':tz' => ($newTz === '' ? null : $newTz), ':id' => $cur['id']]);
        audit($db, 'user.update_profile', 'user', to_int($cur['id']), 'timezone=' . ($newTz ?: 'app-default'));
        flash_set('Timezone preference saved.');
    } else {
        flash_set('Invalid timezone identifier.', 'danger');
    }
    header('Location: change_password.php');
    exit;
}

// Email change request (POST with new_email field)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists('new_email', $_POST)) {
    $newEmail = trim(to_str($_POST['new_email'] ?? ''));
    if ($newEmail === '') {
        flash_set('Email address cannot be empty.', 'danger');
    } elseif (filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
        flash_set('Invalid email address format.', 'danger');
    } else {
        $dupSt = $db->prepare(
            "SELECT id FROM users WHERE (email = :email OR pending_email = :email2) AND id != :id"
        );
        $dupSt->execute([':email' => $newEmail, ':email2' => $newEmail, ':id' => $cur['id']]);
        if ($dupSt->fetch()) {
            flash_set('That email address is already in use.', 'danger');
        } else {
            $sent = ipam_send_email_verification($db, to_int($cur['id']), $newEmail);
            if ($sent) {
                audit($db, 'user.email_change_initiated', 'user', to_int($cur['id']), 'new_email=' . $newEmail);
                flash_set('Verification email sent to ' . $newEmail . '. Check your inbox and spam folder.');
            } else {
                flash_set('Could not send verification email. Ensure SMTP and base_url are configured.', 'danger');
            }
        }
    }
    header('Location: change_password.php');
    exit;
}

$minLen = max(1, to_int($pwPolicy['min_length'] ?? 12));

// Session activity: admins may view any user; others see only their own
$viewUserId = $cur['id'];
$viewUsername = to_str($cur['username']);
if ($cur['role'] === 'admin' && to_int($_GET['user_id'] ?? 0) > 0) {
    $targetId = to_int($_GET['user_id']);
    $uRow = $db->prepare("SELECT id, username FROM users WHERE id = :id");
    $uRow->execute([':id' => $targetId]);
    /** @var array<string, mixed>|false $targetUser */
    $targetUser = $uRow->fetch();
    if ($targetUser) {
        $viewUserId   = to_int($targetUser['id']);
        $viewUsername = to_str($targetUser['username']);
    }
}

$actSt = $db->prepare("
    SELECT created_at, action, ip, user_agent
    FROM audit_log
    WHERE user_id = :uid
      AND action IN ('auth.login', 'auth.logout', 'auth.oidc_login', 'auth.login_failed')
    ORDER BY id DESC
    LIMIT 10
");
$actSt->execute([':uid' => $viewUserId]);
/** @var list<array<string, mixed>> $activityRows */
$activityRows = $actSt->fetchAll();

page_header('Change Password');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <span>Change Password</span>
</div>

<h1>Change Password</h1>
<?php if ($isSsoOnly): ?>
  <p class="muted">Your account authenticates via SSO. Password changes are managed through your identity provider.</p>
<?php else: ?>
  <?php if ($isExpired && !$msg): ?>
    <p class="danger">Your password has expired. Please set a new password to continue.</p>
  <?php endif; ?>
  <?php if ($errors): ?>
    <ul class="danger list-indent">
      <?php foreach ($errors as $errItem): ?><li><?= e($errItem) // nosemgrep ?></li><?php endforeach; ?>
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

<?php
$tzGroups = [];
foreach (timezone_identifiers_list() as $tzId) {
    $parts  = explode('/', $tzId, 2);
    $region = count($parts) === 2 ? $parts[0] : 'Other';
    $tzGroups[$region][] = $tzId;
}
ksort($tzGroups);
$currentTz = to_str($userRow['timezone'] ?? '');
$appTz = to_str(ipam_setting('branding.timezone')) ?: 'UTC';
?>
<div class="card mt-16">
  <h2>Display Timezone</h2>
  <p class="muted">Timestamps are stored as UTC. Select a timezone for display, or leave on "App default" (currently <?= e($appTz) ?>).</p>
  <form method="post" action="change_password.php">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="row">
      <label for="tz-select">Timezone<br>
        <select name="timezone" id="tz-select" style="min-width:220px;">
          <option value="">Use app default (<?= e($appTz) ?>)</option>
          <?php foreach ($tzGroups as $region => $tzIds): ?>
            <optgroup label="<?= e($region) ?>">
              <?php foreach ($tzIds as $tzId): ?>
                <option value="<?= e($tzId) ?>"<?= $currentTz === $tzId ? ' selected' : '' ?>><?= e($tzId) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <p><button type="submit">Save timezone</button></p>
  </form>
</div>

<?php
$currentEmail  = to_str($userRow['email'] ?? '');
$pendingEmail  = to_str($userRow['pending_email'] ?? '');
$pendingExpiry = to_str($userRow['pending_email_expires_at'] ?? '');
$pendingActive = $pendingEmail !== '' && $pendingExpiry !== ''
    && strtotime($pendingExpiry) > time();
?>
<div class="card mt-16">
  <h2>Email Address</h2>
  <?php if ($currentEmail !== ''): ?>
    <p>Current: <strong><?= e($currentEmail) ?></strong></p>
  <?php else: ?>
    <p class="muted">No email address set.</p>
  <?php endif; ?>
  <?php if ($pendingActive): ?>
    <p class="warning">Pending verification: <strong><?= e($pendingEmail) ?></strong>
      — check your inbox for the verification link. Expires <?= e(ipam_format_datetime($pendingExpiry)) ?>.</p>
  <?php endif; ?>
  <form method="post" action="change_password.php" class="mt-10">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="row">
      <label>New email address<br>
        <input type="email" name="new_email" required placeholder="user@example.com" style="min-width:220px;">
      </label>
    </div>
    <p class="muted">A verification link will be sent to the new address. The change takes effect after clicking the link.</p>
    <p><button type="submit">Send verification email</button></p>
  </form>
</div>

<div class="card mt-16">
  <h2>Recent Login Activity<?php if ($viewUserId !== $cur['id']): ?> — <?= e($viewUsername) ?><?php endif; ?></h2>
  <?php if ($cur['role'] === 'admin' && $viewUserId === $cur['id']): ?>
    <p class="muted">Admins: append <code>?user_id=N</code> to view another user's activity.</p>
  <?php endif; ?>
  <?php if (!$activityRows): ?>
    <div class="empty-state">No login activity recorded yet.</div>
  <?php else: ?>
    <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Time</th><th>Action</th><th>IP</th><th>User Agent</th></tr>
      </thead>
      <tbody>
      <?php foreach ($activityRows as $act): ?>
        <tr>
          <td class="muted"><?= e(ipam_format_datetime(to_str($act['created_at']))) ?></td>
          <td><?= e(to_str($act['action'])) ?></td>
          <td class="muted"><?= $act['ip'] !== null ? e(to_str($act['ip'])) : '—' ?></td>
          <td class="muted"><span class="audit-details" title="<?= e(to_str($act['user_agent'] ?? '')) ?>"><?= e(to_str($act['user_agent'] ?? '')) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php page_footer();
