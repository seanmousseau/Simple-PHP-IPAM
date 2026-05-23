<?php
declare(strict_types=1);

// Defensive error handler: routes E_WARNING / E_NOTICE / E_DEPRECATED to
// error_log() only — never stdout. Prevents "headers already sent" cascades
// from corrupting HTTP responses. Operators monitor the PHP error log.
// Full rationale: docs/internal/design-document.md (error-handler invariant).
// History: post_max_size overflow (v2.9.1), undefined config key (v2.9.x).
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) return true;
    error_log(sprintf('Simple-PHP-IPAM [%d]: %s in %s:%d', $severity, $message, $file, $line));
    return true;
}, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED);

// Global exception handler (v2.10.0 #536): routes every uncaught Throwable to
// error_log() (full trace, for operators) and returns an HTTP 500 with only
// the operator-written exception message in the body — no paths, no stack.
// PHP parse errors / memory-exhaustion still bypass user handlers.
// Full rationale: docs/internal/design-document.md (exception-handler invariant).
set_exception_handler(static function (\Throwable $e): void {
    error_log(sprintf(
        "Simple-PHP-IPAM uncaught %s: %s in %s:%d\n%s",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    ));
    // Use htmlspecialchars() directly — e() from lib.php may not be loaded yet.
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' && !headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Simple PHP IPAM — Configuration error</title>';
    echo '<style>body{font:16px system-ui,-apple-system,sans-serif;max-width:720px;margin:80px auto;padding:0 24px;color:#1e293b;background:#f8fafc}h1{font-size:var(--text-xl,1.5rem);margin:0 0 16px;color:#991b1b}.msg{background:#fef2f2;border:1px solid #fecaca;border-left:4px solid #dc2626;padding:16px 20px;border-radius:6px;margin:20px 0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:var(--text-sm,0.875rem);word-break:break-word}.hint{color:#64748b;font-size:var(--text-sm,0.875rem);margin-top:24px}</style>';
    echo '</head><body>';
    echo '<h1>Configuration error</h1>';
    echo '<p>Simple PHP IPAM could not start. The server reports:</p>';
    echo '<div class="msg">' . $msg . '</div>';
    echo '<p class="hint">An administrator should check the PHP error log for full diagnostic details.</p>';
    echo '</body></html>';
    exit(1);
});

/** @var IpamConfig $config */
$config = require __DIR__ . '/config.php';

// Pure helpers — loaded before lib.php so the HTTPS redirect and session
// setup below can call to_str() / ipam_config() without pulling in lib.php.
require_once __DIR__ . '/lib/utils.php';
require_once __DIR__ . '/lib/ip.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/demo_seed.php';
require_once __DIR__ . '/lib/dhcp.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/presentation.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/user_preferences.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/auth_password.php';
require_once __DIR__ . '/lib/auth_rate_limit.php';
require_once __DIR__ . '/lib/auth_recaptcha.php';

// Bootstrap modules (#1293, v3.35.0) — each has a focused test.
require_once __DIR__ . '/lib/bootstrap_dialect.php';
require_once __DIR__ . '/lib/bootstrap_session.php';
require_once __DIR__ . '/lib/bootstrap_runtime.php';
require_once __DIR__ . '/lib/bootstrap_demo.php';

// Composer autoloader (#416). Conditional — vendor/ only exists in release
// tarballs and dev environments after `composer install`.
$_ipam_autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($_ipam_autoload)) {
    $_ipam_autoload = dirname(__DIR__) . '/vendor/autoload.php';
}
if (is_file($_ipam_autoload)) {
    require_once $_ipam_autoload;
}
unset($_ipam_autoload);

// Dialect abstraction (#378) — validate driver + stash Dialect instance.
require_once __DIR__ . '/dialects/Dialect.php';
require_once __DIR__ . '/dialects/DialectValidator.php';
ipam_bootstrap_dialect($config);

// Seed UTC default so pre-DB operations are deterministic. Real timezone is
// applied from DB settings inside ipam_bootstrap_runtime_gates().
date_default_timezone_set('UTC');

/** @param array<string, mixed> $server */
function request_is_https(array $server, bool $trustProxyHeaders): bool
{
    if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') return true;
    if ($trustProxyHeaders) {
        if (!empty($server['HTTP_X_FORWARDED_PROTO']) && strtolower(to_str($server['HTTP_X_FORWARDED_PROTO'])) === 'https') return true;
        if (!empty($server['HTTP_X_FORWARDED_SSL']) && strtolower(to_str($server['HTTP_X_FORWARDED_SSL'])) === 'on') return true;
    }
    return false;
}

// Skip HTTPS redirect for CLI (migrate.php, tmp_cleanup.php).
$isHttps = php_sapi_name() === 'cli' || request_is_https($_SERVER, (bool)$config['proxy_trust']);
if (!$isHttps) {
    $base = rtrim(to_str($config['base_url'] ?? ''), '/');
    $uri  = to_str($_SERVER['REQUEST_URI'] ?? '/');
    if ($base !== '') {
        header('Location: ' . $base . $uri, true, 301);
    } else {
        $host = to_str($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '' || !preg_match('/^(\[[:0-9a-fA-F]+\]|[a-zA-Z0-9._\-]+)(:\d+)?$/', $host)) {
            http_response_code(400);
            echo 'Invalid request: base_url is not configured and Host header is not valid.';
            exit(1);
        }
        header('Location: https://' . $host . $uri, true, 301);
    }
    exit;
}

// Bootstrap chain — order is invariant (session before DB, runtime gates
// before demo, all before page logic). See docs/internal/design-document.md.
ipam_bootstrap_session($config);     // name, cookie params, session_start, lifetime gate

require __DIR__ . '/lib.php';

$db = ipam_db($config);
ipam_db_init($db);

ipam_bootstrap_runtime_gates($config, $db); // timezone, config warnings, housekeeping, alerts
ipam_bootstrap_demo_mode($config, $db);     // nightly reset + demo gate redirect

// v3.26.0 (#1059): legacy backup runner retired; cron.php drives all backups.

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
