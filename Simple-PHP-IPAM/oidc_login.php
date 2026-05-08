<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var IpamConfig $config */

// v3.27.0 (#1113) — sudo step-up re-auth flow. ipam_sudo_oidc_reauth_redirect_url()
// stashes a sudo_oidc_reauth_state in the session and bounces the user here
// with ?prompt=login&sudo=<state>. We MUST NOT short-circuit a logged-in user
// to dashboard.php in that case — the whole point of the round-trip is to
// re-prove identity at the IdP and come back with a fresh sudo grant.
$sudoReauthRequested = (to_str($_GET['prompt'] ?? '') === 'login')
    && (to_str($_GET['sudo']   ?? '') !== '')
    && (to_str($_GET['sudo']   ?? '') === to_str($_SESSION['sudo_oidc_reauth_state'] ?? ''));

if (!$sudoReauthRequested && is_logged_in()) { header('Location: dashboard.php'); exit; }
if (!oidc_enabled($config))                  { header('Location: login.php');     exit; }

try {
    $discovery = oidc_discovery($config);
} catch (Throwable $e) {
    error_log('OIDC discovery error: ' . $e->getMessage());
    $_SESSION['oidc_error'] = 'Could not reach the identity provider. Please try again later.';
    header('Location: login.php');
    exit;
}

$pkce  = oidc_pkce_pair();
$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));

$_SESSION['oidc_state']    = $state;
$_SESSION['oidc_nonce']    = $nonce;
$_SESSION['oidc_verifier'] = $pkce['verifier'];

$params = [
    'response_type'         => 'code',
    'client_id'             => to_str(ipam_setting('oidc.client_id')),
    'redirect_uri'          => to_str(ipam_setting('oidc.redirect_uri')),
    'scope'                 => to_str(ipam_setting('oidc.scopes')),
    'state'                 => $state,
    'nonce'                 => $nonce,
    'code_challenge'        => $pkce['challenge'],
    'code_challenge_method' => 'S256',
];
if ($sudoReauthRequested) {
    // Force the IdP to re-prompt for credentials even if the SSO session is
    // still valid — without prompt=login many IdPs would silently re-issue a
    // token off an existing session, defeating the step-up. Plan §3.1 step 3.
    $params['prompt'] = 'login';
}

header('Location: ' . to_str($discovery['authorization_endpoint']) . '?' . http_build_query($params));
exit;
