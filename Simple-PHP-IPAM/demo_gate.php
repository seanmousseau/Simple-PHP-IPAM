<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

// Already passed the gate — go straight to login
if (!empty($_SESSION['demo_gate_passed'])) {
    header('Location: login.php');
    exit;
}

// Gate not configured — nothing to do
if (empty($config['demo_mode']['enabled']) || empty($config['demo_mode']['gate'])) {
    header('Location: login.php');
    exit;
}

// Build a config stub that maps demo_mode gate settings to the login_protection format
// so we can reuse login_protection_verify() and login_protection_widget_html().
$gateConfig = [
    'login_protection' => [
        'method'      => to_str($config['demo_mode']['gate'] ?? ''),
        'site_key'    => to_str($config['demo_mode']['site_key']   ?? ''),
        'secret_key'  => to_str($config['demo_mode']['secret_key'] ?? ''),
        'min_seconds' => 3,
        'version'     => 2,
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = to_str($_POST['_gate_csrf'] ?? '');
    if (!hash_equals(to_str($_SESSION['gate_csrf'] ?? ''), $token) || $token === '') {
        header('Location: demo_gate.php');
        exit;
    }
    unset($_SESSION['gate_csrf']);

    $result = login_protection_verify($gateConfig, $_POST);
    if ($result === null) {
        // Passed
        $_SESSION['demo_gate_passed'] = true;
        header('Location: login.php');
        exit;
    }
    $gateError = $result !== '' ? $result : ''; // '' = honeypot (silent — re-render)
} else {
    $gateError = '';
}

// Generate a one-time CSRF token for the gate form
$_SESSION['gate_csrf'] = bin2hex(random_bytes(16));

$widgetHtml = login_protection_widget_html($gateConfig);
$gateCsp    = login_protection_extra_csp($gateConfig);

$extraScriptSrc = $gateCsp['script_src'] !== '' ? ' ' . $gateCsp['script_src'] : '';
$extraStyleSrc  = $gateCsp['style_src']  !== '' ? ' ' . $gateCsp['style_src'] : '';
$extraFrameSrc  = $gateCsp['frame_src']  !== '' ? " frame-src 'self' " . $gateCsp['frame_src'] . ';' : '';
header("Content-Security-Policy: default-src 'self'; script-src 'self'{$extraScriptSrc}; style-src 'self'{$extraStyleSrc}; img-src 'self' data:;{$extraFrameSrc} frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once __DIR__ . '/version.php';
$appName = trim(to_str(ipam_setting('branding.site_name'))) ?: 'Simple PHP IPAM';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e($appName) ?> — Access Check</title>
  <link rel="icon" type="image/webp" sizes="32x32" href="assets/favicon-32.webp?v=3.8.1">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png?v=3.8.1">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/apple-touch-icon.png?v=3.8.1">
  <link rel="stylesheet" href="assets/vendor/open-props.min.css?v=3.8.1">
  <link rel="stylesheet" href="assets/app.css?v=3.8.1">
  <script defer src="assets/app.js?v=3.8.1"></script>
</head>
<body>
<div class="gate-wrap">
  <div style="text-align:center;margin-bottom:12px;">
    <div class="nav-brand" style="font-size:2rem;display:block;">Simple<span class="nav-brand-php">PHP</span>IPAM</div>
  </div>
  <h1>Almost there…</h1>
  <?php render_security_banner('demo_gate', 'This is a demo environment. Do not enter real IP data or credentials.'); ?>
  <p class="muted">Please complete the check below to continue to the demo.</p>

  <?php if ($gateError !== ''): ?>
    <p class="danger"><?= e($gateError) ?></p>
  <?php endif; ?>

  <form method="post" action="demo_gate.php">
    <input type="hidden" name="_gate_csrf" value="<?= e(to_str($_SESSION['gate_csrf'])) ?>">
    <?php if ($widgetHtml !== ''): ?>
      <div class="mt-10"><?= $widgetHtml ?></div>
    <?php endif; ?>
    <p class="mt-10"><button type="submit">Continue</button></p>
  </form>
</div>
</body>
</html>
