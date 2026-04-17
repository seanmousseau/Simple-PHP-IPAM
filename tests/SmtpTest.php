<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ipam_send_mail() in lib.php.
 *
 * These tests exercise the routing logic and guard rails without
 * opening a real network connection. PHPMailer is not mocked —
 * instead we verify the native-mail() fallback path (smtp.enabled=false)
 * and the credential-leak guard (smtp.auth_pass must not appear in audit).
 */
class SmtpTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Minimal schema needed for ipam_setting() and audit()
        $this->db->exec("
            CREATE TABLE settings (
                key        TEXT PRIMARY KEY,
                value      TEXT,
                type       TEXT NOT NULL DEFAULT 'string',
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER
            );
            CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER,
                user_id INTEGER,
                username TEXT NOT NULL DEFAULT '',
                ip TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                details TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );
        ");

        $GLOBALS['db']     = $this->db;
        $GLOBALS['config'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['config']);
        // Flush ipam_setting() static cache so settings from one test don't
        // bleed into the next test's assertions.
        ipam_setting_cache_storage(null, true);
    }

    // -----------------------------------------------------------------------
    // Fallback path: smtp.enabled = false → native mail()
    // -----------------------------------------------------------------------

    public function testFallbackToNativeMailWhenSmtpDisabled(): void
    {
        // smtp.enabled is not set → defaults to false → uses mail() path
        $result = ipam_send_mail('test@example.com', 'Subject', 'Body');
        $this->assertSame('mail', $result['transport']);
        // We cannot assert 'success' because mail() depends on a real MTA.
        // What we CAN assert is that the function returns the expected shape.
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertNull($result['error']); // mail() fallback never sets error
    }

    public function testReturnShapeAlwaysPresent(): void
    {
        $result = ipam_send_mail('x@example.com', 'S', 'B');
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('transport', $result);
    }

    // -----------------------------------------------------------------------
    // SMTP path: smtp.enabled = true, missing host → PHPMailer exception
    // -----------------------------------------------------------------------

    public function testSmtpPathReturnsFailureOnBadHost(): void
    {
        // Force smtp.enabled on with a bogus host that cannot connect
        $this->db->exec("INSERT INTO settings (key, value, type) VALUES
            ('smtp.enabled',  '1',       'bool'),
            ('smtp.host',     '127.0.0.1', 'string'),
            ('smtp.port',     '19999',   'int'),
            ('smtp.timeout_seconds', '1', 'int'),
            ('smtp.encryption', 'none',  'string'),
            ('smtp.verify_peer', '0',    'bool')
        ");

        $result = ipam_send_mail('test@example.com', 'Subject', 'Body');
        $this->assertSame('smtp', $result['transport']);
        $this->assertFalse($result['success']);
        $this->assertNotNull($result['error']);
        $this->assertIsString($result['error']);
    }

    // -----------------------------------------------------------------------
    // Credential-leak guard: smtp.auth_pass must not appear in error strings
    // -----------------------------------------------------------------------

    public function testAuthPassNotLeakedInErrorMessage(): void
    {
        $secret = 'super-secret-password-12345';
        $this->db->exec("INSERT INTO settings (key, value, type) VALUES
            ('smtp.enabled',   '1',          'bool'),
            ('smtp.host',      '127.0.0.1',  'string'),
            ('smtp.port',      '19999',      'int'),
            ('smtp.timeout_seconds', '1',    'int'),
            ('smtp.encryption', 'none',      'string'),
            ('smtp.verify_peer', '0',        'bool'),
            ('smtp.auth_user', 'user@example.com', 'string'),
            ('smtp.auth_pass', " . $this->db->quote($secret) . ", 'string')
        ");

        $result = ipam_send_mail('test@example.com', 'Subject', 'Body');
        // Error message must not contain the raw password
        if ($result['error'] !== null) {
            $this->assertStringNotContainsString($secret, $result['error']);
        }
    }

    // -----------------------------------------------------------------------
    // Encryption setting mapping
    // -----------------------------------------------------------------------

    public function testEncryptionNoneDoesNotSetSmtpSecure(): void
    {
        // When encryption=none, SMTPAutoTLS must be disabled. We test this
        // indirectly: a connection attempt to a bogus host completes (fails
        // with connect error, not a TLS negotiation error) — the error string
        // should not contain "STARTTLS" or "SSL".
        $this->db->exec("INSERT INTO settings (key, value, type) VALUES
            ('smtp.enabled',   '1',         'bool'),
            ('smtp.host',      '127.0.0.1', 'string'),
            ('smtp.port',      '19999',     'int'),
            ('smtp.timeout_seconds', '1',   'int'),
            ('smtp.encryption', 'none',     'string'),
            ('smtp.verify_peer', '1',       'bool')
        ");

        $result = ipam_send_mail('test@example.com', 'Subject', 'Body');
        $this->assertSame('smtp', $result['transport']);
        // Should fail with a connection-refused / timeout error, not TLS error
        if ($result['error'] !== null) {
            $this->assertStringNotContainsStringIgnoringCase('starttls', $result['error']);
        }
    }
}
