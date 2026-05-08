<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
/** @var IpamConfig $config */

// v3.27.0 (#1113) — bypass the "already logged in" redirect when this
// callback is the return leg of a sudo step-up reauth flow (the user IS
// logged in; that's the whole point of the round-trip).
$sudoReauthInFlight = isset($_SESSION['sudo_oidc_reauth_state'])
    && to_str($_SESSION['sudo_oidc_reauth_state']) !== '';
if (!$sudoReauthInFlight && is_logged_in()) { header('Location: dashboard.php'); exit; }
if (!oidc_enabled($config))                 { header('Location: login.php');     exit; }

/**
 * Redirect to login with a generic error message.
 * Detailed reason is only written to the server error log.
 */
function oidc_fail(PDO $db, string $logMsg): never
{
    error_log('OIDC callback failure: ' . $logMsg);
    audit($db, 'auth.oidc_failed', 'user', null, $logMsg);
    $_SESSION['oidc_error'] = 'SSO authentication failed. Please try again or contact your administrator.';
    header('Location: login.php');
    exit;
}

// ---- State validation (CSRF guard) ----

$returnedState = to_str($_GET['state'] ?? '');
$savedState    = to_str($_SESSION['oidc_state']    ?? '');
$nonce         = to_str($_SESSION['oidc_nonce']    ?? '');
$verifier      = to_str($_SESSION['oidc_verifier'] ?? '');

// Always clear OIDC session keys regardless of outcome
unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'], $_SESSION['oidc_verifier']);

if ($savedState === '' || !hash_equals($savedState, $returnedState)) {
    oidc_fail($db, 'state mismatch');
}

// ---- IdP error response ----
if (!empty($_GET['error'])) {
    $errorCode = substr(to_str(preg_replace('/[^A-Za-z0-9_.:-]/', '', to_str($_GET['error']))), 0, 64);
    $errorDesc = isset($_GET['error_description'])
        ? ' — ' . substr(trim(to_str($_GET['error_description'])), 0, 200)
        : '';
    oidc_fail($db, "IdP returned error: {$errorCode}{$errorDesc}");
}

$code = to_str($_GET['code'] ?? '');
if ($code === '') oidc_fail($db, 'no authorization code in callback');

// ---- Fetch discovery document ----
try {
    $discovery = oidc_discovery($config);
} catch (Throwable $e) {
    oidc_fail($db, 'discovery: ' . $e->getMessage());
}

// ---- Exchange code for tokens ----
try {
    $tokens = oidc_http_post(to_str($discovery['token_endpoint']), [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => to_str(ipam_setting('oidc.redirect_uri')),
        'client_id'     => to_str(ipam_setting('oidc.client_id')),
        'client_secret' => to_str(ipam_setting('oidc.client_secret')),
        'code_verifier' => $verifier,
    ]);
} catch (Throwable $e) {
    oidc_fail($db, 'token exchange: ' . $e->getMessage());
}

if (empty($tokens['id_token'])) {
    oidc_fail($db, 'no id_token in token response');
}

// ---- Verify ID token (with one JWKS cache-bust retry for key rotation) ----
$expect = [
    'iss'   => to_str($discovery['issuer'] ?? ''),
    'aud'   => to_str(ipam_setting('oidc.client_id')),
    'nonce' => $nonce,
];

try {
    $jwks    = oidc_jwks(to_str($discovery['jwks_uri']));
    $payload = oidc_verify_id_token(to_str($tokens['id_token']), $jwks, $expect);
} catch (Throwable $e) {
    // One retry with a fresh JWKS in case the IdP rotated keys
    try {
        $jwks    = oidc_jwks(to_str($discovery['jwks_uri']), forceRefresh: true);
        $payload = oidc_verify_id_token(to_str($tokens['id_token']), $jwks, $expect);
    } catch (Throwable $e2) {
        oidc_fail($db, 'id_token verification: ' . $e2->getMessage());
    }
}

$sub              = to_str($payload['sub']                ?? '');
$claimEmail       = substr(trim(to_str($payload['email']           ?? '')), 0, 255);
$claimName        = substr(trim(to_str($payload['name']            ?? '')), 0, 255);
$claimPrefUsername = substr(trim(to_str($payload['preferred_username'] ?? '')), 0, 64);
// Sanitise: strip characters not allowed in local usernames (#111)
$claimPrefUsername = preg_replace('/[^a-zA-Z0-9._@\-]/', '', $claimPrefUsername);

if ($sub === '') oidc_fail($db, 'id_token missing sub claim');

// ---- Find or provision local user ----

$st = $db->prepare("SELECT id, username, role, is_active FROM users WHERE oidc_sub = :sub");
$st->execute([':sub' => $sub]);
/** @var array<string, mixed>|false $user */
$user = $st->fetch();

// auto_link: link incoming OIDC login to an existing unlinked local account by username/email.
// auto_provision: create a new account when no match is found. Implies
// auto_link — provisioning runs inside the auto_link block below, so enabling
// auto_provision alone must also flip auto_link on or provisioning never
// fires. This mirrors the documented v2.0.0 behaviour before the rewire.
$autoProvision = (bool)ipam_setting('oidc.auto_provision');
$autoLink      = (bool)ipam_setting('oidc.auto_link') || $autoProvision;

if (!$user && $autoLink) {
    // Try to link an existing local user by preferred_username then by email
    $existing = false;
    if ($claimPrefUsername !== '') {
        $st2 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE username = :u AND oidc_sub IS NULL");
        $st2->execute([':u' => $claimPrefUsername]);
        /** @var array<string, mixed>|false $existing */
        $existing = $st2->fetch();
    }
    if (!$existing && $claimEmail !== '') {
        // Email-only match — do NOT match username here to prevent cross-account linking (#107)
        $st2 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE email = :e AND oidc_sub IS NULL");
        $st2->execute([':e' => $claimEmail]);
        /** @var array<string, mixed>|false $existing */
        $existing = $st2->fetch();
    }

    if ($existing) {
        // Link the existing account to this OIDC subject and sync profile
        $db->prepare("UPDATE users SET oidc_sub = :sub, name = CASE WHEN name='' THEN :n ELSE name END, email = CASE WHEN email='' THEN :e ELSE email END WHERE id = :id")
           ->execute([':sub' => $sub, ':n' => $claimName, ':e' => $claimEmail, ':id' => to_int($existing['id'])]);
        audit($db, 'auth.oidc_link', 'user', to_int($existing['id']), 'sub=' . $sub);
        $user = $existing;
    } elseif ($autoProvision) {
        // Auto-provision a new local user
        $role = to_str(ipam_setting('oidc.default_role'));
        if (!in_array($role, ['admin', 'netops', 'readonly'], true)) $role = 'readonly';

        // Derive a username: prefer preferred_username, fall back to email local-part, then sub
        // Sanitise each candidate the same way as preferred_username (#111)
        $emailLocalPart = $claimEmail !== '' ? preg_replace('/[^a-zA-Z0-9._@\-]/', '', explode('@', $claimEmail)[0]) : '';
        $subSanitised   = preg_replace('/[^a-zA-Z0-9._@\-]/', '', substr($sub, 0, 64));
        $newUsername = $claimPrefUsername !== '' ? $claimPrefUsername
            : ($emailLocalPart !== '' ? $emailLocalPart : ($subSanitised !== '' ? $subSanitised : 'oidcuser'));

        // Set an unusable password hash so the account cannot be used with local auth
        $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        $ins = $db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active, oidc_sub, name, email)
             VALUES (:u, :h, :r, 1, :sub, :n, :e)"
        );
        $baseUsername = $newUsername;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $ins->execute([':u' => $newUsername, ':h' => $unusableHash, ':r' => $role,
                               ':sub' => $sub, ':n' => $claimName, ':e' => $claimEmail]);
                break;
            } catch (PDOException $ex) {
                if ($attempt >= 4) {
                    oidc_fail($db, 'Unable to provision account — username collision after 5 attempts.');
                }
                $newUsername = $baseUsername . '_' . ($attempt + 2);
            }
        }
        $newId = ipam_last_insert_id($db, 'users');
        audit($db, 'auth.oidc_provision', 'user', $newId, 'username=' . $newUsername . ' sub=' . $sub);

        $st3 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE id = :id");
        $st3->execute([':id' => $newId]);
        /** @var array<string, mixed>|false $user */
        $user = $st3->fetch();
    }
}

// For already-linked users: sync name/email from IdP claims if blank
if ($user) {
    if (($claimName !== '' || $claimEmail !== '')) {
        $db->prepare("UPDATE users SET name = CASE WHEN name='' THEN :n ELSE name END, email = CASE WHEN email='' THEN :e ELSE email END WHERE id = :id")
           ->execute([':n' => $claimName, ':e' => $claimEmail, ':id' => to_int($user['id'])]);
    }
}

if (!$user) {
    oidc_fail($db, 'no local user found for sub=' . $sub
        . '. An admin must create or link an account.');
}

if (to_int($user['is_active']) !== 1) {
    oidc_fail($db, 'user account is inactive: ' . to_str($user['username']));
}

// v3.27.0 (#1113) — sudo step-up reauth completion. If the session was in
// the middle of a sudo OIDC reauth flow AND the IdP returned a sub matching
// the currently-logged-in user, mint a sudo grant and redirect to the
// stashed safe return path instead of running the normal login. The match
// is on user id (the existing session's user, looked up from oidc_sub above)
// so a malicious user cannot acquire a grant by signing in to a different
// IdP account during another user's session.
if ($sudoReauthInFlight && is_logged_in()) {
    $current = current_user();
    $currentId = to_int($current['id'] ?? 0);
    if ($currentId > 0 && $currentId === to_int($user['id'])) {
        $return = ipam_sudo_oidc_reauth_complete($db, $currentId);
        header('Location: ' . $return);
        exit;
    }
    // Sub mismatch — clear the in-flight state so it cannot be replayed,
    // and fall through to the normal login flow (which will rebind the
    // session to whichever account the IdP authenticated).
    unset($_SESSION['sudo_oidc_reauth_state'], $_SESSION['sudo_oidc_reauth_return']);
}

// ---- All checks passed — log in ----
login_user(to_int($user['id']), to_str($user['username']), to_str($user['role']));
$db->prepare("UPDATE users SET last_login_at=" . ipam_dialect()->now() . " WHERE id=:id")
   ->execute([':id' => to_int($user['id'])]);
audit($db, 'auth.oidc_login', 'user', to_int($user['id']), 'sub=' . $sub);

header('Location: ' . ipam_post_login_redirect_consume());
exit;
