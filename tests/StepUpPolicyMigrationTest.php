<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies the v3.27.0 #1108 step-up policy seed migration
 * (3.27.0-step-up-policy-settings):
 *   - Fresh install: all 5 auth.step_up.* keys are inserted with the
 *     registry default values (4 bools = true, ttl_seconds = '300').
 *   - Idempotent replay: running the migration again does not duplicate
 *     rows or overwrite operator-modified values.
 *
 * Plan §6 — defaults are deliberately permissive so the v3.26.0 → v3.27.0
 * upgrade is behaviour-preserving for password-MFA admins and bug-fixing
 * for OIDC-only admins. Drift on the seeded values would silently change
 * the install's security posture on upgrade.
 */
final class StepUpPolicyMigrationTest extends TestCase
{
    private const KEYS = [
        'auth.step_up.allow_totp',
        'auth.step_up.allow_email_otp',
        'auth.step_up.allow_webauthn',
        'auth.step_up.allow_provider_reauth',
        'auth.step_up.ttl_seconds',
    ];

    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->db->exec("
            CREATE TABLE settings (
                tenant_id  INTEGER,
                key        TEXT NOT NULL,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string',
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("CREATE UNIQUE INDEX uq_settings_global ON settings (key) WHERE tenant_id IS NULL");
        ipam_setting_cache_bust();
    }

    private function migrationClosure(): callable
    {
        $migs = ipam_migrations();
        $this->assertArrayHasKey('3.27.0-step-up-policy-settings', $migs);
        return $migs['3.27.0-step-up-policy-settings'];
    }

    private function rawValue(string $key): ?string
    {
        $st = $this->db->prepare("SELECT value FROM settings WHERE tenant_id IS NULL AND key = :k");
        $st->execute([':k' => $key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function testFreshInstallSeedsAllFiveKeys(): void
    {
        ($this->migrationClosure())($this->db);
        foreach (self::KEYS as $key) {
            $this->assertNotNull($this->rawValue($key), "Migration must seed $key");
        }
    }

    public function testFreshInstallSeedsRegistryDefaults(): void
    {
        ($this->migrationClosure())($this->db);
        $this->assertSame('1',   $this->rawValue('auth.step_up.allow_totp'));
        $this->assertSame('1',   $this->rawValue('auth.step_up.allow_email_otp'));
        $this->assertSame('1',   $this->rawValue('auth.step_up.allow_webauthn'));
        $this->assertSame('1',   $this->rawValue('auth.step_up.allow_provider_reauth'));
        $this->assertSame('300', $this->rawValue('auth.step_up.ttl_seconds'));
    }

    public function testReplayIsIdempotent(): void
    {
        ($this->migrationClosure())($this->db);
        ($this->migrationClosure())($this->db);
        foreach (self::KEYS as $key) {
            $st = $this->db->prepare("SELECT COUNT(*) FROM settings WHERE tenant_id IS NULL AND key = :k");
            $st->execute([':k' => $key]);
            $count = (int) $st->fetchColumn();
            $this->assertSame(1, $count, "$key must have exactly one global row after replay");
        }
    }

    public function testReplayDoesNotOverwriteOperatorChanges(): void
    {
        $this->db->exec("INSERT INTO settings (tenant_id, key, value, type) VALUES "
            . "(NULL, 'auth.step_up.allow_email_otp', '0',    'bool'),"
            . "(NULL, 'auth.step_up.ttl_seconds',     '1800', 'string')");

        ($this->migrationClosure())($this->db);

        $this->assertSame('0',    $this->rawValue('auth.step_up.allow_email_otp'));
        $this->assertSame('1800', $this->rawValue('auth.step_up.ttl_seconds'));
        $this->assertSame('1',    $this->rawValue('auth.step_up.allow_totp'));
        $this->assertSame('1',    $this->rawValue('auth.step_up.allow_webauthn'));
        $this->assertSame('1',    $this->rawValue('auth.step_up.allow_provider_reauth'));
    }
}
