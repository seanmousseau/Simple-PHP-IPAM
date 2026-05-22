<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 4 Task 4.2 — verifies the audit-layer extraction from lib.php
 * landed cleanly. The functions must (a) still exist in the global namespace
 * and (b) be declared in Simple-PHP-IPAM/lib/audit.php rather than lib.php
 * (proves the move was a real move, not a copy).
 *
 * audit() / audit_export() are exercised behaviourally by AuditLogTest and the
 * api.php /audit endpoint tests; prune_audit_log() (refactored under #912 to
 * use Dialect::with_append_only_bypass) keeps its behavioural coverage in the
 * 3-driver smoke. The two audit_filter_validate_* helpers are pure and are
 * checked behaviourally below.
 */
final class AuditExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function auditFunctions(): array
    {
        return [
            'audit_filter_validate_prefix',
            'audit_filter_validate_action',
            'audit',
            'audit_export',
            'prune_audit_log',
        ];
    }

    public function testAuditFunctionsAreDefined(): void
    {
        foreach ($this->auditFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testAuditFunctionsLiveInAuditFile(): void
    {
        foreach ($this->auditFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/audit.php',
                (string)$declarer,
                "$fn should be declared in lib/audit.php, not " . (string)$declarer
            );
        }
    }

    public function testAuditFilterPrefixesConstIsDefined(): void
    {
        $this->assertTrue(defined('AUDIT_FILTER_PREFIXES'));
        $this->assertIsArray(AUDIT_FILTER_PREFIXES);
        $this->assertContains('auth', AUDIT_FILTER_PREFIXES);
    }

    public function testAuditFilterValidatePrefixAllowlists(): void
    {
        // A known prefix passes through (with surrounding whitespace trimmed).
        $this->assertSame('auth', \audit_filter_validate_prefix('auth'));
        $this->assertSame('subnet', \audit_filter_validate_prefix('  subnet '));
        // Anything not on the allowlist collapses to ''.
        $this->assertSame('', \audit_filter_validate_prefix('not_a_prefix'));
        $this->assertSame('', \audit_filter_validate_prefix(''));
    }

    public function testAuditFilterValidateActionRequiresPrefixDotVerb(): void
    {
        // <prefix>.<verb> and multi-segment actions are accepted (#1100).
        $this->assertSame('auth.login', \audit_filter_validate_action('auth.login'));
        $this->assertSame('mfa.otp.fail', \audit_filter_validate_action('mfa.otp.fail'));
        // No dot, trailing dot, or uppercase are rejected.
        $this->assertSame('', \audit_filter_validate_action('login'));
        $this->assertSame('', \audit_filter_validate_action('auth.'));
        $this->assertSame('', \audit_filter_validate_action('Auth.Login'));
        $this->assertSame('', \audit_filter_validate_action(''));
    }
}
