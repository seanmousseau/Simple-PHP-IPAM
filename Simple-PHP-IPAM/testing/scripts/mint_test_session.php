<?php
/**
 * Test-only CLI helper: mints a logged-in PHP session for the given username
 * and prints the session ID to stdout. Used by step-up-oidc-only.spec.ts to
 * drive a session as an OIDC-only admin (password_hash='!disabled') without
 * actually round-tripping through an OIDC IdP.
 *
 * Why this is necessary: the standard login.php form refuses '!disabled'
 * password hashes, and the OIDC callback handler requires a real IdP
 * round-trip with PKCE / state nonces. Neither path is feasible from
 * Playwright. Driving the session file directly mirrors what login_user()
 * would have written; the resulting session passes is_logged_in() and
 * require_login() exactly the same way a real login does.
 *
 * Session payload mirrors lib.php login_user() (lib.php:998):
 *   uid           int      user id
 *   username      string
 *   role          string   admin|readonly
 *   last_active   int      unix timestamp; require_login() idle-times-out
 *                          on this so we set it to time() at mint.
 *   user_theme    string   loaded from users.theme so page_header() doesn't
 *                          re-query.
 *
 * Usage:
 *   php mint_test_session.php <username>
 *
 * CLI-only. The session save handler must be 'files' with a writable
 * save_path that the same Apache process can read on the next request.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$username = $argv[1] ?? '';
if ($username === '') {
    fwrite(STDERR, "Usage: mint_test_session.php <username>\n");
    exit(2);
}

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) $configPath = '/var/www/html/config.php';
if (!file_exists($configPath)) {
    fwrite(STDERR, "config.php not found\n");
    exit(3);
}
$config = require $configPath;

$libPath = __DIR__ . '/../../lib.php';
if (!file_exists($libPath)) $libPath = '/var/www/html/lib.php';
require_once $libPath;

$db = ipam_db($config);
$st = $db->prepare(
    "SELECT id, username, role, theme FROM users WHERE username = :u LIMIT 1"
);
$st->execute([':u' => $username]);
$row = $st->fetch();
if (!is_array($row)) {
    fwrite(STDERR, "user '{$username}' not found\n");
    exit(5);
}

$uid   = to_int($row['id'] ?? 0);
$theme = to_str($row['theme'] ?? 'auto');
if (!in_array($theme, ['auto', 'light', 'dark'], true)) $theme = 'auto';

// Resolve session.save_path the same way init.php does. If it's empty, fall
// back to the conventional data/sessions/ under the web root.
$savePath = ini_get('session.save_path');
if ($savePath === '' || $savePath === false) {
    $savePath = dirname(__DIR__, 2) . '/data/sessions';
}
if (!is_dir($savePath)) {
    fwrite(STDERR, "session save_path not a directory: {$savePath}\n");
    exit(6);
}

// 32-char lower hex matches PHP's default session_id format closely enough
// that browsers / curl / Playwright treat it as a normal opaque cookie value.
$sid = bin2hex(random_bytes(16));

session_id($sid);
session_save_path($savePath);
$started = session_start([
    'use_cookies'      => 0,
    'use_only_cookies' => 0,
    'cache_limiter'    => '',
    'use_strict_mode'  => 0,
]);
if ($started === false || session_status() !== PHP_SESSION_ACTIVE) {
    fwrite(STDERR, "session_start() failed (status={" . session_status() . "}, save_path={$savePath})\n");
    exit(8);
}

$_SESSION = [];
$_SESSION['uid']         = $uid;
$_SESSION['username']    = to_str($row['username'] ?? '');
$_SESSION['role']        = to_str($row['role'] ?? '');
$_SESSION['last_active'] = time();
$_SESSION['user_theme']  = $theme;

$absMin = (int)(($config['session']['absolute_lifetime_minutes'] ?? 480));
if ($absMin > 0) {
    $_SESSION['_abs_expires'] = time() + ($absMin * 60);
}

session_write_close();

// Replicate init.php's cookie-name derivation (init.php:226-231) so the
// caller can set the right cookie. The mint script doesn't include init.php
// (init.php would session_start() with use_strict_mode=1 and clobber what
// we wrote), so we recompute the name here against the same install dir.
$cookieName = to_str($config['session_name'] ?? '');
if ($cookieName === '' || $cookieName === 'IPAMSESSID') {
    $installDir = dirname(__DIR__, 2); // .../testing/scripts → install root
    $cookieName = 'IPAMSESSID_' . substr(hash('sha256', $installDir), 0, 8);
}

echo "cookie_name={$cookieName}\n";
echo "sid={$sid}\n";
