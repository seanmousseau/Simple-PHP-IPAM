<?php
declare(strict_types=1);

/**
 * Step-up authentication helpers (v3.27.0 #1107).
 *
 * Decouples the "re-prove identity for a sensitive action" gate from the
 * login provider, so OIDC / future LDAP / future SAML users can manage the
 * vault key, sensitive settings, DB import, API key creation, and MFA
 * disable actions without depending on a local password. See
 * docs/superpowers/plans/2026-05-07-v3.27.0.md and
 * docs/internal/step-up-auth.md (proc doc).
 *
 * Resolution order in {@see ipam_sudo_verify()}:
 *   1. Cached grant (refreshes TTL, no audit row).
 *   2. Per-IP rate limit on the 'sudo' bucket.
 *   3. Method permitted by install policy AND enrolled by user.
 *   4. Method-specific verification:
 *        totp        — ipam_totp_verify($secret, $code)
 *        email_otp   — ipam_email_otp_verify($db, $userId, $code)
 *        webauthn    — processGet against $_SESSION['sudo_webauthn_challenge']
 *        password    — password_verify against users.password_hash
 *        oidc_reauth — out-of-band; verified by /oidc/start.php?prompt=login
 *                      return handler, which calls ipam_sudo_grant() directly.
 *   5. On success: ipam_sudo_grant(), audit auth.sudo_passed, clear failures.
 *   6. On failure: audit auth.sudo_failed with stable reason code,
 *      record_auth_failure on the 'sudo' bucket.
 *
 * Session keys owned by this subsystem:
 *   sudo_until_ts                   int   unix epoch when current grant expires
 *   sudo_method                     str   method that minted the current grant
 *   sudo_webauthn_challenge         bin   raw 32B challenge for in-flight assertion
 *   sudo_webauthn_challenge_issued_at int unix epoch the challenge was issued
 *   sudo_oidc_reauth_state          str   nonce used for OIDC prompt=login flow
 *   sudo_oidc_reauth_return         str   safe path to redirect to on OIDC return
 */

const IPAM_SUDO_TTL_ALLOWED       = [0, 60, 300, 900, 1800, 3600];
const IPAM_SUDO_RATE_LIMIT_MAX    = 5;
const IPAM_SUDO_RATE_LIMIT_WINDOW = 900;
const IPAM_SUDO_WEBAUTHN_TTL      = 60;

/**
 * Read the install-wide step-up policy from settings. Defensive defaults
 * match the registry defaults so a corrupted settings table never produces
 * a more permissive policy than the registry intends.
 *
 * @return array{
 *   allow_totp:bool,
 *   allow_email_otp:bool,
 *   allow_webauthn:bool,
 *   allow_provider_reauth:bool,
 *   ttl_seconds:int
 * }
 */
function ipam_sudo_policy(): array
{
    $ttl = to_int(ipam_setting('auth.step_up.ttl_seconds', 300));
    if (!in_array($ttl, IPAM_SUDO_TTL_ALLOWED, true)) {
        $ttl = 300;
    }
    return [
        'allow_totp'            => (bool) to_int(ipam_setting('auth.step_up.allow_totp', true)),
        'allow_email_otp'       => (bool) to_int(ipam_setting('auth.step_up.allow_email_otp', true)),
        'allow_webauthn'        => (bool) to_int(ipam_setting('auth.step_up.allow_webauthn', true)),
        'allow_provider_reauth' => (bool) to_int(ipam_setting('auth.step_up.allow_provider_reauth', true)),
        'ttl_seconds'           => $ttl,
    ];
}

/**
 * True iff the current session has an unexpired sudo grant.
 *
 * Two grant shapes:
 *   - timed grant — `sudo_until_ts` is a future unix timestamp.
 *   - one-shot grant — `sudo_once` is true; consumed by ipam_sudo_consume_once()
 *     which the calling handler invokes after the gated action completes.
 *     Used for the `ttl_seconds=0` ("re-prompt every action") policy.
 */
function ipam_sudo_active(): bool
{
    if (!empty($_SESSION['sudo_once'])) return true;
    return to_int($_SESSION['sudo_until_ts'] ?? 0) > time();
}

/**
 * Consume a one-shot sudo grant. No-op for timed grants. Sensitive handlers
 * call this immediately after the gated action runs successfully so the next
 * sensitive action re-prompts.
 */
function ipam_sudo_consume_once(): void
{
    unset($_SESSION['sudo_once']);
}

/**
 * Clear any cached sudo grant. Call from logout, password change, role
 * change, MFA enrollment change, oidc_sub change, and step-up policy save.
 */
function ipam_sudo_invalidate(): void
{
    unset(
        $_SESSION['sudo_until_ts'],
        $_SESSION['sudo_once'],
        $_SESSION['sudo_method'],
        $_SESSION['sudo_webauthn_challenge'],
        $_SESSION['sudo_webauthn_challenge_issued_at'],
        // In-flight OIDC re-auth state must die with the grant: any nonce or
        // return-path waiting on a callback could otherwise complete a
        // step-up after a logout / password-change / role-downgrade /
        // policy-tightening event invalidated the session. (CodeRabbit
        // #1116.)
        $_SESSION['sudo_oidc_reauth_state'],
        $_SESSION['sudo_oidc_reauth_return']
    );
}

/**
 * Issue a fresh sudo grant scoped to the current policy TTL.
 *
 * Two shapes:
 *   - ttl > 0 → timed grant via `sudo_until_ts = time() + ttl`. Subsequent
 *     sensitive actions short-circuit until the timestamp passes.
 *   - ttl = 0 → "re-prompt every action" via a one-shot `sudo_once` flag.
 *     The action we just authorised can complete (sensitive handlers call
 *     ipam_sudo_consume_once() after the action runs); the next sensitive
 *     action re-prompts. Avoids the previous time()+1 window which could
 *     be reused for any number of sensitive actions within the same second
 *     (CodeRabbit #1116).
 */
function ipam_sudo_grant(string $method): void
{
    $ttl = ipam_sudo_policy()['ttl_seconds'];
    if ($ttl > 0) {
        $_SESSION['sudo_until_ts'] = time() + $ttl;
        unset($_SESSION['sudo_once']);
    } else {
        $_SESSION['sudo_once'] = true;
        unset($_SESSION['sudo_until_ts']);
    }
    $_SESSION['sudo_method'] = $method;
}

/**
 * Methods this user could pass under the current policy. Used by:
 *   - the step-up prompt UI (which form to render by default)
 *   - the lock-out precondition guard on policy save
 *
 * Method strings: 'totp' | 'email_otp' | 'webauthn' | 'password' | 'oidc_reauth'
 *
 * @param array{
 *   allow_totp:bool,
 *   allow_email_otp:bool,
 *   allow_webauthn:bool,
 *   allow_provider_reauth:bool,
 *   ttl_seconds:int
 * }|null $policyOverride  Pass a proposed policy to evaluate availability under
 *                         a hypothetical save (used by the lock-out precondition).
 *                         Null reads the live policy via ipam_sudo_policy().
 * @return list<string>
 */
function ipam_sudo_available_methods(\PDO $db, int $userId, ?array $policyOverride = null): array
{
    if ($userId <= 0) return [];
    $policy    = $policyOverride ?? ipam_sudo_policy();
    $available = [];

    $enrolledMfa = ipam_user_available_mfa_methods($db, $userId);

    if ($policy['allow_totp'] && in_array('totp', $enrolledMfa, true)) {
        $available[] = 'totp';
    }
    if ($policy['allow_email_otp'] && in_array('email_otp', $enrolledMfa, true)) {
        $available[] = 'email_otp';
    }
    if ($policy['allow_webauthn'] && in_array('passkey', $enrolledMfa, true)) {
        $available[] = 'webauthn';
    }
    if ($policy['allow_provider_reauth']) {
        $st = $db->prepare("SELECT password_hash, oidc_sub FROM users WHERE id = :id AND is_active = 1");
        $st->execute([':id' => $userId]);
        $row = $st->fetch();
        if (is_array($row)) {
            $hash = to_str($row['password_hash'] ?? '');
            if ($hash !== '' && !str_starts_with($hash, '!')) {
                $available[] = 'password';
            }
            $oidcSub = $row['oidc_sub'] ?? null;
            if (is_string($oidcSub) && $oidcSub !== '') {
                $available[] = 'oidc_reauth';
            }
        }
    }
    return $available;
}

/**
 * Verify a step-up proof and issue a session sudo grant on success.
 *
 * @param array<string, mixed> $proof  Method-specific payload. Expected shape:
 *   - {method: 'totp',        code: string}
 *   - {method: 'email_otp',   code: string}
 *   - {method: 'webauthn',    client_data_json: string, authenticator_data: string,
 *                             signature: string, credential_id: string} (all base64url)
 *   - {method: 'password',    password: string}
 *   - {method: 'oidc_reauth'} (always returns false; caller must redirect)
 */
function ipam_sudo_verify(\PDO $db, int $userId, array $proof, string $clientIp = ''): bool
{
    if ($userId <= 0) return false;
    if ($clientIp === '') $clientIp = client_ip();

    if (ipam_sudo_active()) {
        $cachedMethod = to_str($_SESSION['sudo_method'] ?? 'cached');
        ipam_sudo_grant($cachedMethod);
        return true;
    }

    if (auth_rate_limited($db, 'sudo', $clientIp, IPAM_SUDO_RATE_LIMIT_MAX, IPAM_SUDO_RATE_LIMIT_WINDOW)) {
        audit($db, 'auth.sudo_rate_limited', 'auth', null, "ip=$clientIp user_id=$userId");
        return false;
    }

    $method = to_str($proof['method'] ?? '');

    $st = $db->prepare("SELECT username, password_hash, totp_secret_enc, totp_enabled, oidc_sub FROM users WHERE id = :id AND is_active = 1");
    $st->execute([':id' => $userId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        audit($db, 'auth.sudo_failed', 'auth', null, "method=$method ip=$clientIp reason=user_not_active");
        record_auth_failure($db, 'sudo', $clientIp, '');
        return false;
    }
    $username  = to_str($row['username'] ?? '');
    $available = ipam_sudo_available_methods($db, $userId);

    $ok     = false;
    $reason = '';

    if (!in_array($method, $available, true)) {
        $reason = 'method_unavailable';
    } else {
        switch ($method) {
            case 'totp':
                // The TOTP secret is stored encrypted at rest (totp_secret_enc)
                // and decrypted with $config['app_secret']. Same shape as
                // totp_verify.php:128 — keep these two call sites in sync.
                $code      = (string) preg_replace('/\D/', '', to_str($proof['code'] ?? ''));
                $cfg       = $GLOBALS['config'] ?? null;
                $appSecret = is_array($cfg) ? to_str($cfg['app_secret'] ?? '') : '';
                $plain     = ($appSecret !== '')
                    ? ipam_totp_decrypt_secret(to_str($row['totp_secret_enc'] ?? ''), $appSecret)
                    : '';
                $ok = ($code !== ''
                    && $plain !== ''
                    && to_int($row['totp_enabled'] ?? 0) === 1
                    && ipam_totp_verify($plain, $code));
                if (!$ok) $reason = 'totp_invalid';
                break;

            case 'email_otp':
                $code = (string) preg_replace('/\D/', '', to_str($proof['code'] ?? ''));
                $ok = ($code !== '' && ipam_email_otp_verify($db, $userId, $code));
                if (!$ok) $reason = 'email_otp_invalid';
                break;

            case 'webauthn':
                $reason = '';
                $ok = ipam_sudo_verify_webauthn($db, $userId, $proof, $reason);
                break;

            case 'password':
                $supplied = to_str($proof['password'] ?? '');
                $hash     = to_str($row['password_hash'] ?? '');
                $ok = ($supplied !== ''
                    && $hash !== ''
                    && !str_starts_with($hash, '!')
                    && password_verify($supplied, $hash));
                if (!$ok) $reason = 'password_invalid';
                break;

            case 'oidc_reauth':
                // OIDC re-auth is verified out-of-band. The caller is
                // expected to call ipam_sudo_oidc_reauth_redirect() to
                // bounce the user through /oidc/start.php?prompt=login;
                // the OIDC return handler calls ipam_sudo_grant() on a
                // successful re-authentication. Reaching this branch
                // means the caller mis-wired the flow.
                $reason = 'oidc_reauth_redirect_required';
                break;

            default:
                $reason = 'unknown_method';
        }
    }

    if ($ok) {
        ipam_sudo_grant($method);
        audit($db, 'auth.sudo_passed', 'auth', null, "method=$method ip=$clientIp user=$username");
        clear_auth_failures($db, 'sudo', $clientIp);
        return true;
    }

    audit($db, 'auth.sudo_failed', 'auth', null, "method=$method ip=$clientIp user=$username reason=$reason");
    record_auth_failure($db, 'sudo', $clientIp, $username);
    return false;
}

/**
 * Issue a fresh WebAuthn challenge for step-up assertion. Stores the raw
 * binary challenge in the session with a 60-second TTL. Returns the JSON
 * assertion options the page's JS handler will pass to
 * navigator.credentials.get().
 *
 * Mirrors the login passkey flow in {@see ipam_passkey_dispatch_challenge()}
 * but uses sudo_webauthn_challenge* session keys so a step-up flow does
 * not collide with an in-flight login.
 *
 * @return array{ok:true, options:string}|array{ok:false, error:string}
 */
function ipam_sudo_issue_webauthn_challenge(\PDO $db, int $userId): array
{
    if (!ipam_passkey_has_credentials($db, $userId)) {
        return ['ok' => false, 'error' => 'no_passkey'];
    }
    $creds = ipam_passkey_get_credentials($db, $userId);
    /** @var list<\lbuchs\WebAuthn\Binary\ByteBuffer> $credentialIds */
    $credentialIds = [];
    foreach ($creds as $c) {
        $cidBin = to_str($c['credential_id'] ?? '');
        if ($cidBin !== '') {
            $credentialIds[] = new \lbuchs\WebAuthn\Binary\ByteBuffer($cidBin);
        }
    }
    if ($credentialIds === []) {
        return ['ok' => false, 'error' => 'no_passkey'];
    }
    $webAuthn  = ipam_passkey_webauthn();
    $assertArgs = $webAuthn->getGetArgs($credentialIds, IPAM_SUDO_WEBAUTHN_TTL);
    $challengeBin = $webAuthn->getChallenge()->getBinaryString();

    // Re-encode binary fields to base64url so they round-trip through
    // JSON.stringify / navigator.credentials.get() without corruption.
    $pk = $assertArgs->publicKey;
    $pk->challenge = rtrim(strtr(base64_encode($challengeBin), '+/', '-_'), '=');
    if (!empty($pk->allowCredentials)) {
        foreach ($pk->allowCredentials as &$ac) {
            if (isset($ac->id) && ($ac->id instanceof \lbuchs\WebAuthn\Binary\ByteBuffer)) {
                $ac->id = rtrim(strtr(base64_encode($ac->id->getBinaryString()), '+/', '-_'), '=');
            }
        }
        unset($ac);
    }

    $_SESSION['sudo_webauthn_challenge']           = $challengeBin;
    $_SESSION['sudo_webauthn_challenge_issued_at'] = time();

    $optionsJson = json_encode($pk);
    if (!is_string($optionsJson)) {
        return ['ok' => false, 'error' => 'json_encode_failed'];
    }
    return ['ok' => true, 'options' => $optionsJson];
}

/**
 * Verify a WebAuthn assertion as a step-up proof. Consumes the session
 * challenge unconditionally on call (single-use regardless of outcome).
 *
 * @param array<string, mixed> $proof  Expects client_data_json, authenticator_data,
 *                                     signature, credential_id (all base64url).
 * @param string $reason  Out-parameter set to a stable failure code on false.
 */
function ipam_sudo_verify_webauthn(\PDO $db, int $userId, array $proof, string &$reason): bool
{
    $challengeBin = to_str($_SESSION['sudo_webauthn_challenge'] ?? '');
    $issuedAt     = to_int($_SESSION['sudo_webauthn_challenge_issued_at'] ?? 0);
    unset(
        $_SESSION['sudo_webauthn_challenge'],
        $_SESSION['sudo_webauthn_challenge_issued_at']
    );

    if ($challengeBin === '' || $issuedAt < (time() - IPAM_SUDO_WEBAUTHN_TTL)) {
        $reason = 'webauthn_no_challenge';
        return false;
    }

    $cdJSON   = base64_decode(strtr(to_str($proof['client_data_json']    ?? ''), '-_', '+/'), true);
    $authData = base64_decode(strtr(to_str($proof['authenticator_data'] ?? ''), '-_', '+/'), true);
    $sig      = base64_decode(strtr(to_str($proof['signature']           ?? ''), '-_', '+/'), true);
    $credIdRaw = to_str($proof['credential_id'] ?? '');
    if (!is_string($cdJSON) || !is_string($authData) || !is_string($sig)
        || $cdJSON === '' || $authData === '' || $sig === '' || $credIdRaw === '') {
        $reason = 'webauthn_malformed';
        return false;
    }
    $credIdBin = base64_decode(strtr($credIdRaw, '-_', '+/'), true);
    if (!is_string($credIdBin) || $credIdBin === '') {
        $reason = 'webauthn_malformed';
        return false;
    }

    $cred = ipam_passkey_find_by_credential_id($db, $credIdBin);
    if (!$cred || to_int($cred['user_id']) !== $userId) {
        $reason = 'webauthn_unknown_credential';
        return false;
    }
    try {
        $webAuthn  = ipam_passkey_webauthn();
        $challenge = new \lbuchs\WebAuthn\Binary\ByteBuffer($challengeBin);
        $webAuthn->processGet(
            $cdJSON,
            $authData,
            $sig,
            to_str($cred['public_key']),
            $challenge,
            to_int($cred['sign_count']),
            false,
            true
        );
        $newSignCount = $webAuthn->getSignatureCounter() ?? to_int($cred['sign_count']);
        ipam_passkey_update_sign_count($db, to_int($cred['id']), $newSignCount);
        return true;
    } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
        $reason = 'webauthn_invalid';
        return false;
    }
}

/**
 * Build the redirect URL that triggers an OIDC re-authentication for sudo.
 * Uses the same /oidc/start.php entry point as initial login, but adds
 * prompt=login and a sudo-specific return marker so the OIDC return
 * handler can mint a sudo grant rather than a fresh login session.
 *
 * @param string $returnPath  Path (no scheme/host) to send the user back to
 *                            after a successful re-auth. Validated via
 *                            ipam_safe_redirect_path() to refuse open-redirect
 *                            payloads.
 * @return string  Absolute path to redirect to, or '' if OIDC is not configured.
 */
function ipam_sudo_oidc_reauth_redirect_url(string $returnPath): string
{
    // Body of oidc_enabled() reads through ipam_setting(); short-circuit
    // here on the same predicate without forcing callers to thread the
    // strictly-shaped $config array.
    $oidcConfigured = (bool) ipam_setting('oidc.enabled')
        && to_str(ipam_setting('oidc.client_id'))     !== ''
        && to_str(ipam_setting('oidc.client_secret')) !== ''
        && to_str(ipam_setting('oidc.discovery_url')) !== ''
        && to_str(ipam_setting('oidc.redirect_uri'))  !== '';
    if (!$oidcConfigured) {
        return '';
    }

    // Same validation as ipam_post_login_redirect_stash() — must be a
    // server-relative path that cannot escape the install. Falls back to
    // /destinations.php (the most common sudo entry point) on bad input.
    $safe = '/destinations.php';
    if ($returnPath !== ''
        && $returnPath[0] === '/'
        && !str_starts_with($returnPath, '//')
        && !preg_match('/[\r\n]/', $returnPath)
        && !str_contains($returnPath, '..')
        && !str_contains($returnPath, '\\')
        && strlen($returnPath) <= 1024) {
        $safe = $returnPath;
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['sudo_oidc_reauth_state']  = $state;
    $_SESSION['sudo_oidc_reauth_return'] = $safe;

    return 'oidc_login.php?prompt=login&sudo=' . rawurlencode($state);
}

/**
 * Called by the OIDC return handler when an OIDC authentication response
 * has been validated as a sudo re-auth (state matches sudo_oidc_reauth_state).
 * Mints a sudo grant and clears the in-flight state. Returns the safe
 * return path the caller should redirect to.
 *
 * @return string  Safe path to redirect to, or '/destinations.php' if state was lost.
 */
function ipam_sudo_oidc_reauth_complete(\PDO $db, int $userId, string $clientIp = ''): string
{
    if ($clientIp === '') $clientIp = client_ip();
    $return = to_str($_SESSION['sudo_oidc_reauth_return'] ?? '/destinations.php');
    unset($_SESSION['sudo_oidc_reauth_state'], $_SESSION['sudo_oidc_reauth_return']);

    ipam_sudo_grant('oidc_reauth');
    $st = $db->prepare("SELECT username FROM users WHERE id = :id");
    $st->execute([':id' => $userId]);
    $username = to_str((string) $st->fetchColumn());
    audit($db, 'auth.sudo_passed', 'auth', null, "method=oidc_reauth ip=$clientIp user=$username");
    clear_auth_failures($db, 'sudo', $clientIp);

    return $return;
}

/**
 * Decode the POST body produced by views/_step_up_prompt.php into the
 * $proof array shape expected by ipam_sudo_verify(). Returns null when no
 * step-up submission is present (caller should render the prompt) and a
 * partial array (just ['method' => ...]) when the user clicked "Send code"
 * or chose the OIDC re-auth method (caller intercepts before verify).
 *
 * @return array<string, string>|null
 */
function ipam_sudo_proof_from_post(): ?array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return null;
    $method = to_str($_POST['_sudo_method'] ?? '');
    if ($method === '') return null;

    switch ($method) {
        case 'totp':
        case 'email_otp':
            return [
                'method' => $method,
                'code'   => to_str($_POST['_sudo_code'] ?? ''),
            ];
        case 'password':
            return [
                'method'   => 'password',
                'password' => to_str($_POST['_sudo_password'] ?? ''),
            ];
        case 'webauthn':
            return [
                'method'             => 'webauthn',
                'client_data_json'   => to_str($_POST['_sudo_client_data_json']   ?? ''),
                'authenticator_data' => to_str($_POST['_sudo_authenticator_data'] ?? ''),
                'signature'          => to_str($_POST['_sudo_signature']          ?? ''),
                'credential_id'      => to_str($_POST['_sudo_credential_id']      ?? ''),
            ];
        case 'oidc_reauth':
            return ['method' => 'oidc_reauth'];
        default:
            return null;
    }
}

/**
 * Generate + send an Email OTP for the given user as part of a step-up
 * flow. Returns true on success (caller re-renders the prompt with a
 * "code sent" notice), false on failure (caller surfaces an error).
 * Does NOT mint a sudo grant — the user still must submit the code,
 * which goes through ipam_sudo_verify() like any other proof.
 */
function ipam_sudo_dispatch_email_otp_send(\PDO $db, int $userId): bool
{
    if ($userId <= 0) return false;
    $code = ipam_email_otp_generate($db, $userId);
    return ipam_email_otp_send($db, $userId, $code);
}

/**
 * Convenience wrapper for handlers: returns true if the session already
 * has an unexpired sudo grant OR the current POST contains a valid step-up
 * proof. On a successful proof the grant is minted as a side effect via
 * ipam_sudo_verify(). Returns false when the caller should render the
 * step-up prompt.
 */
function ipam_sudo_require(\PDO $db, int $userId): bool
{
    if (ipam_sudo_active()) return true;
    $proof = ipam_sudo_proof_from_post();
    if ($proof === null) return false;
    return ipam_sudo_verify($db, $userId, $proof);
}

/**
 * Compose a proposed step-up policy by overlaying a pending settings save
 * (keyed by the registry's full setting names) on top of the live policy.
 * Keys not present in $overrides keep their current value. Returned shape
 * matches ipam_sudo_policy().
 *
 * @param array<string, mixed> $overrides
 * @return array{
 *   allow_totp:bool,
 *   allow_email_otp:bool,
 *   allow_webauthn:bool,
 *   allow_provider_reauth:bool,
 *   ttl_seconds:int
 * }
 */
function ipam_sudo_proposed_policy_from_overrides(array $overrides): array
{
    $p = ipam_sudo_policy();
    $boolMap = [
        'auth.step_up.allow_totp'            => 'allow_totp',
        'auth.step_up.allow_email_otp'       => 'allow_email_otp',
        'auth.step_up.allow_webauthn'        => 'allow_webauthn',
        'auth.step_up.allow_provider_reauth' => 'allow_provider_reauth',
    ];
    foreach ($boolMap as $regKey => $short) {
        if (array_key_exists($regKey, $overrides)) {
            $p[$short] = (bool) $overrides[$regKey];
        }
    }
    if (array_key_exists('auth.step_up.ttl_seconds', $overrides)) {
        $ttl = to_int($overrides['auth.step_up.ttl_seconds']);
        if (in_array($ttl, IPAM_SUDO_TTL_ALLOWED, true)) {
            $p['ttl_seconds'] = $ttl;
        }
    }
    return $p;
}

/**
 * Lock-out precondition for a proposed step-up policy save. Iterates every
 * active admin and returns the username of the first one who would have NO
 * available step-up methods under the proposed policy. Returns '' when every
 * active admin can still satisfy the gate via at least one method.
 *
 * The same shape as the last-active-admin guard in users.php — refuses to
 * let the operator save a configuration that would lock the system out of
 * its own sensitive admin actions.
 *
 * @param array{
 *   allow_totp:bool,
 *   allow_email_otp:bool,
 *   allow_webauthn:bool,
 *   allow_provider_reauth:bool,
 *   ttl_seconds:int
 * } $proposed
 */
function ipam_sudo_policy_lockout_check(\PDO $db, array $proposed): string
{
    $st = $db->query("SELECT id, username FROM users WHERE is_active = 1 AND role = 'admin'");
    if ($st === false) return '';
    $rows = $st->fetchAll();
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $uid      = to_int($row['id'] ?? 0);
        $username = to_str($row['username'] ?? '');
        if ($uid <= 0) continue;
        if (ipam_sudo_available_methods($db, $uid, $proposed) === []) {
            return $username !== '' ? $username : ('user#' . $uid);
        }
    }
    return '';
}
