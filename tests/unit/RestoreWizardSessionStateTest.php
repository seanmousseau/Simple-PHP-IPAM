<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/lib/restore_wizard.php';

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
    /** @var bool */
    private bool $hadConfig = false;
    /** @var mixed */
    private mixed $originalConfig = null;

    protected function setUp(): void
    {
        // Fresh session between tests.
        $_SESSION = [];

        // CR PR #1141: snapshot $GLOBALS['config'] so tests that mutate
        // it (e.g. testNoAppSecretRequired) can't poison later cases.
        $this->hadConfig      = array_key_exists('config', $GLOBALS);
        $this->originalConfig = $GLOBALS['config'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->hadConfig) {
            $GLOBALS['config'] = $this->originalConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }

    public function testStashAndConsumeRoundTrip(): void
    {
        $path = '/var/data/tmp/ipam-restore-staged-abc123.ipambkl1.gz';
        $meta = ['filename' => 'ipam-backup-2026-05-09.ipambkl1.gz', 'destination_id' => 5, 'size' => 7831288];

        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, $path, $meta);
        $this->assertNotEmpty($id, 'stash must return an opaque per-wizard ID');

        $consumed = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($consumed, '#1127: stash + consume must round-trip cleanly');
        $this->assertSame($path, $consumed['path']);
        $this->assertSame($meta, $consumed['meta']);
        $this->assertSame(RESTORE_WIZARD_PHASE_STAGED, $consumed['phase']);
    }

    public function testConsumeIsSingleUse(): void
    {
        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);
        $first  = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_STAGED);
        $second = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_STAGED);

        $this->assertNotNull($first, 'first consume returns the stashed entry');
        $this->assertNull($second, '#1127: second consume must return null (replay defence)');
    }

    public function testPhaseMismatchRefuses(): void
    {
        // User staged a file (phase=staged) but tries to consume as if they
        // had already passed dry-run. The wizard MUST refuse — same guarantee
        // the HMAC phase-locking provided pre-refactor.
        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);

        $consumed = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_DRYRUN_OK);
        $this->assertNull($consumed, '#1127: consuming a `staged` slot expecting `dryrun_passed` must refuse — phase-skip prevention');

        // The slot must remain intact for a correctly-phased subsequent call.
        $correctlyPhased = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($correctlyPhased, 'wrong-phase consume must not destroy the slot');
    }

    public function testExpiredSlotRefuses(): void
    {
        // Manually shape a per-id session entry with a past expiry to
        // simulate a stale slot. Real fix uses time() + 600 at stash time.
        $id = 'fakeid';
        $_SESSION['_pending_restores'] = [
            $id => [
                'path'    => '/y',
                'meta'    => [],
                'phase'   => RESTORE_WIZARD_PHASE_STAGED,
                'expires' => time() - 1,
            ],
        ];

        $consumed = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNull($consumed, '#1127: expired slot must refuse and not return stale data');
        $slots = $_SESSION['_pending_restores'] ?? [];
        $this->assertIsArray($slots);
        $this->assertArrayNotHasKey($id, $slots,
            '#1127: expired slot must be unset on consume so it cannot be re-attempted');
    }

    public function testPhaseAdvancePreservesPathAndMeta(): void
    {
        // Wizard-step semantic: stage → dryrun success → apply.
        // ipam_restore_wizard_advance_phase() flips phase staged → dryrun_passed
        // without consuming. Path and meta survive.
        $path = '/var/data/tmp/ipam-restore-staged-xyz.ipambkl1.gz';
        $meta = ['filename' => 'f.gz', 'destination_id' => 1, 'size' => 100];

        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, $path, $meta);
        $advanced = ipam_restore_wizard_advance_phase($id, RESTORE_WIZARD_PHASE_DRYRUN_OK);
        $this->assertTrue($advanced, '#1127: phase advance from `staged` to `dryrun_passed` must succeed when slot is fresh');

        $consumed = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_DRYRUN_OK);
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

        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/no-app-secret-needed', ['filename' => 'a.gz']);
        $consumed = ipam_restore_wizard_consume_pending($id, RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($consumed, '#1127: stash+consume must work with empty app_secret — the entire point of the refactor');
    }

    /**
     * CR PR #1141 regression: pre-fix, a single `_pending_restore` slot
     * meant a second concurrent wizard (admin opens 2 restore tabs)
     * clobbered the first. Per-id stash gives every wizard its own slot.
     */
    public function testConcurrentTabsDoNotClobberEachOther(): void
    {
        $idA = ipam_restore_wizard_stage_pending(
            RESTORE_WIZARD_PHASE_STAGED,
            '/var/data/tmp/archive-A.ipambkl1.gz',
            ['filename' => 'A.gz', 'destination_id' => 1, 'size' => 100]
        );
        $idB = ipam_restore_wizard_stage_pending(
            RESTORE_WIZARD_PHASE_STAGED,
            '/var/data/tmp/archive-B.ipambkl1.gz',
            ['filename' => 'B.gz', 'destination_id' => 2, 'size' => 200]
        );

        $this->assertNotSame($idA, $idB, 'each stash must produce a distinct opaque ID');

        $consumedB = ipam_restore_wizard_consume_pending($idB, RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($consumedB);
        $this->assertSame('/var/data/tmp/archive-B.ipambkl1.gz', $consumedB['path']);

        $consumedA = ipam_restore_wizard_consume_pending($idA, RESTORE_WIZARD_PHASE_STAGED);
        $this->assertNotNull($consumedA, '#1141: tab A slot must survive tab B consuming its own slot');
        $this->assertSame('/var/data/tmp/archive-A.ipambkl1.gz', $consumedA['path']);
    }

    /**
     * CR PR #1141 round 2: advance_phase must enforce that the slot is
     * currently `staged` AND the target is `dryrun_passed`. Anything
     * else would let a fresh `staged` slot skip the dry-run gate.
     */
    public function testAdvancePhaseRejectsNonStagedSource(): void
    {
        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);
        $this->assertTrue(ipam_restore_wizard_advance_phase($id, RESTORE_WIZARD_PHASE_DRYRUN_OK));
        // Slot is now dryrun_passed. A second advance to dryrun_passed
        // must refuse — re-promoting weakens the phase-lock invariant.
        $this->assertFalse(
            ipam_restore_wizard_advance_phase($id, RESTORE_WIZARD_PHASE_DRYRUN_OK),
            '#1141: advance_phase must refuse when the slot is not currently `staged`'
        );
    }

    public function testAdvancePhaseRejectsBackwardsTransition(): void
    {
        $id = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);
        $this->expectException(InvalidArgumentException::class);
        // staged is not a valid TARGET — only dryrun_passed is.
        ipam_restore_wizard_advance_phase($id, RESTORE_WIZARD_PHASE_STAGED);
    }

    public function testUnknownIdReturnsNull(): void
    {
        $real = ipam_restore_wizard_stage_pending(RESTORE_WIZARD_PHASE_STAGED, '/x', []);
        $this->assertNull(
            ipam_restore_wizard_consume_pending('not-a-real-id', RESTORE_WIZARD_PHASE_STAGED),
            '#1141: looking up an unknown ID must not consume the real slot'
        );
        $this->assertNotNull(
            ipam_restore_wizard_consume_pending($real, RESTORE_WIZARD_PHASE_STAGED),
            '#1141: real ID still consumes its slot after a bogus-id lookup'
        );
    }
}
