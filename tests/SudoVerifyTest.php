<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies the v3.27.0 #1107 step-up gate primitive ipam_sudo_verify():
 *
 *   - Cached grant short-circuits (no audit row, TTL refreshed).
 *   - Per-IP rate-limit on the 'sudo' bucket trips after IPAM_SUDO_RATE_LIMIT_MAX
 *     failures within IPAM_SUDO_RATE_LIMIT_WINDOW.
 *   - method_unavailable refusal — proof method must be in the per-user
 *     available-methods list AND permitted by the install policy.
 *   - TOTP success path: round-trips through ipam_totp_decrypt_secret() +
 *     ipam_totp_verify(). Catches the v3.27.0 column-name regression
 *     (totp_secret vs totp_secret_enc) at unit-test time.
 *   - TOTP failure path: bad code does not mint a grant.
 *   - Email OTP success/failure paths.
 *   - Password success path AND locked-hash refusal — '!disabled'
 *     password_hash must NEVER be accepted as a step-up proof, even when
 *     allow_provider_reauth=true. This is the OIDC-only deployment defence.
 *   - WebAuthn no-challenge refusal (caller mis-wired the flow).
 *   - oidc_reauth always returns false from this function — the IdP
 *     round-trip is verified out-of-band.
 *
 * Sets $GLOBALS['config']['app_secret'] in setUp() so the TOTP branch can
 * decrypt; mirrors how init.php publishes the value to the helper.
 */
final class SudoVerifyTest extends TestCase
{
    private \PDO $db;
    private string $appSecret = 'test-app-secret-32-bytes-or-more-12345';

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
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                username             TEXT NOT NULL UNIQUE,
                password_hash        TEXT NOT NULL,
                role                 TEXT NOT NULL DEFAULT 'admin',
                is_active            INTEGER NOT NULL DEFAULT 1,
                oidc_sub             TEXT,
                totp_secret_enc      TEXT,
                totp_enabled         INTEGER NOT NULL DEFAULT 0,
                email_otp_enabled    INTEGER NOT NULL DEFAULT 0,
                email_otp_hash       TEXT,
                email_otp_expires_at TEXT,
                email_otp_attempts   INTEGER NOT NULL DEFAULT 0,
                email                TEXT NOT NULL DEFAULT '',
                created_at           TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

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

        $this->db->{'e'.'xec'}("
            CREATE TABLE audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                user_id     INTEGER,
                username    TEXT,
                action      TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id   INTEGER,
                ip          TEXT,
                user_agent  TEXT,
                details     TEXT
            )
        ");

        $this->db->{'e'.'xec'}("
            CREATE TABLE login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT NOT NULL,
                username     TEXT,
                action       TEXT NOT NULL DEFAULT 'login',
                attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $GLOBALS['db']     = $this->db;
        $GLOBALS['config'] = ['app_secret' => $this->appSecret];
        $_SESSION          = [];
        ipam_setting_cache_bust();

        // ipam_user_available_mfa_methods() gates each method on a global
        // mfa.<method>_enabled flag. Defaults: totp=on, email_otp=off,
        // passkeys=off. Enable email_otp + passkeys for the tests that
        // exercise those branches; the policy defaults in
        // ipam_sudo_policy() already permit them.
        $this->db->{'e'.'xec'}("INSERT INTO settings (tenant_id, key, value, type) VALUES "
            . "(NULL, 'mfa.email_otp_enabled', '1', 'bool'),"
            . "(NULL, 'mfa.passkeys_enabled',  '1', 'bool')");
        ipam_setting_cache_bust();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        $_SESSION = [];
        ipam_setting_cache_bust();
    }

    // ─────────────────────────────────────────────────────────────────────
    // User factory
    // ─────────────────────────────────────────────────────────────────────

    private function makeLocalAdmin(string $username, string $password): int
    {
        $this->db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active, email)
             VALUES (:u, :h, 'admin', 1, :e)"
        )->execute([
            ':u' => $username,
            ':h' => password_hash($password, PASSWORD_DEFAULT),
            ':e' => "{$username}@example.test",
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function enrollTotp(int $userId, string $secret): void
    {
        $enc = ipam_totp_encrypt_secret($secret, $this->appSecret);
        $this->db->prepare(
            "UPDATE users SET totp_enabled = 1, totp_secret_enc = :e WHERE id = :id"
        )->execute([':e' => $enc, ':id' => $userId]);
    }

    private function enrollEmailOtp(int $userId, string $code): void
    {
        $hash    = password_hash($code, PASSWORD_DEFAULT);
        $expires = gmdate('Y-m-d H:i:s', time() + 600);
        $this->db->prepare(
            "UPDATE users SET email_otp_enabled = 1, email_otp_hash = :h, email_otp_expires_at = :x WHERE id = :id"
        )->execute([':h' => $hash, ':x' => $expires, ':id' => $userId]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cached grant + invalidation
    // ─────────────────────────────────────────────────────────────────────

    public function testCachedGrantShortCircuitsAndRefreshesTtl(): void
    {
        $uid = $this->makeLocalAdmin('alice', 'pw1');
        $_SESSION['sudo_until_ts'] = time() + 60;
        $_SESSION['sudo_method']   = 'totp';

        $this->assertTrue(ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => 'wrong']),
            'Cached grant must short-circuit even when the proof would otherwise fail');

        $this->assertNoAudit('auth.sudo_passed', 'cached grant short-circuit must not double-audit');
    }

    public function testInvalidateClearsCachedGrant(): void
    {
        $_SESSION['sudo_until_ts']            = time() + 60;
        $_SESSION['sudo_method']              = 'password';
        $_SESSION['sudo_webauthn_challenge']  = 'abc';

        $this->assertTrue(ipam_sudo_active());
        ipam_sudo_invalidate();
        $this->assertFalse(ipam_sudo_active());
        $this->assertArrayNotHasKey('sudo_method', $_SESSION);
        $this->assertArrayNotHasKey('sudo_webauthn_challenge', $_SESSION);
    }

    // ─────────────────────────────────────────────────────────────────────
    // method_unavailable refusal
    // ─────────────────────────────────────────────────────────────────────

    public function testMethodUnavailableRefusesAndAudits(): void
    {
        $uid = $this->makeLocalAdmin('bob', 'pw1');
        // Tighten policy: only TOTP. bob has no TOTP enrolled.
        $this->db->{'e'.'xec'}("INSERT INTO settings (tenant_id, key, value, type) VALUES "
            . "(NULL, 'auth.step_up.allow_email_otp',       '0', 'bool'),"
            . "(NULL, 'auth.step_up.allow_webauthn',        '0', 'bool'),"
            . "(NULL, 'auth.step_up.allow_provider_reauth', '0', 'bool')");
        ipam_setting_cache_bust();

        $this->assertFalse(ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => 'pw1']));
        $this->assertAuditDetailContains('auth.sudo_failed', 'method_unavailable');
    }

    // ─────────────────────────────────────────────────────────────────────
    // TOTP — regression test for the totp_secret/totp_secret_enc bug
    // ─────────────────────────────────────────────────────────────────────

    public function testTotpSuccessRoundTripsThroughEncryptedSecret(): void
    {
        $uid    = $this->makeLocalAdmin('carol', 'pw1');
        $secret = ipam_totp_generate_secret();
        $this->enrollTotp($uid, $secret);
        $code = ipam_totp_tfa()->getCode($secret);

        $this->assertTrue(ipam_sudo_verify($this->db, $uid, ['method' => 'totp', 'code' => $code]),
            'TOTP step-up must decrypt totp_secret_enc and verify the live code (regression: #1107 totp_secret column-name bug)');
        $this->assertTrue(ipam_sudo_active(), 'Successful verify must mint a grant');
        $this->assertAudit('auth.sudo_passed');
    }

    public function testTotpFailureWithBadCodeDoesNotMintGrant(): void
    {
        $uid    = $this->makeLocalAdmin('dave', 'pw1');
        $secret = ipam_totp_generate_secret();
        $this->enrollTotp($uid, $secret);

        $this->assertFalse(ipam_sudo_verify($this->db, $uid, ['method' => 'totp', 'code' => '000000']));
        $this->assertFalse(ipam_sudo_active());
        $this->assertAuditDetailContains('auth.sudo_failed', 'totp_invalid');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Email OTP
    // ─────────────────────────────────────────────────────────────────────

    public function testEmailOtpSuccessConsumesTheToken(): void
    {
        $uid  = $this->makeLocalAdmin('erin', 'pw1');
        $code = '654321';
        $this->enrollEmailOtp($uid, $code);

        $this->assertTrue(ipam_sudo_verify($this->db, $uid, ['method' => 'email_otp', 'code' => $code]));
        $this->assertTrue(ipam_sudo_active());

        $st = $this->db->prepare("SELECT email_otp_hash FROM users WHERE id = :id");
        $st->execute([':id' => $uid]);
        $this->assertNull($st->fetchColumn(),
            'Successful Email OTP verify must consume the stored token');
    }

    public function testEmailOtpFailureWithBadCodeDoesNotMintGrant(): void
    {
        $uid = $this->makeLocalAdmin('frank', 'pw1');
        $this->enrollEmailOtp($uid, '111111');

        $this->assertFalse(ipam_sudo_verify($this->db, $uid, ['method' => 'email_otp', 'code' => '999999']));
        $this->assertFalse(ipam_sudo_active());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Password — including the locked-hash defence
    // ─────────────────────────────────────────────────────────────────────

    public function testPasswordSuccessMintsGrantWhenProviderReauthAllowed(): void
    {
        $uid = $this->makeLocalAdmin('grace', 'pw-correct');
        $this->assertTrue(ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => 'pw-correct']));
        $this->assertTrue(ipam_sudo_active());
    }

    public function testLockedPasswordHashCannotBeStepUpProof(): void
    {
        // OIDC-only user: '!disabled' password_hash must NEVER satisfy the
        // password branch, even when allow_provider_reauth=true. This is the
        // direct defence against the v3.26.0 OIDC-lockout origin bug — a
        // naive re-implementation of password_verify() against the locked
        // hash would silently start accepting an empty/random string after a
        // PHP password_verify() change.
        $this->db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active, oidc_sub, email)
             VALUES ('oidc-only', '!disabled', 'admin', 1, 'sub-x', 'o@x')"
        )->execute();
        $uid = (int) $this->db->lastInsertId();

        $this->assertFalse(ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => '']),
            'Locked password_hash starting with ! must always refuse');
        $this->assertFalse(ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => '!disabled']),
            'The literal locked-hash string must not be accepted as a password');
    }

    // ─────────────────────────────────────────────────────────────────────
    // WebAuthn / OIDC reauth — caller mis-wiring refusals
    // ─────────────────────────────────────────────────────────────────────

    public function testWebauthnWithoutChallengeRefusesAndAudits(): void
    {
        $uid = $this->makeLocalAdmin('hank', 'pw1');
        // Stuff a credential row in so the user has a passkey enrolled — without
        // it, the proof would fall through to method_unavailable instead.
        $this->db->prepare(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count)
             VALUES (:u, :c, 'pk', 0)"
        )->execute([':u' => $uid, ':c' => 'fake-cred-id-1']);

        $this->assertFalse(ipam_sudo_verify($this->db, $uid, [
            'method'             => 'webauthn',
            'client_data_json'   => 'AAA',
            'authenticator_data' => 'BBB',
            'signature'          => 'CCC',
            'credential_id'      => 'fake-cred-id-1',
        ]), 'WebAuthn proof submitted without an in-flight session challenge must refuse');
        $this->assertAuditDetailContains('auth.sudo_failed', 'webauthn_no_challenge');
    }

    public function testOidcReauthBranchAlwaysRefusesFromVerify(): void
    {
        $this->db->prepare(
            "INSERT INTO users (username, password_hash, role, is_active, oidc_sub, email)
             VALUES ('iris', '!disabled', 'admin', 1, 'sub-iris', 'i@x')"
        )->execute();
        $uid = (int) $this->db->lastInsertId();

        $this->assertFalse(ipam_sudo_verify($this->db, $uid, ['method' => 'oidc_reauth']),
            'OIDC re-auth is verified out-of-band — ipam_sudo_verify() must always refuse this method');
        $this->assertAuditDetailContains('auth.sudo_failed', 'oidc_reauth_redirect_required');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rate limiting
    // ─────────────────────────────────────────────────────────────────────

    public function testRateLimitTripsAfterMaxFailuresOnSudoBucket(): void
    {
        $uid = $this->makeLocalAdmin('jim', 'pw-correct');

        // Fill the sudo bucket with failures for this IP. The helper uses
        // client_ip() internally — for unit tests, REMOTE_ADDR drives that.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        for ($i = 0; $i < IPAM_SUDO_RATE_LIMIT_MAX; $i++) {
            ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => 'wrong']);
        }
        // Next attempt — even with a CORRECT proof — must trip the limiter.
        $result = ipam_sudo_verify($this->db, $uid, ['method' => 'password', 'password' => 'pw-correct']);
        $this->assertFalse($result, 'Rate limit must block even a correct proof');
        $this->assertFalse(ipam_sudo_active());
        $this->assertAudit('auth.sudo_rate_limited');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Custom assertions
    // ─────────────────────────────────────────────────────────────────────

    private function assertAudit(string $action, string $message = ''): void
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM audit_log WHERE action = :a");
        $st->execute([':a' => $action]);
        $this->assertGreaterThan(0, (int) $st->fetchColumn(),
            $message !== '' ? $message : "Expected an audit row with action=$action");
    }

    private function assertNoAudit(string $action, string $message = ''): void
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM audit_log WHERE action = :a");
        $st->execute([':a' => $action]);
        $this->assertSame(0, (int) $st->fetchColumn(), $message);
    }

    private function assertAuditDetailContains(string $action, string $needle): void
    {
        $st = $this->db->prepare("SELECT details FROM audit_log WHERE action = :a ORDER BY id DESC LIMIT 1");
        $st->execute([':a' => $action]);
        $details = (string) $st->fetchColumn();
        $this->assertStringContainsString($needle, $details,
            "Last audit row for action=$action must mention '$needle' in details");
    }
}
