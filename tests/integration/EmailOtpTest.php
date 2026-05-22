<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Email OTP library functions (#684).
 * Uses in-memory SQLite with the users table email_otp_* columns.
 */
class EmailOtpTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec("
            CREATE TABLE users (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                username             TEXT NOT NULL UNIQUE,
                password_hash        TEXT NOT NULL DEFAULT '',
                role                 TEXT NOT NULL DEFAULT 'readonly',
                is_active            INTEGER NOT NULL DEFAULT 1,
                email                TEXT,
                email_otp_enabled    INTEGER NOT NULL DEFAULT 0,
                email_otp_hash       TEXT,
                email_otp_expires_at TEXT,
                email_otp_attempts   INTEGER NOT NULL DEFAULT 0
            )
        ");
        $this->db->exec("
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT, entity_type TEXT,
                entity_id INTEGER, user_id INTEGER, username TEXT, ip TEXT, user_agent TEXT,
                details TEXT, created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $this->db->exec("INSERT INTO users (username, email) VALUES ('alice', 'alice@example.com')");
        $GLOBALS['db'] = $this->db;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db']);
    }

    private function userId(): int
    {
        return (int)$this->db->query("SELECT id FROM users WHERE username='alice'")->fetchColumn();
    }

    public function testGenerateReturns6DigitString(): void
    {
        $code = ipam_email_otp_generate($this->db, $this->userId());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testGenerateStoresHashNotPlaintext(): void
    {
        $code = ipam_email_otp_generate($this->db, $this->userId());
        $row = $this->db->query("SELECT email_otp_hash FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertNotEmpty($row['email_otp_hash']);
        $this->assertNotEquals($code, $row['email_otp_hash'], 'must not store plaintext code');
        $this->assertTrue(password_verify($code, $row['email_otp_hash']), 'stored hash must verify against code');
    }

    public function testGenerateSetsExpiry(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        $row = $this->db->query("SELECT email_otp_expires_at FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertNotEmpty($row['email_otp_expires_at']);
    }

    public function testGenerateResetsAttempts(): void
    {
        // Set attempts to 3 first
        $this->db->exec("UPDATE users SET email_otp_attempts = 3 WHERE id={$this->userId()}");
        ipam_email_otp_generate($this->db, $this->userId());
        $row = $this->db->query("SELECT email_otp_attempts FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertSame(0, (int)$row['email_otp_attempts']);
    }

    public function testVerifyCorrectCodeSucceeds(): void
    {
        $code = ipam_email_otp_generate($this->db, $this->userId());
        $result = ipam_email_otp_verify($this->db, $this->userId(), $code);
        $this->assertTrue($result);
    }

    public function testVerifyCorrectCodeClearsHash(): void
    {
        $code = ipam_email_otp_generate($this->db, $this->userId());
        ipam_email_otp_verify($this->db, $this->userId(), $code);
        $row = $this->db->query("SELECT email_otp_hash, email_otp_expires_at FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertNull($row['email_otp_hash']);
        $this->assertNull($row['email_otp_expires_at']);
    }

    public function testVerifyWrongCodeFails(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        $result = ipam_email_otp_verify($this->db, $this->userId(), '000000');
        $this->assertFalse($result);
    }

    public function testVerifyWrongCodeIncrementsAttempts(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        ipam_email_otp_verify($this->db, $this->userId(), '000000');
        $row = $this->db->query("SELECT email_otp_attempts FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertSame(1, (int)$row['email_otp_attempts']);
    }

    public function testVerifyExpiredCodeFails(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        // Manually set expiry to the past
        $this->db->exec("UPDATE users SET email_otp_expires_at = datetime('now', '-1 minute') WHERE id={$this->userId()}");
        $known = '123456';
        $hash = password_hash($known, PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE users SET email_otp_hash = ? WHERE id = ?")->execute([$hash, $this->userId()]);
        $result = ipam_email_otp_verify($this->db, $this->userId(), $known);
        $this->assertFalse($result, 'expired OTP must be rejected even with correct code');
    }

    public function testVerifyRateLimitedAfterFiveAttempts(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        // Simulate 5 failed attempts
        $this->db->exec("UPDATE users SET email_otp_attempts = 5 WHERE id={$this->userId()}");
        $known = '654321';
        $hash = password_hash($known, PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE users SET email_otp_hash = ?, email_otp_expires_at = datetime('now', '+10 minutes') WHERE id = ?")->execute([$hash, $this->userId()]);
        $result = ipam_email_otp_verify($this->db, $this->userId(), $known);
        $this->assertFalse($result, 'must reject after 5 attempts even with correct code');
    }

    public function testClearRemovesOtpData(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        ipam_email_otp_clear($this->db, $this->userId());
        $row = $this->db->query("SELECT email_otp_hash, email_otp_expires_at, email_otp_attempts FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertNull($row['email_otp_hash']);
        $this->assertNull($row['email_otp_expires_at']);
        $this->assertSame(0, (int)$row['email_otp_attempts']);
    }

    // ---- v3.29.0 #905 — TTL boundary edges ----
    //
    // The verify path uses `if ($expires < gmdate(...)) { reject }` (lib.php:12015).
    // That's strict less-than, so:
    //   - expires exactly equal to now  → still valid (boundary inclusive)
    //   - expires one second before now → rejected
    // These two tests pin both sides of that boundary so a future
    // refactor to `<=` or a higher-resolution timestamp comparison
    // (e.g. switching to microtime) fails noisily.
    //
    // CSRF-on-resend coverage (the other half of #905's issue body) is
    // NOT applicable to v3.29.0: email_otp_verify.php has no `resend`
    // action — re-issuing the OTP requires returning to login.php and
    // re-authenticating. If a resend handler lands in a future release,
    // its CSRF gate needs a sibling test here.

    public function testVerifyTokenAtExactExpiryStillSucceeds(): void
    {
        // Pin the strict-less-than semantics: when expires_at equals the
        // current UTC timestamp, the verify path treats the token as
        // still valid. Set the expiry to whatever the DB clock reports
        // as "now" so the comparison hits exact equality at the column
        // value vs gmdate('Y-m-d H:i:s') in PHP. Because both sides go
        // through second-resolution string comparison, this is stable.
        ipam_email_otp_generate($this->db, $this->userId());
        $known = '424242';
        $hash  = password_hash($known, PASSWORD_DEFAULT);
        // Use PHP's gmdate so the test mirrors the production path
        // exactly (the verify function compares against gmdate('Y-m-d H:i:s')).
        $now = gmdate('Y-m-d H:i:s');
        $this->db->prepare("UPDATE users SET email_otp_hash = ?, email_otp_expires_at = ? WHERE id = ?")
                 ->execute([$hash, $now, $this->userId()]);
        $result = ipam_email_otp_verify($this->db, $this->userId(), $known);
        $this->assertTrue($result, 'OTP with expiry exactly equal to "now" must still verify (strict-less-than boundary is inclusive)');
    }

    public function testVerifyTokenOneSecondPastExpiryFails(): void
    {
        ipam_email_otp_generate($this->db, $this->userId());
        $known = '424242';
        $hash  = password_hash($known, PASSWORD_DEFAULT);
        // 1 second in the past relative to PHP's UTC clock. Use a 2-second
        // offset to defuse millisecond-boundary races on slow CI runners.
        $past = gmdate('Y-m-d H:i:s', time() - 2);
        $this->db->prepare("UPDATE users SET email_otp_hash = ?, email_otp_expires_at = ? WHERE id = ?")
                 ->execute([$hash, $past, $this->userId()]);
        $result = ipam_email_otp_verify($this->db, $this->userId(), $known);
        $this->assertFalse($result, 'OTP one second past expiry must be rejected');
    }

    public function testVerifyPastExpiryClearsHashAndAuditsExpired(): void
    {
        // Beyond rejecting the code, the production path clears the
        // hash and writes an mfa.otp.expired audit row (lib.php:12015-12019).
        // Pin both so a future refactor that just returns false without
        // cleanup is caught.
        ipam_email_otp_generate($this->db, $this->userId());
        $known = '424242';
        $hash  = password_hash($known, PASSWORD_DEFAULT);
        $past  = gmdate('Y-m-d H:i:s', time() - 60);
        $this->db->prepare("UPDATE users SET email_otp_hash = ?, email_otp_expires_at = ? WHERE id = ?")
                 ->execute([$hash, $past, $this->userId()]);

        ipam_email_otp_verify($this->db, $this->userId(), $known);

        $row = $this->db->query("SELECT email_otp_hash, email_otp_expires_at FROM users WHERE id={$this->userId()}")->fetch();
        $this->assertNull($row['email_otp_hash'], 'expired-path must clear stored hash');
        $this->assertNull($row['email_otp_expires_at'], 'expired-path must clear stored expiry');

        $auditCount = (int) $this->db->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'mfa.otp.expired' AND entity_type = 'user'"
        )->fetchColumn();
        $this->assertSame(1, $auditCount, 'expired-path must emit exactly one mfa.otp.expired audit row');
    }
}
