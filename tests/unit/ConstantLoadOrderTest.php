<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #662 / #666.
 *
 * IPAM_VERSION must be defined at lib.php module scope — NOT only inside
 * page_footer() or ipam_webhook_deliver(). Before the fix, AJAX exit-early
 * paths (webhooks.php test_fire, health.php cache builder) reached
 * IPAM_VERSION before any lazy-loading function ran, causing HTTP 500.
 *
 * This test is meaningful because after Task 1 Step 3 we remove the explicit
 * `require version.php` from tests/bootstrap.php — from that point on, the
 * constant is only available if lib.php itself loads it at module scope.
 */
class ConstantLoadOrderTest extends TestCase
{
    public function testIpamVersionDefinedAtModuleScope(): void
    {
        // lib.php is loaded by bootstrap.php. After Step 3 removes the
        // explicit version.php load from bootstrap.php, this assertion only
        // passes if lib.php itself loads version.php at its own module scope.
        $this->assertTrue(
            defined('IPAM_VERSION'),
            'IPAM_VERSION must be defined by lib.php at module scope, not only inside page_footer(). '
            . 'Root cause of #662: test_fire in webhooks.php exits before page_footer() is called.'
        );
    }

    public function testIpamVersionIsSemanticVersion(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            IPAM_VERSION,
            'IPAM_VERSION must follow semver "MAJOR.MINOR.PATCH" — any other value (including "?") is a bug'
        );
    }
}
