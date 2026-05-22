<?php

declare(strict_types=1);

// Usage: php tools/audit-at-suppressions.php
// Emits TSV: file<TAB>line<TAB>callsite<TAB>has_adjacent_comment

$root = dirname(__DIR__);
$targets = [
    'file_get_contents',
    'file_put_contents',
    'chmod',
    'mkdir',
    'unlink',
    'fopen',
    'fread',
    'fwrite',
    'fclose',
    'flock',
    'rename',
    'copy',
    'touch',
    'is_readable',
    'is_writable',
    'date_default_timezone_set',
];

$pattern = '/@(' . implode('|', array_map('preg_quote', $targets)) . ')\s*\(/';

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

/** @var \SplFileInfo $f */
foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') {
        continue;
    }

    $path = $f->getPathname();

    // Skip vendor and node_modules
    if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
        continue;
    }

    $lines = file($path);
    if ($lines === false) {
        continue;
    }

    foreach ($lines as $i => $line) {
        if (preg_match($pattern, $line, $m)) {
            $prev = trim($lines[$i - 1] ?? '');
            $sameLine = trim($line);

            // Check for justifying comment: previous line starts with // or *,
            // or same line contains //
            $hasComment = str_contains($prev, '//') ||
                          str_starts_with($prev, '//') ||
                          str_starts_with($prev, '*') ||
                          str_contains($sameLine, '//');

            echo str_replace($root . '/', '', $path) . "\t"
                . ($i + 1) . "\t"
                . trim($line) . "\t"
                . ($hasComment ? 'Y' : 'N') . "\n";
        }
    }
}
