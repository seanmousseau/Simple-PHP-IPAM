<?php
declare(strict_types=1);
/**
 * destination_edit_drawer.php — HTML partial endpoint for the global drawer's
 * Destinations editors (#803, F12).
 *
 * GET ?id=<int>&form=destination|schedule → admin-gated. Returns the drawer
 * body HTML for the matching backup_destinations row (or its first schedule),
 * or 404 if the id does not resolve.
 *
 * The form itself POSTs back to backup_admin.php?tab=destinations with CSRF;
 * this endpoint is an idempotent read so no CSRF is required here.
 */
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');
require __DIR__ . '/lib/backup_admin_destinations.php';

$idRaw = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id    = is_int($idRaw) && $idRaw > 0 ? $idRaw : 0;
$form  = is_string($_GET['form'] ?? null) ? (string) $_GET['form'] : '';

header('Content-Type: text/html; charset=utf-8');

if ($id === 0 || ($form !== 'destination' && $form !== 'schedule')) {
    http_response_code(400);
    echo '<p class="danger">Bad request.</p>';
    exit;
}

$html = ipam_render_destination_edit_drawer($db, $id, $form);
if ($html === null) {
    http_response_code(404);
    echo '<p class="danger">Destination not found &mdash; it may have been deleted in another tab.</p>';
    exit;
}

echo $html;
