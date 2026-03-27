<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

if (is_logged_in())         { header('Location: dashboard.php'); exit; }
if (!oidc_enabled($config)) { header('Location: login.php');     exit; }

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

$returnedState = (string)($_GET['state'] ?? '');
$savedState    = (string)($_SESSION['oidc_state']    ?? '');
$nonce         = (string)($_SESSION['oidc_nonce']    ?? '');
$verifier      = (string)($_SESSION['oidc_verifier'] ?? '');

// Always clear OIDC session keys regardless of outcome
unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'], $_SESSION['oidc_verifier']);

if ($savedState === '' || !hash_equals($savedState, $returnedState)) {
    oidc_fail($db, 'state mismatch');
}

// ---- IdP error response ----
if (!empty($_GET['error'])) {
    oidc_fail($db, 'IdP returned error: ' . $_GET['error']
        . (isset($_GET['error_description']) ? ' — ' . $_GET['error_description'] : ''));
}

$code = (string)($_GET['code'] ?? '');
if ($code === '') oidc_fail($db, 'no authorization code in callback');

// ---- Fetch discovery document ----
try {
    $discovery = oidc_discovery($config);
} catch (Throwable $e) {
    oidc_fail($db, 'discovery: ' . $e->getMessage());
}

// ---- Exchange code for tokens ----
try {
    $tokens = oidc_http_post($discovery['token_endpoint'], [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => (string)$config['oidc']['redirect_uri'],
        'client_id'     => (string)$config['oidc']['client_id'],
        'client_secret' => (string)$config['oidc']['client_secret'],
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
    'iss'   => (string)($discovery['issuer'] ?? ''),
    'aud'   => (string)$config['oidc']['client_id'],
    'nonce' => $nonce,
];

try {
    $jwks    = oidc_jwks((string)$discovery['jwks_uri']);
    $payload = oidc_verify_id_token((string)$tokens['id_token'], $jwks, $expect);
} catch (Throwable $e) {
    // One retry with a fresh JWKS in case the IdP rotated keys
    try {
        $jwks    = oidc_jwks((string)$discovery['jwks_uri'], forceRefresh: true);
        $payload = oidc_verify_id_token((string)$tokens['id_token'], $jwks, $expect);
    } catch (Throwable $e2) {
        oidc_fail($db, 'id_token verification: ' . $e2->getMessage());
    }
}

$sub              = (string)($payload['sub']                ?? '');
$claimEmail       = trim((string)($payload['email']           ?? ''));
$claimName        = trim((string)($payload['name']            ?? ''));
$claimPrefUsername = trim((string)($payload['preferred_username'] ?? ''));

if ($sub === '') oidc_fail($db, 'id_token missing sub claim');

// ---- Find or provision local user ----

$st = $db->prepare("SELECT id, username, role, is_active FROM users WHERE oidc_sub = :sub");
$st->execute([':sub' => $sub]);
$user = $st->fetch();

if (!$user && !empty($config['oidc']['auto_provision'])) {
    // Try to link an existing local user by preferred_username then by email
    $existing = false;
    if ($claimPrefUsername !== '') {
        $st2 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE username = :u AND oidc_sub IS NULL");
        $st2->execute([':u' => $claimPrefUsername]);
        $existing = $st2->fetch();
    }
    if (!$existing && $claimEmail !== '') {
        $st2 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE (username = :u OR email = :e) AND oidc_sub IS NULL");
        $st2->execute([':u' => $claimEmail, ':e' => $claimEmail]);
        $existing = $st2->fetch();
    }

    if ($existing) {
        // Link the existing account to this OIDC subject and sync profile
        $db->prepare("UPDATE users SET oidc_sub = :sub, name = CASE WHEN name='' THEN :n ELSE name END, email = CASE WHEN email='' THEN :e ELSE email END WHERE id = :id")
           ->execute([':sub' => $sub, ':n' => $claimName, ':e' => $claimEmail, ':id' => (int)$existing['id']]);
        audit($db, 'auth.oidc_link', 'user', (int)$existing['id'], 'sub=' . $sub);
        $user = $existing;
    } else {
        // Auto-provision a new local user
        $role = (string)($config['oidc']['default_role'] ?? 'readonly');
        if (!in_array($role, ['admin', 'readonly'], true)) $role = 'readonly';

        // Derive a username: prefer preferred_username, fall back to email local-part, then sub
        $newUsername = $claimPrefUsername !== '' ? $claimPrefUsername
            : ($claimEmail !== '' ? explode('@', $claimEmail)[0] : $sub);

        // Set an unusable password hash so the account cannot be used with local auth
        $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

        try {
            $ins = $db->prepare(
                "INSERT INTO users (username, password_hash, role, is_active, oidc_sub, name, email)
                 VALUES (:u, :h, :r, 1, :sub, :n, :e)"
            );
            $ins->execute([':u' => $newUsername, ':h' => $unusableHash, ':r' => $role,
                           ':sub' => $sub, ':n' => $claimName, ':e' => $claimEmail]);
        } catch (PDOException $ex) {
            // username collision — append a short random suffix
            $newUsername .= '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $ins->execute([':u' => $newUsername, ':h' => $unusableHash, ':r' => $role,
                           ':sub' => $sub, ':n' => $claimName, ':e' => $claimEmail]);
        }
        $newId = (int)$db->lastInsertId();
        audit($db, 'auth.oidc_provision', 'user', $newId, 'username=' . $newUsername . ' sub=' . $sub);

        $st3 = $db->prepare("SELECT id, username, role, is_active FROM users WHERE id = :id");
        $st3->execute([':id' => $newId]);
        $user = $st3->fetch();
    }
}

// For already-linked users: sync name/email from IdP claims if blank
if ($user) {
    if (($claimName !== '' || $claimEmail !== '')) {
        $db->prepare("UPDATE users SET name = CASE WHEN name='' THEN :n ELSE name END, email = CASE WHEN email='' THEN :e ELSE email END WHERE id = :id")
           ->execute([':n' => $claimName, ':e' => $claimEmail, ':id' => (int)$user['id']]);
    }
}

if (!$user) {
    oidc_fail($db, 'no local user found for sub=' . $sub
        . '. An admin must create or link an account.');
}

if ((int)$user['is_active'] !== 1) {
    oidc_fail($db, 'user account is inactive: ' . $user['username']);
}

// ---- All checks passed — log in ----
login_user((int)$user['id'], (string)$user['username'], (string)$user['role']);
audit($db, 'auth.oidc_login', 'user', (int)$user['id'], 'sub=' . $sub);

header('Location: dashboard.php');
exit;
