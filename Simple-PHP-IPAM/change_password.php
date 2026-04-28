<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

if (demo_mode_enabled()) {
    page_header('Account');
    echo "<h1>Account</h1>";
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

// 2FA: disable TOTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'disable_totp') {
    csrf_require();
    $disableStmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
    $disableStmt->execute([':id' => $cur['id']]);
    /** @var array<string, mixed>|false $disableRow */
    $disableRow    = $disableStmt->fetch();
    $disablePwHash = $disableRow ? to_str($disableRow['password_hash']) : '';
    $isSsoOnlyDisable = str_starts_with($disablePwHash, '!');
    if (!$isSsoOnlyDisable) {
        $confirmPw = to_str($_POST['current_password'] ?? '');
        if ($confirmPw === '' || !password_verify($confirmPw, $disablePwHash)) {
            flash_set('Current password is incorrect. 2FA was not disabled.', 'danger');
            header('Location: change_password.php');
            exit;
        }
    }
    $db->prepare("UPDATE users SET totp_enabled=0, totp_secret_enc=NULL WHERE id=:id")
       ->execute([':id' => $cur['id']]);
    $db->prepare("DELETE FROM totp_backup_codes WHERE user_id=:uid")
       ->execute([':uid' => $cur['id']]);
    audit($db, 'auth.totp_disable', 'user', to_int($cur['id']), 'self');
    flash_set('Two-factor authentication disabled.');
    header('Location: change_password.php');
    exit;
}

// Fetch the full user row to check for SSO-only account
$st = $db->prepare(
    "SELECT password_hash, oidc_sub, timezone, email,
            pending_email, pending_email_expires_at,
            totp_enabled, totp_secret_enc
       FROM users WHERE id = :id"
);
$st->execute([':id' => $cur['id']]);
/** @var array<string, mixed>|false $userRow */
$userRow = $st->fetch();

// SSO-only: unusable hash starts with '!' (password_verify always returns false)
$isSsoOnly = $userRow && str_starts_with(to_str($userRow['password_hash']), '!');

$pwPolicy = [
    'min_length'        => to_int(ipam_setting('password_policy.min_length', 12)),
    'require_uppercase' => (bool)ipam_setting('password_policy.require_uppercase', false),
    'require_lowercase' => (bool)ipam_setting('password_policy.require_lowercase', false),
    'require_number'    => (bool)ipam_setting('password_policy.require_number', false),
    'require_symbol'    => (bool)ipam_setting('password_policy.require_symbol', false),
];
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
            session_regenerate_id(true);
            // Reset the absolute lifetime clock so the new session gets a fresh window.
            // Only set it when the feature is enabled (lifetime > 0).
            $absLifetimeMin = to_int($config['session']['absolute_lifetime_minutes'] ?? 480);
            if ($absLifetimeMin > 0) {
                $_SESSION['_abs_expires'] = time() + ($absLifetimeMin * 60);
            }
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
            if ($sent['success']) {
                audit($db, 'user.email_change_initiated', 'user', to_int($cur['id']), 'new_email=' . $newEmail);
                flash_set('Verification email sent to ' . $newEmail . '. Check your inbox and spam folder.');
            } else {
                flash_set('Could not send verification email: ' . $sent['error'], 'danger');
            }
        }
    }
    header('Location: change_password.php');
    exit;
}

$minLen = max(1, $pwPolicy['min_length']);

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

// --- Email OTP enrollment: start (send code) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'email_otp_enable') {
    csrf_require();
    $emailOtpEnabled = (bool)to_int(ipam_setting('mfa.email_otp_enabled', false));
    if (!$emailOtpEnabled) {
        flash_set('Email OTP is not enabled by your administrator.', 'danger');
        header('Location: change_password.php');
        exit;
    }
    $eoUserRow = $db->prepare("SELECT email, email_otp_enabled FROM users WHERE id = :id");
    $eoUserRow->execute([':id' => to_int($cur['id'])]);
    /** @var array<string, mixed>|false $eoRow */
    $eoRow      = $eoUserRow->fetch();
    if ($eoRow && to_int($eoRow['email_otp_enabled'] ?? 0) === 1) {
        header('Location: change_password.php#email-otp');
        exit;
    }
    $eoEmail    = $eoRow ? to_str($eoRow['email'] ?? '') : '';
    if ($eoEmail === '') {
        flash_set('You must set an email address on your account before enabling Email OTP.', 'danger');
        header('Location: change_password.php');
        exit;
    }
    $code = ipam_email_otp_generate($db, to_int($cur['id']));
    $sent = ipam_email_otp_send($db, to_int($cur['id']), $code);
    if (!$sent) {
        flash_set('Failed to send verification email. Please check SMTP configuration.', 'danger');
        header('Location: change_password.php');
        exit;
    }
    $_SESSION['email_otp_enrolling'] = true;
    header('Location: change_password.php#email-otp');
    exit;
}

// --- Email OTP enrollment: verify submitted code ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'email_otp_verify_enroll') {
    csrf_require();
    $submittedCode = trim(to_str($_POST['otp_code'] ?? ''));
    if (!isset($_SESSION['email_otp_enrolling'])) {
        flash_set('Enrollment session expired. Please start again.', 'danger');
        header('Location: change_password.php');
        exit;
    }
    if (ipam_email_otp_verify($db, to_int($cur['id']), $submittedCode)) {
        $db->prepare("UPDATE users SET email_otp_enabled = 1 WHERE id = :id")
           ->execute([':id' => to_int($cur['id'])]);
        unset($_SESSION['email_otp_enrolling']);
        unset($_SESSION['mfa_enrollment_required']);
        audit($db, 'user.email_otp_enable', 'user', to_int($cur['id']), 'Email OTP 2FA enrolled');
        flash_set('Email OTP enabled successfully.');
    } else {
        // Check if the verifier cleared OTP state due to too many attempts (attempts >= 5
        // causes ipam_email_otp_verify() to call ipam_email_otp_clear() and return false).
        $attemptRow = $db->prepare("SELECT email_otp_attempts FROM users WHERE id = :id");
        $attemptRow->execute([':id' => to_int($cur['id'])]);
        /** @var array<string, mixed>|false $attemptData */
        $attemptData = $attemptRow->fetch();
        if ($attemptData === false || to_int($attemptData['email_otp_attempts']) === 0) {
            unset($_SESSION['email_otp_enrolling']);
            flash_set('Too many incorrect attempts. Please request a new code.', 'danger');
        } else {
            flash_set('Invalid or expired code. Please try again.', 'danger');
        }
    }
    header('Location: change_password.php#email-otp');
    exit;
}

// --- Email OTP: disable ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'email_otp_disable') {
    csrf_require();
    $eoDisableStmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
    $eoDisableStmt->execute([':id' => $cur['id']]);
    /** @var array<string, mixed>|false $eoDisableRow */
    $eoDisableRow    = $eoDisableStmt->fetch();
    $eoDisablePwHash = $eoDisableRow ? to_str($eoDisableRow['password_hash']) : '';
    if (!str_starts_with($eoDisablePwHash, '!')) {
        $eoConfirmPw = to_str($_POST['current_password'] ?? '');
        if ($eoConfirmPw === '' || !password_verify($eoConfirmPw, $eoDisablePwHash)) {
            flash_set('Current password is incorrect. Email OTP was not disabled.', 'danger');
            header('Location: change_password.php#email-otp');
            exit;
        }
    }
    $db->prepare("UPDATE users SET email_otp_enabled = 0 WHERE id = :id")
       ->execute([':id' => to_int($cur['id'])]);
    ipam_email_otp_clear($db, to_int($cur['id']));
    unset($_SESSION['email_otp_enrolling']);
    audit($db, 'user.email_otp_disable', 'user', to_int($cur['id']), 'Email OTP 2FA disabled');
    flash_set('Email OTP disabled.');
    header('Location: change_password.php#email-otp');
    exit;
}

// --- Passkeys: delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'passkey_delete') {
    csrf_require();
    $pkDelStmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
    $pkDelStmt->execute([':id' => $cur['id']]);
    /** @var array<string, mixed>|false $pkDelRow */
    $pkDelRow   = $pkDelStmt->fetch();
    $pkDelHash  = $pkDelRow ? to_str($pkDelRow['password_hash']) : '';
    if (!str_starts_with($pkDelHash, '!')) {
        $pkDelPw = to_str($_POST['current_password'] ?? '');
        if ($pkDelPw === '' || !password_verify($pkDelPw, $pkDelHash)) {
            flash_set('Current password is incorrect. Passkey was not removed.', 'danger');
            header('Location: change_password.php#passkeys');
            exit;
        }
    }
    $credId = to_int($_POST['credential_id'] ?? 0);
    if ($credId > 0 && ipam_passkey_delete($db, $credId, to_int($cur['id']))) {
        audit($db, 'user.passkey_delete', 'user', to_int($cur['id']), "credential_id={$credId}");
    }
    header('Location: change_password.php#passkeys');
    exit;
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

$passkeysEnabled = (bool)to_int(ipam_setting('mfa.passkeys_enabled', false));
$passkeyCreds    = ipam_passkey_get_credentials($db, to_int($cur['id']));

if (isset($_GET['mfa_required']) && !empty($_SESSION['mfa_enrollment_required'])) {
    flash_set('Your administrator requires 2FA. Please enroll in TOTP or Email OTP below.', 'warning');
}

page_header('Account');
?>
<div class="breadcrumbs">
  <a href="dashboard.php">Dashboard</a><span class="sep">›</span>
  <span>Account</span>
</div>

<h1>Account</h1>
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
      <label>Current password<br><input type="password" name="old_password" required autocomplete="current-password"></label>
    </div>
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
    <p><button type="submit">Update</button></p>
  </form>
<?php endif; ?>

<?php $totpGlobalEnabled = (bool)to_int(ipam_setting('mfa.totp_enabled', true)); ?>
<div class="card mt-16">
  <h2>Two-Factor Authentication</h2>
  <?php if (!$totpGlobalEnabled): ?>
    <p class="muted">TOTP is not enabled on this server. Contact your administrator.</p>
  <?php elseif (to_int($userRow['totp_enabled'] ?? 0) === 1): ?>
    <p class="success">Two-factor authentication is <strong>enabled</strong>.</p>
    <form method="post" action="change_password.php">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="disable_totp">
      <?php if (!$isSsoOnly): ?>
        <label style="display:block;margin-bottom:8px;">Confirm current password:<br>
          <input type="password" name="current_password" autocomplete="current-password" required>
        </label>
      <?php endif; ?>
      <button type="submit" class="button-danger"
        onclick="return confirm('Disable 2FA? You will no longer need a code to log in.')">
        Disable 2FA
      </button>
    </form>
  <?php else: ?>
    <p class="muted">Two-factor authentication is <strong>not enabled</strong>.</p>
    <a href="totp_enroll.php" class="action-pill">Enable 2FA</a>
  <?php endif; ?>
</div>

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
    && strtotime($pendingExpiry . ' UTC') > time();
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

<?php
// --- Email OTP section ---
$emailOtpGlobalEnabled = (bool)to_int(ipam_setting('mfa.email_otp_enabled', false));
$eoSt = $db->prepare("SELECT email_otp_enabled, email FROM users WHERE id = :id");
$eoSt->execute([':id' => to_int($cur['id'])]);
/** @var array<string, mixed>|false $eoUser */
$eoUser    = $eoSt->fetch();
$emailOtpIsOn = $eoUser && to_int($eoUser['email_otp_enabled'] ?? 0) === 1;
$eoHasEmail   = $eoUser && to_str($eoUser['email'] ?? '') !== '';
$eoEnrolling  = !empty($_SESSION['email_otp_enrolling']);
?>
<div class="card mt-16" id="email-otp">
  <h2>Email OTP</h2>
  <?php if (!$emailOtpGlobalEnabled): ?>
    <p class="muted">Email OTP is not enabled by your administrator.</p>
  <?php elseif ($emailOtpIsOn): ?>
    <p class="success">Email OTP is <strong>active</strong>. A verification code will be emailed to you at each login.</p>
    <form method="post" action="change_password.php#email-otp">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="email_otp_disable">
      <?php if (!$isSsoOnly): ?>
        <label style="display:block;margin-bottom:8px;">Confirm current password:<br>
          <input type="password" name="current_password" autocomplete="current-password" required>
        </label>
      <?php endif; ?>
      <button type="submit" class="button-danger"
        onclick="return confirm('Disable Email OTP? You will no longer receive a code by email at login.')">
        Disable Email OTP
      </button>
    </form>
  <?php elseif ($eoEnrolling): ?>
    <p>A 6-digit code was sent to your email address. Enter it below to confirm enrollment.</p>
    <form method="post" action="change_password.php#email-otp">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="email_otp_verify_enroll">
      <div class="row">
        <label for="otp_code">Verification code<br>
          <input type="text" id="otp_code" name="otp_code" inputmode="numeric" pattern="\d{6}"
                 maxlength="6" autocomplete="one-time-code" required placeholder="6-digit code"
                 style="max-width:160px;">
        </label>
      </div>
      <p><button type="submit">Confirm</button></p>
    </form>
    <form method="post" action="change_password.php#email-otp" style="margin-top:.5rem">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="email_otp_enable">
      <button type="submit" class="button-secondary">Resend code</button>
    </form>
  <?php elseif (!$eoHasEmail): ?>
    <p class="warning">You must <a href="change_password.php#email">set an email address</a> before you can enable Email OTP.</p>
  <?php else: ?>
    <p>Add Email OTP as a second factor. Each login will require a code sent to your email address.</p>
    <form method="post" action="change_password.php#email-otp">
      <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="email_otp_enable">
      <p><button type="submit">Enable Email OTP</button></p>
    </form>
  <?php endif; ?>
</div>

<div class="card mt-16" id="passkeys">
  <h2><?= icon('finger-print') ?> Passkeys</h2>
  <?php if (!$passkeysEnabled): ?>
    <p class="muted">Passkeys are not enabled on this server. Contact your administrator.</p>
  <?php else: ?>
    <?php if ($passkeyCreds !== []): ?>
      <p>Your registered passkeys:</p>
      <ul style="list-style:none;padding:0;margin:0 0 1rem">
        <?php foreach ($passkeyCreds as $pk): ?>
          <li style="display:flex;align-items:center;gap:.5rem;padding:.4rem 0;border-bottom:1px solid var(--border)">
            <?= icon('key') ?>
            <span style="flex:1"><strong><?= e(to_str($pk['name'])) ?></strong>
              <span class="muted" style="font-size:.8rem">
                — added <?= e(substr(to_str($pk['created_at']), 0, 10)) ?>
                <?php if (to_str($pk['last_used_at']) !== ''): ?>
                  · last used <?= e(substr(to_str($pk['last_used_at']), 0, 10)) ?>
                <?php endif ?>
              </span>
            </span>
            <form method="post" action="change_password.php#passkeys"
                  onsubmit="return confirm('Remove this passkey?')">
              <input type="hidden" name="csrf"          value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action"        value="passkey_delete">
              <input type="hidden" name="credential_id" value="<?= e((string)to_int($pk['id'])) ?>">
              <?php if (!$isSsoOnly): ?>
              <input type="password" name="current_password" placeholder="Current password"
                     autocomplete="current-password" required
                     style="margin-right:.4rem;width:auto">
              <?php endif ?>
              <button type="submit" class="action-pill button-danger"
                      aria-label="Remove passkey <?= e(to_str($pk['name'])) ?>">
                <?= icon('trash') ?> Remove
              </button>
            </form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="muted">No passkeys registered yet.</p>
    <?php endif ?>
    <?php $passkeyDefaultName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM'; ?>
    <button type="button" id="btn-add-passkey" class="action-pill"
            data-default-name="<?= e($passkeyDefaultName) ?>">
      <?= icon('plus-circle') ?> Add Passkey
    </button>
    <span id="passkey-add-status" class="muted" style="font-size:.85rem;display:none;margin-left:.5rem"></span>
  <?php endif ?>
</div>

<?php page_footer();
