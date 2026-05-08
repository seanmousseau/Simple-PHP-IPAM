<?php
/**
 * Test-only CLI helper: writes a single setting directly through
 * ipam_setting_set(), bypassing the UI's step-up gate and lock-out guard.
 * Used by step-up-oidc-only.spec.ts to tighten the install-wide step-up
 * policy into the "stranding" state that the UI would normally refuse to
 * commit, so the spec can prove the prompt partial renders the actionable
 * "no methods available" error end-to-end.
 *
 * Constrained to a small set of allow-listed keys to keep the blast radius
 * tight even when run with cluster-admin docker exec privileges.
 *
 * Usage:
 *   php set_test_setting.php <key> <value>
 *
 * Examples:
 *   php set_test_setting.php auth.step_up.allow_provider_reauth false
 *   php set_test_setting.php auth.step_up.allow_provider_reauth true
 *
 * CLI-only.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$key = $argv[1] ?? '';
$rawValue = $argv[2] ?? '';
if ($key === '' || $rawValue === '') {
    fwrite(STDERR, "Usage: set_test_setting.php <key> <value>\n");
    exit(2);
}

// Allowlist: only keys this fixture is meant to drive. Add as new specs
// need them — the explicit list is the safety net.
$ALLOWED_KEYS = [
    'auth.step_up.allow_totp',
    'auth.step_up.allow_email_otp',
    'auth.step_up.allow_webauthn',
    'auth.step_up.allow_provider_reauth',
    'auth.step_up.ttl_seconds',
];
if (!in_array($key, $ALLOWED_KEYS, true)) {
    fwrite(STDERR, "Key '{$key}' is not in the allowed test-setting list.\n");
    exit(3);
}

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) $configPath = '/var/www/html/config.php';
$config = require $configPath;

$libPath = __DIR__ . '/../../lib.php';
if (!file_exists($libPath)) $libPath = '/var/www/html/lib.php';
require_once $libPath;

$db = ipam_db($config);

// Resolve the registry definition so we cast the stringy CLI arg to the
// type the registry expects. ipam_setting_set() validates internally too.
$defs = ipam_setting_definitions();
$type = is_string($defs[$key]['type'] ?? null) ? $defs[$key]['type'] : 'string';

$value = match ($type) {
    'bool'   => in_array(strtolower($rawValue), ['1', 'true', 'on', 'yes'], true),
    'int'    => to_int($rawValue),
    default  => $rawValue,
};

try {
    ipam_setting_set($db, $key, $value, null);
} catch (\Throwable $e) {
    fwrite(STDERR, "set failed: " . $e->getMessage() . "\n");
    exit(7);
}

echo "ok\n";
