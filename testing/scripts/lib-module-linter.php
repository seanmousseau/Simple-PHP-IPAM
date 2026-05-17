<?php
declare(strict_types=1);

/**
 * lib-module-linter.php — v3.30.0 ADR-004 enforcement.
 *
 * Two rules today: the header-comment rule and the cross-module require
 * ban. More rules are added rule-by-rule as Phase 2+ extractions land
 * (global $config; ban, function-uniqueness).
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
 * Check that a single lib/*.php module does not require/include a sibling
 * lib/*.php module.
 *
 * v3.30.0 modules are loaded only by init.php (and lib.php); inter-module
 * dependencies resolve lazily at call time. A `require`/`require_once`/
 * `include`/`include_once` whose target resolves into the same `lib/`
 * directory re-introduces the hidden dependency graph ADR-004 eliminates.
 *
 * Detection is tokenizer-based: the file is tokenized with token_get_all()
 * and only genuine `T_REQUIRE` / `T_REQUIRE_ONCE` / `T_INCLUDE` /
 * `T_INCLUDE_ONCE` tokens are examined. A `require`/`include` word that
 * appears inside a comment (`// ...`, `/* ... *​/`) or inside a string
 * literal / heredoc is not such a token, so commented-out or quoted
 * requires are naturally ignored — that is the point of using the
 * tokenizer rather than a raw regex.
 *
 * A require is flagged when its target path expression resolves to a
 * `.php` file in the module's own `lib/` directory. The two resolvable
 * shapes are:
 *   - `__DIR__ . '/Name.php'`            — sibling in the same directory
 *   - `... '/lib/Name.php'`              — explicit lib/ segment in the path
 * Targets that climb out of `lib/` (`__DIR__ . '/../dialects/...'`,
 * `dirname(__DIR__) . '/version.php'`, `__DIR__ . '/../views/...'`) are
 * NOT flagged. Dynamic targets (a bare variable) cannot be resolved and
 * are not flagged.
 *
 * Returns null on pass, or a human-readable reason on violation.
 */
function ipam_lml_check_cross_module_require(string $path): ?string {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return 'unreadable';
    }

    $tokens = token_get_all($contents);
    $n = count($tokens);

    $requireKinds = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok) || !in_array($tok[0], $requireKinds, true)) {
            continue;
        }
        $keyword = strtolower($tok[1]);

        // Walk forward to the statement-terminating ';', collecting the
        // string-literal path fragments and the raw expression text.
        $literal = '';
        $expr = '';
        $sawDirname = false;
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_string($t)) {
                if ($t === ';') {
                    break;
                }
                $expr .= $t;
                continue;
            }
            $expr .= $t[1];
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                // Strip the surrounding quote characters.
                $literal .= substr($t[1], 1, -1);
            } elseif ($t[0] === T_STRING && strtolower($t[1]) === 'dirname') {
                $sawDirname = true;
            }
        }

        if ($literal === '') {
            // No string literal at all — dynamic target, cannot resolve.
            continue;
        }

        // An explicit "/../" segment climbs out of lib/ — not a sibling.
        if (str_contains($literal, '../')) {
            continue;
        }

        // dirname(__DIR__) climbs to the parent of lib/ — not a sibling.
        if ($sawDirname && str_contains($expr, '__DIR__')) {
            continue;
        }

        // Resolve the basename of the target.
        $target = basename($literal);
        if (!str_ends_with($target, '.php')) {
            continue;
        }

        $rootedInLib = false;
        // Shape 1: __DIR__ . '/Name.php' — __DIR__ is the lib/ dir itself.
        // (The __DIR__ . '/lib/Name.php' case is caught by Shape 2 below,
        // so the bare-__DIR__ shape need not handle a '/lib/' literal.)
        if (str_contains($expr, '__DIR__') && !str_contains($literal, '/lib/')) {
            $rootedInLib = true;
        }
        // Shape 2: any path containing an explicit '/lib/Name.php' segment.
        if (preg_match('#/lib/[^/]+\.php$#', $literal) === 1) {
            $rootedInLib = true;
        }

        if ($rootedInLib) {
            return sprintf(
                'cross-module require: %s %s',
                $keyword,
                trim($expr)
            );
        }
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
        $crossReason = ipam_lml_check_cross_module_require($file);
        if ($crossReason !== null) {
            fwrite(STDERR, sprintf("%s: %s\n", $file, $crossReason));
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
