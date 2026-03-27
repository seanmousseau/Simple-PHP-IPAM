<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

if (is_logged_in())      { header('Location: dashboard.php'); exit; }
if (!oidc_enabled($config)) { header('Location: login.php');     exit; }

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
    'client_id'             => (string)$config['oidc']['client_id'],
    'redirect_uri'          => (string)$config['oidc']['redirect_uri'],
    'scope'                 => (string)($config['oidc']['scopes'] ?? 'openid email profile'),
    'state'                 => $state,
    'nonce'                 => $nonce,
    'code_challenge'        => $pkce['challenge'],
    'code_challenge_method' => 'S256',
];

header('Location: ' . $discovery['authorization_endpoint'] . '?' . http_build_query($params));
exit;
