<?php
declare(strict_types=1);

/**
 * lib-module-linter.php — v3.30.0 ADR-004 enforcement (skeleton).
 *
 * Skeleton implementation: one rule today (header comment), more rules
 * added rule-by-rule as Phase 2+ extractions land (global $config; ban,
 * cross-module require ban, function-uniqueness).
 *
 * Usage:
 *   php testing/scripts/lib-module-linter.php --root=<path>
 *
 * Walks `<root>/lib/*.php`. For each file, the first non-blank line
 * after the opening `<?php` (and any `declare(...);`) must be a PHPDoc
 * block starting with `/**` and containing `@module`.
 *
 * Pre-v3.30.0 files under `Simple-PHP-IPAM/lib/` are allowlisted in
 * LEGACY_PRE_V3_30_FILES — each Phase 2+ extraction that adds a header
 * to one of those files removes its entry from this list.
 *
 * Exit codes:
 *   0 — clean
 *   1 — one or more violations (or bad usage)
 */

/**
 * Pre-v3.30.0 lib/*.php files allowlisted from the header-comment rule.
 *
 * Entries are removed as Phase 2+ extraction tasks add @module PHPDoc
 * headers to each file.
 */
const LEGACY_PRE_V3_30_FILES = [
    'app_secret.php',
    'auth_step_up.php',
    'backup.php',
    'backup_admin_destinations.php',
    'backup_admin_history.php',
    'backup_admin_restore.php',
    'BackupClientInterface.php',
    'LocalBackupClient.php',
    'restore_dsn.php',
    'restore_wizard.php',
    'S3Client.php',
    'SftpClient.php',
    'vault.php',
];

/**
 * Parse a single --root=<path> argument out of $argv.
 *
 * @param list<string> $argv
 */
function ipam_lml_parse_root(array $argv): ?string {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--root=')) {
            return substr($arg, 7);
        }
    }
    return null;
}

/**
 * Check that a single file begins with a PHPDoc block containing @module.
 *
 * Reads the first non-blank, non-opening-tag, non-declare line. That line
 * must start with the PHPDoc opener and the block (terminated by the
 * standard closer) must contain the @module tag.
 *
 * Returns null on pass, or a human-readable reason on violation.
 */
function ipam_lml_check_header(string $path): ?string {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return 'unreadable';
    }
    $lines = preg_split('/\r\n|\n|\r/', $contents);
    if ($lines === false) {
        return 'unreadable';
    }

    $i = 0;
    $n = count($lines);

    // Skip opening <?php tag (may share a line with declare or be alone).
    while ($i < $n && trim($lines[$i]) === '') {
        $i++;
    }
    if ($i >= $n) {
        return 'header missing (empty file)';
    }
    $line = trim($lines[$i]);
    if (!str_starts_with($line, '<?php')) {
        return 'header missing (no <?php opening)';
    }
    // If the <?php line has trailing content (e.g. "<?php declare(...);"),
    // we still advance to the next line for the header search.
    $i++;

    // Skip blank lines and a single declare(...); statement if present.
    while ($i < $n && trim($lines[$i]) === '') {
        $i++;
    }
    if ($i < $n && preg_match('/^\s*declare\s*\(/', $lines[$i]) === 1) {
        // Consume until the terminating ); (declares are typically one-line).
        while ($i < $n && !str_contains($lines[$i], ');')) {
            $i++;
        }
        $i++; // step past the line containing );
    }

    // Skip more blank lines before the docblock.
    while ($i < $n && trim($lines[$i]) === '') {
        $i++;
    }

    if ($i >= $n) {
        return 'header missing';
    }

    $first = trim($lines[$i]);
    if (!str_starts_with($first, '/**')) {
        return 'header missing';
    }

    // Collect the docblock until */.
    $block = '';
    for (; $i < $n; $i++) {
        $block .= $lines[$i] . "\n";
        if (str_contains($lines[$i], '*/')) {
            break;
        }
    }

    if (!str_contains($block, '@module')) {
        return 'header missing (@module tag absent)';
    }

    return null;
}

/**
 * Walk `<root>/lib/*.php` and apply rules.
 *
 * @return int 0 on clean, 1 on violations.
 */
function ipam_lml_run(string $root): int {
    $libDir = rtrim($root, '/') . '/lib';
    if (!is_dir($libDir)) {
        fwrite(STDERR, sprintf("lib-module-linter: not a directory: %s\n", $libDir));
        return 1;
    }

    $files = glob($libDir . '/*.php');
    if ($files === false) {
        fwrite(STDERR, sprintf("lib-module-linter: glob failed: %s\n", $libDir));
        return 1;
    }
    sort($files);

    $violations = 0;
    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, LEGACY_PRE_V3_30_FILES, true)) {
            continue;
        }
        $reason = ipam_lml_check_header($file);
        if ($reason !== null) {
            fwrite(STDERR, sprintf("%s: %s\n", $file, $reason));
            $violations++;
        }
    }

    return $violations > 0 ? 1 : 0;
}

// CLI entrypoint guard — only execute when invoked directly (not require()d).
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $root = ipam_lml_parse_root($argv);
    if ($root === null) {
        fwrite(STDERR, "Usage: php lib-module-linter.php --root=<path>\n");
        exit(1);
    }
    exit(ipam_lml_run($root));
}
