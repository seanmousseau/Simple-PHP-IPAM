<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

if (is_logged_in()) { header('Location: dashboard.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_require();

$maxAttempts   = (int)($config['login_max_attempts']   ?? 5);
$lockoutSeconds = (int)($config['login_lockout_seconds'] ?? 900);

$error = '';
$timedOut = !empty($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();

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
            audit($db, 'auth.login', 'user', (int)$user['id'], 'login ok');
            header('Location: dashboard.php');
            exit;
        }

        record_login_failure($db, $ip);
        $error = 'Invalid username or password.';
        audit($db, 'auth.login_failed', 'user', null, 'username=' . $username);
    }
}

page_header('Login');
?>
<h1>Login</h1>
<?php if ($timedOut && !$error): ?>
  <p class="warning">Your session expired due to inactivity. Please log in again.</p>
<?php endif; ?>
<?php if ($error): ?><p class="danger"><?= e($error) ?></p><?php endif; ?>

<form method="post" action="login.php" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="row">
    <label>Username<br><input name="username" required></label>
    <label>Password<br><input type="password" name="password" required></label>
  </div>
  <p><button type="submit">Login</button></p>
  <p class="muted">First run: use bootstrap admin from <code>config.php</code>, then change it.</p>
</form>
<?php page_footer();
