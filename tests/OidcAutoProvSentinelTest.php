<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Bug U (Pass A 2026-05-08, v3.27.2 #1120) — OIDC auto-provisioner must
 * write the literal `'!disabled'` sentinel into users.password_hash, not
 * a real bcrypt hash.
 *
 * Pre-fix: oidc_callback.php at line 159 generated
 *   password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)
 * for auto-provisioned accounts. Functionally those random bytes are
 * unknown to the user so local-password login is impossible — but the
 * lockout-protection model and demo-seed convention (see
 * Simple-PHP-IPAM/demo_seed.php's header doc, and the `'!disabled'` rows
 * seeded for readonly-user/netops-user in lib.php) document the sentinel
 * as the canonical OIDC-only marker. Mixed conventions are a footgun:
 * future code that gates on `password_hash === '!disabled'` would see a
 * bcrypt hash for auto-provisioned users and treat them as having a
 * real password they don't know.
 *
 * Source-level contract test: v3.29.0 #1099 extracted the inline
 * auto-prov branch out of oidc_callback.php into oidc_provision_user()
 * in lib.php (closes around line 10460). The sentinel contract now
 * lives there; this test follows it.
 *
 * End-to-end behaviour is covered by the v3.29.0 #1099 unit suite
 * (tests/OidcClaimMappingTest::testProvisionUsesPreferredUsername etc.,
 * which assert the persisted row carries password_hash = '!disabled')
 * and by the Pass A regression on the test instance.
 */
final class OidcAutoProvSentinelTest extends TestCase
{
    public function testAutoProvUsesDisabledSentinelNotRealBcrypt(): void
    {
        $libPath = __DIR__ . '/../Simple-PHP-IPAM/lib.php';
        $lib = (string) file_get_contents($libPath);
        $this->assertNotEmpty($lib, 'lib.php must be readable');

        // Locate the oidc_provision_user() helper that owns the
        // auto-provision INSERT in v3.29.0+.
        $start = strpos($lib, 'function oidc_provision_user(');
        $this->assertNotFalse($start, 'oidc_provision_user() function not found in lib.php');

        // The function body is ~80 lines; 4000 chars of look-ahead
        // comfortably brackets it.
        $branch = substr($lib, $start, 4000);

        $this->assertStringContainsString(
            "'!disabled'",
            $branch,
            "#1120: oidc_provision_user() must store the '!disabled' sentinel into users.password_hash"
        );

        // Negative: the pre-fix mechanism (random bcrypt) must not return.
        $this->assertDoesNotMatchRegularExpression(
            '/password_hash\s*\([^)]*PASSWORD_(DEFAULT|BCRYPT)/',
            $branch,
            "#1120: oidc_provision_user() must NOT call password_hash() — use the '!disabled' sentinel instead"
        );

        // Also: oidc_callback.php must no longer contain inline password
        // hashing for the auto-prov path. The whole inline block was
        // replaced by a call to oidc_provision_user() in v3.29.0 #1099,
        // so any reintroduction of `password_hash(...PASSWORD_DEFAULT...)`
        // there would be a regression of #1120.
        $cbPath = __DIR__ . '/../Simple-PHP-IPAM/oidc_callback.php';
        $cb = (string) file_get_contents($cbPath);
        $this->assertNotEmpty($cb, 'oidc_callback.php must be readable');
        $this->assertDoesNotMatchRegularExpression(
            '/password_hash\s*\([^)]*PASSWORD_(DEFAULT|BCRYPT)/',
            $cb,
            "#1120/#1099: oidc_callback.php must not contain inline password_hash() — provisioning is delegated to oidc_provision_user()"
        );
    }
}
