<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

if (is_logged_in()) { header('Location: dashboard.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$maxAttempts   = to_int(ipam_setting('security.login_max_attempts'));
$lockoutSeconds = to_int(ipam_setting('security.login_lockout_seconds'));
$acctMaxAttempts  = to_int(ipam_setting('security.account_lockout_max_attempts'));
$acctLockoutSecs  = to_int(ipam_setting('security.account_lockout_seconds'));

$error    = '';
$timedOut = !empty($_GET['timeout']);
$reason   = to_str($_GET['reason'] ?? '');
$isRecovery = recovery_mode_enabled($config);

// Login protection setup (#124) — widget HTML also sets time_check session ts on GET
$lpMethod     = $isRecovery ? '' : to_str(ipam_setting('login_protection.method'));
$lpWidgetHtml = $isRecovery ? '' : login_protection_widget_html($config);
$lpCsp        = $isRecovery ? ['script_src' => '', 'style_src' => '', 'frame_src' => ''] : login_protection_extra_csp($config);

// Consume any OIDC error flash set by oidc_callback.php or oidc_login.php
if (!empty($_SESSION['oidc_error'])) {
    $error = to_str($_SESSION['oidc_error']);
    unset($_SESSION['oidc_error']);
}

// ?reason= messages from redirects (session expiry, persistent lockout)
if ($error === '' && $reason === 'session_expired') {
    $error = 'Your session has expired. Please log in again.';
} elseif ($error === '' && $reason === 'locked') {
    $error = 'This account is locked due to too many failed two-factor authentication attempts.';
} elseif ($error === '' && $reason === 'otp_locked') {
    $error = 'Too many incorrect codes. Please log in again.';
}

// Render-prep variables (needed even when goto jumps past POST block)
try {
    $firstRunSt = $db->query("SELECT 1 FROM audit_log WHERE action='auth.login' LIMIT 1");
    $firstRun = $firstRunSt !== false ? !$firstRunSt->fetch() : false;
} catch (Throwable $e) {
    $firstRun = true; // treat as first-run if audit_log is temporarily unavailable
}
$oidcActive            = oidc_enabled($config) && !demo_mode_enabled();
$disableLocal          = $isRecovery ? false : ($oidcActive && (bool)ipam_setting('oidc.disable_local_login'));
$disableEmergencyBypass= $oidcActive && (bool)ipam_setting('oidc.disable_emergency_bypass');
$hideEmergencyLink     = $oidcActive && (bool)ipam_setting('oidc.hide_emergency_link');
$localForceShown       = $isRecovery || (isset($_GET['local']) && !$disableEmergencyBypass);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(to_str($_POST['username'] ?? ''));
    $password = to_str($_POST['password'] ?? '');
    $ip = client_ip();

    // Login protection verification (#124) — before rate limiting / DB checks
    if ($lpMethod !== '') {
        $lpResult = login_protection_verify($config, $_POST);
        if ($lpResult !== null) {
            // '' = silent honeypot rejection (no error); non-empty = show error
            if ($lpResult !== '') $error = $lpResult;
            goto render_page;
        }
    }

    if (demo_mode_enabled()) {
        // Demo mode: only demo/demo is accepted; rate limiting still applies (#115)
        // Demo-mode IP rate-limit (#1134 unchanged here — demo mode is
        // a niche surface; the real fix is in the production branch below).
        if (login_rate_limited($db, $ip, $maxAttempts, $lockoutSeconds)) {
            $error = 'Too many failed login attempts from this network. Please try again later.';
        } elseif ($username === 'demo' && $password === 'demo') {
            $st = $db->prepare("SELECT id, username, role FROM users WHERE username = 'demo' AND is_active = 1");
            $st->execute();
            /** @var array<string, mixed>|false $demoUser */
            $demoUser = $st->fetch();
            if ($demoUser) {
                login_user(to_int($demoUser['id']), to_str($demoUser['username']), to_str($demoUser['role']));
                $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
                   ->execute([':id' => to_int($demoUser['id'])]);
                audit($db, 'auth.login', 'user', to_int($demoUser['id']), 'demo login');
                header('Location: ' . ipam_post_login_redirect_consume());
                exit;
            }
        } else {
            record_login_failure($db, $ip);
            $error = 'Only the demo account (demo / demo) is available in demo mode.';
        }
    } else {
        // Purge stale attempts opportunistically (keep rows 2× the lockout window)
        purge_old_login_attempts($db, $lockoutSeconds * 2);

        if (!$isRecovery && login_rate_limited($db, $ip, $maxAttempts, $lockoutSeconds)) {
            // #1134 (v3.27.3): IP-specific message + once-per-window
            // 'auth.ip_rate_limited' audit row. Pre-fix, the message was
            // generic and 'auth.login_blocked' fired on every refused
            // attempt within the window — operators searching audit_log
            // by username missed the rows entirely (entity_id NULL).
            // CR PR #1141: derive unlock_at from the OLDEST still-counted
            // failure rather than `time() + $lockoutSeconds`. The latter
            // overstates the wait under steady traffic and could keep
            // the dampener suppressing past the real unlock point — a
            // genuine later lockout would never get its own audit row.
            $unlockAt        = auth_rate_limit_unlock_at($db, 'login', $ip, $lockoutSeconds);
            $remainingSecs   = max(1, $unlockAt - time());
            $remainingMin    = max(1, (int) ceil($remainingSecs / 60));
            $error = 'Too many failed login attempts from this network. Please try again in '
                   . $remainingMin . ' minute' . ($remainingMin === 1 ? '' : 's') . '.';
            ipam_audit_ip_rate_limited($db, 'login', $ip, $maxAttempts, $unlockAt);
        } elseif (!$isRecovery && $username !== '' && account_locked_out($db, $username, $acctMaxAttempts, $acctLockoutSecs)) {
            $error = 'This account is temporarily locked due to too many failed attempts.';
            audit($db, 'auth.account_locked', 'user', null, '');
        } else {
            $st = $db->prepare("SELECT id, username, password_hash, role, is_active, totp_enabled, email_otp_enabled FROM users WHERE username = :u");
            $st->execute([':u' => $username]);
            /** @var array<string, mixed>|false $user */
            $user = $st->fetch();

            // Recovery mode: bootstrap_admin credentials reset the user's password
            $bootstrapUser = to_str($config['bootstrap_admin']['username']);
            $bootstrapPass = to_str($config['bootstrap_admin']['password']);
            if ($isRecovery && $bootstrapUser !== '' && $username === $bootstrapUser && $password === $bootstrapPass) {
                if (!$user) {
                    // Recreate admin user if deleted
                    $hash = password_hash($bootstrapPass, PASSWORD_DEFAULT);
                    $db->prepare("INSERT INTO users (username, password_hash, role, is_active) VALUES (:u, :h, 'admin', 1)")
                       ->execute([':u' => $bootstrapUser, ':h' => $hash]);
                    $uid = (int)$db->lastInsertId();
                    audit($db, 'auth.recovery_provision', 'user', $uid, 'recovery_mode recreated bootstrap admin');
                } else {
                    $uid = to_int($user['id']);
                    $hash = password_hash($bootstrapPass, PASSWORD_DEFAULT);
                    $db->prepare("UPDATE users SET password_hash = :h, is_active = 1 WHERE id = :id")
                       ->execute([':h' => $hash, ':id' => $uid]);
                    audit($db, 'auth.recovery_reset', 'user', $uid, 'recovery_mode reset password');
                }
                clear_login_failures($db, $ip);
                clear_account_lockout($db, $username);
                login_user($uid, $bootstrapUser, 'admin');
                $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
                   ->execute([':id' => $uid]);
                audit($db, 'auth.recovery_login', 'user', $uid, 'recovery_mode');
                header('Location: ' . ipam_post_login_redirect_consume());
                exit;
            }

            if ($user && to_int($user['is_active']) === 1 && password_verify($password, to_str($user['password_hash']))) {
                if (password_needs_rehash(to_str($user['password_hash']), PASSWORD_DEFAULT)) {
                    $new = password_hash($password, PASSWORD_DEFAULT);
                    $up = $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
                    $up->execute([':h' => $new, ':id' => $user['id']]);
                }
                clear_login_failures($db, $ip);
                clear_account_lockout($db, $username);
                // #746 v3.16.0: dispatch on the user's preferred MFA method
                // when set, otherwise fall through to the most-recently-enrolled
                // available method. ipam_user_available_mfa_methods() already
                // applies the same global-toggle gating that the previous
                // hand-rolled chain did (#747 for TOTP, mfa.email_otp_enabled,
                // mfa.passkeys_enabled).
                $uid = to_int($user['id']);
                $available = ipam_user_available_mfa_methods($db, $uid);
                $preferred = ipam_user_preferred_mfa($db, $uid);
                $chain = $available;
                if ($preferred !== null && in_array($preferred, $available, true)) {
                    // Move preferred to the front of the dispatch chain only
                    // when it is actually available (enrolled + globally enabled).
                    // Otherwise leave $chain = $available so a stale preference
                    // does not short-circuit dispatch.
                    $chain = array_values(array_unique(array_merge([$preferred], $available)));
                }

                foreach ($chain as $method) {
                    if ($method === 'totp') {
                        $_SESSION['totp_pending_uid'] = $uid;
                        header('Location: totp_verify.php');
                        exit;
                    }
                    if ($method === 'email_otp') {
                        $code = ipam_email_otp_generate($db, $uid);
                        if (!ipam_email_otp_send($db, $uid, $code)) {
                            ipam_email_otp_clear($db, $uid, 'email_send_failed');
                            // Fall through to the next available method rather than
                            // dead-ending the user when SMTP is broken.
                            continue;
                        }
                        $_SESSION['email_otp_pending_uid'] = $uid;
                        header('Location: email_otp_verify.php');
                        exit;
                    }
                    if ($method === 'passkey') {
                        if (ipam_passkey_dispatch_challenge($db, $uid)) {
                            audit($db, 'auth.passkey_challenge', 'user', $uid, 'passkey challenge issued');
                            header('Location: passkey_verify.php');
                            exit;
                        }
                        continue;
                    }
                }
                // Reached here only if every method in $chain failed to dispatch
                // (e.g. SMTP broken on email_otp, passkey challenge could not be
                // issued). Treat this as a hard auth failure when the user
                // actually has methods enrolled — falling through to login_user()
                // below would let a user in without an MFA challenge, defeating
                // the second factor entirely. (CR feedback on PR #757.)
                if (count($chain) > 0) {
                    audit($db, 'auth.login_failed', 'user', $uid, 'all_mfa_dispatch_failed');
                    $error = 'Could not deliver an authentication challenge. Please contact your administrator.';
                    goto render_page;
                }
                // #747: when computing whether mfa.require is satisfied, treat
                // users with TOTP enrolled-but-globally-disabled as having no
                // TOTP — they cannot use it to log in until the admin re-enables
                // it. They must enroll Email OTP (or have a passkey already).
                $totpGloballyEnabledForRequireGate = (bool)to_int(ipam_setting('mfa.totp_enabled', true));
                $totpSatisfies = $totpGloballyEnabledForRequireGate && to_int($user['totp_enabled'] ?? 0) === 1;
                $emailOtpGloballyEnabled = (bool)to_int(ipam_setting('mfa.email_otp_enabled', false));
                $emailOtpSatisfies = $emailOtpGloballyEnabled && to_int($user['email_otp_enabled'] ?? 0) === 1;
                $passkeysGloballyEnabled = (bool)to_int(ipam_setting('mfa.passkeys_enabled', false));
                $passkeySatisfies = $passkeysGloballyEnabled && ipam_passkey_has_credentials($db, $uid);
                if ((bool)to_int(ipam_setting('mfa.require', false)) &&
                    !$totpSatisfies &&
                    !$emailOtpSatisfies &&
                    !$passkeySatisfies) {
                    login_user(to_int($user['id']), to_str($user['username']), to_str($user['role']));
                    $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
                       ->execute([':id' => to_int($user['id'])]);
                    audit($db, 'auth.login', 'user', to_int($user['id']), 'login ok (mfa_require redirect)');
                    $_SESSION['mfa_enrollment_required'] = true;
                    header('Location: change_password.php?mfa_required=1#email-otp');
                    exit;
                }
                login_user(to_int($user['id']), to_str($user['username']), to_str($user['role']));
                $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
                   ->execute([':id' => to_int($user['id'])]);
                audit($db, 'auth.login', 'user', to_int($user['id']), 'login ok');
                header('Location: ' . ipam_post_login_redirect_consume());
                exit;
            }

            // Normalise response time to prevent username enumeration via timing (#109)
            if (!$user || to_int($user['is_active'] ?? 0) !== 1) {
                password_verify($password, '$2y$12$' . str_repeat('a', 53));
            }

            record_login_failure($db, $ip, $username);
            $error = 'Invalid username or password.';
            audit($db, 'auth.login_failed', 'user', null, ''); // #117: do not log submitted username
        }
    }
}

render_page:
page_header('Login', array_filter([
    'extra_script_src' => $lpCsp['script_src'],
    'extra_style_src'  => $lpCsp['style_src'],
    'extra_frame_src'  => $lpCsp['frame_src'],
    'no_sidebar'       => true,
]));
$appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <a href="login.php" class="nav-brand">Simple<span class="nav-brand-php">PHP</span>IPAM</a>
  </div>
  <h1>Login</h1>
  <?php if ($timedOut && !$error): ?>
    <p class="warning">Your session expired due to inactivity. Please log in again.</p>
  <?php endif; ?>
  <?php if (!empty($_GET['restored'])): ?>
    <p class="success">Database restored. Please log in again.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>

  <?php if ($oidcActive): ?>
  <p><a href="oidc_login.php" class="btn-sso">Sign in with <?= e(to_str(ipam_setting('oidc.display_name'))) ?></a></p>
  <?php endif; ?>

  <?php if (!$disableLocal || $localForceShown): ?>
  <?php if ($oidcActive): ?><p class="muted mt-12">— or sign in with a local account —</p><?php endif; ?>
  <form method="post" action="login.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="row">
      <label>Username<br><input name="username" autocomplete="username" required></label>
      <label>Password<br><input type="password" name="password" autocomplete="current-password" required></label>
    </div>
    <?php if ($lpWidgetHtml !== ''): ?>
      <div class="mt-10"><?= $lpWidgetHtml ?></div>
    <?php endif; ?>
    <p><button type="submit">Login</button></p>
    <?php if (demo_mode_enabled()): ?>
      <p class="muted" style="margin-top:8px;font-size:.85rem;">Demo credentials: <strong>demo</strong> / <strong>demo</strong></p>
    <?php else: ?>
      <p class="muted" style="margin-top:6px;font-size:.85rem;"><a href="forgot_password.php">Forgot password?</a></p>
    <?php endif; ?>
    <?php if ($firstRun): ?>
      <p class="muted" style="margin-top:10px;">First run: use bootstrap admin from <code>config.php</code>, then change it.</p>
    <?php endif; ?>
  </form>
  <?php elseif ($oidcActive): ?>
    <p class="muted mt-16">Local password login is disabled. Use SSO above.
      <?php if (!$hideEmergencyLink && !$disableEmergencyBypass): ?>
        <a href="login.php?local=1" class="muted font-sm">(emergency local access)</a>
      <?php endif; ?>
    </p>
  <?php endif; ?>
</div><!-- /.login-card -->
<?php
$idleSeconds = to_int(ipam_setting('security.session_idle_seconds'));
if ($idleSeconds > 0):
    $idleMins = (int)round($idleSeconds / 60);
    $idleLabel = $idleMins >= 60
        ? round($idleMins / 60, 1) . ' hr'
        : $idleMins . ' min';
?>
  <p class="muted text-center" style="font-size:.82rem;margin-top:14px;">Sessions expire after <?= e($idleLabel) ?> of inactivity.</p>
<?php endif; ?>
</div><!-- /.login-wrap -->
<?php page_footer();
