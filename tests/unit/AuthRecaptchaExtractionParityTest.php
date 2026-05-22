<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 6 Task 6.4 — verifies the reCAPTCHA / login-protection
 * extraction from lib.php landed cleanly (#907). The functions must
 * (a) still exist in the global namespace and (b) be declared in
 * Simple-PHP-IPAM/lib/auth_recaptcha.php rather than lib.php (proves the
 * move was a real move, not a copy).
 *
 * Behavioural coverage of the CAPTCHA verify paths lives in the auth test
 * suites and the 3-driver Playwright smoke. This file only enforces the
 * physical location of the code.
 *
 * Note: the core session/CSRF/login helpers (lib/auth.php) and the
 * login/IP rate-limiting helpers (lib/auth_rate_limit.php) deliberately
 * stay out of this module — see the negative assertions.
 */
final class AuthRecaptchaExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function authRecaptchaFunctions(): array
    {
        return [
            'recaptcha_enterprise_verify',
            'recaptcha_expected_action_resolved',
            'login_protection_verify',
            'login_protection_widget_html',
            'login_protection_extra_csp',
        ];
    }

    public function testAuthRecaptchaFunctionsAreDefined(): void
    {
        foreach ($this->authRecaptchaFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testAuthRecaptchaFunctionsLiveInAuthRecaptchaFile(): void
    {
        foreach ($this->authRecaptchaFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/auth_recaptcha.php',
                (string)$declarer,
                "$fn should be declared in lib/auth_recaptcha.php, not " . (string)$declarer
            );
        }
    }

    public function testNonRecaptchaHelpersStayOutOfAuthRecaptchaFile(): void
    {
        // These belong to lib/auth.php (session/login) and
        // lib/auth_rate_limit.php (rate limiting/lockout) and must NOT
        // have moved into lib/auth_recaptcha.php.
        $other = [
            'csrf_require',          // core session/CSRF (lib/auth.php)
            'login_user',            // session establishment (lib/auth.php)
            'auth_rate_limited',     // login/IP rate limiting (lib/auth_rate_limit.php)
            'account_locked_out',    // account lockout (lib/auth_rate_limit.php)
        ];
        foreach ($other as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should still be defined");
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringNotContainsString(
                '/lib/auth_recaptcha.php',
                (string)$declarer,
                "$fn should NOT have moved to lib/auth_recaptcha.php"
            );
        }
    }
}
