<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo '{"ok":false}';
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

/** Keys exposed by this endpoint. Extend here as new preferences land.
 *  NOTE: each new allowlist key also needs a matching `case` in the POST switch below,
 *  otherwise it falls through to the 400 default arm. */
const IPAM_PREF_ALLOWLIST = ['theme'];

$uid = to_int(current_user()['id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo '{"ok":false}';
    exit;
}

if ($method === 'POST') {
    csrf_require();

    $key   = to_str($_POST['key']   ?? '');
    $value = to_str($_POST['value'] ?? '');

    if (!in_array($key, IPAM_PREF_ALLOWLIST, true)) {
        http_response_code(400);
        echo '{"ok":false}';
        exit;
    }

    switch ($key) {
        case 'theme':
            $value = strtolower(trim($value));
            if (!in_array($value, ['light', 'dark', 'auto'], true)) {
                http_response_code(400);
                echo '{"ok":false}';
                exit;
            }
            try {
                ipam_user_preference_set($db, $uid, 'theme', $value);
            } catch (\Throwable) {
                http_response_code(500);
                echo '{"ok":false}';
                exit;
            }
            $_SESSION['user_theme'] = $value;
            break;
        default:
            http_response_code(400);
            echo '{"ok":false}';
            exit;
    }

    echo '{"ok":true}';
    exit;
}

// GET — read-only, session-gated, no CSRF required.
// Allowlist $key against a fixed set of literals so the taint chain from
// $_GET never reaches ipam_user_preference_get() or the response output.
$rawKey      = to_str($_GET['key'] ?? '');
$resolvedKey = match ($rawKey) {
    'theme' => 'theme',
    default => null,
};

if ($resolvedKey === null) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

$current  = ipam_user_preference_get($db, $uid, $resolvedKey);
$response = json_encode(['ok' => true, 'value' => $current]);
echo is_string($response) ? $response : '{"ok":true,"value":null}';
