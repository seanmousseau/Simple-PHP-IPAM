<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug X (Pass A 2026-05-08, v3.27.1) — every sudo-class handler must
 * call ipam_sudo_consume_once() after passing the gate.
 *
 * Pre-fix: the helper ipam_sudo_consume_once() existed at
 * lib/auth_step_up.php:91-94 with documented use ("Sensitive handlers
 * call this immediately after the gated action runs successfully so the
 * next sensitive action re-prompts.") — but ZERO handlers actually
 * called it. Repo-wide grep returned no callers. Effect: TTL=0 grants
 * persisted as session-long warm grants, weaker than even the 60s
 * timed grant. Documented stricter-security mode delivered LOOSER
 * security than its alternatives.
 *
 * Decision (locked 2026-05-08, Path Forward §1 step 4): consume always
 * after handler gate-pass — not "on success only". Strongest stance
 * for TTL=0 operators.
 *
 * Source-level contract tests: each handler file must contain
 * ipam_sudo_consume_once(). Locks the wiring against future refactors
 * that might silently drop the call. Pass B regression on the test
 * instance exercises actual session behaviour end-to-end.
 */
final class SudoConsumeOnceWiringTest extends TestCase
{
    /**
     * Map of handler file → human description for failure messages.
     *
     * @return array<string,string>
     */
    private function handlers(): array
    {
        return [
            'Simple-PHP-IPAM/api_keys.php' => 'API key create',
            'Simple-PHP-IPAM/change_password.php' => 'change_password disable_totp/email_otp/passkey',
            'Simple-PHP-IPAM/db_tools.php' => 'DB import',
            'Simple-PHP-IPAM/lib/backup_admin_destinations.php' => 'vault_reveal/set/replace',
            'Simple-PHP-IPAM/settings.php' => 'sensitive setting save (per-key + group)',
            'Simple-PHP-IPAM/settings_reveal.php' => 'sensitive setting reveal',
            // step_up.php is excluded — see the wider sweep test for the
            // documented exemption (it's the relay endpoint, not an action handler).
        ];
    }

    public function testEveryHandlerWithSudoRequireAlsoCallsConsumeOnce(): void
    {
        $missing = [];
        foreach ($this->handlers() as $relPath => $desc) {
            $abs = __DIR__ . '/../../' . $relPath;
            $contents = (string) file_get_contents($abs);
            $this->assertNotEmpty($contents, "could not read $relPath");

            $hasRequire = str_contains($contents, 'ipam_sudo_require(');
            $hasConsume = str_contains($contents, 'ipam_sudo_consume_once(');

            if ($hasRequire && !$hasConsume) {
                $missing[] = "$relPath ($desc)";
            }
        }

        $this->assertEmpty(
            $missing,
            "Handlers calling ipam_sudo_require() must also call ipam_sudo_consume_once() to honour TTL=0 'one-shot grant' policy. "
            . "Missing in: " . implode(', ', $missing)
        );
    }

    /**
     * Defence against silent regressions: if anyone adds a new
     * ipam_sudo_require() call site, this test reminds them to
     * pair it with consume_once. Greps the entire tree.
     */
    public function testNoSudoRequireWithoutMatchingConsumeOnceAcrossAppCode(): void
    {
        $appDir = realpath(__DIR__ . '/../../Simple-PHP-IPAM');
        $this->assertNotFalse($appDir);

        // Walk PHP files, skip vendor/ and tests/.
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS));
        $missing = [];
        foreach ($iter as $f) {
            if (!($f instanceof SplFileInfo)) continue;
            $path = $f->getPathname();
            if (!str_ends_with($path, '.php')) continue;
            if (str_contains($path, '/vendor/')) continue;
            if (str_contains($path, '/lib/auth_step_up.php')) continue; // helper definition itself
            // step_up.php is the GENERIC relay endpoint — operators land
            // here when a JS-driven sudo flow needs an out-of-band proof
            // (e.g., settings_reveal.php XHR redirected via a 401). The
            // actual action runs in a SEPARATE request on the original
            // page after the redirect; this page just mints the grant.
            // Consuming here would clear the warm grant before the real
            // action handler can use it. Exempt by design.
            if (str_ends_with($path, '/step_up.php')) continue;

            $contents = (string) file_get_contents($path);
            if (!str_contains($contents, 'ipam_sudo_require(')) continue;
            if (str_contains($contents, 'ipam_sudo_consume_once(')) continue;

            $missing[] = str_replace($appDir . '/', '', $path);
        }

        $this->assertEmpty(
            $missing,
            'New sudo-class handlers detected without ipam_sudo_consume_once() — Bug X (sudo_once consumption) family. '
            . 'Files: ' . implode(', ', $missing)
        );
    }
}
