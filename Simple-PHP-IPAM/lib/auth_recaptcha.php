<?php
declare(strict_types=1);

/**
 * @module auth_recaptcha
 *
 * reCAPTCHA Enterprise verification and login-form protection helpers
 * extracted from lib.php in v3.30.0 (ADR-004 Phase 6 Task 6.4, sub of
 * #907). Functions stay in the global namespace per ADR-004 Option E.
 *
 * Responsibilities:
 *  - reCAPTCHA Enterprise Assessment API verification, fail-open on
 *    network errors or misconfiguration (recaptcha_enterprise_verify).
 *  - Resolving the reCAPTCHA v3 expected-action name, honouring the
 *    legacy top-level $config['recaptcha_action'] key before the
 *    registry key recaptcha_enterprise.expected_action
 *    (recaptcha_expected_action_resolved).
 *  - Login-form bot protection for the login/demo-gate forms across all
 *    supported methods — honeypot, time_check, Turnstile, hCaptcha,
 *    reCAPTCHA (standard + Enterprise) and Friendly Captcha: the POST
 *    verifier (login_protection_verify), the widget HTML snippet
 *    (login_protection_widget_html), and the extra CSP directives the
 *    active method needs (login_protection_extra_csp).
 *
 * Inclusion rule: functions whose primary job is rendering or verifying a
 * login-form anti-bot challenge. Deliberately NOT moved here: core
 * session/CSRF/login (lib/auth.php), password policy/reset
 * (lib/auth_password.php), and login/IP rate limiting + account lockout
 * (lib/auth_rate_limit.php) — those stay in their own modules.
 *
 * ADR-003: recaptcha_expected_action_resolved() reads the legacy
 * top-level config key via ipam_config('recaptcha_action') instead of
 * $GLOBALS['config']. The remaining helpers take their config as a
 * caller-passed `array $config` / `array $cfg` parameter (demo_gate
 * passes a stub, login.php passes an empty array and lets the helpers
 * fall back to ipam_setting()), so no `global $config` reads exist and no
 * signatures change. The `global $db` handle is never accessed here.
 *
 * Dependencies: lib.php (oidc_http_post — used by the
 * Turnstile/hCaptcha/reCAPTCHA/Friendly form-encoded verify paths;
 * ipam_http_post_json — used by the reCAPTCHA Enterprise JSON verify
 * path), lib/auth.php
 * (client_ip — used by the same verify paths), lib/utils.php
 * (to_int / to_str / e), lib/settings.php (ipam_setting() — fallback
 * config source) and lib/config.php (ipam_config() — legacy action key).
 * All cross-module helpers resolve lazily at call time, never at include
 * time — this module has no side-effects on load.
 */

/**
 * Verify a reCAPTCHA token against the Enterprise Assessment API.
 * Returns null on pass, error string on fail, null on network error (fail-open).
 *
 * @param array{enabled: bool, project_id: string, api_key: string, expected_action: string, score_threshold: float} $cfg
 */
function recaptcha_enterprise_verify(string $token, string $siteKey, array $cfg): ?string
{
    $projectId      = to_str($cfg['project_id']);
    $apiKey         = to_str($cfg['api_key']);
    $expectedAction = to_str($cfg['expected_action']);
    $threshold      = (float)$cfg['score_threshold'];

    if ($projectId === '' || $apiKey === '') {
        error_log('reCAPTCHA Enterprise: project_id and api_key must be configured.');
        return null; // fail open — misconfiguration should not block users
    }

    $url  = 'https://recaptchaenterprise.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/assessments?key=' . rawurlencode($apiKey);
    $body = ['event' => ['token' => $token, 'expectedAction' => $expectedAction, 'siteKey' => $siteKey]];

    try {
        // Shared JSON POST helper (lib.php): TLS peer verification ON, 10s
        // timeout. Never throws — transport failures arrive via 'error'.
        $http = ipam_http_post_json($url, $body);
        if ($http['error'] !== null) {
            error_log('reCAPTCHA Enterprise: ' . $http['error'] . '.');
            return null; // fail open — transport failure must not block users
        }
        $resp = $http['body'];
        if (!is_array($resp)) {
            error_log('reCAPTCHA Enterprise: Invalid JSON response.');
            return null;
        }
        $tokenProps  = is_array($resp['tokenProperties'] ?? null) ? $resp['tokenProperties'] : [];
        $riskAnalysis = is_array($resp['riskAnalysis'] ?? null)   ? $resp['riskAnalysis']   : [];

        if (!empty($tokenProps['invalid'])) return 'Security check failed. Please try again.';
        if ($expectedAction !== '' && isset($tokenProps['action']) && to_str($tokenProps['action']) !== $expectedAction) {
            return 'Security check failed. Please try again.';
        }

        $score = (isset($riskAnalysis['score']) && is_numeric($riskAnalysis['score'])) ? (float)$riskAnalysis['score'] : 0.0;
        return $score >= $threshold ? null : 'Security check failed. Please try again.';
    } catch (Throwable $e) {
        error_log('reCAPTCHA Enterprise verify error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Resolve the reCAPTCHA v3 expected action name, honouring the legacy
 * top-level $config['recaptcha_action'] key (documented since #289) before
 * falling back to the v2.6.0 registry key recaptcha_enterprise.expected_action.
 * Both the widget render and the Enterprise verify path must go through this
 * helper so the action emitted in the hidden input matches the action checked
 * during verification — otherwise valid Enterprise tokens fail action matching.
 */
function recaptcha_expected_action_resolved(): string
{
    $legacyAction = ipam_config('recaptcha_action');
    $resolved = (is_string($legacyAction) && $legacyAction !== '')
        ? $legacyAction
        : to_str(ipam_setting('recaptcha_enterprise.expected_action'));
    return $resolved !== '' ? $resolved : 'login';
}

/**
 * Verify the login form protection token/field for the current POST request.
 *
 * Returns null on pass, '' for a silent honeypot rejection (no error shown),
 * or a non-empty error string that should be shown to the user.
 * Fails open on network errors so a broken CAPTCHA provider never blocks login.
 *
 * @param array<string, mixed> $config Stub config (demo_gate) or empty array (login.php); falls back to ipam_setting().
 * @param array<string, mixed> $post
 */
function login_protection_verify(array $config, array $post): ?string
{
    // demo_gate.php passes its own stub; fall back to ipam_setting() for login.php
    $raw = $config['login_protection'] ?? [];
    $lp  = is_array($raw) ? $raw : [];
    $cfg = fn(string $k): mixed => array_key_exists($k, $lp) ? $lp[$k] : ipam_setting("login_protection.{$k}");

    $method = to_str($cfg('method'));
    if ($method === '' || $method === 'null') return null;

    if ($method === 'honeypot') {
        return ($post['website'] ?? '') !== '' ? '' : null;
    }

    if ($method === 'time_check') {
        $min = max(1, to_int($cfg('min_seconds')));
        $ts  = to_int($_SESSION['login_form_at'] ?? 0);
        unset($_SESSION['login_form_at']);
        if ($ts === 0 || (time() - $ts) < $min) {
            return 'Form submission was too fast. Please wait a moment and try again.';
        }
        return null;
    }

    $secretKey = to_str($cfg('secret_key'));
    $siteKey   = to_str($cfg('site_key'));

    if ($method === 'turnstile') {
        $token = to_str($post['cf-turnstile-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';
        try {
            $resp = oidc_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('Turnstile verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'hcaptcha') {
        $token = to_str($post['h-captcha-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';
        try {
            $resp = oidc_http_post('https://hcaptcha.com/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('hCaptcha verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'recaptcha') {
        $token = to_str($post['g-recaptcha-response'] ?? '');
        if ($token === '') return 'Please complete the security check.';

        // Use Enterprise API if configured; fall back to standard reCAPTCHA API
        if ((bool)ipam_setting('recaptcha_enterprise.enabled')) {
            $rawThreshold = ipam_setting('recaptcha_enterprise.score_threshold');
            $enterprise = [
                'enabled'         => true,
                'project_id'      => to_str(ipam_setting('recaptcha_enterprise.project_id')),
                'api_key'         => to_str(ipam_setting('recaptcha_enterprise.api_key')),
                // Must match the action the widget emits (see
                // recaptcha_expected_action_resolved for the precedence rules).
                'expected_action' => recaptcha_expected_action_resolved(),
                'score_threshold' => is_numeric($rawThreshold) ? (float)$rawThreshold : 0.5,
            ];
            return recaptcha_enterprise_verify($token, $siteKey, $enterprise);
        }

        try {
            $resp = oidc_http_post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => client_ip(),
            ]);
        } catch (Throwable $e) {
            error_log('reCAPTCHA verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    if ($method === 'friendly_captcha') {
        $token = to_str($post['frc-captcha-solution'] ?? '');
        if ($token === '' || $token === '.UNSTARTED') return 'Please complete the security check.';
        if ($token === '.FETCHING') return null; // in-progress, fail open
        try {
            $resp = oidc_http_post('https://api.friendlycaptcha.com/api/v1/siteverify', [
                'secret'  => $secretKey,
                'solution'=> $token,
                'sitekey' => $siteKey,
            ]);
        } catch (Throwable $e) {
            error_log('FriendlyCaptcha verify error: ' . $e->getMessage());
            return null; // fail open
        }
        return !empty($resp['success']) ? null : 'Security check failed. Please try again.';
    }

    return null; // unknown method — pass through
}

/**
 * Return the HTML widget snippet to embed in the login/gate form.
 * For time_check, also sets the session timestamp on GET requests.
 *
 * @param array<string, mixed> $config Stub config (demo_gate) or empty array (login.php); falls back to ipam_setting().
 */
function login_protection_widget_html(array $config): string
{
    $raw = $config['login_protection'] ?? [];
    $lp  = is_array($raw) ? $raw : [];
    $cfg = fn(string $k): mixed => array_key_exists($k, $lp) ? $lp[$k] : ipam_setting("login_protection.{$k}");

    $method  = to_str($cfg('method'));
    $siteKey = e(to_str($cfg('site_key')));

    switch ($method) {
        case 'honeypot':
            return "<input type='text' name='website' autocomplete='off' tabindex='-1' aria-hidden='true' class='hidden'>";
        case 'time_check':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $_SESSION['login_form_at'] = time();
            }
            return ''; // no visible widget
        case 'turnstile':
            return "<script src='https://challenges.cloudflare.com/turnstile/v0/api.js' async defer></script>"
                 . "<div class='cf-turnstile' data-sitekey='{$siteKey}'></div>";
        case 'hcaptcha':
            return "<script src='https://js.hcaptcha.com/1/api.js' async defer></script>"
                 . "<div class='h-captcha' data-sitekey='{$siteKey}'></div>";
        case 'recaptcha':
            $ver   = to_int($cfg('version'));
            $isEnt = (bool)ipam_setting('recaptcha_enterprise.enabled');
            if ($ver === 3) {
                $scriptSrc    = $isEnt
                    ? "https://www.google.com/recaptcha/enterprise.js?render={$siteKey}"
                    : "https://www.google.com/recaptcha/api.js?render={$siteKey}";
                $entAttr      = $isEnt ? " data-recaptcha-enterprise='1'" : '';
                $action       = e(recaptcha_expected_action_resolved());
                $actionAttr   = " data-recaptcha-action='{$action}'";
                return "<script src='{$scriptSrc}' async defer></script>"
                     . "<input type='hidden' name='g-recaptcha-response' id='g-recaptcha-response' data-recaptcha-v3-key='{$siteKey}'{$entAttr}{$actionAttr}>";
            }
            return "<script src='https://www.google.com/recaptcha/api.js' async defer></script>"
                 . "<div class='g-recaptcha' data-sitekey='{$siteKey}'></div>";
        case 'friendly_captcha':
            return "<script src='https://cdn.jsdelivr.net/npm/friendly-challenge@latest/widget.module.min.js' async defer></script>"
                 . "<div class='frc-captcha' data-sitekey='{$siteKey}'></div>";
        default:
            return '';
    }
}

/**
 * Return extra CSP directives needed for the active login protection method.
 * Returns ['script_src' => '...', 'frame_src' => '...'] — either may be empty.
 * Turnstile, hCaptcha, and reCAPTCHA render inside an iframe so frame_src must
 * be explicitly allowed; Friendly Captcha uses Web Components (no iframe needed).
 *
 * @param array<string, mixed> $config Stub config (demo_gate) or empty array (login.php); falls back to ipam_setting().
 * @return array{script_src: string, style_src: string, frame_src: string}
 */
function login_protection_extra_csp(array $config): array
{
    $raw = $config['login_protection'] ?? [];
    $lp  = is_array($raw) ? $raw : [];
    $method = to_str(array_key_exists('method', $lp) ? $lp['method'] : ipam_setting('login_protection.method'));
    return match ($method) {
        'turnstile'        => [
            'script_src' => 'https://challenges.cloudflare.com',
            'style_src'  => "'unsafe-inline'",
            'frame_src'  => 'https://challenges.cloudflare.com',
        ],
        'hcaptcha'         => [
            'script_src' => 'https://hcaptcha.com https://assets.hcaptcha.com',
            'style_src'  => '',
            'frame_src'  => 'https://newassets.hcaptcha.com',
        ],
        'recaptcha'        => [
            'script_src' => 'https://www.google.com https://www.gstatic.com',
            'style_src'  => '',
            'frame_src'  => 'https://www.google.com',
        ],
        'friendly_captcha' => [
            'script_src' => 'https://cdn.jsdelivr.net',
            'style_src'  => '',
            'frame_src'  => '',
        ],
        default            => ['script_src' => '', 'style_src' => '', 'frame_src' => ''],
    };
}
