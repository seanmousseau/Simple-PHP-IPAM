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
 * Source-level contract test: oidc_callback.php's auto-prov branch
 * stores the sentinel verbatim. End-to-end behaviour is covered by the
 * Pass A regression on the test instance.
 */
final class OidcAutoProvSentinelTest extends TestCase
{
    public function testAutoProvUsesDisabledSentinelNotRealBcrypt(): void
    {
        $path = __DIR__ . '/../Simple-PHP-IPAM/oidc_callback.php';
        $contents = (string) file_get_contents($path);
        $this->assertNotEmpty($contents, 'oidc_callback.php must be readable');

        // Locate the auto-provision branch. Bracket it from the
        // `} elseif ($autoProvision) {` line through the matching close
        // before the next `} else {` or end-of-block.
        $start = strpos($contents, 'elseif ($autoProvision)');
        $this->assertNotFalse($start, 'auto-provision branch not found in oidc_callback.php');

        // Reasonable upper bound for the branch — looking 4000 chars ahead
        // is enough; the branch is ~50 lines today.
        $branch = substr($contents, $start, 4000);

        $this->assertStringContainsString(
            "'!disabled'",
            $branch,
            "#1120: auto-provision branch must store the '!disabled' sentinel into users.password_hash"
        );

        // Negative: forbid generating a real password hash here. The
        // pattern `password_hash(...PASSWORD_DEFAULT...)` was the pre-fix
        // mechanism that produced random-but-real bcrypt hashes.
        $this->assertDoesNotMatchRegularExpression(
            '/password_hash\s*\([^)]*PASSWORD_(DEFAULT|BCRYPT)/',
            $branch,
            "#1120: auto-provision branch must NOT call password_hash() — use the '!disabled' sentinel instead"
        );
    }
}
