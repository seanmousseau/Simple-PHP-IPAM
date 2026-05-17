<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 6 Task 6.1 — verifies the core session/CSRF/login extraction
 * from lib.php landed cleanly (#907). The functions must (a) still exist in the
 * global namespace and (b) be declared in Simple-PHP-IPAM/lib/auth.php rather
 * than lib.php (proves the move was a real move, not a copy).
 *
 * Behavioural coverage of login/session/CSRF lives in the auth test suites and
 * the 3-driver Playwright smoke. This file only enforces the physical location
 * of the code.
 *
 * Note: rate-limiting (Task 6.3) and reCAPTCHA (Task 6.4) functions are still
 * deferred and must not have moved to lib/auth.php — see the negative assertions.
 * Task 6.2 (password) is complete; validate_password_complexity now lives in
 * lib/auth_password.php.
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

    public function testRateLimitRecaptchaStayInLibPhp(): void
    {
        // These belong to Tasks 6.3–6.4 and must NOT have moved with this task.
        // Task 6.2 (validate_password_complexity) is complete — it now lives in
        // lib/auth_password.php and is no longer asserted here.
        $deferred = [
            'login_rate_limited',            // Task 6.3 — rate limiting
            'account_locked_out',            // Task 6.3 — rate limiting
            'recaptcha_enterprise_verify',   // Task 6.4 — reCAPTCHA
        ];
        foreach ($deferred as $fn) {
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
