<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';

/**
 * v3.29.0 #887 — Pin the reCAPTCHA Enterprise verify contract.
 *
 * Two production helpers are covered:
 *
 *   - `recaptcha_enterprise_verify(string $token, string $siteKey, array $cfg): ?string`
 *     returns null on pass, error string on fail, null on network error
 *     (fail-open). The HTTP-call-dependent branches (score threshold,
 *     action match, invalid-token rejection) require a mock HTTP server
 *     to drive deterministically and are NOT covered here — see the
 *     in-class comment for the rationale. The misconfiguration branch
 *     IS covered: empty `project_id` or `api_key` returns null without
 *     attempting the HTTP call.
 *
 *   - `recaptcha_expected_action_resolved(): string`
 *     resolves the v3 expected-action name via the documented
 *     precedence: legacy `$config['recaptcha_action']` wins; otherwise
 *     the v2.6.0+ registry key `recaptcha_enterprise.expected_action`;
 *     otherwise the hard-coded fallback `'login'`. Pinned here so a
 *     future refactor that swaps the precedence (or loses the legacy
 *     escape hatch) fails noisily — operator-facing because mismatched
 *     action emission vs. verification check breaks every Enterprise
 *     verify call silently.
 *
 * Coverage gap (deliberate): the response-parsing decision logic
 * (invalid token → reject, action mismatch → reject, score below
 * threshold → reject, otherwise pass) is gated behind a
 * `file_get_contents()` POST to googleapis.com. Driving it
 * deterministically requires a stream-context wrapper or a small
 * extraction. The v3.29.0 plan explicitly forbids extraction-for-test;
 * the response-parsing path is exercised end-to-end by the
 * `login.spec.ts` Playwright suite against a stubbed reCAPTCHA
 * response in the CI test environment. Per-operator manual smoke is
 * the documented coverage there.
 */
final class RecaptchaV3VerifyTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private $savedConfig = null;
    private bool $hadSavedConfig = false;

    protected function setUp(): void
    {
        $this->hadSavedConfig = array_key_exists('config', $GLOBALS);
        $this->savedConfig    = $this->hadSavedConfig ? $GLOBALS['config'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->hadSavedConfig) {
            $GLOBALS['config'] = $this->savedConfig;
        } else {
            unset($GLOBALS['config']);
        }
        // recaptcha_expected_action_resolved() now reads via ipam_config()
        // (ADR-004 Task 6.4 / ADR-003). In-place reassignment of
        // $GLOBALS['config'] to an array with identical count+keys does not
        // change the auto-detect fingerprint, so the explicit cache
        // invalidation is required between fixtures.
        ipam_config_invalidate_cache();
    }

    // ---- recaptcha_enterprise_verify fail-open paths ----

    public function testVerifyReturnsNullWhenProjectIdEmpty(): void
    {
        // Misconfiguration (empty project_id) MUST fail open — the
        // alternative is blocking every login because a CAPTCHA
        // provider is configured incorrectly. Verified at lib.php:7036.
        $result = recaptcha_enterprise_verify(
            'sometoken',
            'sitekey',
            ['enabled' => true, 'project_id' => '', 'api_key' => 'k', 'expected_action' => 'login', 'score_threshold' => 0.5]
        );
        $this->assertNull($result);
    }

    public function testVerifyReturnsNullWhenApiKeyEmpty(): void
    {
        $result = recaptcha_enterprise_verify(
            'sometoken',
            'sitekey',
            ['enabled' => true, 'project_id' => 'p', 'api_key' => '', 'expected_action' => 'login', 'score_threshold' => 0.5]
        );
        $this->assertNull($result);
    }

    public function testVerifyReturnsNullWhenBothMissing(): void
    {
        $result = recaptcha_enterprise_verify(
            'sometoken',
            'sitekey',
            ['enabled' => true, 'project_id' => '', 'api_key' => '', 'expected_action' => 'login', 'score_threshold' => 0.5]
        );
        $this->assertNull($result);
    }

    // ---- recaptcha_expected_action_resolved precedence ----

    public function testActionResolvedDefaultsToLoginWhenNothingSet(): void
    {
        $GLOBALS['config'] = []; // No legacy key.
        ipam_config_invalidate_cache();
        // Production also reads ipam_setting('recaptcha_enterprise.expected_action')
        // which returns the schema default (empty) when no setting is configured.
        // Without a configured DB-backed setting, the helper must fall through
        // to the literal 'login'.
        $this->assertSame('login', recaptcha_expected_action_resolved());
    }

    public function testActionResolvedUsesLegacyConfigKeyWhenPresent(): void
    {
        // Legacy escape hatch: $config['recaptcha_action'] wins over the
        // registry key. Documented behaviour since #289.
        $GLOBALS['config'] = ['recaptcha_action' => 'custom_legacy_action'];
        ipam_config_invalidate_cache();
        $this->assertSame('custom_legacy_action', recaptcha_expected_action_resolved());
    }

    public function testActionResolvedEmptyLegacyKeyFallsThrough(): void
    {
        // An empty string in the legacy slot must NOT be treated as
        // "operator opted out" — it falls through to the registry and
        // ultimately the 'login' default. Otherwise an accidental empty
        // string in config.php would silently break action matching.
        $GLOBALS['config'] = ['recaptcha_action' => ''];
        ipam_config_invalidate_cache();
        $this->assertSame('login', recaptcha_expected_action_resolved());
    }
}
