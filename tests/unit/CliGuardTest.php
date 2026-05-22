<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Asserts that every CLI-only entry point rejects web invocation by
 * checking PHP_SAPI / php_sapi_name() at the top of the file.
 *
 * This is the project's enforcement of the rule documented in CLAUDE.md
 * "Security patterns": cron.php, scan_run.php, migrate.php, demo_seed.php,
 * demo_reset.php, and tmp_cleanup.php must never run under a web SAPI.
 *
 * Issue #381 originally proposed a Semgrep rule, but Semgrep cannot cleanly
 * express "this file MUST contain pattern X near the top" — it expresses
 * "this match MUST NOT contain Y". A PHPUnit test that lints the first 20
 * lines of each file is deterministic, easy to debug, and runs in the same
 * Tier 1 pipeline as Semgrep.
 */
final class CliGuardTest extends TestCase
{
    /**
     * Files that must not run under a web SAPI. The path is relative to the
     * repo root and resolved against __DIR__/.. so the test works regardless
     * of where PHPUnit is invoked from.
     *
     * @return list<array{string}>
     */
    public static function cliFilesProvider(): array
    {
        return [
            ['Simple-PHP-IPAM/cron.php'],
            ['Simple-PHP-IPAM/scan_run.php'],
            ['Simple-PHP-IPAM/migrate.php'],
            ['Simple-PHP-IPAM/demo_seed.php'],
            ['Simple-PHP-IPAM/demo_reset.php'],
            ['Simple-PHP-IPAM/tmp_cleanup.php'],
        ];
    }

    /**
     * @dataProvider cliFilesProvider
     */
    public function testCliFileHasSapiGuard(string $relPath): void
    {
        $absPath = dirname(dirname(__DIR__)) . '/' . $relPath;
        $this->assertFileExists($absPath, "CLI script $relPath is missing — update CliGuardTest::cliFilesProvider if it was removed.");

        // 8KB is large enough to clear any reasonable docblock (demo_seed.php
        // has a ~40-line header docblock and the guard is on line 43).
        $head = (string) file_get_contents($absPath, false, null, 0, 8192);
        $this->assertNotEmpty($head, "Could not read first 8KB of $relPath");

        // Strip block comments, line comments, and single-line string literals
        // before matching so a commented-out guard or a docblock example cannot
        // satisfy the check. A bare token mention is NOT enough — the match
        // must be the full `if (PHP_SAPI !== 'cli')` statement.
        $codeOnly = (string) preg_replace('!/\*[\s\S]*?\*/!', '', $head);
        $codeOnly = (string) preg_replace('/^\s*(?:\/\/|#[^!]).*$/m', '', $codeOnly);
        $codeOnly = (string) preg_replace("/'[^'\\n]*'|\"[^\"\\n]*\"/", "''", $codeOnly);

        $pattern = '/\bif\s*\(\s*(?:PHP_SAPI|php_sapi_name\s*\(\s*\))\s*!==?\s*\'\'/';
        if (!preg_match($pattern, $codeOnly, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail(
                "$relPath must reject web invocation. Add at the top:\n"
                . "    if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }"
            );
        }
        $guardOffset = (int) $m[0][1];

        // The guard must come before any of a short list of real side effects:
        // require statements for app code, database opens, direct output.
        // `declare(strict_types=1);` and bare `use`/`function` declarations
        // are fine — those don't execute user-visible work.
        foreach (['require ', 'require_once', 'include ', 'include_once', '\\$db\s*=', '\\$config\s*=', 'echo ', 'print '] as $marker) {
            if (preg_match('/' . $marker . '/', $codeOnly, $mm, PREG_OFFSET_CAPTURE)) {
                $markerOffset = (int) $mm[0][1];
                $this->assertLessThan(
                    $markerOffset,
                    $guardOffset,
                    "$relPath: SAPI guard must run BEFORE `" . trim($marker, '\\') . "`. Move the `if (PHP_SAPI !== 'cli')` block above it."
                );
            }
        }
    }
}
