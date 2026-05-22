<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.35.0 (#1293) — Verifies that init.php has been thinned to ≤150 lines and
 * that each extracted bootstrap_*.php module exists and exposes its required
 * entry-point function.
 *
 * Structural (file / function existence) assertions live here.
 * Behavioural coverage of each module's logic lives in dedicated test classes
 * (BootstrapSessionTest, BootstrapDemoTest, BootstrapRuntimeTest).
 */
final class InitBootstrapTest extends TestCase
{
    public function testInitPhpUnderLineCap(): void
    {
        $lines = file(dirname(__DIR__) . '/Simple-PHP-IPAM/init.php');
        $this->assertNotFalse($lines);
        $this->assertLessThanOrEqual(
            150,
            count($lines),
            'init.php exceeded 150-line cap; extract more to lib/bootstrap_*.php'
        );
    }

    /** @return array<string, array{string, string}> */
    public static function bootstrapModules(): array
    {
        return [
            'session module'  => ['bootstrap_session',  'ipam_bootstrap_session'],
            'demo module'     => ['bootstrap_demo',     'ipam_bootstrap_demo_mode'],
            'runtime module'  => ['bootstrap_runtime',  'ipam_bootstrap_runtime_gates'],
            'dialect module'  => ['bootstrap_dialect',  'ipam_bootstrap_dialect'],
        ];
    }

    #[DataProvider('bootstrapModules')]
    public function testEachBootstrapModuleFileExists(string $module, string $func): void
    {
        $path = dirname(__DIR__) . "/Simple-PHP-IPAM/lib/{$module}.php";
        $this->assertFileExists($path, "{$module}.php must exist under lib/");
    }

    #[DataProvider('bootstrapModules')]
    public function testEachBootstrapModuleExposesEntryFunction(string $module, string $func): void
    {
        $path = dirname(__DIR__) . "/Simple-PHP-IPAM/lib/{$module}.php";
        if (!file_exists($path)) {
            $this->markTestSkipped("{$module}.php not yet created");
        }
        require_once $path;
        $this->assertTrue(
            function_exists($func),
            "{$module}.php must define {$func}()"
        );
    }
}
