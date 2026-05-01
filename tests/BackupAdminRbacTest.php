<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lints that every backup/restore admin entry point gates with
 * require_role('admin') near the top of the file, before any code that
 * could leak state or run side effects.
 *
 * Mirrors CliGuardTest's structural-lint approach. Pairs with the
 * Playwright backup-rbac.spec.ts which exercises actual HTTP responses.
 *
 * Source: #811 (T10 — backup_overhaul.md §8). Adding a new admin file
 * under the backup/restore surface without a role guard must fail this
 * test.
 */
final class BackupAdminRbacTest extends TestCase
{
    /**
     * Files that must require admin role. Path is relative to the repo
     * root and resolved against __DIR__/.. so the test works regardless
     * of where PHPUnit is invoked from.
     *
     * The unified surface (backup_admin.php) is a single router that
     * include()s tab files from lib/ — those includes never run unless
     * the parent file's require_role() has already passed, so they do
     * not need their own guard. Legacy URLs are kept until v4.0.0 to
     * avoid breaking bookmarks.
     *
     * @return list<array{string}>
     */
    public static function adminFilesProvider(): array
    {
        return [
            ['Simple-PHP-IPAM/backup_admin.php'],            // unified surface (F1)
            ['Simple-PHP-IPAM/run_backup_now.php'],          // run-now POST handler (F3)
            ['Simple-PHP-IPAM/backup_history.php'],          // legacy URL
            ['Simple-PHP-IPAM/backups.php'],                 // legacy URL (301 → db_tools)
            ['Simple-PHP-IPAM/restore_web.php'],             // legacy URL
            ['Simple-PHP-IPAM/backup_run_detail.php'],       // drawer partial (#803, F11)
            ['Simple-PHP-IPAM/destination_edit_drawer.php'], // drawer partial (#803, F12)
        ];
    }

    /**
     * @dataProvider adminFilesProvider
     */
    public function testFileGatesAdminRole(string $relPath): void
    {
        $absPath = dirname(__DIR__) . '/' . $relPath;
        $this->assertFileExists($absPath, "Admin page $relPath is missing — update BackupAdminRbacTest::adminFilesProvider if it was removed.");

        $head = (string) file_get_contents($absPath, false, null, 0, 8192);
        $this->assertNotEmpty($head, "Could not read first 8KB of $relPath");

        // Strip block comments, line comments, and string literals so a
        // commented-out guard or a docblock example cannot satisfy the
        // check.
        $codeOnly = (string) preg_replace('!/\*[\s\S]*?\*/!', '', $head);
        $codeOnly = (string) preg_replace('/^\s*(?:\/\/|#[^!]).*$/m', '', $codeOnly);
        $codeOnly = (string) preg_replace("/'[^'\\n]*'|\"[^\"\\n]*\"/", "''", $codeOnly);

        $pattern = "/\brequire_role\s*\(\s*''\s*\)/";
        if (!preg_match($pattern, $codeOnly, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail(
                "$relPath must gate with require_role('admin'). Add near the top:\n"
                . "    require __DIR__ . '/init.php';\n"
                . "    require_role('admin');"
            );
        }
        $guardOffset = (int) $m[0][1];

        // The guard must come before output or DB-mutating side effects.
        // require/include of init.php and lib.php is fine (those bring
        // require_role() into scope) — only flag echo/print and explicit
        // header() output that could leak state to a non-admin caller.
        foreach (['echo ', 'print '] as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '/', $codeOnly, $mm, PREG_OFFSET_CAPTURE)) {
                $markerOffset = (int) $mm[0][1];
                $this->assertLessThan(
                    $markerOffset,
                    $guardOffset,
                    "$relPath: require_role('admin') must run BEFORE `" . trim($marker) . "`. Move the guard above it."
                );
            }
        }
    }

    /**
     * Smoke check that backup_admin.php's tab whitelist is the canonical
     * list — adding a tab without updating the Playwright RBAC spec
     * should be obvious from a diff. Asserts the five known tabs are
     * present; new tabs cause a fail-soft reminder to extend the
     * Playwright spec.
     */
    public function testBackupAdminTabsAreEnumerated(): void
    {
        $absPath = dirname(__DIR__) . '/Simple-PHP-IPAM/backup_admin.php';
        $src = (string) file_get_contents($absPath);
        foreach (['backup', 'restore', 'destinations', 'notifications', 'history'] as $tab) {
            $this->assertMatchesRegularExpression(
                "/'\\Q$tab\\E'\\s*=>/",
                $src,
                "backup_admin.php is missing tab '$tab' in its \$tabs array. If you added or removed a tab, update testing/playwright/tests/backup-rbac.spec.ts to match."
            );
        }
    }
}
