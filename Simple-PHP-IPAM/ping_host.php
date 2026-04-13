<?php
declare(strict_types=1);
/**
 * ping_host.php — on-demand ICMP probe for a single address
 *
 * POST-only AJAX endpoint. Available to all authenticated users including
 * read-only (non-destructive operation). The IP is always resolved from the
 * database by address_id — never trusted from the request body.
 *
 * Request (form-encoded POST):
 *   csrf        — CSRF token
 *   address_id  — integer address.id
 *
 * Response (JSON):
 *   {"up":true,  "latency_ms":6}
 *   {"up":false}
 *   {"error":"not_found"}  — address_id not in DB
 *   {"error":"method"}     — not a POST request
 *   {"error":"csrf"}       — CSRF mismatch
 */

require __DIR__ . '/init.php';
/** @var \PDO $db */
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method']);
    exit;
}

// Manual CSRF check so we can return JSON instead of HTML
if (!hash_equals(to_str($_SESSION['csrf'] ?? ''), to_str($_POST['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf']);
    exit;
}

$addressId = to_int($_POST['address_id'] ?? 0);
if ($addressId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_id']);
    exit;
}

// Resolve IP from DB — never trust raw IP from the request
$st = $db->prepare("SELECT ip FROM addresses WHERE id = :id LIMIT 1");
$st->execute([':id' => $addressId]);
/** @var array{ip:string}|false $row */
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$validated = normalize_ip(to_str($row['ip']));
if ($validated === null) {
    http_response_code(500);
    echo json_encode(['error' => 'invalid_ip']);
    exit;
}

// ipam_probe_icmp() returns latency in ms on success, null on failure.
// CAP_NET_RAW failures are logged to the PHP error log automatically.
$latency = ipam_probe_icmp($validated['ip']);

if ($latency !== null) {
    echo json_encode(['up' => true, 'latency_ms' => $latency]);
} else {
    echo json_encode(['up' => false]);
}
