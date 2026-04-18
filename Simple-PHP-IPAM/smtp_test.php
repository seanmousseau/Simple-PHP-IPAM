<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_require();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$user = current_user();
$toAddr = '';

// Use the logged-in admin's email, fall back to smtp.from_address
$row = $db->prepare("SELECT email FROM users WHERE id = :id");
$row->execute([':id' => $user['id']]);
/** @var array<string,mixed>|false $uRow */
$uRow = $row->fetch();
if ($uRow && to_str($uRow['email']) !== '') {
    $toAddr = to_str($uRow['email']);
}
if ($toAddr === '') {
    $toAddr = to_str(ipam_setting('smtp.from_address'));
}
if ($toAddr === '') {
    echo json_encode(['ok' => false, 'message' => 'No recipient: set your user email or configure smtp.from_address.', 'transport' => null]);
    exit;
}

$appName = to_str(ipam_setting('branding.site_name'));
$subject = "[{$appName}] SMTP test email";
$body    = "This is a test email sent from {$appName}.\n\nIf you received this, your mail delivery is working correctly.";

$result = ipam_send_mail($toAddr, $subject, $body);

audit($db, 'mail.test', 'user', to_int($user['id']), "to={$toAddr} transport={$result['transport']} ok=" . ($result['success'] ? '1' : '0'));

echo json_encode([
    'ok'        => $result['success'],
    'message'   => $result['success']
        ? "Test email sent to {$toAddr} via {$result['transport']}."
        : "Send failed via {$result['transport']}: " . ($result['error'] ?? 'unknown error'),
    'transport' => $result['transport'],
]);
