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
     * Two lib/*.php files declaring the same global function name must be
     * flagged by the function-uniqueness rule (a copy-instead-of-move
     * extraction bug — a PHP "cannot redeclare" fatal at runtime).
     */
    public function testDuplicateFunctionIsReported(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/a.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module a\n */\n"
            . "function foo(): void {}\n");
        file_put_contents($tmp . '/lib/b.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module b\n */\n"
            . "function foo(): void {}\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=1', $raw, $raw);
        $this->assertStringContainsString('duplicate function', $raw);
        $this->assertStringContainsString('foo', $raw);
        $this->assertStringContainsString('a.php', $raw);
        $this->assertStringContainsString('b.php', $raw);
    }

    /**
     * Unique function names across lib/*.php, plus a class method that
     * happens to share a free function's name, must NOT be flagged — class
     * methods are not collected as free functions.
     */
    public function testUniqueFunctionsAndClassMethodsPass(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/a.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module a\n */\n"
            . "function a_one(): void {}\n"
            . "function &byref_one(): array { static \$a = []; return \$a; }\n"
            . "\$f = function () { return 1; };\n"
            . "\$g = function &() { static \$b = []; return \$b; };\n");
        file_put_contents($tmp . '/lib/b.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module b\n */\n"
            . "function b_one(): void {}\n");
        // A class file whose method shares a name with a free function.
        file_put_contents($tmp . '/lib/SomeClient.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module someclient\n */\n"
            . "class SomeClient {\n    public function a_one(): void {}\n"
            . "    public function b_one(): void {}\n}\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=0', $raw, $raw);
        $this->assertStringNotContainsString('duplicate function', $raw);
    }

    /**
     * Two lib/*.php files each declaring the same reference-return
     * function (`function &dupe_ref()`) must be flagged by the
     * function-uniqueness rule. Reference-return declarations put a `&`
     * between `function` and the name; the collector must still see them
     * — otherwise a copy-instead-of-move of a by-ref function (e.g.
     * `ipam_setting_cache_store()`) goes silently undetected.
     */
    public function testDuplicateReferenceReturnFunctionIsReported(): void
    {
        $tmp = sys_get_temp_dir() . '/lml_' . uniqid();
        mkdir($tmp . '/lib', 0700, true);
        file_put_contents($tmp . '/lib/a.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module a\n */\n"
            . "function &dupe_ref(): array { static \$a = []; return \$a; }\n");
        file_put_contents($tmp . '/lib/b.php',
            "<?php\ndeclare(strict_types=1);\n/**\n * @module b\n */\n"
            . "function &dupe_ref(): array { static \$b = []; return \$b; }\n");

        $cmd = sprintf('php %s --root=%s 2>&1; echo "RC=$?"',
            escapeshellarg(self::LINTER), escapeshellarg($tmp));
        $out = shell_exec($cmd);
        $raw = is_string($out) ? $out : '';
        $this->assertStringContainsString('RC=1', $raw, $raw);
        $this->assertStringContainsString('duplicate function', $raw);
        $this->assertStringContainsString('dupe_ref', $raw);
        $this->assertStringContainsString('a.php', $raw);
        $this->assertStringContainsString('b.php', $raw);
    }

    /**
     * The real Simple-PHP-IPAM/lib tree must pass all three rules.
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
