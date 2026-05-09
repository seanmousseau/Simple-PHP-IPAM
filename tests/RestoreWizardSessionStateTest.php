<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Restore wizard session-state refactor (#1127, v3.27.3).
 *
 * Pre-refactor: ipam_restore_wizard_sign() HMAC-signs the phase-locked
 * staged-file token using HKDF(app_secret, "ipam-v4:restore-wizard"). On
 * installs without app_secret (the v3.26.0 vault-key-relocation post-state),
 * staging throws \RuntimeException('ipam_restore_wizard: cannot sign without
 * app_secret'). Net effect: restore-from-remote-destination broken on every
 * install that took the v3.26.0 "remove app_secret" upgrade path.
 *
 * Post-refactor: server-side $_SESSION['_pending_restore'] state with
 * explicit phase progression. No HMAC; no app_secret dependency. The
 * three guarantees the HMAC token provided are preserved:
 *
 *   1. Phase progression (no apply-without-dryrun) — checked by
 *      asserting the session's `phase` matches the expected phase before
 *      the server processes the next step.
 *   2. Path authenticity — the path is never client-visible; user
 *      supplies action verbs only.
 *   3. No replay — single-use ipam_restore_wizard_consume_pending() that
 *      clears the slot after returning, plus 10-min expiry.
 *
 * Tests assert all three via the new helpers. Pre-fix the helpers don't
 * exist → tests fail to load.
 */
final class RestoreWizardSessionStateTest extends TestCase
{
    protected function setUp(): void
    {
        // Fresh session between tests.
        $_SESSION = [];
    }

    public function testStashAndConsumeRoundTrip(): void
    {
        $path = '/var/data/tmp/ipam-restore-staged-abc123.ipambkl1.gz';
        $meta = ['filename' => 'ipam-backup-2026-05-09.ipambkl1.gz', 'destination_id' => 5, 'size' => 7831288];

        ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, $path, $meta);

        $consumed = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($consumed, '#1127: stash + consume must round-trip cleanly');
        $this->assertSame($path, $consumed['path']);
        $this->assertSame($meta, $consumed['meta']);
        $this->assertSame(RESTORE_WIZARD_PHASE_STAGED, $consumed['phase']);
    }

    public function testConsumeIsSingleUse(): void
    {
        ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);
        $first  = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_STAGED);
        $second = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_STAGED);

        $this->assertNotNull($first, 'first consume returns the stashed entry');
        $this->assertNull($second, '#1127: second consume must return null (replay defence)');
    }

    public function testPhaseMismatchRefuses(): void
    {
        // User staged a file (phase=staged) but tries to consume as if they
        // had already passed dry-run. The wizard MUST refuse — same guarantee
        // the HMAC phase-locking provided pre-refactor.
        ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);

        $consumed = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_DRYRUN_OK);
        $this->assertNull($consumed, '#1127: consuming a `staged` slot expecting `dryrun_passed` must refuse — phase-skip prevention');

        // The slot must remain intact for a correctly-phased subsequent call.
        $correctlyPhased = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($correctlyPhased, 'wrong-phase consume must not destroy the slot');
    }

    public function testExpiredSlotRefuses(): void
    {
        // Manually shape a session entry with a past expiry to simulate
        // a stale slot. Real fix uses time() + 600 at stash time.
        $_SESSION['_pending_restore'] = [
            'path'    => '/y',
            'meta'    => [],
            'phase'   => RESTORE_WIZARD_PHASE_STAGED,
            'expires' => time() - 1,
        ];

        $consumed = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNull($consumed, '#1127: expired slot must refuse and not return stale data');
        $this->assertArrayNotHasKey('_pending_restore', $_SESSION,
            '#1127: expired slot must be unset on consume so it cannot be re-attempted');
    }

    public function testPhaseAdvancePreservesPathAndMeta(): void
    {
        // Wizard-step semantic: stage → dryrun success → apply.
        // ipam_restore_wizard_advance_phase() flips phase staged → dryrun_passed
        // without consuming. Path and meta survive.
        $path = '/var/data/tmp/ipam-restore-staged-xyz.ipambkl1.gz';
        $meta = ['filename' => 'f.gz', 'destination_id' => 1, 'size' => 100];

        ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, $path, $meta);
        $advanced = ipam_restore_wizard_advance_phase(RESTORE_WIZARD_PHASE_DRYRUN_OK);
        $this->assertTrue($advanced, '#1127: phase advance from `staged` to `dryrun_passed` must succeed when slot is fresh');

        $consumed = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_DRYRUN_OK);
        $this->assertNotNull($consumed, 'after advance, dryrun_passed consume must succeed');
        $this->assertSame($path, $consumed['path']);
        $this->assertSame($meta, $consumed['meta']);
    }

    public function testNoAppSecretRequired(): void
    {
        // Explicit guarantee of the refactor: works with no app_secret in
        // $config. The legacy wizard sign throws here — the new mechanism
        // must not depend on $config at all.
        $GLOBALS['config'] = ['app_secret' => ''];

        ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/no-app-secret-needed', ['filename' => 'a.gz']);
        $consumed = ipam_restore_wizard_consume_pending(RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($consumed, '#1127: stash+consume must work with empty app_secret — the entire point of the refactor');
    }
}
