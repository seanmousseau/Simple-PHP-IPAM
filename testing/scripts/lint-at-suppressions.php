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
 *
 * Also re-includable from tests: defines functions only at top level; CLI
 * bootstrap only runs when this file is the invoked script.
 */

const LINT_AT_TARGETS = [
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

function lint_at_pattern(): string
{
    return '/@(' . implode('|', array_map('preg_quote', LINT_AT_TARGETS)) . ')\s*\(/';
}

/**
 * CR #1307 #7: tighter comment-justification heuristic — strip string literals
 * before looking for '//' so URLs or '//' inside quoted values don't count.
 *
 * Returns true if:
 *   - same line contains '//' that is outside any string literal, OR
 *   - previous trimmed line starts with '//' or '*' (block-comment continuation).
 */
function lint_at_has_inline_comment(string $line): bool
{
    // Strip single- and double-quoted string literals (handles \-escape sequences),
    // then check whether any '//' remains — that '//' must be a comment.
    $stripped = preg_replace('/\'(?:\\\\.|[^\\\\\'])*\'|"(?:\\\\.|[^\\\\"])*"/', '', $line);
    return $stripped !== null && str_contains($stripped, '//');
}

function lint_at_has_justification(string $line, string $prevLine): bool
{
    $prevTrimmed = trim($prevLine);

    return lint_at_has_inline_comment($line) ||
           str_starts_with($prevTrimmed, '//') ||
           str_starts_with($prevTrimmed, '*');
}

/**
 * Check a single file for un-justified @-suppressions.
 *
 * @return array{file:string,line:int,content:string}|null
 */
function lint_at_check_file(string $path): ?array
{
    $lines = @file($path);
    if ($lines === false) {
        // CR #1307 #5: fail closed on unreadable / missing file. Returning null
        // (clean) here would silently skip the requested target and let CI pass
        // without actually linting it. Throw instead so the CLI bootstrap exits
        // non-zero and the operator knows the file could not be read.
        throw new \RuntimeException("lint-at-suppressions: cannot read {$path}");
    }
    $pattern = lint_at_pattern();
    foreach ($lines as $i => $line) {
        if (preg_match($pattern, $line)) {
            $prev = $lines[$i - 1] ?? '';
            if (!lint_at_has_justification($line, $prev)) {
                return [
                    'file' => $path,
                    'line' => $i + 1,
                    'content' => trim($line),
                ];
            }
        }
    }
    return null;
}

/**
 * Check a list of files. Returns the first offender or null if all clean.
 *
 * @param list<string> $paths
 * @return array{file:string,line:int,content:string}|null
 */
function lint_at_check_paths(array $paths): ?array
{
    foreach ($paths as $path) {
        $result = lint_at_check_file($path);
        if ($result !== null) {
            return $result;
        }
    }
    return null;
}

/**
 * Recursively enumerate .php files under a directory, skipping vendor/ and node_modules/.
 *
 * @return list<string>
 */
function lint_at_enumerate(string $root): array
{
    $out = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    /** @var \SplFileInfo $f */
    foreach ($rii as $f) {
        if ($f->getExtension() !== 'php') {
            continue;
        }
        $fpath = $f->getPathname();
        if (str_contains($fpath, '/vendor/') || str_contains($fpath, '/node_modules/')) {
            continue;
        }
        $out[] = $fpath;
    }
    return $out;
}

// CLI bootstrap — runs only when this file is invoked directly, not when require'd from a test.
$script = isset($_SERVER['SCRIPT_FILENAME']) && is_string($_SERVER['SCRIPT_FILENAME'])
    ? $_SERVER['SCRIPT_FILENAME']
    : '';
if (PHP_SAPI === 'cli' && $script !== '' && realpath($script) === __FILE__) {
    $paths = [];
    if (isset($argc, $argv) && $argc > 1) {
        for ($i = 1; $i < $argc; ++$i) {
            $paths[] = (string) $argv[$i];
        }
    } else {
        $paths = lint_at_enumerate(dirname(__DIR__) . '/../Simple-PHP-IPAM');
    }
    $offender = lint_at_check_paths($paths);
    if ($offender !== null) {
        fwrite(STDERR, sprintf(
            "%s:%d: un-justified @-suppression: %s\n",
            $offender['file'],
            $offender['line'],
            $offender['content']
        ));
        exit(1);
    }
    exit(0);
}
