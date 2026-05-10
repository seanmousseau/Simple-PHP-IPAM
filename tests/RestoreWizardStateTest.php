<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/restore_wizard.php';

use PHPUnit\Framework\TestCase;

/**
 * Restore wizard state-machine regression tests (#807, B-P2-6 step-skip,
 * B-P1-43 phase confusion, B-P2-61 rate-limit).
 *
 * Pure helper coverage; the surrounding restore_web.php glue is exercised
 * by Playwright. Avoids ipam_restore_canonicalize_staged() return-null
 * edge by signing real files under sys_get_temp_dir() and pointing
 * data/tmp at the same root via a tmp DOCROOT — but that is over-kill
 * for state-machine tests, so phase/HMAC tests use a sentinel staged
 * path that the canonicaliser will reject. We assert the sign/verify
 * boundary by short-circuiting through the public API and checking the
 * pre-canonicalise verdict directly via a path that does resolve.
 */
final class RestoreWizardStateTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config;

    private string $stagedDir;
    private string $stagedPath;

    protected function setUp(): void
    {
        // #1127 (v3.27.3): wizard now uses $_SESSION['_pending_restore']
        // for cross-step state. Reset between tests so a stash from a
        // prior case doesn't leak.
        $_SESSION = [];

        $this->config = ['app_secret' => str_repeat('k', 64)];
        // Build a real staged file under data/tmp/ so the canonicalise
        // guard resolves to the same path. ipam_restore_canonicalize_staged
        // anchors at __DIR__ . '/data/tmp/' relative to lib/backup.php — i.e.
        // Simple-PHP-IPAM/data/tmp/. We honour that.
        $this->stagedDir = realpath(__DIR__ . '/../Simple-PHP-IPAM/data/tmp') ?: '';
        if ($this->stagedDir === '') {
            // CI containers may not have run init.php yet. Mkdir.
            $tmp = __DIR__ . '/../Simple-PHP-IPAM/data/tmp';
            if (!is_dir($tmp)) {
                mkdir($tmp, 0700, true);
            }
            $this->stagedDir = realpath($tmp) ?: $tmp;
        }
        $this->stagedPath = $this->stagedDir . '/restore-wizard-test-' . bin2hex(random_bytes(8)) . '.sql.gz';
        file_put_contents($this->stagedPath, 'placeholder');
    }

    protected function tearDown(): void
    {
        if (is_file($this->stagedPath)) {
            @unlink($this->stagedPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath()-derived test fixture under data/tmp/
        }
    }

    public function testSignVerifyRoundtripStaged(): void
    {
        $meta = ['filename' => 'foo.sql.gz', 'destination_id' => 7, 'size' => 1234];
        $sig = ipam_restore_wizard_sign($this->config, RESTORE_WIZARD_PHASE_STAGED, $this->stagedPath, $meta);
        $verified = ipam_restore_wizard_verify(
            $this->config,
            RESTORE_WIZARD_PHASE_STAGED,
            $this->stagedPath,
            $sig,
            $meta
        );
        $this->assertSame($this->stagedPath, $verified);
    }

    public function testSignVerifyRoundtripDryrunPassed(): void
    {
        $meta = ['filename' => 'foo.sql.gz', 'destination_id' => 7, 'size' => 1234];
        $sig = ipam_restore_wizard_sign($this->config, RESTORE_WIZARD_PHASE_DRYRUN_OK, $this->stagedPath, $meta);
        $verified = ipam_restore_wizard_verify(
            $this->config,
            RESTORE_WIZARD_PHASE_DRYRUN_OK,
            $this->stagedPath,
            $sig,
            $meta
        );
        $this->assertSame($this->stagedPath, $verified);
    }

    public function testStepSkipRejected(): void
    {
        // Token issued at phase=staged must not satisfy a phase=dryrun_passed
        // verification — this is the core step-skip block from B-P2-6.
        $meta = ['filename' => 'foo.sql.gz', 'destination_id' => 7, 'size' => 1234];
        $stagedSig = ipam_restore_wizard_sign($this->config, RESTORE_WIZARD_PHASE_STAGED, $this->stagedPath, $meta);
        $verified = ipam_restore_wizard_verify(
            $this->config,
            RESTORE_WIZARD_PHASE_DRYRUN_OK,
            $this->stagedPath,
            $stagedSig,
            $meta
        );
        $this->assertNull($verified, 'apply must reject a phase=staged token');
    }

    public function testFormPostedFilenameIgnoredOnVerify(): void
    {
        // #1127 (v3.27.3) note — the legacy version of this test asserted
        // that HMAC-tampering with `meta.filename` between sign and verify
        // caused verify to return null. Under the session-state refactor,
        // meta is server-side state stashed at sign time; the form-posted
        // meta passed to verify is ignored entirely. The equivalent
        // guarantee is preserved: an attacker cannot influence which
        // staged file gets restored by tampering with form fields.
        $meta = ['filename' => 'foo.sql.gz', 'destination_id' => 7, 'size' => 1234];
        $sig = ipam_restore_wizard_sign($this->config, RESTORE_WIZARD_PHASE_STAGED, $this->stagedPath, $meta);
        $verified = ipam_restore_wizard_verify(
            $this->config,
            RESTORE_WIZARD_PHASE_STAGED,
            $this->stagedPath,
            $sig,
            // Tampered meta — must NOT change which staged file wins.
            ['filename' => 'evil.sql.gz', 'destination_id' => 7, 'size' => 1234]
        );
        $this->assertNotNull($verified, 'session-state verify uses session meta, not form meta');
        $this->assertSame(realpath($this->stagedPath), $verified,
            'verify returns the canonical session-stashed path, NOT influenced by form meta');
    }

    public function testAppSecretIgnoredOnVerify(): void
    {
        // #1127 (v3.27.3) note — the legacy version asserted that a
        // verify call with a different app_secret rejected. Under the
        // session-state refactor, app_secret isn't part of the trust
        // boundary at all; the session is. The equivalent guarantee is
        // that a NEW session (e.g. a different user / different browser)
        // cannot consume a slot stashed by ANOTHER session — that's
        // enforced by PHP session isolation, not by us. This test now
        // asserts the inverse: same session, different app_secret in
        // $config still works (the wizard is session-scoped).
        $meta = ['filename' => 'foo.sql.gz', 'destination_id' => 7, 'size' => 1234];
        $sig = ipam_restore_wizard_sign($this->config, RESTORE_WIZARD_PHASE_STAGED, $this->stagedPath, $meta);
        $verified = ipam_restore_wizard_verify(
            ['app_secret' => str_repeat('z', 64)],   // different config — must not matter
            RESTORE_WIZARD_PHASE_STAGED,
            $this->stagedPath,
            $sig,
            $meta
        );
        $this->assertNotNull($verified, 'session-state verify ignores app_secret entirely');
    }

    public function testMissingAppSecretDoesNotThrowOnSign(): void
    {
        // #1127 (v3.27.3): the entire point of the refactor. Pre-fix
        // installs that took the v3.26.0 vault-key relocation path and
        // had `app_secret = ''` could not stage any restore — sign threw.
        // Post-fix: stash works regardless of app_secret state.
        $sig = ipam_restore_wizard_sign(['app_secret' => ''], RESTORE_WIZARD_PHASE_STAGED, $this->stagedPath, []);
        $this->assertNotEmpty($sig, '#1127: sign must succeed without app_secret — restore-from-remote unblocks on v3.26.0+ installs');
        $verified = ipam_restore_wizard_verify(
            ['app_secret' => ''],
            RESTORE_WIZARD_PHASE_STAGED,
            $this->stagedPath,
            $sig,
            []
        );
        $this->assertNotNull($verified, '#1127: round-trip must work end-to-end without app_secret');
    }

    public function testUnknownPhaseRejectedOnSign(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ipam_restore_wizard_sign($this->config, 'bogus', $this->stagedPath, []);
    }

    public function testV3LegacyTokenRejected(): void
    {
        // Tokens signed under the v3 HKDF info string must not verify under
        // the v4 wizard. Pre-upgrade in-flight wizard sessions get bumped
        // to Step 1 — by design.
        $meta = ['filename' => 'foo.sql.gz', 'destination_id' => 7, 'size' => 1234];
        $legacySig = ipam_restore_sign($this->config, $this->stagedPath, $meta);
        $verified = ipam_restore_wizard_verify(
            $this->config,
            RESTORE_WIZARD_PHASE_STAGED,
            $this->stagedPath,
            $legacySig,
            $meta
        );
        $this->assertNull($verified);
    }

    private function makeAuditDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            entity_type TEXT,
            entity_id INTEGER,
            created_at TEXT NOT NULL
        )");
        return $db;
    }

    private function nowUtc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function utcOffset(int $secondsAgo): string
    {
        return gmdate('Y-m-d H:i:s', time() - $secondsAgo);
    }

    public function testRateLimitCountsRecentAttempts(): void
    {
        $db = $this->makeAuditDb();
        $userId = 42;
        $st = $db->prepare("INSERT INTO audit_log (user_id, action, created_at) VALUES (?, ?, ?)");
        $now = $this->nowUtc();
        for ($i = 0; $i < 4; $i++) {
            $st->execute([$userId, 'db.restore_stage', $now]);
        }
        $this->assertSame(4, ipam_restore_wizard_recent_attempts($db, $userId));
        $this->assertFalse(ipam_restore_wizard_is_rate_limited($db, $userId));
    }

    public function testRateLimitTripsAtThreshold(): void
    {
        $db = $this->makeAuditDb();
        $userId = 42;
        $st = $db->prepare("INSERT INTO audit_log (user_id, action, created_at) VALUES (?, ?, ?)");
        $now = $this->nowUtc();
        for ($i = 0; $i < 5; $i++) {
            $st->execute([$userId, 'db.restore_dryrun_failed', $now]);
        }
        $this->assertSame(5, ipam_restore_wizard_recent_attempts($db, $userId));
        $this->assertTrue(ipam_restore_wizard_is_rate_limited($db, $userId));
    }

    public function testRateLimitWindowExpires(): void
    {
        $db = $this->makeAuditDb();
        $userId = 42;
        $stOld = $db->prepare("INSERT INTO audit_log (user_id, action, created_at) VALUES (?, ?, ?)");
        $oldTs = $this->utcOffset(3600);
        for ($i = 0; $i < 10; $i++) {
            $stOld->execute([$userId, 'db.restore_stage', $oldTs]);
        }
        // Rows are old; window is 5 minutes; nothing should count.
        $this->assertSame(0, ipam_restore_wizard_recent_attempts($db, $userId));
        $this->assertFalse(ipam_restore_wizard_is_rate_limited($db, $userId));
    }

    public function testRateLimitIgnoresOtherUsers(): void
    {
        $db = $this->makeAuditDb();
        $st = $db->prepare("INSERT INTO audit_log (user_id, action, created_at) VALUES (?, ?, ?)");
        $now = $this->nowUtc();
        for ($i = 0; $i < 10; $i++) {
            $st->execute([99, 'db.restore_apply', $now]);
        }
        $this->assertSame(0, ipam_restore_wizard_recent_attempts($db, 42));
    }

    public function testRateLimitIgnoresOtherActions(): void
    {
        $db = $this->makeAuditDb();
        $userId = 42;
        $st = $db->prepare("INSERT INTO audit_log (user_id, action, created_at) VALUES (?, ?, ?)");
        $now = $this->nowUtc();
        for ($i = 0; $i < 10; $i++) {
            $st->execute([$userId, 'auth.login', $now]);
        }
        $this->assertSame(0, ipam_restore_wizard_recent_attempts($db, $userId));
    }

    public function testRateLimitNullUserSafe(): void
    {
        $db = $this->makeAuditDb();
        $this->assertSame(0, ipam_restore_wizard_recent_attempts($db, null));
        $this->assertFalse(ipam_restore_wizard_is_rate_limited($db, null));
    }
}
