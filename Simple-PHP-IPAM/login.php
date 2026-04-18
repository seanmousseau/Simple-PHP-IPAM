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
        if (login_rate_limited($db, $ip, $maxAttempts, $lockoutSeconds)) {
            $error = 'Too many failed login attempts. Please try again later.';
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
                header('Location: dashboard.php');
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
            $error = 'Too many failed login attempts. Please try again later.';
            audit($db, 'auth.login_blocked', 'user', null, 'ip=' . $ip);
        } elseif (!$isRecovery && $username !== '' && account_locked_out($db, $username, $acctMaxAttempts, $acctLockoutSecs)) {
            $error = 'This account is temporarily locked due to too many failed attempts.';
            audit($db, 'auth.account_locked', 'user', null, '');
        } else {
            $st = $db->prepare("SELECT id, username, password_hash, role, is_active FROM users WHERE username = :u");
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
                header('Location: dashboard.php');
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
                login_user(to_int($user['id']), to_str($user['username']), to_str($user['role']));
                $db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
                   ->execute([':id' => to_int($user['id'])]);
                audit($db, 'auth.login', 'user', to_int($user['id']), 'login ok');
                header('Location: dashboard.php');
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
]));
$appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <picture>
      <source srcset="assets/logo.webp" type="image/webp">
      <img src="assets/logo.png" alt="<?= e($appName) ?>" width="200" height="60">
    </picture>
  </div>
  <h1>Login</h1>
  <?php if ($timedOut && !$error): ?>
    <p class="warning">Your session expired due to inactivity. Please log in again.</p>
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
      <label>Username<br><input name="username" required></label>
      <label>Password<br><input type="password" name="password" required></label>
    </div>
    <?php if ($lpWidgetHtml !== ''): ?>
      <div class="mt-10"><?= $lpWidgetHtml ?></div>
    <?php endif; ?>
    <p><button type="submit">Login</button></p>
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
