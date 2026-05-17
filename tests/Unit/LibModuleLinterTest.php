<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LibModuleLinterTest extends TestCase
{
    private const LINTER = __DIR__ . '/../../testing/scripts/lib-module-linter.php';

    public function testHeaderMissingIsReported(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/example.php', "<?php\nfunction foo(){}\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=1', $raw);
        $this->assertStringContainsString('header missing', $raw);
    }

    /**
     * A v3.30.0 module that require_once's a sibling lib/*.php file must
     * be flagged by the cross-module require ban.
     */
    public function testCrossModuleRequireIsReported(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/y.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module y\n */\n");
        file_put_contents($tmp . '/lib/x.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module x\n */\n"
            . "require_once __DIR__ . '/y.php';\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=1', $raw);
        $this->assertStringContainsString('cross-module require', $raw);
        $this->assertStringContainsString('y.php', $raw);
    }

    /**
     * A clean module whose only requires target non-lib/ paths must pass.
     */
    public function testNonLibRequiresPass(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/clean.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module clean\n */\n"
            . "require_once __DIR__ . '/../dialects/Dialect.php';\n"
            . "require_once dirname(__DIR__) . '/version.php';\n"
            . "include __DIR__ . '/../views/_partial.php';\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=0', $raw);
        $this->assertStringNotContainsString('cross-module require', $raw);
    }

    /**
     * A sibling-lib require that exists only inside comments (a `//` line
     * comment and a `/* ... *​/` block comment) must NOT be flagged. The
     * tokenizer-based rule ignores comment tokens — this is the regression
     * guard against the old raw-regex behaviour that scanned file text.
     */
    public function testCommentedOutRequireIsNotFlagged(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/y.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module y\n */\n");
        file_put_contents($tmp . '/lib/x.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module x\n */\n"
            . "// require_once __DIR__ . '/y.php';\n"
            . "/* require_once __DIR__ . '/y.php'; */\n"
            . "function x_thing(): void {}\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=0', $raw, $raw);
        $this->assertStringNotContainsString('cross-module require', $raw);
    }

    /**
     * The real Simple-PHP-IPAM/lib tree must pass both rules.
     */
    public function testRealRepoPasses(): void
    {
        $root = __DIR__ . '/../../Simple-PHP-IPAM';
        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($root));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=0', $raw, $raw);
    }
}
