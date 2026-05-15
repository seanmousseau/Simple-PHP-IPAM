<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */

// CodeRabbit / user feedback on PR #450 (#449 follow-up): the sensitive-field
// renderer never echoes the stored secret into the HTML source, so the eye
// toggle on its own can only reveal a value the user has just typed. To make
// the toggle reveal the actual stored secret on demand, this endpoint returns
// the value as JSON when the eye is clicked on a "Set" field.
//
// Gates (every layer matters because the response body is the secret itself):
//   - admin role
//   - POST + CSRF
//   - demo mode blocked
//   - registry must list the key with sensitive=true (no arbitrary read)
//   - audit log entry: setting.reveal with the key name (value never logged)

require_login();
require_role('admin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

csrf_require();

if (demo_mode_enabled()) {
    http_response_code(403);
    echo json_encode(['error' => 'Demo mode is read-only.']);
    exit;
}

$key = trim(to_str($_POST['key'] ?? ''));
if ($key === '') {
    http_response_code(400);
    echo json_encode(['error' => 'key required']);
    exit;
}

$defs = ipam_setting_definitions();
if (!isset($defs[$key]) || empty($defs[$key]['sensitive'])) {
    http_response_code(404);
    echo json_encode(['error' => 'unknown or non-sensitive key']);
    exit;
}

// v3.27.0 (#1113) — gate the reveal behind ipam_sudo_require(). Same blast
// radius as a vault-key reveal: the response body is the raw secret. Returns
// HTTP 401 with a stable error code so the JS toggle handler can detect the
// gate miss and route the user through a step-up flow before retrying.
$cur    = current_user();
$userId = to_int($cur['id'] ?? 0);
if (!ipam_sudo_require($db, $userId)) {
    http_response_code(401);
    echo json_encode([
        'error'             => 'step_up_required',
        'message'           => 'Re-authenticate to reveal this setting.',
        'step_up_return_to' => 'settings.php',
    ]);
    exit;
}
ipam_sudo_consume_once();  // Bug X (Pass A 2026-05-08, v3.27.1): consume sudo_once for TTL=0 policy.


$value = ipam_setting($key);
if (!is_string($value)) $value = '';

audit($db, 'setting.reveal', 'setting', null, "key={$key}");

// #1166 v3.29.0: response is application/json; json_encode provides structural escaping.
// nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag -- application/json response, structural escape via json_encode
echo json_encode(['value' => $value]);
