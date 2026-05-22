<?php

declare(strict_types=1);

/**
 * CI lint: checks for @-suppressed I/O calls without justifying comments.
 *
 * Accepts file paths as args, or scans Simple-PHP-IPAM/ recursively if none given.
 * Exits 1 + prints first offender if any un-justified @-suppression found.
 * Exits 0 if all good.
 *
 * Usage:
 *   php testing/scripts/lint-at-suppressions.php                 # scan whole app
 *   php testing/scripts/lint-at-suppressions.php path/to/file.php  # check single file
 */

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

/**
 * Check if a line has a justifying comment.
 *
 * Rule: same line contains //, OR previous line starts with //, OR previous line starts with *
 */
function hasCommentJustification(string $line, string $prevLine): bool
{
    $prevTrimmed = trim($prevLine);
    $lineTrimmed = trim($line);

    return str_contains($line, '//') ||
           str_starts_with($prevTrimmed, '//') ||
           str_starts_with($prevTrimmed, '*');
}

/**
 * Check a single file for un-justified @-suppressions.
 *
 * @return array{found:bool,file:string,line:int,content:string}|null
 */
function lintFile(string $path, string $pattern): ?array
{
    $lines = @file($path);
    if ($lines === false) {
        return null;
    }

    foreach ($lines as $i => $line) {
        if (preg_match($pattern, $line, $m)) {
            $prev = $lines[$i - 1] ?? '';
            if (!hasCommentJustification($line, $prev)) {
                return [
                    'found' => true,
                    'file' => $path,
                    'line' => $i + 1,
                    'content' => trim($line),
                ];
            }
        }
    }

    return null;
}

// Determine target paths
$paths = [];

// phpstan: $argc and $argv are always defined in CLI context
if (isset($argc) && $argc > 1) {
    // Explicit files passed as args
    for ($i = 1; $i < $argc; ++$i) {
        if (isset($argv[$i])) {
            $paths[] = $argv[$i];
        }
    }
} else {
    // Recursive scan of Simple-PHP-IPAM/
    $root = dirname(__DIR__) . '/../Simple-PHP-IPAM';
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    /** @var \SplFileInfo $f */
    foreach ($rii as $f) {
        if ($f->getExtension() === 'php') {
            $fpath = $f->getPathname();
            if (!str_contains($fpath, '/vendor/') && !str_contains($fpath, '/node_modules/')) {
                $paths[] = $fpath;
            }
        }
    }
}

// Lint each path; report first offender and exit 1
foreach ($paths as $path) {
    $result = lintFile($path, $pattern);
    if ($result !== null && $result['found']) {
        fwrite(STDERR, sprintf(
            "%s:%d: un-justified @-suppression: %s\n",
            $result['file'],
            $result['line'],
            $result['content']
        ));
        exit(1);
    }
}

// All good
exit(0);
