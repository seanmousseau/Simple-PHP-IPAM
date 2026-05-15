<?php
declare(strict_types=1);

/**
 * v3.29.0 #1101 — Migration linter.
 *
 * Walks every `function(PDO $db): void { … }` closure in the target
 * migrations file and flags SQLite-only SQL patterns (`PRAGMA …`,
 * `sqlite_master`, `datetime('now')`, `INTEGER PRIMARY KEY AUTOINCREMENT`,
 * `sqlite_sequence`) that appear inside closures showing NO awareness of
 * multi-engine support.
 *
 * The deliberately-conservative gate detection: any closure that mentions
 * `$driver`, `$driverRaw`, `driver_name()`, `PDO::ATTR_DRIVER_NAME`, or
 * any equality comparison against `'sqlite' / 'mysql' / 'pgsql'` is
 * trusted to know what it's doing. The lint catches "I copied an old
 * SQLite closure into a new migration without thinking about MySQL /
 * Postgres" mistakes, not subtler "I gated incorrectly" subtleties — AST
 * analysis is out of scope.
 *
 * Returns 0 (no findings), 1 (one or more findings) or 2 (fatal
 * read / parse error). Designed as a pre-commit / CI lint, not a runtime
 * assertion.
 *
 * Exposed as both a CLI entry point (run when this file is the main
 * script) and a callable `ipam_run_migration_linter(string): array`
 * function so PHPUnit can drive it without shelling out.
 */

/**
 * Run the linter against a target file. Returns
 *   ['exit' => int, 'stdout' => string, 'stderr' => string]
 *
 * Pure: no I/O outside reading the target file.
 */
function ipam_run_migration_linter(string $path): array
{
    $src = @file_get_contents($path);
    if ($src === false) {
        return ['exit' => 2, 'stdout' => '', 'stderr' => "migration-linter: cannot read $path\n"];
    }

    $sqliteOnly = [
        'PRAGMA'                            => '/\bPRAGMA\s+\w+/',
        'sqlite_master'                     => '/\bsqlite_master\b/',
        "datetime('now')"                   => "/\\bdatetime\\(\\s*['\"]now['\"]\\s*\\)/i",
        'INTEGER PRIMARY KEY AUTOINCREMENT' => '/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i',
        'sqlite_sequence'                   => '/\bsqlite_sequence\b/',
    ];

    $enginePatterns = [
        "/driver_name\\(\\)/",
        "/PDO::ATTR_DRIVER_NAME/",
        "/\\\$driver(Raw)?\\b/",
        "/===\\s*['\"](sqlite|mysql|pgsql)['\"]/",
        "/!==\\s*['\"](sqlite|mysql|pgsql)['\"]/",
    ];

    $signatureRe = '/(?:static\s+)?function\s*\(\s*PDO\s+\$db\s*\)(?:\s*:\s*void)?\s*\{/';
    if (!preg_match_all($signatureRe, $src, $matches, PREG_OFFSET_CAPTURE)) {
        return [
            'exit' => 2,
            'stdout' => '',
            'stderr' => "migration-linter: no migration closures detected in $path — pattern broken?\n",
        ];
    }

    $findings = [];
    $closureCount = 0;
    foreach ($matches[0] as [$signatureText, $sigStart]) {
        $bodyOpen = $sigStart + strlen($signatureText) - 1;
        $depth = 0;
        $bodyClose = -1;
        $inString = false;
        $stringDelim = '';
        $len = strlen($src);
        for ($i = $bodyOpen; $i < $len; $i++) {
            $ch = $src[$i];
            if ($inString) {
                if ($ch === '\\') { $i++; continue; }
                if ($ch === $stringDelim) { $inString = false; }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $stringDelim = $ch;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) { $bodyClose = $i; break; }
            }
        }
        if ($bodyClose < 0) {
            continue;
        }
        $closureCount++;
        $body = substr($src, $bodyOpen, $bodyClose - $bodyOpen + 1);

        $engineAware = false;
        foreach ($enginePatterns as $g) {
            if (preg_match($g, $body)) { $engineAware = true; break; }
        }
        if ($engineAware) {
            continue;
        }

        foreach ($sqliteOnly as $name => $rx) {
            if (preg_match_all($rx, $body, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$matchText, $relOffset]) {
                    $absOffset = $bodyOpen + $relOffset;
                    $lineNum = substr_count(substr($src, 0, $absOffset), "\n") + 1;
                    $findings[] = sprintf(
                        "  %s:%d  %s pattern in ungated closure",
                        basename($path),
                        $lineNum,
                        $name
                    );
                }
            }
        }
    }

    if ($findings === []) {
        return [
            'exit' => 0,
            'stdout' => sprintf("migration-linter: 0 findings across %d migration closures.\n", $closureCount),
            'stderr' => '',
        ];
    }

    $stdout = sprintf("migration-linter: %d finding(s) across %d closures:\n", count($findings), $closureCount);
    foreach ($findings as $f) {
        $stdout .= $f . "\n";
    }
    $stdout .= "\nEach finding is a SQLite-only SQL pattern (PRAGMA / sqlite_master /\n"
             . "datetime('now') / INTEGER PRIMARY KEY AUTOINCREMENT / sqlite_sequence)\n"
             . "inside a migration closure that does NOT show any awareness of\n"
             . "multi-engine support. Either:\n"
             . "  (a) Gate the closure: if (\$driverRaw !== 'sqlite') return;\n"
             . "  (b) Replace the pattern with the engine-portable equivalent\n"
             . "      (information_schema, ipam_dialect()->now(), etc.)\n";
    return ['exit' => 1, 'stdout' => $stdout, 'stderr' => ''];
}

// CLI entry point: only runs when this file is invoked directly (not
// require'd by a test). PHP_SAPI === 'cli' + the include-guard pattern.
if (PHP_SAPI === 'cli' && realpath((string)($argv[0] ?? '')) === __FILE__) {
    $path = $argv[1] ?? (__DIR__ . '/../../Simple-PHP-IPAM/migrations.php');
    $result = ipam_run_migration_linter($path);
    if ($result['stderr'] !== '') {
        fwrite(STDERR, $result['stderr']);
    }
    if ($result['stdout'] !== '') {
        echo $result['stdout'];
    }
    exit($result['exit']);
}
