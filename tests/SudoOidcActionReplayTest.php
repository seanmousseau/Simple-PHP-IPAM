<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * OIDC step-up action replay (#1131, v3.27.3).
 *
 * Pre-fix mechanism: OIDC re-auth grants sudo and redirects the user to
 * the originating page (Bug Z fix in v3.27.1), but the original POST
 * body that triggered the step-up gate is dropped on the OIDC roundtrip
 * (browser left to Authentik, came back via GET). For some sudo-class
 * actions (e.g. api_keys.php create) this is mildly annoying — the user
 * re-clicks the action button and the sudo grant is now warm. For
 * vault_reveal it's broken UX — the user expected the key to appear
 * after re-auth, but they have to click Reveal again.
 *
 * Operator-reported in v3.27.2 wide-regression by Sean: "Step-up auth
 * to reveal backup key with OIDC redirected back to destinations page
 * but did not reveal the key."
 *
 * Post-fix design: at OIDC-link-render time, stash the pending action
 * context in $_SESSION['_sudo_oidc_pending']. After
 * ipam_sudo_oidc_reauth_complete() grants sudo, the caller checks for
 * a pending action and either auto-redirects to a tiny replay page
 * that POSTs the original fields back, or exposes the pending action
 * for the destination page to consume.
 *
 * The three guarantees the helpers must provide:
 *
 *   1. Stash + consume round-trip: pending action survives the OIDC
 *      navigate-away-and-back cycle.
 *   2. Single-use: consuming the pending action clears the slot so a
 *      later request can't replay it.
 *   3. Expiry: 10-min TTL prevents stale replay if the user abandons
 *      the OIDC flow and comes back hours later.
 */
final class SudoOidcActionReplayTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testStashAndConsumeRoundTrip(): void
    {
        $context = [
            'target' => 'backup_admin.php?tab=destinations',
            'fields' => ['action' => 'vault_reveal'],
            'csrf'   => 'csrf-token-abc123',
        ];
        ipam_sudo_oidc_stash_pending($context);

        $consumed = ipam_sudo_oidc_consume_pending();
        $this->assertNotNull($consumed, '#1131: stash + consume must round-trip');
        $this->assertSame($context['target'], $consumed['target']);
        $this->assertSame($context['fields'], $consumed['fields']);
        $this->assertSame($context['csrf'], $consumed['csrf']);
    }

    public function testConsumeIsSingleUse(): void
    {
        ipam_sudo_oidc_stash_pending(['target' => '/x', 'fields' => [], 'csrf' => 'c']);
        $first  = ipam_sudo_oidc_consume_pending();
        $second = ipam_sudo_oidc_consume_pending();

        $this->assertNotNull($first, 'first consume returns the stashed entry');
        $this->assertNull($second, '#1131: second consume must return null (replay defence)');
    }

    public function testExpiredSlotRefuses(): void
    {
        $_SESSION['_sudo_oidc_pending'] = [
            'target'  => '/y',
            'fields'  => [],
            'csrf'    => 'c',
            'expires' => time() - 1,
        ];

        $consumed = ipam_sudo_oidc_consume_pending();
        $this->assertNull($consumed, '#1131: expired slot must refuse');
        $this->assertArrayNotHasKey('_sudo_oidc_pending', $_SESSION,
            '#1131: expired slot must auto-clear so it cannot be re-attempted');
    }

    public function testNoSlotReturnsNull(): void
    {
        $consumed = ipam_sudo_oidc_consume_pending();
        $this->assertNull($consumed, 'no slot → null (operators may visit the page directly without a pending action)');
    }

    public function testStashOverwritesPriorPending(): void
    {
        // If the user clicked one sudo-class action, abandoned the OIDC
        // flow without completing, then started another sudo-class action,
        // the second stash must replace the first — not stack.
        ipam_sudo_oidc_stash_pending(['target' => '/first', 'fields' => ['action' => 'a1'], 'csrf' => 'c1']);
        ipam_sudo_oidc_stash_pending(['target' => '/second', 'fields' => ['action' => 'a2'], 'csrf' => 'c2']);

        $consumed = ipam_sudo_oidc_consume_pending();
        $this->assertNotNull($consumed);
        $this->assertSame('/second', $consumed['target'], '#1131: second stash must overwrite first');
        $this->assertSame(['action' => 'a2'], $consumed['fields']);
    }

    /**
     * CR PR #1141 regression: views/_step_up_prompt.php must filter
     * `csrf` and `_sudo_*` keys out of the replay stash before passing
     * to ipam_sudo_oidc_stash_pending(). If a caller forwards the
     * original POST wholesale, a stale `csrf` would land in the
     * replayed POST alongside the fresh one sudo_replay.php injects;
     * PHP would then read the LATER (stale) value and the replay's
     * CSRF check would fail. `_sudo_*` proof tokens would similarly
     * mint a second-grant-of-stale-state.
     */
    public function testReplayStashFiltersReservedKeys(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/views/_step_up_prompt.php');
        $this->assertMatchesRegularExpression(
            '/\$key\s*===\s*[\'"]csrf[\'"]/',
            $src,
            '#1141: _step_up_prompt.php must skip the `csrf` key when building the replay stash'
        );
        $this->assertMatchesRegularExpression(
            '/str_starts_with\s*\(\s*\$key\s*,\s*[\'"]_sudo_[\'"]/',
            $src,
            '#1141: _step_up_prompt.php must skip `_sudo_*` keys when building the replay stash'
        );
    }
}
