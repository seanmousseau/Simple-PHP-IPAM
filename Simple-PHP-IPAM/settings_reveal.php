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

$value = ipam_setting($key);
if (!is_string($value)) $value = '';

audit($db, 'setting.reveal', 'setting', null, "key={$key}");

echo json_encode(['value' => $value]);
