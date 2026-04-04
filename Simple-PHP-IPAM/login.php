<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

if (is_logged_in()) { header('Location: dashboard.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$maxAttempts   = (int)($config['login_max_attempts']   ?? 5);
$lockoutSeconds = (int)($config['login_lockout_seconds'] ?? 900);

$error    = '';
$timedOut = !empty($_GET['timeout']);

// Login protection setup (#124) — widget HTML also sets time_check session ts on GET
$lpMethod     = (string)(($config['login_protection'] ?? [])['method'] ?? '');
$lpWidgetHtml = login_protection_widget_html($config);
$lpCsp        = login_protection_extra_csp($config);

// Consume any OIDC error flash set by oidc_callback.php or oidc_login.php
if (!empty($_SESSION['oidc_error'])) {
    $error = (string)$_SESSION['oidc_error'];
    unset($_SESSION['oidc_error']);
}

// Render-prep variables (needed even when goto jumps past POST block)
try {
    $firstRun = !$db->query("SELECT 1 FROM audit_log WHERE action='auth.login' LIMIT 1")->fetch();
} catch (Throwable $e) {
    $firstRun = true; // treat as first-run if audit_log is temporarily unavailable
}
$oidcActive            = oidc_enabled($config) && !demo_mode_enabled();
$disableLocal          = $oidcActive && !empty($config['oidc']['disable_local_login']);
$disableEmergencyBypass= $oidcActive && !empty($config['oidc']['disable_emergency_bypass']);
$hideEmergencyLink     = $oidcActive && !empty($config['oidc']['hide_emergency_link']);
$localForceShown       = isset($_GET['local']) && !$disableEmergencyBypass;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
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
            $demoUser = $st->fetch();
            if ($demoUser) {
                login_user((int)$demoUser['id'], (string)$demoUser['username'], (string)$demoUser['role']);
                $db->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=:id")
                   ->execute([':id' => (int)$demoUser['id']]);
                audit($db, 'auth.login', 'user', (int)$demoUser['id'], 'demo login');
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

        if (login_rate_limited($db, $ip, $maxAttempts, $lockoutSeconds)) {
            $error = 'Too many failed login attempts. Please try again later.';
            audit($db, 'auth.login_blocked', 'user', null, 'ip=' . $ip);
        } else {
            $st = $db->prepare("SELECT id, username, password_hash, role, is_active FROM users WHERE username = :u");
            $st->execute([':u' => $username]);
            $user = $st->fetch();

            if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $new = password_hash($password, PASSWORD_DEFAULT);
                    $up = $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
                    $up->execute([':h' => $new, ':id' => $user['id']]);
                }
                clear_login_failures($db, $ip);
                login_user((int)$user['id'], (string)$user['username'], (string)$user['role']);
                $db->prepare("UPDATE users SET last_login_at=datetime('now') WHERE id=:id")
                   ->execute([':id' => (int)$user['id']]);
                audit($db, 'auth.login', 'user', (int)$user['id'], 'login ok');
                header('Location: dashboard.php');
                exit;
            }

            // Normalise response time to prevent username enumeration via timing (#109)
            if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
                password_verify($password, '$2y$12$' . str_repeat('a', 53));
            }

            record_login_failure($db, $ip);
            $error = 'Invalid username or password.';
            audit($db, 'auth.login_failed', 'user', null, ''); // #117: do not log submitted username
        }
    }
}

render_page:
page_header('Login', array_filter([
    'extra_script_src' => $lpCsp['script_src'],
    'extra_frame_src'  => $lpCsp['frame_src'],
]));
$appName = trim((string)($config['app_name'] ?? '')) ?: 'Simple PHP IPAM';
?>
<div class="login-wrap">
<div class="login-card">
  <div class="login-logo">
    <img src="assets/logo.svg" alt="<?= e($appName) ?>">
    <p class="muted"><?= e($appName) ?></p>
  </div>
  <h1>Login</h1>
  <?php if ($timedOut && !$error): ?>
    <p class="warning">Your session expired due to inactivity. Please log in again.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>

  <?php if ($oidcActive): ?>
  <p><a href="oidc_login.php" class="btn-sso">Sign in with <?= e((string)($config['oidc']['display_name'] ?? 'SSO')) ?></a></p>
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
$idleSeconds = (int)($config['session_idle_seconds'] ?? 1800);
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
