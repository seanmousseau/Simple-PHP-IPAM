<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * IP-level lockout audit + UI visibility (#1134, v3.27.3).
 *
 * Pre-fix: when login_rate_limited returns true, login.php audits
 * 'auth.login_blocked' with username=NULL and details='ip=...'. The
 * audit row exists but operators searching the audit log by username
 * (the most common path) don't see it. And the row fires on EVERY
 * blocked attempt within the window — once a single IP trips the
 * limit, it floods the log with one row per refused attempt for the
 * next N minutes.
 *
 * Post-fix design:
 *   - New audit verb 'auth.ip_rate_limited' fires ONCE per rate-limit
 *     window with IP + attempt count + unlock_at.
 *   - Subsequent attempts in the same window do NOT re-emit (dampener).
 *   - login.php error message becomes IP-specific, distinguishable
 *     from the existing account-level lockout message.
 */
final class IpRateLimitAuditTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($this->db);
        apply_migrations($this->db);
    }

    public function testFirstFireEmitsAuditRow(): void
    {
        $ip = '192.0.2.99';
        ipam_audit_ip_rate_limited($this->db, 'login', $ip, 5, time() + 600);

        $rows = $this->db->query("SELECT action, ip, details FROM audit_log WHERE action = 'auth.ip_rate_limited' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, '#1134: first fire must emit one audit row');
        $this->assertSame($ip, $rows[0]['ip']);
        $this->assertStringContainsString('attempts=5', $rows[0]['details']);
        $this->assertStringContainsString('unlock_at=', $rows[0]['details']);
        $this->assertStringContainsString('action=login', $rows[0]['details']);
    }

    public function testSubsequentFireWithinWindowSkipsAuditRow(): void
    {
        $ip = '192.0.2.99';
        $unlockAt = time() + 600;
        ipam_audit_ip_rate_limited($this->db, 'login', $ip, 5, $unlockAt);
        ipam_audit_ip_rate_limited($this->db, 'login', $ip, 6, $unlockAt);
        ipam_audit_ip_rate_limited($this->db, 'login', $ip, 7, $unlockAt);

        $rows = $this->db->query("SELECT id FROM audit_log WHERE action = 'auth.ip_rate_limited'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, '#1134: dampener must prevent re-emission within the same window');
    }

    public function testNewWindowReFires(): void
    {
        $ip = '192.0.2.99';
        ipam_audit_ip_rate_limited($this->db, 'login', $ip, 5, time() - 1);
        ipam_audit_ip_rate_limited($this->db, 'login', $ip, 5, time() + 600);

        $rows = $this->db->query("SELECT id FROM audit_log WHERE action = 'auth.ip_rate_limited'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows, '#1134: a new lockout window after expiry must emit a fresh audit row');
    }

    public function testDifferentIpFiresIndependently(): void
    {
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.1', 5, time() + 600);
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.2', 5, time() + 600);
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.1', 6, time() + 600);

        $rows = $this->db->query("SELECT ip FROM audit_log WHERE action = 'auth.ip_rate_limited' ORDER BY ip")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows, '#1134: per-IP dampener — distinct IPs must each get a fresh audit row');
        $this->assertSame(['192.0.2.1', '192.0.2.2'], array_column($rows, 'ip'));
    }

    public function testErrorMessageIsIpSpecific(): void
    {
        // Source-level: login.php's IP-rate-limit branch must produce a
        // user-facing message that mentions "network" or "IP" so the
        // operator/user can distinguish from the generic wrong-password
        // and account-level lockout messages.
        $login = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/login.php');
        $idx = strpos($login, 'login_rate_limited');
        $this->assertNotFalse($idx);
        $branch = substr($login, $idx, 600);

        $this->assertMatchesRegularExpression(
            '/network|this IP|from this/i',
            $branch,
            '#1134: IP rate-limit error message must distinguish from generic wrong-password / account-lockout'
        );
    }
}
