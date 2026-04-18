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

// Fetch the full user row to check for SSO-only account
$st = $db->prepare("SELECT password_hash, oidc_sub, timezone FROM users WHERE id = :id");
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
