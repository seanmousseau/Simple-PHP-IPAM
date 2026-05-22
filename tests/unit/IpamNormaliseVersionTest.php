<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * v3.29.0 #896 (A28) — ipam_normalise_version() contract pin.
 *
 * The function in Simple-PHP-IPAM/lib.php is intentionally tiny:
 *
 *     function ipam_normalise_version(string $v): string
 *     {
 *         $v = ltrim($v, 'v');
 *         $parts = explode('.', $v);
 *         while (count($parts) < 3) $parts[] = '0';
 *         return implode('.', $parts);
 *     }
 *
 * Its sole consumer is version_compare() inside ipam_update_check()
 * (GitHub /releases poll). The contract this test pins is THE OBSERVED
 * BEHAVIOUR of the current implementation — not a wishlist. Several
 * cases here document genuinely surprising results (e.g. pre-release
 * suffixes are preserved unchanged because they happen to land in the
 * third dotted component, and "more than three parts" is left as-is).
 * If a future refactor "fixes" any of these by accident, the test will
 * fail and force a conscious decision.
 */
final class IpamNormaliseVersionTest extends TestCase
{
    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function versionProvider(): array
    {
        return [
            // Canonical semver passes through unchanged.
            'standard semver'        => ['1.2.3', '1.2.3'],
            // Leading 'v' is stripped (ltrim, so multiple v's would all go).
            'leading v stripped'     => ['v1.2.3', '1.2.3'],
            // Two-part versions get a trailing .0.
            'two-part gets .0'       => ['1.2', '1.2.0'],
            // One-part versions get two trailing .0's.
            'one-part gets .0.0'     => ['3', '3.0.0'],
            // Surprise: empty string. explode('.', '') returns [''], which
            // padding extends to ['', '0', '0'] and implode joins to '.0.0'
            // — note the leading dot, NOT '0.0.0'. This is the observed
            // behaviour; documented here so a refactor that "fixes" it
            // (e.g. by short-circuiting empty input) fails this test and
            // forces a coordinated callsite review.
            'empty becomes dot-0-0'  => ['', '.0.0'],
            // Pre-release suffix is preserved: explode('.', '1.2.3-rc1') →
            // ['1','2','3-rc1'] (3 parts → no padding). PHP's version_compare()
            // understands '-rc1' as a pre-release marker, so the suffix
            // travelling intact is load-bearing.
            'pre-release rc'         => ['1.2.3-rc1', '1.2.3-rc1'],
            'pre-release beta'       => ['1.2.3-beta.1', '1.2.3-beta.1'],
            // Pre-release on a two-part version: '1.2-rc1' splits to
            // ['1','2-rc1'] → padded to ['1','2-rc1','0']. The '-rc1'
            // attaches to the MINOR component, which is almost certainly
            // not what a caller expects. Documented here as the observed
            // behaviour; do not "fix" without coordinating callers.
            'pre-release on two-part'=> ['1.2-rc1', '1.2-rc1.0'],
            // Case is NOT folded — the implementation does no lowercasing.
            'uppercase RC preserved' => ['1.2.3-RC1', '1.2.3-RC1'],
            // Leading-zero numeric segments are preserved as strings; the
            // function never re-parses to int.
            'leading zero preserved' => ['1.02.3', '1.02.3'],
            // More than three components are passed through verbatim — the
            // padding loop only fires while count < 3.
            'four parts pass through'=> ['1.2.3.4', '1.2.3.4'],
            // Non-numeric input still survives the round-trip; ltrim('v')
            // only strips leading v's, so 'not-a-version' is unchanged and
            // padded to three parts.
            'garbage input padded'   => ['not-a-version', 'not-a-version.0.0'],
            // Double leading v: ltrim strips both.
            'double v stripped'      => ['vv1.0.0', '1.0.0'],
        ];
    }

    #[DataProvider('versionProvider')]
    public function testNormalisation(string $input, string $expected): void
    {
        $this->assertSame($expected, ipam_normalise_version($input));
    }

    /**
     * Sanity: the output of the function must always be usable with
     * version_compare(). This is the actual consumer in lib.php, so the
     * contract isn't just "produces this string" but "produces a string
     * version_compare can rank against another normalised string".
     */
    public function testNormalisedOutputsAreComparable(): void
    {
        $this->assertSame(-1, version_compare(
            ipam_normalise_version('1.2'),
            ipam_normalise_version('1.2.1')
        ));
        $this->assertSame(0, version_compare(
            ipam_normalise_version('v1.2.3'),
            ipam_normalise_version('1.2.3')
        ));
        // Pre-release sorts before the same-numbered release — this is
        // the consumer behaviour that motivated keeping the suffix intact.
        $this->assertSame(-1, version_compare(
            ipam_normalise_version('1.2.3-rc1'),
            ipam_normalise_version('1.2.3')
        ));
    }
}
