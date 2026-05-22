<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for validate_password_complexity() — password policy enforcement (#686/#716).
 * Pure function tests; no DB or session required.
 */
class PasswordPolicyTest extends TestCase
{
    // ── Minimum length ────────────────────────────────────────────────────────

    public function testMinLengthPass(): void
    {
        $errors = validate_password_complexity('abcdefgh', ['min_length' => 8]);
        $this->assertEmpty($errors);
    }

    public function testMinLengthFail(): void
    {
        $errors = validate_password_complexity('abcdefg', ['min_length' => 8]);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('8', $errors[0]);
    }

    public function testMinLengthExactBoundaryFails(): void
    {
        // 3 chars, min=4 → should fail
        $errors = validate_password_complexity('abc', ['min_length' => 4]);
        $this->assertNotEmpty($errors);
    }

    public function testMinLengthExactBoundaryPasses(): void
    {
        $errors = validate_password_complexity('abcd', ['min_length' => 4]);
        $this->assertEmpty($errors);
    }

    // ── Uppercase ─────────────────────────────────────────────────────────────

    public function testUppercaseRequiredMissing(): void
    {
        $errors = validate_password_complexity('password1!', ['min_length' => 1, 'require_uppercase' => true]);
        $this->assertNotEmpty($errors);
    }

    public function testUppercaseRequiredPresent(): void
    {
        $errors = validate_password_complexity('Password1!', ['min_length' => 1, 'require_uppercase' => true]);
        $this->assertEmpty($errors);
    }

    public function testUppercaseNotRequiredIgnored(): void
    {
        $errors = validate_password_complexity('alllowercase', ['min_length' => 1, 'require_uppercase' => false]);
        $this->assertEmpty($errors);
    }

    // ── Lowercase ─────────────────────────────────────────────────────────────

    public function testLowercaseRequiredMissing(): void
    {
        $errors = validate_password_complexity('PASSWORD1!', ['min_length' => 1, 'require_lowercase' => true]);
        $this->assertNotEmpty($errors);
    }

    public function testLowercaseRequiredPresent(): void
    {
        $errors = validate_password_complexity('PASSWORD1p', ['min_length' => 1, 'require_lowercase' => true]);
        $this->assertEmpty($errors);
    }

    // ── Numbers ───────────────────────────────────────────────────────────────

    public function testNumberRequiredMissing(): void
    {
        $errors = validate_password_complexity('NoNumbers!', ['min_length' => 1, 'require_number' => true]);
        $this->assertNotEmpty($errors);
    }

    public function testNumberRequiredPresent(): void
    {
        $errors = validate_password_complexity('Password1', ['min_length' => 1, 'require_number' => true]);
        $this->assertEmpty($errors);
    }

    // ── Special characters ────────────────────────────────────────────────────

    public function testSymbolRequiredMissing(): void
    {
        $errors = validate_password_complexity('Password1', ['min_length' => 1, 'require_symbol' => true]);
        $this->assertNotEmpty($errors);
    }

    public function testSymbolRequiredPresent(): void
    {
        $errors = validate_password_complexity('Password1!', ['min_length' => 1, 'require_symbol' => true]);
        $this->assertEmpty($errors);
    }

    // ── Combined requirements ─────────────────────────────────────────────────

    public function testAllRequirementsPass(): void
    {
        $policy = [
            'min_length'        => 12,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_number'    => true,
            'require_symbol'    => true,
        ];
        $errors = validate_password_complexity('Passw0rd!xYz', $policy);
        $this->assertEmpty($errors);
    }

    public function testMultipleFailuresReturnsAllErrors(): void
    {
        $policy = [
            'min_length'        => 20,
            'require_uppercase' => true,
            'require_number'    => true,
            'require_symbol'    => true,
        ];
        $errors = validate_password_complexity('short', $policy);
        // Should fail on length + uppercase + number + symbol = at least 2 errors
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    public function testNoRequirementsAnyPasswordPasses(): void
    {
        $errors = validate_password_complexity('x', ['min_length' => 1]);
        $this->assertEmpty($errors);
    }

    // ── Edge cases ─────────────────────────────────────────────────────────────

    public function testEmptyPasswordFailsMinLength(): void
    {
        $errors = validate_password_complexity('', ['min_length' => 1]);
        $this->assertNotEmpty($errors);
    }

    public function testDefaultMinLengthIsEnforced(): void
    {
        // validate_password_complexity uses max(1, $policy['min_length'] ?? 12)
        // empty policy → min_length defaults to 12; 'short' (5 chars) should fail
        $errors = validate_password_complexity('short', []);
        $this->assertNotEmpty($errors, 'Empty policy should still enforce default min length of 12');
    }

    public function testUnicodeLengthCounting(): void
    {
        // mb_strlen counts characters, not bytes — 8 unicode chars should satisfy min_length=8
        $errors = validate_password_complexity('pássword', ['min_length' => 8]);
        $this->assertEmpty($errors);
    }
}
