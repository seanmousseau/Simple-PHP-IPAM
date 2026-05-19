<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * v3.33.0 A6 (#934) — Pin the no-throw + memoised contract of
 * ipam_app_base_url() and the boot-time validation of `base_url` in
 * ipam_validate_config().
 *
 * Before A6, ipam_app_base_url() threw a RuntimeException when base_url
 * was unset/invalid; callers (password reset, email verification)
 * swallowed it in try/catch, so a misconfigured base_url failed
 * silently. The function now returns '' instead, and ipam_validate_config()
 * surfaces a set-but-invalid base_url as an admin-visible warning.
 *
 * NOTE: ipam_app_base_url() memoises in a process-level static. Once it
 * has computed a value it never recomputes. Every test whose result
 * depends on the FIRST call landing on a particular config therefore runs
 * in a separate process (@runInSeparateProcess) with a cold memo, so the
 * suite is robust under any --order-by (including random) without relying
 * on PHPUnit's default alphabetical method order.
 */
class AppBaseUrlTest extends TestCase
{
    /** @var mixed */
    private $savedConfig;

    protected function setUp(): void
    {
        $this->savedConfig = $GLOBALS['config'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedConfig === null) {
            unset($GLOBALS['config']);
        } else {
            $GLOBALS['config'] = $this->savedConfig;
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testReturnsConfiguredHttpsUrlWithoutTrailingSlash(): void
    {
        $GLOBALS['config'] = ['base_url' => 'https://ipam.example.com/'];
        $this->assertSame('https://ipam.example.com', ipam_app_base_url());
    }

    /**
     * Genuinely proves memoisation: the first call computes from the valid
     * config; the config is then mutated to a DIFFERENT valid value; the
     * second call must still return the FIRST value. If the function
     * recomputed it would return the mutated URL, so an identical result
     * proves the memo — not the config — drove the second call. Runs in a
     * fresh process so the memo is guaranteed cold on the first call.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMemoisesResultAcrossCalls(): void
    {
        $GLOBALS['config'] = ['base_url' => 'https://first.example.com'];
        $first = ipam_app_base_url();
        $this->assertSame('https://first.example.com', $first);

        $GLOBALS['config'] = ['base_url' => 'https://mutated.example.com'];
        $second = ipam_app_base_url();
        $this->assertSame(
            'https://first.example.com',
            $second,
            'ipam_app_base_url() must return the memoised first value, not recompute from mutated config'
        );
    }

    /**
     * Genuinely exercises the empty-string / invalid path: a fresh process
     * guarantees the memo is cold, so this call really computes against an
     * empty base_url and proves no exception escapes.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDoesNotThrow(): void
    {
        $GLOBALS['config'] = ['base_url' => ''];
        $this->assertSame('', ipam_app_base_url());
    }

    public function testValidateConfigFlagsInvalidBaseUrl(): void
    {
        $warnings = ipam_validate_config(['base_url' => 'http://insecure.example.com']);
        $hit = array_filter($warnings, fn($w) => stripos($w, 'base_url') !== false);
        $this->assertNotEmpty($hit, 'non-https base_url must be flagged');
    }

    public function testValidateConfigFlagsMalformedBaseUrl(): void
    {
        $warnings = ipam_validate_config(['base_url' => 'not a url']);
        $hit = array_filter($warnings, fn($w) => stripos($w, 'base_url') !== false);
        $this->assertNotEmpty($hit, 'malformed base_url must be flagged');
    }

    public function testValidateConfigDoesNotFalsePositiveOnUnsetBaseUrl(): void
    {
        $warnings = ipam_validate_config([]);
        $hit = array_filter($warnings, fn($w) => stripos($w, 'base_url') !== false);
        $this->assertEmpty($hit, 'unset base_url is legitimately optional');
    }

    public function testValidateConfigAcceptsValidHttpsBaseUrl(): void
    {
        $warnings = ipam_validate_config(['base_url' => 'https://ipam.example.com']);
        $hit = array_filter($warnings, fn($w) => stripos($w, 'base_url') !== false);
        $this->assertEmpty($hit, 'valid https base_url must not be flagged');
    }
}
