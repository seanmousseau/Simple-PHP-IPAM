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

// 2FA: disable TOTP. v3.27.0 (#1112) — gated by ipam_sudo_require() instead
// of the legacy current_password verify so OIDC-only users can disable TOTP
// via TOTP/Email OTP/passkey/OIDC re-auth under the install policy.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'disable_totp') {
    csrf_require();
    if (!ipam_sudo_require($db, to_int($cur['id']))) {
        page_header('Confirm your identity');
        $stepUpUserId       = to_int($cur['id']);
        $stepUpFormAction   = 'change_password.php';
        $stepUpHiddenFields = ['action' => 'disable_totp'];
        $stepUpDescription  = 'Re-authenticate to disable TOTP two-factor authentication.';
        $stepUpReturnPath   = 'change_password.php';
        $stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. TOTP was not disabled.' : '';
        include __DIR__ . '/views/_step_up_prompt.php';
        page_footer();
        exit;
    }
    ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.

    $db->prepare("UPDATE users SET totp_enabled=0, totp_secret_enc=NULL WHERE id=:id")
       ->execute([':id' => $cur['id']]);
    $db->prepare("DELETE FROM totp_backup_codes WHERE user_id=:uid")
       ->execute([':uid' => $cur['id']]);
    audit($db, 'auth.totp_disable', 'user', to_int($cur['id']), 'self');
    // MFA enrollment change invalidates any cached sudo grant (plan §3.5).
    ipam_sudo_invalidate();
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
            // Password change invalidates any cached sudo grant — the
            // credential the grant might have been minted from is no
            // longer the current credential (plan §3.5).
            ipam_sudo_invalidate();
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

// --- Email OTP: disable. v3.27.0 (#1112) — gated by ipam_sudo_require(). ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'email_otp_disable') {
    csrf_require();
    if (!ipam_sudo_require($db, to_int($cur['id']))) {
        page_header('Confirm your identity');
        $stepUpUserId       = to_int($cur['id']);
        $stepUpFormAction   = 'change_password.php';
        $stepUpHiddenFields = ['action' => 'email_otp_disable'];
        $stepUpDescription  = 'Re-authenticate to disable Email OTP two-factor authentication.';
        $stepUpReturnPath   = 'change_password.php#email-otp';
        $stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. Email OTP was not disabled.' : '';
        include __DIR__ . '/views/_step_up_prompt.php';
        page_footer();
        exit;
    }
    ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.

    $db->prepare("UPDATE users SET email_otp_enabled = 0 WHERE id = :id")
       ->execute([':id' => to_int($cur['id'])]);
    ipam_email_otp_clear($db, to_int($cur['id']));
    unset($_SESSION['email_otp_enrolling']);
    audit($db, 'user.email_otp_disable', 'user', to_int($cur['id']), 'Email OTP 2FA disabled');
    // MFA enrollment change invalidates any cached sudo grant (plan §3.5).
    ipam_sudo_invalidate();
    flash_set('Email OTP disabled.');
    header('Location: change_password.php#email-otp');
    exit;
}

// --- Passkeys: delete. v3.27.0 (#1112) — gated by ipam_sudo_require(). ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && to_str($_POST['action'] ?? '') === 'passkey_delete') {
    csrf_require();
    $credId = to_int($_POST['credential_id'] ?? 0);
    if (!ipam_sudo_require($db, to_int($cur['id']))) {
        page_header('Confirm your identity');
        $stepUpUserId       = to_int($cur['id']);
        $stepUpFormAction   = 'change_password.php';
        $stepUpHiddenFields = ['action' => 'passkey_delete', 'credential_id' => (string) $credId];
        $stepUpDescription  = 'Re-authenticate to remove this passkey.';
        $stepUpReturnPath   = 'change_password.php#passkeys';
        $stepUpError        = isset($_POST['_sudo_method']) ? 'Verification failed. Passkey was not removed.' : '';
        include __DIR__ . '/views/_step_up_prompt.php';
        page_footer();
        exit;
    }
    ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.

    if ($credId > 0 && ipam_passkey_delete($db, $credId, to_int($cur['id']))) {
        audit($db, 'user.passkey_delete', 'user', to_int($cur['id']), "credential_id={$credId}");
        // MFA enrollment change invalidates any cached sudo grant (plan §3.5).
        ipam_sudo_invalidate();
    }
    header('Location: change_password.php#passkeys');
    exit;
}

// 2FA: set preferred MFA method (#746). Accepts 'totp', 'email_otp',
// 'passkey', or '' (clear). Server-side guards: the chosen method must be
// currently enrolled by the user AND globally enabled. Anything else is
// silently coerced to NULL so a stale form post (e.g. method just disabled
// in another tab) cannot pin the user to an unusable preference.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && to_str($_POST['action'] ?? '') === 'set_preferred_mfa') {
    csrf_require();
    $submitted = to_str($_POST['preferred_mfa'] ?? '');
    $available = ipam_user_available_mfa_methods($db, to_int($cur['id']));
    $newVal    = in_array($submitted, $available, true) ? $submitted : null;

    $st = $db->prepare("UPDATE users SET preferred_mfa_method = :v WHERE id = :id");
    $st->bindValue(':v', $newVal, $newVal === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $st->bindValue(':id', to_int($cur['id']), PDO::PARAM_INT);
    $st->execute();
    audit(
        $db,
        'auth.mfa_preferred_set',
        'user',
        to_int($cur['id']),
        'preferred_mfa_method=' . ($newVal ?? 'null')
    );
    flash_set('Preferred sign-in method updated.');
    header('Location: change_password.php#mfa-preferred');
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

<?php
// ── Two-Factor Authentication card (TOTP + Email OTP + Passkeys, #745, #755) ──
$totpGlobalEnabled     = (bool)to_int(ipam_setting('mfa.totp_enabled', true));
$emailOtpGlobalEnabled = (bool)to_int(ipam_setting('mfa.email_otp_enabled', false));
$passkeysEnabled       = (bool)to_int(ipam_setting('mfa.passkeys_enabled', false));

$totpUserEnabled = to_int($userRow['totp_enabled'] ?? 0) === 1;

$eoSt = $db->prepare("SELECT email_otp_enabled, email FROM users WHERE id = :id");
$eoSt->execute([':id' => to_int($cur['id'])]);
/** @var array<string, mixed>|false $eoUser */
$eoUser           = $eoSt->fetch();
$emailOtpUserEnabled = $eoUser && to_int($eoUser['email_otp_enabled'] ?? 0) === 1;
$eoHasEmail       = $eoUser && to_str($eoUser['email'] ?? '') !== '';
$eoEnrolling      = !empty($_SESSION['email_otp_enrolling']);

$passkeyCreds       = ipam_passkey_get_credentials($db, to_int($cur['id']));
$passkeyUserEnabled = $passkeyCreds !== [];
$passkeyDefaultName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';

// Preferred MFA method picker (#746). Read the persisted value (tolerant
// of the column not existing on partial test DBs) and compute the set of
// methods this user is allowed to choose from.
$mfaAvailable = ipam_user_available_mfa_methods($db, to_int($cur['id']));
$mfaPreferred = ipam_user_preferred_mfa($db, to_int($cur['id'])) ?? '';
$mfaMethodLabels = [
    'totp'      => 'Authenticator app',
    'email_otp' => 'Email one-time code',
    'passkey'   => 'Passkey',
];
?>
<section class="card mt-16 mfa-card" aria-labelledby="mfa-heading">
  <header class="mfa-card__header">
    <?= icon('shield', 'mfa-card__icon') ?>
    <div>
      <h2 id="mfa-heading">Two-Factor Authentication</h2>
      <p class="muted mfa-card__lede">Add a second factor so a stolen password is not enough to access your account.</p>
    </div>
  </header>

  <?php if (count($mfaAvailable) >= 1): ?>
  <!-- ── Preferred sign-in method (#746) ─────────────────────────────── -->
  <div class="mfa-preferred" id="mfa-preferred" role="group" aria-labelledby="mfa-preferred-label">
    <span id="mfa-preferred-label" class="mfa-preferred__label">Preferred method at login</span>
    <?php if (count($mfaAvailable) === 1): ?>
      <?php $only = $mfaAvailable[0]; ?>
      <p class="mfa-preferred__static muted">
        <?= e($mfaMethodLabels[$only] ?? $only) ?>
        <span class="muted">(only enrolled method)</span>
        <span class="sr-only"> &mdash; currently your preferred sign-in method</span>
      </p>
    <?php else: ?>
      <form method="post" action="change_password.php#mfa-preferred" class="mfa-preferred__form">
        <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="set_preferred_mfa">
        <label class="sr-only" for="mfa-preferred-select">Preferred sign-in method</label>
        <select name="preferred_mfa" id="mfa-preferred-select" class="mfa-preferred__select"
                aria-describedby="mfa-preferred-help">
          <option value="" <?= $mfaPreferred === '' ? 'selected' : '' ?>>
            Most-recently-enrolled (default)
          </option>
          <?php foreach ($mfaAvailable as $m): ?>
            <option value="<?= e($m) ?>" <?= $mfaPreferred === $m ? 'selected' : '' ?>>
              <?= e($mfaMethodLabels[$m] ?? $m) ?><?= $mfaPreferred === $m ? ' — currently preferred' : '' ?>
            </option>
          <?php endforeach ?>
        </select>
        <button type="submit" class="action-pill">Save</button>
        <span id="mfa-preferred-help" class="mfa-preferred__help muted">
          Login lands on this method first. The other enrolled methods stay
          available via the &ldquo;Use … instead&rdquo; links on every verify page.
        </span>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <ul class="mfa-method-list" role="list">
    <!-- ── Authenticator app (TOTP) ─────────────────────────────────────── -->
    <li class="mfa-method-row" id="totp">
      <div class="mfa-method-row__lead">
        <?= icon('shield', 'mfa-method-row__icon') ?>
        <div class="mfa-method-row__text">
          <h3 class="mfa-method-row__title">Authenticator app</h3>
          <p class="mfa-method-row__desc">Use a TOTP app (1Password, Authy, Google Authenticator) to generate one-time codes.</p>
        </div>
      </div>
      <?php if (!$totpGlobalEnabled): ?>
        <?php if ($totpUserEnabled): ?>
          <span class="mfa-method-pill mfa-method-pill--unavailable" data-method="totp">Unavailable</span>
        <?php else: ?>
          <span class="mfa-method-pill mfa-method-pill--unavailable" data-method="totp">Disabled by admin</span>
        <?php endif; ?>
      <?php elseif ($totpUserEnabled): ?>
        <span class="mfa-method-pill mfa-method-pill--enabled" data-method="totp">Enabled</span>
      <?php else: ?>
        <span class="mfa-method-pill mfa-method-pill--disabled" data-method="totp">Disabled</span>
      <?php endif; ?>
      <div class="mfa-method-row__actions">
        <?php if ($totpUserEnabled): ?>
          <form method="post" action="change_password.php#totp" class="mfa-method-row__form">
            <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="disable_totp">
            <button type="submit" class="action-pill button-danger"
              onclick="return confirm('Disable authenticator app 2FA? You will be prompted to re-authenticate before the change is applied.')">
              Disable
            </button>
          </form>
        <?php elseif ($totpGlobalEnabled): ?>
          <a href="totp_enroll.php" class="action-pill">Enroll</a>
        <?php endif; ?>
      </div>
      <?php if (!$totpGlobalEnabled && $totpUserEnabled): ?>
        <p class="mfa-method-row__hint" role="note">
          <?= icon('info', 'mfa-method-row__hint-icon') ?>
          Your TOTP enrolment is preserved and will reactivate automatically if TOTP is re-enabled.
        </p>
      <?php endif; ?>
    </li>

    <!-- ── Email one-time code ─────────────────────────────────────────── -->
    <li class="mfa-method-row" id="email-otp">
      <div class="mfa-method-row__lead">
        <?= icon('envelope', 'mfa-method-row__icon') ?>
        <div class="mfa-method-row__text">
          <h3 class="mfa-method-row__title">Email one-time code</h3>
          <p class="mfa-method-row__desc">A 6-digit code is emailed to your address each time you sign in.</p>
        </div>
      </div>
      <?php if (!$emailOtpGlobalEnabled): ?>
        <?php if ($emailOtpUserEnabled): ?>
          <span class="mfa-method-pill mfa-method-pill--unavailable" data-method="email_otp">Unavailable</span>
        <?php else: ?>
          <span class="mfa-method-pill mfa-method-pill--unavailable" data-method="email_otp">Disabled by admin</span>
        <?php endif; ?>
      <?php elseif ($emailOtpUserEnabled): ?>
        <span class="mfa-method-pill mfa-method-pill--enabled" data-method="email_otp">Enabled</span>
      <?php elseif ($eoEnrolling): ?>
        <span class="mfa-method-pill mfa-method-pill--pending" data-method="email_otp">Code sent</span>
      <?php else: ?>
        <span class="mfa-method-pill mfa-method-pill--disabled" data-method="email_otp">Disabled</span>
      <?php endif; ?>
      <div class="mfa-method-row__actions">
        <?php if ($emailOtpUserEnabled): ?>
          <form method="post" action="change_password.php#email-otp" class="mfa-method-row__form">
            <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="email_otp_disable">
            <button type="submit" class="action-pill button-danger"
              onclick="return confirm('Disable Email OTP? You will be prompted to re-authenticate before the change is applied.')">
              Disable
            </button>
          </form>
        <?php elseif (!$emailOtpGlobalEnabled): ?>
          <!-- no enroll affordance when globally disabled -->
        <?php elseif ($eoEnrolling): ?>
          <form method="post" action="change_password.php#email-otp" class="mfa-method-row__form">
            <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="email_otp_verify_enroll">
            <label class="mfa-method-row__codelabel" for="otp_code">Verification code
              <input type="text" id="otp_code" name="otp_code" inputmode="numeric" pattern="\d{6}"
                     maxlength="6" autocomplete="one-time-code" required placeholder="6-digit code">
            </label>
            <button type="submit" class="action-pill">Confirm</button>
          </form>
          <form method="post" action="change_password.php#email-otp" class="mfa-method-row__form">
            <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="email_otp_enable">
            <button type="submit" class="action-pill button-secondary">Resend code</button>
          </form>
        <?php elseif (!$eoHasEmail): ?>
          <span class="mfa-method-row__notice">Set an email address first.</span>
        <?php else: ?>
          <form method="post" action="change_password.php#email-otp" class="mfa-method-row__form">
            <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="email_otp_enable">
            <button type="submit" class="action-pill">Enroll</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!$emailOtpGlobalEnabled && $emailOtpUserEnabled): ?>
        <p class="mfa-method-row__hint" role="note">
          <?= icon('info', 'mfa-method-row__hint-icon') ?>
          Your Email OTP enrolment is preserved and will reactivate automatically if Email OTP is re-enabled.
        </p>
      <?php endif; ?>
    </li>

    <!-- ── Passkeys ────────────────────────────────────────────────────── -->
    <li class="mfa-method-row" id="passkeys">
      <div class="mfa-method-row__lead">
        <?= icon('finger-print', 'mfa-method-row__icon') ?>
        <div class="mfa-method-row__text">
          <h3 class="mfa-method-row__title">Passkeys</h3>
          <p class="mfa-method-row__desc">Sign in with a device biometric, security key, or password manager.</p>
        </div>
      </div>
      <?php if (!$passkeysEnabled): ?>
        <?php if ($passkeyUserEnabled): ?>
          <span class="mfa-method-pill mfa-method-pill--unavailable" data-method="passkeys">Unavailable</span>
        <?php else: ?>
          <span class="mfa-method-pill mfa-method-pill--unavailable" data-method="passkeys">Disabled by admin</span>
        <?php endif; ?>
      <?php elseif ($passkeyUserEnabled): ?>
        <span class="mfa-method-pill mfa-method-pill--enabled" data-method="passkeys"><?= (int)count($passkeyCreds) ?> registered</span>
      <?php else: ?>
        <span class="mfa-method-pill mfa-method-pill--disabled" data-method="passkeys">Disabled</span>
      <?php endif; ?>
      <div class="mfa-method-row__actions">
        <?php if ($passkeysEnabled): ?>
          <button type="button" id="btn-add-passkey" class="action-pill"
                  data-default-name="<?= e($passkeyDefaultName) ?>">
            <?= icon('plus-circle') ?> Add passkey
          </button>
          <span id="passkey-add-status" class="muted mfa-method-row__notice" style="display:none"></span>
        <?php endif; ?>
      </div>
      <?php if ($passkeysEnabled && $passkeyCreds !== []): ?>
        <ul class="mfa-passkey-list" role="list">
          <?php foreach ($passkeyCreds as $pk): ?>
            <li class="mfa-passkey-list__item">
              <?= icon('key', 'mfa-passkey-list__icon') ?>
              <span class="mfa-passkey-list__name"><strong><?= e(to_str($pk['name'])) ?></strong>
                <span class="muted mfa-passkey-list__meta">
                  added <?= e(substr(to_str($pk['created_at']), 0, 10)) ?>
                  <?php if (to_str($pk['last_used_at']) !== ''): ?>
                    · last used <?= e(substr(to_str($pk['last_used_at']), 0, 10)) ?>
                  <?php endif ?>
                </span>
              </span>
              <form method="post" action="change_password.php#passkeys" class="mfa-passkey-list__form"
                    onsubmit="return confirm('Remove this passkey?')">
                <input type="hidden" name="csrf"          value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action"        value="passkey_delete">
                <input type="hidden" name="credential_id" value="<?= e((string)to_int($pk['id'])) ?>">
                <button type="submit" class="action-pill button-danger"
                        aria-label="Remove passkey <?= e(to_str($pk['name'])) ?>">
                  <?= icon('trash') ?> Remove
                </button>
              </form>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endif; ?>
      <?php if (!$passkeysEnabled && $passkeyUserEnabled): ?>
        <p class="mfa-method-row__hint" role="note">
          <?= icon('info', 'mfa-method-row__hint-icon') ?>
          Your registered passkeys are preserved and will be available again if Passkeys are re-enabled.
        </p>
      <?php endif; ?>
    </li>
  </ul>
</section>

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

<?php page_footer();
