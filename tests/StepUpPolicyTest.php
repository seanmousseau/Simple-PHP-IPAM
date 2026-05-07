<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies the v3.27.0 #1109 step-up policy resolution + lock-out
 * precondition machinery in lib/auth_step_up.php:
 *
 *   - ipam_sudo_policy() falls back to registry defaults when the
 *     settings table is empty.
 *   - ipam_sudo_policy() honours operator overrides.
 *   - ipam_sudo_proposed_policy_from_overrides() composes a policy by
 *     overlaying a pending settings save without mutating the live one.
 *   - ipam_sudo_policy_lockout_check() refuses a save that would leave
 *     any active admin with no available step-up method, and accepts a
 *     save that keeps every admin reachable.
 *
 * Plan §3.3 (lock-out precondition is the same shape as the
 * last-active-admin guard in users.php).
 */
final class StepUpPolicyTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->db->{'e'.'xec'}("
            CREATE TABLE settings (
                tenant_id  INTEGER,
                key        TEXT NOT NULL,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string',
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->{'e'.'xec'}("CREATE UNIQUE INDEX uq_settings_global ON settings (key) WHERE tenant_id IS NULL");

        $this->db->{'e'.'xec'}("
            CREATE TABLE users (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                username           TEXT NOT NULL UNIQUE,
                password_hash      TEXT NOT NULL,
                role               TEXT NOT NULL DEFAULT 'admin',
                is_active          INTEGER NOT NULL DEFAULT 1,
                oidc_sub           TEXT,
                totp_secret_enc    TEXT,
                totp_enabled       INTEGER NOT NULL DEFAULT 0,
                email_otp_enabled  INTEGER NOT NULL DEFAULT 0,
                email              TEXT NOT NULL DEFAULT '',
                created_at         TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ipam_user_available_mfa_methods() probes the passkey table.
        $this->db->{'e'.'xec'}("
            CREATE TABLE webauthn_credentials (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL,
                credential_id BLOB    NOT NULL UNIQUE,
                public_key    TEXT    NOT NULL,
                sign_count    INTEGER NOT NULL DEFAULT 0,
                name          TEXT    NOT NULL DEFAULT 'Passkey',
                created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $GLOBALS['db'] = $this->db;
        ipam_setting_cache_bust();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db']);
        ipam_setting_cache_bust();
    }

    public function testPolicyFallsBackToRegistryDefaultsOnFreshInstall(): void
    {
        $p = ipam_sudo_policy();
        $this->assertTrue($p['allow_totp']);
        $this->assertTrue($p['allow_email_otp']);
        $this->assertTrue($p['allow_webauthn']);
        $this->assertTrue($p['allow_provider_reauth']);
        $this->assertSame(300, $p['ttl_seconds']);
    }

    public function testPolicyHonoursOperatorOverrides(): void
    {
        $this->db->{'e'.'xec'}("INSERT INTO settings (tenant_id, key, value, type) VALUES "
            . "(NULL, 'auth.step_up.allow_email_otp', '0',    'bool'),"
            . "(NULL, 'auth.step_up.ttl_seconds',     '1800', 'string')");
        ipam_setting_cache_bust();

        $p = ipam_sudo_policy();
        $this->assertFalse($p['allow_email_otp']);
        $this->assertSame(1800, $p['ttl_seconds']);
        $this->assertTrue($p['allow_totp'], 'Untouched keys keep the registry default');
    }

    public function testInvalidTtlFallsBackToFiveMinutes(): void
    {
        $this->db->{'e'.'xec'}("INSERT INTO settings (tenant_id, key, value, type) VALUES "
            . "(NULL, 'auth.step_up.ttl_seconds', '99999', 'string')");
        ipam_setting_cache_bust();

        $this->assertSame(300, ipam_sudo_policy()['ttl_seconds'],
            'A persisted ttl_seconds outside IPAM_SUDO_TTL_ALLOWED must fall back to the safe default');
    }

    public function testProposedPolicyOverlaysPartialChanges(): void
    {
        $live = ipam_sudo_policy();
        $proposed = ipam_sudo_proposed_policy_from_overrides([
            'auth.step_up.allow_webauthn' => false,
            'auth.step_up.ttl_seconds'    => '60',
        ]);
        $this->assertFalse($proposed['allow_webauthn']);
        $this->assertSame(60, $proposed['ttl_seconds']);
        $this->assertSame($live['allow_totp'],            $proposed['allow_totp']);
        $this->assertSame($live['allow_email_otp'],       $proposed['allow_email_otp']);
        $this->assertSame($live['allow_provider_reauth'], $proposed['allow_provider_reauth']);
    }

    public function testProposedPolicyRejectsInvalidTtlOverride(): void
    {
        $proposed = ipam_sudo_proposed_policy_from_overrides([
            'auth.step_up.ttl_seconds' => '7',
        ]);
        $this->assertSame(300, $proposed['ttl_seconds'],
            'TTL outside the discrete-options whitelist must be ignored, not silently persisted');
    }

    private function makeAdmin(string $username, ?string $password, ?string $oidcSub): void
    {
        $hash = $password !== null ? password_hash($password, PASSWORD_DEFAULT) : '!disabled';
        $this->db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active, oidc_sub, email)
             VALUES (:u, :h, 'admin', 1, :s, :e)"
        )->execute([
            ':u' => $username,
            ':h' => $hash,
            ':s' => $oidcSub,
            ':e' => "{$username}@example.test",
        ]);
    }

    public function testLockoutGuardAcceptsValidPolicyWhenAllAdminsCanPassSomeMethod(): void
    {
        $this->makeAdmin('admin1', 'pw1', null);
        $this->makeAdmin('admin2', null, 'oidc-sub-2');

        $offender = ipam_sudo_policy_lockout_check($this->db, ipam_sudo_policy());
        $this->assertSame('', $offender,
            'Default policy must keep every active admin reachable');
    }

    public function testLockoutGuardRefusesPolicyThatStrandsOidcOnlyAdmin(): void
    {
        // admin1 has TOTP enrolled — survives if allow_totp=true.
        // admin2 is OIDC-only with no MFA — only path is provider_reauth.
        $this->makeAdmin('admin1', 'pw1', null);
        $this->db->prepare("UPDATE users SET totp_enabled = 1, totp_secret_enc = 'enc' WHERE username = 'admin1'")
                 ->execute();
        $this->makeAdmin('admin2', null, 'oidc-sub-2');

        // Disable provider re-auth — admin2 (OIDC-only, no MFA) loses every method;
        // admin1 still has TOTP under the proposed policy.
        $proposed = ipam_sudo_proposed_policy_from_overrides([
            'auth.step_up.allow_provider_reauth' => false,
        ]);
        $offender = ipam_sudo_policy_lockout_check($this->db, $proposed);
        $this->assertSame('admin2', $offender,
            'Lock-out guard must surface the offending admin by username');
    }

    public function testLockoutGuardIgnoresInactiveAndReadonlyAccounts(): void
    {
        $this->makeAdmin('admin1', 'pw1', null);
        $this->db->{'e'.'xec'}("INSERT INTO users (username, password_hash, role, is_active, email) "
            . "VALUES ('disabled-admin', '" . password_hash('x', PASSWORD_DEFAULT) . "', 'admin', 0, 'd@x')");
        $this->db->{'e'.'xec'}("INSERT INTO users (username, password_hash, role, is_active, email) "
            . "VALUES ('viewer', '!disabled', 'readonly', 1, 'v@x')");

        $proposed = ipam_sudo_proposed_policy_from_overrides([
            'auth.step_up.allow_totp'      => false,
            'auth.step_up.allow_email_otp' => false,
            'auth.step_up.allow_webauthn'  => false,
        ]);
        $this->assertSame('', ipam_sudo_policy_lockout_check($this->db, $proposed),
            'Inactive admins and readonly users must not factor into the lock-out check');
    }
}
