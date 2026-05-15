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

// v3.29.0 #1099: claim normalisation extracted to oidc_extract_claims().
$claims = oidc_extract_claims($payload);
$sub               = $claims['sub'];
$claimEmail        = $claims['email'];
$claimName         = $claims['name'];
$claimPrefUsername = $claims['preferred_username'];

if ($sub === '') oidc_fail($db, 'id_token missing sub claim');

// ---- Find or provision local user ----
//
// v3.29.0 #1099: resolve logic extracted to oidc_resolve_user(). It does
// the same three-step chain (current-by-sub → unlinked-by-username →
// unlinked-by-email) the inline code did pre-refactor. Returns null if
// nothing matches — auto-provision (when enabled) handles that branch.

$resolved = oidc_resolve_user($db, $sub, $claimEmail, $claimPrefUsername);

// auto_link: link incoming OIDC login to an existing unlinked local account by username/email.
// auto_provision: create a new account when no match is found. Implies
// auto_link — provisioning runs inside the auto_link block below, so enabling
// auto_provision alone must also flip auto_link on or provisioning never
// fires. This mirrors the documented v2.0.0 behaviour before the rewire.
$autoProvision = (bool)ipam_setting('oidc.auto_provision');
$autoLink      = (bool)ipam_setting('oidc.auto_link') || $autoProvision;

// Determine whether $resolved represents an already-linked user (came
// from the WHERE oidc_sub = :sub branch) or an unlinked candidate the
// resolver returned for auto-link. Re-query by sub to disambiguate — a
// row whose oidc_sub already equals $sub is the steady-state path;
// anything else is an unlinked candidate.
/** @var array<string, mixed>|false $user */
$user = false;
if ($resolved !== null) {
    $checkSub = $db->prepare("SELECT 1 FROM users WHERE id = :id AND oidc_sub = :sub");
    $checkSub->execute([':id' => to_int($resolved['id']), ':sub' => $sub]);
    if ($checkSub->fetchColumn()) {
        $user = $resolved;
    } elseif ($autoLink) {
        // Link the unlinked candidate to this OIDC subject and sync profile.
        $db->prepare("UPDATE users SET oidc_sub = :sub, name = CASE WHEN name='' THEN :n ELSE name END, email = CASE WHEN email='' THEN :e ELSE email END WHERE id = :id")
           ->execute([':sub' => $sub, ':n' => $claimName, ':e' => $claimEmail, ':id' => to_int($resolved['id'])]);
        audit($db, 'auth.oidc_link', 'user', to_int($resolved['id']), 'sub=' . $sub);
        $user = $resolved;
    }
    // If we found an unlinked candidate but auto_link is off, treat as "no match".
}

if (!$user && $autoProvision) {
    // v3.29.0 #1099: provisioning extracted to oidc_provision_user().
    $role = to_str(ipam_setting('oidc.default_role'));
    try {
        $provisioned = oidc_provision_user($db, $claims, $role);
    } catch (RuntimeException $e) {
        oidc_fail($db, 'Unable to provision account — ' . $e->getMessage());
    }
    audit($db, 'auth.oidc_provision', 'user', $provisioned['id'], 'username=' . $provisioned['username'] . ' sub=' . $sub);

    $st3 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE id = :id");
    $st3->execute([':id' => $provisioned['id']]);
    /** @var array<string, mixed>|false $user */
    $user = $st3->fetch();
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
