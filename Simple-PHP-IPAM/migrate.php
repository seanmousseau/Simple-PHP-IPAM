<?php
declare(strict_types=1);

// CLI-only guard MUST run before init.php. Otherwise an HTTP hit on
// /migrate.php would pass through init.php's full boot (HTTPS redirect,
// session, ipam_db_init → apply_migrations) before the 403 fires, which
// defeats the purpose of the guard.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

require __DIR__ . '/init.php';
/** @var \PDO $db */

try {
    $applied = apply_migrations($db);
    echo "Migrations applied: " . (count($applied) ? implode(', ', $applied) : '(none)') . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
