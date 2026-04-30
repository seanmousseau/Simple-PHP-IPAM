<?php
declare(strict_types=1);
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
csrf_require();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}

$idRaw = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$id    = is_int($idRaw) ? $idRaw : 0;
$result = ipam_destination_test_now($db, $id, 'manual');
echo json_encode($result);
