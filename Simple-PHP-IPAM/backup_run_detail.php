<?php
declare(strict_types=1);
/**
 * backup_run_detail.php — read-only HTML partial endpoint for the
 * Backup History drawer (#803, F11).
 *
 * GET ?id=<int> → admin-gated. Returns the drawer body HTML for the
 * matching backup_runs row, or 404 if the id does not resolve.
 *
 * Body content is fetched via fetch() from app.js's data-drawer-url
 * delegate; CSRF is not required (idempotent read).
 */
require __DIR__ . '/init.php';
/** @var \PDO $db */
require_role('admin');

$idRaw = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id    = is_int($idRaw) && $idRaw > 0 ? $idRaw : 0;

header('Content-Type: text/html; charset=utf-8');

if ($id === 0) {
    http_response_code(400);
    echo '<p class="danger">Bad request &mdash; missing id.</p>';
    exit;
}

$html = ipam_render_backup_run_detail($db, $id);
if ($html === null) {
    http_response_code(404);
    echo '<p class="danger">Run #' . e((string) $id) . ' not found &mdash; it may have been deleted in another tab.</p>';
    exit;
}

echo $html;
