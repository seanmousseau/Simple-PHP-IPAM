<?php
declare(strict_types=1);

/**
 * @module auth
 *
 * Core session, CSRF, and login helpers extracted from lib.php in v3.30.0
 * (ADR-004 Phase 6 Task 6.1, sub of #907). Functions stay in the global
 * namespace per ADR-004 Option E.
 *
 * Responsibilities:
 *  - CSRF token read + POST enforcement (csrf_token, csrf_require).
 *  - Login-state checks and RBAC gates (is_logged_in, require_login,
 *    require_role, require_write_access, current_user).
 *  - Session establishment / teardown (login_user, logout_user).
 *  - Post-login redirect stash/consume (open-redirect-safe).
 *  - Caller IP resolution honouring proxy-trust config (client_ip).
 *  - Absolute session-lifetime enforcement (ipam_session_enforce_absolute_lifetime).
 *
 * Inclusion rule: functions whose primary job is establishing, validating,
 * or tearing down an authenticated browser session, or guarding a page
 * against unauthenticated / under-privileged access. Login rate-limiting
 * (lib/auth_rate_limit.php, Task 6.3), password complexity / Argon2 / reset
 * tokens (Task 6.2), and reCAPTCHA (Task 6.4) deliberately stay behind.
 *
 * ADR-003: `global $config;` / `$GLOBALS['config']` reads are converted to
 * the ipam_config() / ipam_config_nested() accessors from lib/config.php.
 * login_user() seeds the absolute-lifetime expiry from
 * ipam_config_nested('session', 'absolute_lifetime_minutes'); client_ip()
 * reads ipam_config('proxy_trust') for the legacy back-compat path.
 * ipam_session_enforce_absolute_lifetime() takes its $config array as a
 * caller-passed parameter, so its signature is unchanged. The `global $db`
 * handle stays a runtime PDO lookup.
 *
 * Dependencies: lib/db.php ($GLOBALS['db'] PDO handle), lib/audit.php,
 * lib/utils.php (to_str / to_int), lib/ip.php (ip_in_any_cidr),
 * lib/presentation.php, lib/config.php (ipam_config / ipam_config_nested),
 * lib/settings.php (ipam_setting), lib/user_preferences.php
 * (ipam_user_preference_get — called by login_user()). All cross-module
 * helpers resolve lazily at call time, never at include time — this module
 * has no side-effects on load.
 */

/* ---------------- CSRF ---------------- */

/**
 * Return the current session's CSRF token, or '' when none is set.
 * Reads $_SESSION['csrf']; does not generate or mutate it.
 */
function csrf_token(): string
{
    $t = $_SESSION['csrf'] ?? null;
    return is_string($t) ? $t : '';
}

/**
 * Enforce CSRF protection on POST requests. A no-op for non-POST methods.
 * On a missing or mismatched $_POST['csrf'] token: sends a hard 403,
 * writes a plain-text body, and exit()s — never a redirect. Must be called
 * on every browser POST handler before mutating state (CLAUDE.md
 * invariant #4); api.php is the only CSRF-exempt surface.
 *
 * Side effects: may emit a 403 header + body and terminate the request.
 */
function csrf_require(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = $_POST['csrf'] ?? null;
    $real = csrf_token();
    if (!is_string($sent) || !hash_equals($real, $sent)) {
        // Hard 403 — never a redirect. PHP's `header('Location: ...')`
        // silently clobbers the response code to 302, which obscures the
        // CSRF failure and lets API/XHR clients silently follow the
        // redirect to login.php and re-submit. The user_preference.php B1
        // CSRF spec (#879) caught this regression.
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "CSRF token missing or invalid.\n";
        exit;
    }
}

/* ---------------- Auth / RBAC ---------------- */

/** True when the session holds an authenticated user id. Reads $_SESSION['uid']. */
function is_logged_in(): bool { return !empty($_SESSION['uid']); }

/**
 * Stash a same-origin request URI in the session so the user can be
 * redirected back to it after they finish logging in (including any MFA
 * detour). Only safe relative paths are accepted — schemes, hosts, embedded
 * newlines, and parent-directory traversal are all rejected to prevent
 * open-redirect and header-injection.
 */
function ipam_post_login_redirect_stash(string $uri): void
{
    if ($uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//')) return;
    if (preg_match('/[\r\n]/', $uri)) return;
    if (str_contains($uri, '..')) return;
    // Reject backslashes — some browsers canonicalise them to forward slashes,
    // which would let "/\evil.com" become "//evil.com" after normalisation.
    if (str_contains($uri, '\\')) return;
    if (strlen($uri) > 1024) return;
    $_SESSION['post_login_redirect'] = $uri;
}

/**
 * Pull and clear the stashed post-login URI. Returns $default if nothing is
 * stashed or the stashed value fails revalidation (defence in depth — same
 * checks as stash, in case the session was tampered with).
 */
function ipam_post_login_redirect_consume(string $default = 'dashboard.php'): string
{
    $uri = to_str($_SESSION['post_login_redirect'] ?? '');
    unset($_SESSION['post_login_redirect']);
    if ($uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//')) return $default;
    if (preg_match('/[\r\n]/', $uri)) return $default;
    if (str_contains($uri, '..')) return $default;
    // Reject backslashes — see note in ipam_post_login_redirect_stash().
    if (str_contains($uri, '\\')) return $default;
    if (strlen($uri) > 1024) return $default;
    return $uri;
}

/**
 * Page guard: require an authenticated, non-stale session. Enforces idle
 * timeout (security.session_idle_seconds), max password age, and pending
 * MFA enrollment.
 *
 * Side effects: on any failed check, sends a `Location:` header and
 * exit()s — to login.php (not logged in / idle timeout), to
 * change_password.php (password expired / MFA required). On success,
 * refreshes $_SESSION['last_active']. May stash the current REQUEST_URI
 * for post-login redirect and may open/close a fresh session. Reads the
 * global $db handle and ipam_setting() values.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        ipam_post_login_redirect_stash(to_str($_SERVER['REQUEST_URI'] ?? ''));
        header('Location: login.php');
        exit;
    }
    $idle = to_int(ipam_setting('security.session_idle_seconds'));
    if (isset($_SESSION['last_active']) && (time() - to_int($_SESSION['last_active'])) > $idle) {
        // Stash the URI before logout_user() wipes the session so the
        // post-login redirect survives the idle-timeout bounce.
        $stashUri = to_str($_SERVER['REQUEST_URI'] ?? '');
        logout_user();
        if ($stashUri !== '') {
            session_start();
            ipam_post_login_redirect_stash($stashUri);
            session_write_close();
        }
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_active'] = time();

    $maxAge = to_int(ipam_setting('password_policy.max_password_age_days'));
    if ($maxAge > 0) {
        $page = basename(to_str($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (!in_array($page, ['change_password.php', 'logout.php'], true)) {
            try {
                $db = $GLOBALS['db'] ?? null;
                if ($db instanceof PDO) {
                    $st = $db->prepare("SELECT oidc_sub, password_changed_at FROM users WHERE id = :id");
                    $st->execute([':id' => to_int($_SESSION['uid'] ?? 0)]);
                    /** @var array<string, mixed>|false $row */
                    $row = $st->fetch();
                    if ($row && $row['oidc_sub'] === null) {
                        $changedAt = to_str($row['password_changed_at'] ?? '');
                        // password_changed_at is stored in UTC; build the
                        // cutoff in UTC (gmdate) for a correct comparison.
                        $cutoff    = gmdate('Y-m-d H:i:s', time() - $maxAge * 86400);
                        if ($changedAt === '' || $changedAt < $cutoff) {
                            header('Location: change_password.php?expired=1');
                            exit;
                        }
                    }
                }
            } catch (Throwable) {
                // Column may not exist yet on pre-1.4 installs — silently skip
            }
        }
    }

    if (!empty($_SESSION['mfa_enrollment_required'])) {
        $page = basename(to_str($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if (!in_array($page, ['change_password.php', 'totp_enroll.php', 'logout.php'], true)) {
            header('Location: change_password.php?mfa_required=1');
            exit;
        }
    }
}

/** @return array<string, mixed> */
function current_user(): array
{
    return [
        'id' => to_int($_SESSION['uid'] ?? 0),
        'username' => to_str($_SESSION['username'] ?? ''),
        'role' => to_str($_SESSION['role'] ?? ''),
    ];
}

/**
 * Page guard: require login and an exact role match. Calls require_login()
 * first (which may redirect + exit); then, if the session role differs
 * from $role, sends a 403 and exit()s with 'Forbidden'.
 *
 * @param string $role Required role, e.g. 'admin'.
 */
function require_role(string $role): void
{
    require_login();
    if (current_user()['role'] !== $role) {
        http_response_code(403);
        exit('Forbidden');
    }
}

/**
 * Page guard: require login and a non-readonly role. Calls require_login()
 * first (which may redirect + exit); then, if the session role is
 * 'readonly', sends a 403 and exit()s with 'Read-only account'.
 */
function require_write_access(): void
{
    require_login();
    if (current_user()['role'] === 'readonly') {
        http_response_code(403);
        exit('Read-only account');
    }
}

/**
 * Establish an authenticated session for a verified user.
 *
 * Side effects: regenerates the session id, populates $_SESSION (uid,
 * username, role, last_active); sets $_SESSION['_abs_expires'] from
 * session.absolute_lifetime_minutes config when that is > 0, otherwise
 * clears it; and — when $db is supplied — loads the user's persisted theme
 * into $_SESSION['user_theme']. The caller is responsible for verifying
 * credentials/MFA before calling this.
 *
 * @param int      $uid      Authenticated user id.
 * @param string   $username Authenticated username.
 * @param string   $role     User role.
 * @param PDO|null $db        Live PDO handle; when null the theme preference is skipped.
 */
function login_user(int $uid, string $username, string $role, ?PDO $db = null): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    $_SESSION['username'] = $username;
    $_SESSION['role'] = $role;
    $_SESSION['last_active'] = time();
    // Seed absolute session lifetime from config at login time.
    // ADR-003: was $GLOBALS['config']['session']['absolute_lifetime_minutes'].
    $absMin = to_int(ipam_config_nested('session', 'absolute_lifetime_minutes') ?? 480);
    if ($absMin > 0) {
        $_SESSION['_abs_expires'] = time() + ($absMin * 60);
    } else {
        unset($_SESSION['_abs_expires']);
    }
    // Load persisted theme preference so page_header() can prime localStorage
    if ($db !== null) {
        $theme = to_str(ipam_user_preference_get($db, $uid, 'theme') ?? 'auto');
        $_SESSION['user_theme'] = in_array($theme, ['light', 'dark', 'auto'], true) ? $theme : 'auto';
    }
}

/**
 * Tear down the current session. Side effects: clears $_SESSION, expires
 * the session cookie, and calls session_destroy().
 */
function logout_user(): void
{
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie((string)session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/* ---------------- Caller IP resolution ---------------- */

/**
 * Resolve the caller's IP address, honouring proxy-trust configuration.
 *
 * When security.proxy_trust_cidrs is set, trusts X-Forwarded-For only if
 * REMOTE_ADDR is a listed proxy CIDR, then walks the chain right-to-left
 * and returns the first untrusted hop (OWASP pattern). Falls back to the
 * legacy boolean `proxy_trust` config flag (leftmost XFF, deprecated —
 * logs a one-shot warning). Returns REMOTE_ADDR when no proxy trust is
 * configured. Reads $_SERVER, ipam_setting() and ipam_config().
 *
 * @return string The resolved client IP, or '127.0.0.1' when REMOTE_ADDR is absent.
 */
function client_ip(): string
{
    $remote = to_str($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

    // New (v3.26.0+) preferred path: trust X-Forwarded-For only when the
    // direct REMOTE_ADDR is one of the operator-listed proxy CIDRs, then walk
    // the chain right-to-left and return the first untrusted hop. This is the
    // OWASP-recommended pattern (see docs/configuration.md → proxy_trust_cidrs).
    $cidrsRaw  = to_str(ipam_setting('security.proxy_trust_cidrs', ''));
    $cidrs     = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $cidrsRaw) ?: [])));
    $xffHeader = to_str($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

    if (!empty($cidrs)) {
        if (!ip_in_any_cidr($remote, $cidrs)) {
            return $remote;
        }
        if ($xffHeader === '') {
            return $remote;
        }
        $hops      = array_reverse(array_map('trim', explode(',', $xffHeader)));
        $candidate = $remote;
        foreach ($hops as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                return $candidate;
            }
            if (ip_in_any_cidr($hop, $cidrs)) {
                $candidate = $hop;
                continue;
            }
            return $hop;
        }
        return $candidate;
    }

    // Legacy back-compat: the old boolean `proxy_trust` flag in config.php
    // unconditionally trusted the leftmost X-Forwarded-For value. That is
    // unsafe (the leftmost hop is whatever the original client sent and is
    // freely spoofable) but operators relying on it must not break silently
    // on upgrade. Emit a one-shot deprecation log per request and keep the
    // old behaviour. Operators should migrate to security.proxy_trust_cidrs.
    // ADR-003: was $GLOBALS['config']['proxy_trust'].
    if (!empty(ipam_config('proxy_trust')) && $xffHeader !== '') {
        static $warned = false;
        if (!$warned) {
            error_log('client_ip: legacy `proxy_trust` config flag is deprecated; configure security.proxy_trust_cidrs instead (#876).');
            $warned = true;
        }
        $parts     = array_map('trim', explode(',', $xffHeader));
        $candidate = $parts[0];
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }
    return $remote;
}

// ============================================================
// Session name derivation (#950 B.P3)
// ============================================================

/**
 * Derive the session cookie name from `$config['session_name']`, falling
 * back to a per-install-directory-hashed default when unset or set to the
 * legacy `'IPAMSESSID'` literal. Two installs at different filesystem paths
 * never collide.
 *
 * The fallback logic is identical to the bootstrap at `init.php`; this
 * helper exists so `api.php`'s own session-bootstrap path (used for
 * browser-session-authenticated GET endpoints when no Bearer key is
 * supplied) can derive the same value without duplicating the logic.
 *
 * @param IpamConfig $config
 */
function ipam_session_name(array $config): string
{
    $name = to_str($config['session_name']);
    if ($name === '' || $name === 'IPAMSESSID') {
        $name = 'IPAMSESSID_' . substr(hash('sha256', dirname(__DIR__)), 0, 8);
    }
    return $name;
}

// ============================================================
// Session absolute lifetime (v3.6.0, #420)
// ============================================================

/** @param IpamConfig $config */
function ipam_session_enforce_absolute_lifetime(array $config): void
{
    $lifetimeMin = (int)(($config['session']['absolute_lifetime_minutes'] ?? 480));
    if ($lifetimeMin <= 0) {
        return; // Disabled
    }
    if (!isset($_SESSION['_abs_expires'])) {
        return; // Not yet seeded — pre-auth request; seeding happens in login_user()
    }
    $expires    = $_SESSION['_abs_expires'];
    $expiresInt = is_int($expires) ? $expires : (is_numeric($expires) ? (int)$expires : 0);
    if (time() > $expiresInt) {
        // Stash REQUEST_URI before destroying the session so users who follow
        // an authenticated link (e.g. email-verification) with a stale cookie
        // are returned to that URL after re-login rather than dumped on the
        // dashboard.
        $stashUri = to_str($_SERVER['REQUEST_URI'] ?? '');
        $_SESSION = [];
        session_destroy();
        if ($stashUri !== '') {
            session_start();
            ipam_post_login_redirect_stash($stashUri);
            session_write_close();
        }
        header('Location: login.php?reason=session_expired');
        exit;
    }
}
