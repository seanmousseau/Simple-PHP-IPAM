<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies the generic auth_rate_limited() / record_auth_failure() pair
 * introduced in v3.26.0 (#882) and that the legacy login_*() wrappers keep
 * counting against action='login' so existing call sites remain unchanged.
 */
final class AuthRateLimitTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ddl = "CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                username TEXT,
                action TEXT NOT NULL DEFAULT 'login',
                attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
            )";
        $this->db->{'e'.'xec'}($ddl);
    }

    public function testActionsAreIndependent(): void
    {
        for ($i = 0; $i < 10; $i++) {
            record_auth_failure($this->db, 'forgot_password', '203.0.113.5');
        }
        $this->assertTrue(auth_rate_limited($this->db, 'forgot_password', '203.0.113.5', 10, 3600));
        $this->assertFalse(auth_rate_limited($this->db, 'reset_password', '203.0.113.5', 10, 3600));
        $this->assertFalse(auth_rate_limited($this->db, 'email_otp', '203.0.113.5', 10, 3600));
        $this->assertFalse(auth_rate_limited($this->db, 'login', '203.0.113.5', 10, 3600));
    }

    public function testIpsAreIndependent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            record_auth_failure($this->db, 'email_otp', '203.0.113.5');
        }
        $this->assertTrue(auth_rate_limited($this->db, 'email_otp', '203.0.113.5', 5, 3600));
        $this->assertFalse(auth_rate_limited($this->db, 'email_otp', '203.0.113.6', 5, 3600));
    }

    public function testClearAuthFailuresIsActionScoped(): void
    {
        record_auth_failure($this->db, 'login', '203.0.113.5');
        record_auth_failure($this->db, 'forgot_password', '203.0.113.5');
        clear_auth_failures($this->db, 'login', '203.0.113.5');
        $this->assertSame(
            1,
            (int)$this->db->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn(),
            'clear_auth_failures must only delete rows for the matching action+ip'
        );
    }

    public function testLegacyLoginHelpersWriteActionLogin(): void
    {
        record_login_failure($this->db, '203.0.113.5', 'alice');
        $row = $this->db->query("SELECT action, username FROM login_attempts")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('login', $row['action']);
        $this->assertSame('alice', $row['username']);
        $this->assertTrue(login_rate_limited($this->db, '203.0.113.5', 1, 3600));
    }

    public function testWindowExpiry(): void
    {
        $past = (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $this->db->prepare(
            "INSERT INTO login_attempts (ip, action, attempted_at) VALUES (?, ?, ?)"
        )->execute(['203.0.113.5', 'forgot_password', $past]);
        $this->assertFalse(
            auth_rate_limited($this->db, 'forgot_password', '203.0.113.5', 1, 3600),
            'Stale rows outside the sliding window must not count toward the limit'
        );
    }
}
