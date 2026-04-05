<?php
declare(strict_types=1);
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo '{"ok":false}';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

$theme = strtolower(trim((string)($_POST['theme'] ?? '')));
if (!in_array($theme, ['light', 'dark', 'auto'], true)) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

$uid = (int)(current_user()['id'] ?? 0);
if ($uid > 0) {
    $db->prepare("UPDATE users SET theme = :t WHERE id = :id")
       ->execute([':t' => $theme, ':id' => $uid]);
    $_SESSION['user_theme'] = $theme;
}

echo '{"ok":true}';
