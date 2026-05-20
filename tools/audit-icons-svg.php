<?php
declare(strict_types=1);

/**
 * audit-icons-svg.php — verify every <symbol id="icon-…"> in assets/icons.svg
 * has at least one referencing call site, and that every site that references
 * an icon points at a symbol that exists.
 *
 * Closes findings:
 *   - D14 (#942): audit assets/icons.svg for dead symbols
 *
 * Why this exists. icons.svg is a 22 KB sprite shipped to every page in the
 * <head>. Symbols that have no callers are pure dead weight — but historically
 * have accumulated as features got renamed, redesigned, or never wired up at
 * all. This script surfaces both kinds of drift: unused symbols (waste) and
 * missing symbols (broken UI). It runs in CI; the gate is a non-zero exit code
 * if either condition is true (configurable via the env vars below for the
 * one-off migration where the sweep is happening).
 *
 * Reference patterns scanned:
 *   - `icon('name', …)` / `icon("name", …)` PHP helper calls (lib/presentation.php)
 *   - `<use href="#icon-name">` literal references (in PHP, JS, CSS)
 *   - `xlink:href="#icon-name"` legacy form
 *
 * Allowlist:
 *   tools/audit-icons-allowlist.txt — one symbol per line (icon-…), supports
 *   `# comments` and blank lines. Symbols on the allowlist are reported as
 *   notes ("staged for upcoming work") instead of failures. Use sparingly —
 *   the allowlist exists for icons we deliberately ship ahead of their first
 *   caller because we know they're coming in an upcoming UX milestone.
 *
 * Environment variables:
 *   AUDIT_ICONS_ALLOW_UNUSED — if "1", unused non-allowlisted symbols are
 *                              warnings, not failures. For one-off migrations.
 *
 * Exit codes:
 *   0 — clean (or unused-only and AUDIT_ICONS_ALLOW_UNUSED=1)
 *   1 — symbols referenced but not defined (would break UI)
 *   2 — symbols defined but unreferenced and AUDIT_ICONS_ALLOW_UNUSED unset
 *
 * Usage:
 *   php tools/audit-icons-svg.php             # full audit, fails on either drift
 *   AUDIT_ICONS_ALLOW_UNUSED=1 php tools/audit-icons-svg.php   # report only
 */

$repoRoot      = dirname(__DIR__);
$svgPath       = $repoRoot . '/Simple-PHP-IPAM/assets/icons.svg';
$allowlistPath = $repoRoot . '/tools/audit-icons-allowlist.txt';

$allowlist = [];
if (is_file($allowlistPath)) {
    foreach (file($allowlistPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $allowlist[$line] = true;
    }
}

if (!is_file($svgPath)) {
    fwrite(STDERR, "ERROR: icons.svg not found at {$svgPath}\n");
    exit(1);
}

$svg = (string) file_get_contents($svgPath);

// Defined symbols: <symbol id="icon-…"> or <symbol id="icon-…"/>.
$defined = [];
if (preg_match_all('/<symbol\s+[^>]*\bid="(icon-[a-z0-9_-]+)"/i', $svg, $m)) {
    $defined = array_values(array_unique($m[1]));
    sort($defined);
}

// Search roots for callers. Stay within the app — never the vendor/ or
// node_modules/ trees.
// IMPORTANT: page entry-points (users.php, sites.php, addresses.php, …) live
// at the top level of Simple-PHP-IPAM/, NOT under views/. Scanning the whole
// Simple-PHP-IPAM/ subtree and pruning vendor/node_modules. Skipping the
// Simple-PHP-IPAM/assets/vendor/ tree — third-party CSS may legitimately
// contain unrelated `icon-*` class names that aren't sprite references.
$searchRoots = [
    $repoRoot . '/Simple-PHP-IPAM',
];
$excludeDirs = [
    $repoRoot . '/Simple-PHP-IPAM/assets/vendor',
    $repoRoot . '/Simple-PHP-IPAM/assets/fonts',
];

$used = [];
$iterFiles = function (string $root) use ($excludeDirs, $svgPath): \Generator {
    if (is_file($root)) {
        yield $root;
        return;
    }
    if (!is_dir($root)) {
        return;
    }
    $rii = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            function ($current) use ($excludeDirs) {
                if (!$current->isDir()) return true;
                foreach ($excludeDirs as $ex) {
                    if (rtrim($current->getPathname(), '/') === rtrim($ex, '/')) return false;
                }
                return true;
            }
        )
    );
    foreach ($rii as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower($f->getExtension());
        if ($f->getPathname() === $svgPath) continue;
        if (!in_array($ext, ['php', 'js', 'css', 'html', 'svg'], true)) continue;
        yield $f->getPathname();
    }
};

foreach ($searchRoots as $root) {
    foreach ($iterFiles($root) as $file) {
        $content = (string) file_get_contents($file);

        // icon('name') / icon("name") — bare-name PHP helper.
        if (preg_match_all('/\bicon\(\s*[\'"]([a-z0-9_-]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $used['icon-' . $name] = true;
            }
        }

        // <use href="#icon-name"> or xlink:href="#icon-name".
        if (preg_match_all('/(?:xlink:)?href=[\'"]#?(icon-[a-z0-9_-]+)/', $content, $m)) {
            foreach ($m[1] as $name) {
                $used[$name] = true;
            }
        }
    }
}

$usedList = array_keys($used);
sort($usedList);

$unusedAll = array_values(array_diff($defined, $usedList));
$missing   = array_values(array_diff($usedList, $defined));
$unusedAllowed = array_values(array_filter($unusedAll, fn($s) => isset($allowlist[$s])));
$unusedReal    = array_values(array_filter($unusedAll, fn($s) => !isset($allowlist[$s])));
sort($unusedAllowed);
sort($unusedReal);
sort($missing);

echo "=== icons.svg audit ===\n";
echo 'defined symbols: ' . count($defined) . "\n";
echo 'used references: ' . count($usedList) . "\n";
echo 'unused (defined, no caller): ' . count($unusedReal) . "\n";
echo 'allowlisted (defined, staged for upcoming): ' . count($unusedAllowed) . "\n";
echo 'missing (called, not defined): ' . count($missing) . "\n";

if ($unusedAllowed) {
    echo "\n--- allowlisted (staged for upcoming work) ---\n";
    foreach ($unusedAllowed as $s) echo "  {$s}\n";
}

if ($unusedReal) {
    echo "\n--- unused symbols (candidates for removal) ---\n";
    foreach ($unusedReal as $s) echo "  {$s}\n";
}

if ($missing) {
    echo "\n--- MISSING symbols (UI will be broken) ---\n";
    foreach ($missing as $s) echo "  {$s}\n";
}

if ($missing) {
    fwrite(STDERR, "\nFAIL: " . count($missing) . " icon reference(s) point at symbols not defined in icons.svg\n");
    exit(1);
}

if ($unusedReal) {
    $allow = getenv('AUDIT_ICONS_ALLOW_UNUSED');
    if ($allow === '1') {
        echo "\nWARN: " . count($unusedReal) . " unused symbol(s) — allowed because AUDIT_ICONS_ALLOW_UNUSED=1.\n";
        exit(0);
    }
    fwrite(STDERR, "\nFAIL: " . count($unusedReal) . " unused symbol(s) — delete them from icons.svg, add them to tools/audit-icons-allowlist.txt, or set AUDIT_ICONS_ALLOW_UNUSED=1 during a migration.\n");
    exit(2);
}

echo "\nclean.\n";
exit(0);
