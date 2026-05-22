<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../testing/scripts/lint-at-suppressions.php';

final class AtSuppressionLintTest extends TestCase
{
    public function testLinterFlagsUncommentedAtSuppression(): void
    {
        $result = lint_at_check_file(__DIR__ . '/fixtures/at-suppression/bad.php');
        $this->assertNotNull($result, 'linter must flag the un-justified @ in bad.php');
        $this->assertStringEndsWith('bad.php', $result['file']);
        $this->assertSame(5, $result['line']);
    }

    public function testLinterAcceptsCommentedAtSuppression(): void
    {
        $result = lint_at_check_file(__DIR__ . '/fixtures/at-suppression/good.php');
        $this->assertNull($result, 'linter must accept the justified @ in good.php');
    }
}
