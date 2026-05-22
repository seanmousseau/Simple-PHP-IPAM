<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 6 -- verifies the core session/CSRF/login extraction from
 * lib.php landed cleanly (#907). The functions must (a) still exist in the
 * global namespace and (b) be declared in Simple-PHP-IPAM/lib/auth.php rather
 * than lib.php (proves the move was a real move, not a copy).
 *
 * Behavioural coverage of login/session/CSRF lives in the auth test suites and
 * the 3-driver Playwright smoke. This file only enforces the physical location
 * of the code.
 *
 * All four Phase 6 modules are now complete:
 *  - Task 6.1: session/CSRF/login -- lib/auth.php (asserted positively below)
 *  - Task 6.2: password policy/reset -- lib/auth_password.php
 *  - Task 6.3: rate limiting + lockout -- lib/auth_rate_limit.php
 *  - Task 6.4: reCAPTCHA + login-protection -- lib/auth_recaptcha.php
 *
 * The negative assertions below confirm that the rate-limit and reCAPTCHA
 * functions did NOT land in lib/auth.php (they live in their own modules).
 */
final class AuthExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function authFunctions(): array
    {
        return [
            // CSRF.
            'csrf_token',
            'csrf_require',
            // Auth / RBAC.
            'is_logged_in',
            'ipam_post_login_redirect_stash',
            'ipam_post_login_redirect_consume',
            'require_login',
            'current_user',
            'require_role',
            'require_write_access',
            'login_user',
            'logout_user',
            'client_ip',
            // Session absolute-lifetime enforcement.
            'ipam_session_enforce_absolute_lifetime',
        ];
    }

    public function testAuthFunctionsAreDefined(): void
    {
        foreach ($this->authFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testAuthFunctionsLiveInAuthFile(): void
    {
        foreach ($this->authFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/auth.php',
                (string)$declarer,
                "$fn should be declared in lib/auth.php, not " . (string)$declarer
            );
        }
    }

    public function testRateLimitRecaptchaNotInAuthPhp(): void
    {
        // These functions live in their own Phase 6 modules (Tasks 6.3-6.4)
        // and must NOT be declared in lib/auth.php.
        $notInAuthPhp = [
            'login_rate_limited',            // Task 6.3 -- lib/auth_rate_limit.php
            'account_locked_out',            // Task 6.3 -- lib/auth_rate_limit.php
            'recaptcha_enterprise_verify',   // Task 6.4 -- lib/auth_recaptcha.php
        ];
        foreach ($notInAuthPhp as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should still be defined");
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringNotContainsString(
                '/lib/auth.php',
                (string)$declarer,
                "$fn should NOT have moved to lib/auth.php"
            );
        }
    }
}
