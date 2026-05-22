<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * B31 (#950): `Simple-PHP-IPAM/lib/presentation.php` (consumed by
 * `page_header()` on every authenticated page) and
 * `Simple-PHP-IPAM/demo_gate.php` (its own `<head>` for the pre-login
 * gate) each declare a `$jsModules` array. They must stay byte-identical
 * — drift means one surface ships a different module set than the
 * other, which the v3.34.0 frontend-modularization split (#939) was
 * explicitly designed to prevent.
 *
 * Static-extracting both arrays via regex and asserting equality is a
 * cheap drift gate. The Playwright `frontend-modules.spec.ts` already
 * covers the page_header() side; this test pins the demo_gate.php side
 * without needing to spin up demo_mode in a dockerized harness.
 */
final class JsModulesParityTest extends TestCase
{
    /**
     * Read `$jsModules = [ … ];` out of $php and return the module
     * names (each quoted string element) in order.
     *
     * @return list<string>
     */
    private function extractJsModules(string $path): array
    {
        $php = (string) file_get_contents($path);
        $this->assertNotEmpty($php, "{$path} must be readable");

        if (!preg_match('/\$jsModules\s*=\s*\[(.*?)\];/s', $php, $m)) {
            $this->fail("{$path} does not contain a `\$jsModules = [ … ]` array literal");
        }
        $body = $m[1];

        // Strip line comments so module names aren't picked out of the
        // `// C01 — synchronous theme apply pre-paint` annotations.
        $body = preg_replace('~//[^\n]*~', '', $body) ?? $body;

        if (!preg_match_all("/'([a-z0-9-]+)'/", $body, $mm)) {
            $this->fail("{$path} `\$jsModules` array contains no quoted module names");
        }
        return $mm[1];
    }

    public function testPageHeaderAndDemoGateShareTheSameModuleList(): void
    {
        $presentation = $this->extractJsModules(__DIR__ . '/../../Simple-PHP-IPAM/lib/presentation.php');
        $demoGate     = $this->extractJsModules(__DIR__ . '/../../Simple-PHP-IPAM/demo_gate.php');

        $this->assertNotEmpty($presentation, 'lib/presentation.php $jsModules must be non-empty');
        $this->assertSame(
            $presentation,
            $demoGate,
            '#950 B31: lib/presentation.php and demo_gate.php $jsModules arrays must stay byte-identical. '
            . "Drift means the gate page ships a different module set than the authenticated surface. "
            . 'If you added a module, register it in BOTH files.'
        );
    }
}
