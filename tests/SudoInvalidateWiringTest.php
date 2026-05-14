<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug T (Pass A 2026-05-08, v3.27.1) — every documented sudo-grant
 * invalidation event must call ipam_sudo_invalidate() at its handler
 * site. auth-model.md "Step-up auth" "Sudo grant invalidation" lists 11 events; the
 * Pass A audit found 6 had no caller. v3.27.1 wires the 3 self-action
 * sites (TOTP enroll, Email OTP enroll, passkey add). The 3 cross-user
 * sites (users.php role downgrade, oidc_sub link/unlink) are documented
 * limitations — see in-code comments and auth-model.md "Step-up auth" update.
 *
 * Source-level contract test. Asserts each handler file CONTAINS a
 * call to ipam_sudo_invalidate(). Locks the wiring against future
 * regressions.
 */
final class SudoInvalidateWiringTest extends TestCase
{
    /**
     * @return array<string,string>
     */
    private function selfActionHandlers(): array
    {
        return [
            // Site → human description
            'Simple-PHP-IPAM/change_password.php' => 'change_password (password change + TOTP/EmailOTP/passkey disable + email_otp enroll)',
            'Simple-PHP-IPAM/totp_enroll.php' => 'TOTP enrollment',
            'Simple-PHP-IPAM/passkey_register.php' => 'passkey registration',
            'Simple-PHP-IPAM/settings.php' => 'step-up policy save (sudo invalidate after policy change)',
        ];
    }

    public function testEverySelfActionHandlerCallsInvalidate(): void
    {
        $missing = [];
        foreach ($this->selfActionHandlers() as $relPath => $desc) {
            $abs = __DIR__ . '/../' . $relPath;
            $contents = (string) file_get_contents($abs);
            $this->assertNotEmpty($contents, "could not read $relPath");
            if (!str_contains($contents, 'ipam_sudo_invalidate(')) {
                $missing[] = "$relPath ($desc)";
            }
        }
        $this->assertEmpty(
            $missing,
            'Every self-action handler that mutates auth state must call '
            . 'ipam_sudo_invalidate() per auth-model.md "Step-up auth" contract. Missing in: '
            . implode(', ', $missing)
        );
    }

    /**
     * Specific assertions per fix from Bug T — exact events that v3.27.1
     * is wiring up. If any of these regress (someone removes the call),
     * this test names the specific contract violated.
     */
    public function testTotpEnrollInvalidatesGrant(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/totp_enroll.php');
        $this->assertMatchesRegularExpression(
            '/totp_enabled\s*=\s*1[\s\S]{0,400}ipam_sudo_invalidate\(\s*\)/',
            $src,
            'TOTP enrollment must call ipam_sudo_invalidate() after writing totp_enabled=1'
        );
    }

    public function testEmailOtpEnrollInvalidatesGrant(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/change_password.php');
        $this->assertMatchesRegularExpression(
            "/audit\\(\\\$db,\\s*'user\\.email_otp_enable'[\\s\\S]{0,400}ipam_sudo_invalidate\\(\\s*\\)/",
            $src,
            'Email OTP enrollment must call ipam_sudo_invalidate() after the user.email_otp_enable audit'
        );
    }

    public function testPasskeyRegisterInvalidatesGrant(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/passkey_register.php');
        $this->assertMatchesRegularExpression(
            "/audit\\(\\\$db,\\s*'mfa\\.passkey\\.register'[\\s\\S]{0,400}ipam_sudo_invalidate\\(\\s*\\)/",
            $src,
            'Passkey registration must call ipam_sudo_invalidate() after the mfa.passkey.register audit'
        );
    }
}
