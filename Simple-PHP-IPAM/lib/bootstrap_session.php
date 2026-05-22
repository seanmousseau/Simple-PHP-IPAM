<?php
declare(strict_types=1);

/**
 * @module bootstrap_session
 *
 * Session isolation bootstrap extracted from init.php in v3.35.0 (#1293).
 * Responsibility: configure and start the PHP session with per-install
 * isolation (name derivation, cookie params, storage path, strict mode) and
 * enforce the absolute session lifetime immediately after session_start().
 *
 * Must be called AFTER the HTTPS redirect check (init.php) and AFTER
 * lib.php is loaded (ipam_session_name / ipam_session_enforce_absolute_lifetime
 * are defined there). Must be called BEFORE any code that reads $_SESSION.
 *
 * ADR-003: no `global $config;` — caller passes $config explicitly.
 * No sibling lib/*.php requires — all helpers resolve at call time.
 *
 * @param IpamConfig $config
 */
function ipam_bootstrap_session(array $config): void
{
    // -------------------------------------------------------------------------
    // Session isolation (v2.10.0 #532 security fix)
    // -------------------------------------------------------------------------
    // Two IPAM installs under the same hostname used to share a session cookie
    // because the default session name ('IPAMSESSID') and path ('/') were
    // identical on every deploy. A user logged into /ipam-a/ would be
    // authenticated on /ipam-b/ without ever entering credentials, because the
    // browser sent the same cookie, PHP loaded the same $_SESSION record, and
    // /ipam-b/'s require_login() trusted the $_SESSION['user_id'] (which
    // resolved against its own users table to its own admin).
    //
    // Three layers of isolation close this:
    //
    // 1. Cookie NAME derived from the install directory hash, so two installs
    //    at different filesystem paths never collide. Users who set
    //    config.session_name explicitly keep full control and get their
    //    exact value (operators who front-proxy multiple hostnames may need
    //    this for SSO-style shared sessions).
    //
    // 2. Cookie PATH scoped to the install's URL directory, so even if two
    //    installs somehow wound up with the same cookie name, the browser
    //    would not send /ipam-a/'s cookie to /ipam-b/ requests. Operators
    //    behind a reverse proxy that rewrites paths can override via
    //    config.session_cookie_path.
    //
    // 3. Session STORAGE path under each install's data/sessions/ with 0700
    //    perms, so PHP's session files are physically separated per install.
    //    Defense in depth: prevents cross-read even if both cookies ever
    //    collided via session fixation.
    //
    // The default for every layer is "derive from __DIR__", which makes
    // isolation automatic on every install without requiring any config edit.
    // B.P3 (#950): the fallback logic (config_session_name === ''
    // || 'IPAMSESSID' → 'IPAMSESSID_' . hash(__DIR__)) is shared with
    // api.php's own session-bootstrap path. Helper lives in lib/auth.php
    // — keyed on the lib/ parent directory so it resolves to this same
    // install root that __DIR__ resolves to here.
    $configuredSessionName = ipam_session_name($config);
    session_name($configuredSessionName);

    // Derive the cookie path from the running script so an install at
    // /claude/ipam-mysql/login.php gets path=/claude/ipam-mysql/. CLI mode
    // has no SCRIPT_NAME and doesn't issue cookies, so the fallback is '/'.
    $configuredCookiePath = to_str($config['session_cookie_path'] ?? '');
    if ($configuredCookiePath === '') {
        $scriptName = to_str($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '') {
            $dir = str_replace('\\', '/', dirname($scriptName));
            $configuredCookiePath = ($dir === '' || $dir === '.' || $dir === '/') ? '/' : $dir . '/';
        } else {
            $configuredCookiePath = '/';
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $configuredCookiePath,
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    // Session storage isolation: each install keeps its own session files
    // under data/sessions/ so two installs that somehow ended up with the
    // same session ID would still look up different records. Created with
    // 0700 perms so other local users on a shared host cannot list or read
    // the session files.
    $sessionSaveDir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($sessionSaveDir)) {
        // best-effort: directory may already exist if another request raced us
        @mkdir($sessionSaveDir, 0700, true);
    }
    // CR #1307 #4 / #1292 review: fail closed if data/sessions cannot be
    // created or is not writable. Continuing with PHP's default save_path
    // (typically /tmp) would silently fall back to a shared directory,
    // weakening the per-install session isolation that #532 established.
    // Boot must stop here rather than silently degrade security.
    if (!is_dir($sessionSaveDir) || !is_writable($sessionSaveDir)) {
        throw new \RuntimeException(
            "IPAM boot failed: session save directory '{$sessionSaveDir}' "
            . "cannot be created or is not writable. "
            . "Check permissions on data/ or create data/sessions/ with 0700 manually."
        );
    }
    ini_set('session.save_path', $sessionSaveDir);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();

    // LiteSpeed Server-Side Cache bypass: LiteSpeed ignores the standard
    // Cache-Control: no-store header for its own ESI/full-page cache. All PHP
    // pages in this app carry session state and CSRF tokens so they must never
    // be served from cache. X-LiteSpeed-Cache-Control is the authoritative
    // opt-out that LiteSpeed actually respects.
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        header('X-LiteSpeed-Cache-Control: no-cache');
    }

    // Enforce absolute session lifetime (#420); no-op in CLI context where there is no session
    if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
        ipam_session_enforce_absolute_lifetime($config);
    }
}
