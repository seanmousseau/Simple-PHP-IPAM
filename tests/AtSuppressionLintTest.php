<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AtSuppressionLintTest extends TestCase
{
    /**
     * Runs the linter against a fixture file.
     *
     * @return array{0:string,1:int} stdout+stderr and exit code
     */
    private function runLinter(string $fixtureRelative): array
    {
        $rootDir = dirname(__DIR__);
        $script = $rootDir . '/testing/scripts/lint-at-suppressions.php';
        $fixture = __DIR__ . '/' . $fixtureRelative;

        $desc = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Test harness: paths constrained to __DIR__ constant + static fixture paths
        // nosemgrep: CWE-94 — test code only, not user input
        $proc = proc_open(
            ['php', $script, $fixture],
            $desc,
            $pipes
        );

        if (!is_resource($proc)) {
            throw new RuntimeException('Failed to start linter process');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $out = $stdout . $stderr;

        foreach ($pipes as $p) {
            fclose($p);
        }

        return [$out, proc_close($proc)];
    }

    public function testLinterFlagsUncommentedAtSuppression(): void
    {
        [$out, $code] = $this->runLinter('fixtures/at-suppression/bad.php');

        $this->assertStringContainsString('bad.php', $out, 'linter must name the offending file');
        $this->assertNotSame(0, $code, 'linter must exit non-zero on un-justified @');
    }

    public function testLinterAcceptsCommentedAtSuppression(): void
    {
        [$_out, $code] = $this->runLinter('fixtures/at-suppression/good.php');

        $this->assertSame(0, $code);
    }
}
