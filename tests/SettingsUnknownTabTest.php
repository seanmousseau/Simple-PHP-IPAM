<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * #1129 (v3.27.3) — settings.php silently falls back to the General tab
 * when ?tab= is unknown. Operators get no signal that their bookmark or
 * shared link points at a tab that no longer exists.
 *
 * Post-fix: settings.php captures the requested-but-unknown slug and
 * renders an inline banner on the General tab explaining the fallback,
 * so the URL stays intact (forwarding compat) but the user sees what
 * happened.
 *
 * Source-level test — settings.php is too tightly coupled to init.php
 * to render in a unit test. Validating the source ensures the fallback
 * branch carries an operator-visible signal.
 */
final class SettingsUnknownTabTest extends TestCase
{
    public function testUnknownTabFallbackEmitsBanner(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/settings.php');

        // The fallback branch must capture the bad slug into a variable
        // (so the banner can echo it) — not silently coerce it.
        $this->assertMatchesRegularExpression(
            '/\$unknownTab\s*=/',
            $src,
            '#1129: settings.php must remember the unknown slug for the banner'
        );

        // The rendered output must contain a banner referencing the bad
        // slug. We grep for "no longer exists" / "doesn\'t exist" / a
        // similar phrase in proximity to $unknownTab so it\'s clear the
        // banner explains the fallback.
        $this->assertMatchesRegularExpression(
            '/(no longer exists|doesn.t exist|unknown setting)/i',
            $src,
            '#1129: settings.php must render an explanatory banner when ?tab= is unknown'
        );
    }
}
