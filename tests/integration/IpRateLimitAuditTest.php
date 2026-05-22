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
        // CR PR #1141 round 2: assert the schema read succeeded so a
        // missing/unreadable schema.sql fails fast with a clear message
        // instead of producing misleading downstream SQL errors.
        $schema = file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql');
        $this->assertNotFalse($schema, 'schema.sql must be readable in test setup');
        $this->db->exec($schema);
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
        // The `ip` column reflects client_ip() at audit() time. The
        // operator-relevant IP (the one being rate-limited) lives in
        // `details` so it's discoverable via grep regardless of the
        // remote-host substitution.
        $this->assertStringContainsString('ip=' . $ip, $rows[0]['details']);
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
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.1', 6, time() + 600);  // dampened (same IP, same window)

        $rows = $this->db->query("SELECT details FROM audit_log WHERE action = 'auth.ip_rate_limited' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows, '#1134: per-IP dampener — distinct IPs must each get a fresh audit row');
        // The IPs are in the details substring (audit_log.ip column reflects
        // client_ip() which is constant per-process in test).
        $this->assertStringContainsString('ip=192.0.2.1', $rows[0]['details']);
        $this->assertStringContainsString('ip=192.0.2.2', $rows[1]['details']);
    }

    public function testIpPrefixCollisionDoesNotDampenDistinctIps(): void
    {
        // CR PR #1141 regression: pre-fix, the dampener LIKE pattern
        // `'%action=login%ip=192.0.2.1%'` matched ip=192.0.2.10 too,
        // because the trailing `%` made the IP a prefix match. Distinct
        // IPs that share a prefix must each get their own audit row.
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.1',  5, time() + 600);
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.10', 5, time() + 600);
        ipam_audit_ip_rate_limited($this->db, 'login', '192.0.2.100', 5, time() + 600);

        $rows = $this->db->query(
            "SELECT details FROM audit_log WHERE action = 'auth.ip_rate_limited' ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(3, $rows, '#1134/CR1141: ip-prefix collision must not suppress distinct lockouts');
        $this->assertStringContainsString('ip=192.0.2.1',    $rows[0]['details']);
        $this->assertStringContainsString('ip=192.0.2.10',   $rows[1]['details']);
        $this->assertStringContainsString('ip=192.0.2.100',  $rows[2]['details']);
    }

    public function testDampenerStateRowTracksWindow(): void
    {
        // #1143: the once-per-window guarantee is now backed by the
        // rate_limit_dampener table (PRIMARY KEY (action, ip)) instead of an
        // audit_log scan. A single process can't truly race; this asserts the
        // contract the atomic UPDATE-then-INSERT-if-zero enforces — exactly
        // one tracking row per (action, ip), carrying the active window's
        // unlock_at, untouched while the window is still active and updated
        // in place once it expires.
        $unlockA = time() + 600;
        ipam_audit_ip_rate_limited($this->db, 'login', '198.51.100.4', 5, $unlockA);
        ipam_audit_ip_rate_limited($this->db, 'login', '198.51.100.4', 6, $unlockA + 999); // dampened

        $rows = $this->db->query(
            "SELECT action, ip, unlock_at FROM rate_limit_dampener WHERE ip = '198.51.100.4'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows, '#1143: exactly one dampener row per (action, ip)');
        $this->assertSame('login', $rows[0]['action']);
        $this->assertSame($unlockA, (int) $rows[0]['unlock_at'], 'active window must not be overwritten');

        // Expire the stored window, then a fresh fire claims a new one in place.
        $this->db->prepare("UPDATE rate_limit_dampener SET unlock_at = :u WHERE ip = :ip")
            ->execute([':u' => time() - 1, ':ip' => '198.51.100.4']);
        $unlockB = time() + 900;
        ipam_audit_ip_rate_limited($this->db, 'login', '198.51.100.4', 5, $unlockB);
        $rows = $this->db->query(
            "SELECT unlock_at FROM rate_limit_dampener WHERE ip = '198.51.100.4'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame($unlockB, (int) $rows[0]['unlock_at'], 'expired window updated in place');
    }

    public function testErrorMessageIsIpSpecific(): void
    {
        // Source-level: login.php's IP-rate-limit branch must produce a
        // user-facing message that mentions "network" or "IP" so the
        // operator/user can distinguish from the generic wrong-password
        // and account-level lockout messages.
        $login = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/login.php');
        // CR PR #1141: anchor on the call to ipam_audit_ip_rate_limited
        // — that's the production branch we care about, and the slice
        // around it covers the user-facing error message regardless of
        // how many leading comments accompany the rate-limit branch.
        $idx = strpos($login, 'ipam_audit_ip_rate_limited');
        $this->assertNotFalse($idx);
        $branch = substr($login, max(0, $idx - 800), 1200);

        $this->assertMatchesRegularExpression(
            '/network|this IP|from this/i',
            $branch,
            '#1134: IP rate-limit error message must distinguish from generic wrong-password / account-lockout'
        );
    }
}
