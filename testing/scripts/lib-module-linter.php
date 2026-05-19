<?php
declare(strict_types=1);

/**
 * lib-module-linter.php — v3.30.0 ADR-004 enforcement.
 *
 * Four rules: the header-comment rule, the cross-module require ban, and
 * function-uniqueness across lib/*.php (ADR-004); plus the ADR-003 (#1207)
 * config-access ban — `global $config;` / `$GLOBALS['config']` are
 * forbidden across the WHOLE `<root>` tree, not just lib/*.php.
 *
 * Usage:
 *   php testing/scripts/lib-module-linter.php --root=<path>
 *
 * The lib/*.php rules walk `<root>/lib/*.php`. For each such file, the
 * first non-blank line
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
 * *sibling* `.php` file in the module's own `lib/` directory.
 *
 * For a `__DIR__`-anchored require the base directory is `__DIR__` (the
 * linted file's own dir) climbed up once per enclosing `dirname()` call;
 * the string literal is appended and the result path-normalized. Every
 * sibling-import shape therefore resolves correctly and is flagged —
 * `__DIR__ . '/Name.php'`, `__DIR__ . '/../lib/Name.php'` (climbs out and
 * back), and `dirname(__DIR__) . '/lib/Name.php'` (parent-then-back-in).
 * Targets that genuinely land outside `lib/` (`dirname(__DIR__) .
 * '/version.php'`, `__DIR__ . '/../dialects/...'`) resolve to another
 * directory and are NOT flagged. A non-`__DIR__` path containing an
 * explicit `/lib/Name.php` segment is also flagged. Dynamic targets (a
 * bare variable) cannot be resolved and are not flagged.
 *
 * Returns null on pass, or a human-readable reason on violation.
 */

/**
 * Lexically normalize a path: collapse '.' and '..' segments without
 * touching the filesystem (the target may not exist on disk). Leading
 * '/' is preserved. A '..' that would climb above the root is dropped.
 */
function ipam_lml_normalize_path(string $path): string {
    $absolute = str_starts_with($path, '/');
    $out = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($out !== [] && end($out) !== '..') {
                array_pop($out);
            } elseif (!$absolute) {
                $out[] = '..';
            }
            continue;
        }
        $out[] = $segment;
    }
    return ($absolute ? '/' : '') . implode('/', $out);
}

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
        $dirnameDepth = 0;
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
                // Count enclosing dirname() calls so the base directory can
                // be climbed the right number of levels (see below).
                $dirnameDepth++;
            }
        }

        if ($literal === '') {
            // No string literal at all — dynamic target, cannot resolve.
            continue;
        }

        // Resolve the basename of the target.
        $target = basename($literal);
        if (!str_ends_with($target, '.php')) {
            continue;
        }

        $libDir = dirname($path);

        $rootedInLib = false;
        if (str_contains($expr, '__DIR__')) {
            // __DIR__-anchored. The base directory is __DIR__ (the linted
            // file's own dir) climbed up once per enclosing dirname() call;
            // the string literal is then appended and the result normalized.
            // This catches every sibling-import shape — __DIR__ . '/X.php',
            // __DIR__ . '/../lib/X.php', dirname(__DIR__) . '/lib/X.php' —
            // while genuine out-of-lib targets resolve to another directory.
            $base = $libDir;
            for ($d = 0; $d < $dirnameDepth; $d++) {
                $base = dirname($base);
            }
            $resolved = ipam_lml_normalize_path($base . '/' . $literal);
            if (dirname($resolved) === $libDir
                && basename($resolved) !== basename($path)) {
                $rootedInLib = true;
            }
        } elseif (preg_match('#/lib/[^/]+\.php$#', $literal) === 1) {
            // Non-__DIR__ path with an explicit '/lib/Name.php' segment.
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
 * Check that a single .php file does not read config via `global $config;`
 * or direct `$GLOBALS['config']` access — ADR-003 (#1207) mandates the
 * `ipam_config()` / `ipam_config_nested()` accessor as the only conduit.
 *
 * Detection is tokenizer-based, so the same `global $config;` text inside a
 * comment or a string literal is naturally ignored — only genuine code is
 * flagged. Two shapes are detected:
 *
 *   1. `global $config;` — a `T_GLOBAL` token followed (skipping
 *      whitespace) by a `T_VARIABLE` whose value is `$config`. A
 *      multi-variable `global $a, $config;` is also caught: every
 *      T_VARIABLE up to the terminating `;` is examined.
 *   2. `$GLOBALS['config']` — a `T_VARIABLE` `$GLOBALS` immediately
 *      followed by `[` and a `T_CONSTANT_ENCAPSED_STRING` whose unquoted
 *      value is `config`.
 *
 * The accessor's own module (`lib/config.php`) is the single legitimate
 * reader of `$GLOBALS['config']` and is excluded by the caller, not here.
 *
 * Returns null on pass, or a human-readable reason on the first violation.
 */
function ipam_lml_check_config_access(string $path): ?string {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return 'unreadable';
    }

    $tokens = token_get_all($contents);
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            continue;
        }

        // Shape 1: `global ... $config ...;`
        if ($tok[0] === T_GLOBAL) {
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_string($t)) {
                    if ($t === ';') {
                        break;
                    }
                    continue;
                }
                if ($t[0] === T_VARIABLE && $t[1] === '$config') {
                    return 'forbidden `global $config;` (ADR-003 #1207 — use ipam_config())';
                }
            }
            continue;
        }

        // Shape 2: `$GLOBALS['config']`
        if ($tok[0] === T_VARIABLE && $tok[1] === '$GLOBALS') {
            // Next significant token must be `[`.
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j])
                && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $n || $tokens[$j] !== '[') {
                continue;
            }
            $j++;
            while ($j < $n && is_array($tokens[$j])
                && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j < $n && is_array($tokens[$j])
                && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING
                && substr($tokens[$j][1], 1, -1) === 'config') {
                return "forbidden \$GLOBALS['config'] access "
                    . '(ADR-003 #1207 — use ipam_config())';
            }
        }
    }

    return null;
}

/**
 * Collect the names of top-level (file-scope) named function declarations
 * in a single file.
 *
 * Detection is tokenizer-based. A declaration is a `T_FUNCTION` token
 * followed (skipping whitespace, and an optional single `&` for a
 * reference-return declaration — `function &foo()`) by a `T_STRING` —
 * the function name. Anonymous functions (`T_FUNCTION` followed by `(`,
 * or `&` then `(`) yield no `T_STRING` and are naturally skipped; arrow
 * functions are a distinct `T_FN` token and are never `T_FUNCTION`.
 *
 * Class methods are excluded by brace-depth tracking: while inside a
 * `class`/`interface`/`trait`/`enum` body the depth is non-zero, so a
 * `T_FUNCTION` there is a method, not a free function, and is not
 * collected. The legacy client classes (`S3Client.php`, etc.) declare
 * only methods, so nothing is collected from them.
 *
 * @return list<string> file-scope function names declared in the file.
 */
function ipam_lml_collect_functions(string $path): array {
    $contents = @file_get_contents($path);
    if ($contents === false) {
        return [];
    }

    $tokens = token_get_all($contents);
    $n = count($tokens);

    $names = [];
    $braceDepth = 0;          // depth of all { } seen so far
    $classBodyDepths = [];    // brace depths at which a class body opened

    $classKinds = [T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) {
        $classKinds[] = T_ENUM;
    }
    $pendingClass = false;    // saw a class-like keyword, awaiting its `{`

    for ($i = 0; $i < $n; $i++) {
        $tok = $tokens[$i];

        if (is_string($tok)) {
            if ($tok === '{') {
                $braceDepth++;
                if ($pendingClass) {
                    $classBodyDepths[] = $braceDepth;
                    $pendingClass = false;
                }
            } elseif ($tok === '}') {
                if ($classBodyDepths !== []
                    && end($classBodyDepths) === $braceDepth) {
                    array_pop($classBodyDepths);
                }
                $braceDepth--;
            }
            continue;
        }

        // String-interpolation openers (`"...{$x}..."`, `"...${x}..."`) are
        // array-form tokens, but their matching `}` is a plain `}` string
        // token. Count them as opening braces so depth stays balanced —
        // otherwise heredocs / interpolated strings drive braceDepth
        // negative and class-body tracking breaks.
        if ($tok[0] === T_CURLY_OPEN
            || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $braceDepth++;
            continue;
        }

        if ($tok[0] === T_DOUBLE_COLON) {
            // A `::` token means the preceding name is a reference, not a
            // declaration — `Foo::class`, `Foo::method()`. `T_CLASS` also
            // appears in `Foo::class`; clearing the pending-class flag here
            // keeps it from latching onto an unrelated later `{`.
            $pendingClass = false;
            continue;
        }

        if (in_array($tok[0], $classKinds, true)) {
            // A genuine class declaration opens a `{` body before the next
            // class-like keyword. The `Foo::class` false positive is
            // defused by the T_DOUBLE_COLON clear above.
            $pendingClass = true;
            continue;
        }

        if ($tok[0] !== T_FUNCTION) {
            continue;
        }

        // Skip whitespace to the next significant token.
        $j = $i + 1;
        while ($j < $n
            && is_array($tokens[$j])
            && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }

        // Skip a single `&` for a reference-return declaration —
        // `function &foo()`. On PHP 8 this `&` tokenizes as the array
        // token T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG; on older
        // runtimes (or other positions) it can be a bare `'&'` string
        // token. Handle both forms, then skip any trailing whitespace.
        if ($j < $n) {
            $amp = $tokens[$j];
            $isAmp = ($amp === '&')
                || (is_array($amp)
                    && defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG')
                    && $amp[0] === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG);
            if ($isAmp) {
                $j++;
                while ($j < $n
                    && is_array($tokens[$j])
                    && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
            }
        }

        if ($j >= $n || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
            // Anonymous function (`(` follows, possibly after `&`) — not a
            // declaration.
            continue;
        }

        // Inside a class/interface/trait/enum body → method, not a free fn.
        if ($classBodyDepths !== []) {
            continue;
        }

        $names[] = $tokens[$j][1];
    }

    return $names;
}

/**
 * Files exempt from the ADR-003 config-access ban. These are the bootstrap
 * and accessor-implementation sites that legitimately touch
 * `$GLOBALS['config']`:
 *
 *   - `lib/config.php` — the ipam_config() accessor itself; it IS the
 *     single legitimate reader of `$GLOBALS['config']`.
 *   - `config.php` / `config.php.example` — operator-edited bootstrap
 *     files that DEFINE the config array via `return [...]`. (They never
 *     use `global`/`$GLOBALS` today, but excluding them documents intent.)
 *   - `init.php` — the bootstrap that populates `$GLOBALS['config']` before
 *     lib/config.php is loaded. (It currently assigns, never reads via the
 *     banned shapes, but the assignment site is the bootstrap boundary.)
 *
 * Paths are matched on the segment relative to the linted root.
 */
const ADR003_CONFIG_BAN_EXEMPT = [
    'lib/config.php',
    'config.php',
    'config.php.example',
    'init.php',
];

/**
 * Recursively collect every `*.php` file under a directory, skipping the
 * `data/` runtime directory (sqlite DB, generated artefacts) and any
 * `vendor/` tree. Returns an absolute, sorted list.
 *
 * @return list<string>
 */
function ipam_lml_collect_php_files(string $dir): array {
    $out = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php') {
            continue;
        }
        if (str_contains($path, '/vendor/') || str_contains($path, '/data/')) {
            continue;
        }
        $out[] = $path;
    }
    sort($out);
    return $out;
}

/**
 * Walk `<root>/lib/*.php` (header / cross-module / uniqueness rules) and
 * the whole `<root>` tree (the ADR-003 config-access ban).
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

    // Per-file rules (header, cross-module require) apply to v3.30.0
    // modules only — legacy files are allowlisted.
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

    // Cross-file rule: function uniqueness. A global function name may be
    // declared in at most one lib/*.php file — a duplicate is a PHP
    // "cannot redeclare" fatal. This is a global runtime invariant, so it
    // covers ALL lib files including the legacy allowlisted ones.
    /** @var array<string, list<string>> $declaredIn */
    $declaredIn = [];
    foreach ($files as $file) {
        foreach (ipam_lml_collect_functions($file) as $fn) {
            $declaredIn[$fn][] = $file;
        }
    }
    foreach ($declaredIn as $fn => $where) {
        if (count($where) > 1) {
            fwrite(STDERR, sprintf(
                "duplicate function: %s() declared in %d files: %s\n",
                $fn,
                count($where),
                implode(', ', $where)
            ));
            $violations++;
        }
    }

    // Whole-tree rule: the ADR-003 (#1207) config-access ban. Unlike the
    // header / cross-module rules (lib/*.php only), this scans every
    // *.php file under <root> — api.php, page handlers, views, migrations,
    // lib/*. `global $config;` and direct `$GLOBALS['config']` access are
    // forbidden everywhere; the only conduit is ipam_config() /
    // ipam_config_nested(). The bootstrap + accessor files in
    // ADR003_CONFIG_BAN_EXEMPT are excluded.
    $rootReal = rtrim($root, '/');
    foreach (ipam_lml_collect_php_files($rootReal) as $file) {
        $rel = ltrim(substr($file, strlen($rootReal)), '/');
        if (in_array($rel, ADR003_CONFIG_BAN_EXEMPT, true)) {
            continue;
        }
        $cfgReason = ipam_lml_check_config_access($file);
        if ($cfgReason !== null) {
            fwrite(STDERR, sprintf("%s: %s\n", $file, $cfgReason));
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
