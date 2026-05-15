<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LibModuleLinterTest extends TestCase
{
    public function testHeaderMissingIsReported(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/example.php', "<?php\nfunction foo(){}\n");

        $linter = __DIR__ . '/../../testing/scripts/lib-module-linter.php';
        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg($linter), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=1', $raw);
        $this->assertStringContainsString('header missing', $raw);
    }
}
