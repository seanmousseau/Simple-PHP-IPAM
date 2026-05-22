<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 6 Task 6.2 — verifies the password-complexity and
 * password-reset token/email extraction from lib.php landed cleanly (#907).
 * The functions must (a) still exist in the global namespace and (b) be
 * declared in Simple-PHP-IPAM/lib/auth_password.php rather than lib.php
 * (proves the move was a real move, not a copy).
 *
 * Behavioural coverage of password policy and reset flows lives in the auth
 * test suites and the 3-driver Playwright smoke. This file only enforces the
 * physical location of the code.
 *
 * Note: ipam_argon2id_derive (backup-vault KDF), ipam_app_base_url (generic
 * URL helper), and ipam_send_email_verification (email-change verification)
 * deliberately stay behind in lib.php — see the negative assertions.
 */
final class AuthPasswordExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function authPasswordFunctions(): array
    {
        return [
            'validate_password_complexity',
            'ipam_create_reset_token',
            'ipam_consume_reset_token',
            'ipam_send_reset_email',
        ];
    }

    public function testAuthPasswordFunctionsAreDefined(): void
    {
        foreach ($this->authPasswordFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testAuthPasswordFunctionsLiveInAuthPasswordFile(): void
    {
        foreach ($this->authPasswordFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/auth_password.php',
                (string)$declarer,
                "$fn should be declared in lib/auth_password.php, not " . (string)$declarer
            );
        }
    }

    public function testMiscategorizedHelpersStayInLibPhp(): void
    {
        // These were NOT part of Task 6.2 and must NOT have moved into
        // lib/auth_password.php: the backup-vault KDF, the generic
        // canonical-URL helper, and the email-change verification sender.
        $deferred = [
            'ipam_argon2id_derive',         // backup-vault KDF (IPAMBKP3 #836)
            'ipam_app_base_url',            // generic canonical-URL helper
            'ipam_send_email_verification', // email-change verification
        ];
        foreach ($deferred as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should still be defined");
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringNotContainsString(
                '/lib/auth_password.php',
                (string)$declarer,
                "$fn should NOT have moved to lib/auth_password.php"
            );
        }
    }
}
