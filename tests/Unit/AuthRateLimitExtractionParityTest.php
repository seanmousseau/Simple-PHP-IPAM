<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 6 Task 6.3 — verifies the login/IP rate-limiting and
 * account-lockout extraction from lib.php landed cleanly (#907). The
 * functions must (a) still exist in the global namespace and (b) be
 * declared in Simple-PHP-IPAM/lib/auth_rate_limit.php rather than lib.php
 * (proves the move was a real move, not a copy).
 *
 * Behavioural coverage of rate limiting and lockout lives in the auth test
 * suites and the 3-driver Playwright smoke. This file only enforces the
 * physical location of the code.
 *
 * Note: the core session/CSRF/login helpers (lib/auth.php), password
 * policy / reset helpers (lib/auth_password.php), and the reCAPTCHA
 * helpers (Task 6.4) deliberately stay out of this module — see the
 * negative assertions.
 */
final class AuthRateLimitExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function authRateLimitFunctions(): array
    {
        return [
            'auth_rate_limited',
            'record_auth_failure',
            'clear_auth_failures',
            'login_rate_limited',
            'auth_rate_limit_unlock_at',
            'ipam_audit_ip_rate_limited',
            'prune_rate_limit_dampener',
            'record_login_failure',
            'clear_login_failures',
            'account_locked_out',
            'clear_account_lockout',
            'purge_old_login_attempts',
            'ipam_api_key_rate_limit_check',
            'ipam_clear_persistent_lockout',
        ];
    }

    public function testAuthRateLimitFunctionsAreDefined(): void
    {
        foreach ($this->authRateLimitFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testAuthRateLimitFunctionsLiveInAuthRateLimitFile(): void
    {
        foreach ($this->authRateLimitFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/auth_rate_limit.php',
                (string)$declarer,
                "$fn should be declared in lib/auth_rate_limit.php, not " . (string)$declarer
            );
        }
    }

    public function testNonRateLimitHelpersStayOutOfAuthRateLimitFile(): void
    {
        // These belong to lib/auth.php (session/login) and
        // lib/auth_password.php (password policy/reset) and must NOT
        // have moved into lib/auth_rate_limit.php.
        $other = [
            'csrf_require',                 // core session/CSRF (lib/auth.php)
            'login_user',                   // session establishment (lib/auth.php)
            'validate_password_complexity', // password policy (lib/auth_password.php)
            'ipam_create_reset_token',      // password reset (lib/auth_password.php)
            'recovery_mode_enabled',        // config check, not a rate-limit concern (lib.php)
            'ipam_is_persistently_locked',  // persistent-lockout read, tied to MFA flow (lib.php)
            'ipam_record_2fa_failure',      // 2FA-failure recording, tied to MFA flow (lib.php)
        ];
        foreach ($other as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should still be defined");
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringNotContainsString(
                '/lib/auth_rate_limit.php',
                (string)$declarer,
                "$fn should NOT have moved to lib/auth_rate_limit.php"
            );
        }
    }
}
