<?php
declare(strict_types=1);

require __DIR__ . '/init.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Forbidden\n");
}

try {
    $applied = apply_migrations($db);
    echo "Migrations applied: " . (count($applied) ? implode(', ', $applied) : '(none)') . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Migration failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
