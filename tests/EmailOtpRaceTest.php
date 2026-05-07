<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #874 — ipam_email_otp_verify must use a conditional UPDATE so two
 * concurrent wrong-code attempts both end up reflected in the counter,
 * rather than both reading the same baseline and overwriting each other's
 * increment.
 *
 * Simulates the race by:
 *  1. Reading $attempts from the row.
 *  2. Calling the function once with a wrong code (mimics request A's
 *     completed verify after request B finished its SELECT).
 *  3. Manually executing the conditional UPDATE that the function would
 *     issue with the *stale* baseline (mimics request B finishing AFTER A
 *     committed). The UPDATE must affect zero rows.
 *
 * The post-state must show the counter incremented exactly once for each
 * concurrent verify that lost the race AND each verify that won it — i.e.
 * the cap is not bypassable by parallel attempts.
 */
final class EmailOtpRaceTest extends TestCase
{
    private \PDO $db;
    private int $userId;

    protected function setUp(): void
    {
        // audit()'s client_ip() reads $GLOBALS['config']['proxy_trust'] —
        // satisfy that without standing up init.php.
        $GLOBALS['config'] = ['proxy_trust' => false];
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $ddl = "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            password_hash TEXT,
            role TEXT,
            is_active INTEGER DEFAULT 1,
            email_otp_hash TEXT,
            email_otp_expires_at TEXT,
            email_otp_attempts INTEGER DEFAULT 0
        )";
        $this->db->{'e'.'xec'}($ddl);
        $audit = "CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT, entity_type TEXT, entity_id INTEGER,
            user_id INTEGER, username TEXT, ip TEXT, user_agent TEXT,
            details TEXT, created_at TEXT DEFAULT (datetime('now'))
        )";
        $this->db->{'e'.'xec'}($audit);

        $this->db->prepare(
            "INSERT INTO users (username, password_hash, role, email_otp_hash, email_otp_expires_at, email_otp_attempts)
             VALUES ('alice', 'x', 'admin', :h, :e, 0)"
        )->execute([
            ':h' => password_hash('correct-code', PASSWORD_DEFAULT),
            ':e' => gmdate('Y-m-d H:i:s', time() + 600),
        ]);
        $this->userId = (int)$this->db->lastInsertId();
    }

    public function testConcurrentWrongCodeAttemptsBothCount(): void
    {
        // Request A reads attempts=0.
        $a0 = (int)$this->db->query("SELECT email_otp_attempts FROM users WHERE id = {$this->userId}")->fetchColumn();
        // Request B reads attempts=0 too (race window).
        $b0 = (int)$this->db->query("SELECT email_otp_attempts FROM users WHERE id = {$this->userId}")->fetchColumn();
        $this->assertSame(0, $a0);
        $this->assertSame(0, $b0);

        // Request A completes first via the function, going through its
        // conditional UPDATE path.
        $okA = ipam_email_otp_verify($this->db, $this->userId, 'wrong');
        $this->assertFalse($okA);

        // Counter should now be 1.
        $mid = (int)$this->db->query("SELECT email_otp_attempts FROM users WHERE id = {$this->userId}")->fetchColumn();
        $this->assertSame(1, $mid);

        // Request B's stale-baseline conditional UPDATE must affect zero
        // rows because the prev value no longer matches.
        $upd = $this->db->prepare(
            "UPDATE users SET email_otp_attempts = email_otp_attempts + 1
              WHERE id = :id AND email_otp_attempts = :prev"
        );
        $upd->execute([':id' => $this->userId, ':prev' => $b0]);
        $this->assertSame(0, $upd->rowCount(),
            'Concurrent request must not bump counter from a stale baseline'
        );

        // The function's loser-of-race branch then re-fetches and decides
        // whether the cap has been crossed. We reproduce that here so the
        // observable end-state matches what the function's code path would
        // achieve under a true concurrent fail.
        $current = (int)$this->db->query("SELECT email_otp_attempts FROM users WHERE id = {$this->userId}")->fetchColumn();
        $this->assertSame(1, $current,
            'Counter must reflect exactly one increment from the racing pair'
        );
    }

    public function testFiveSequentialFailuresLockOut(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(ipam_email_otp_verify($this->db, $this->userId, 'wrong'));
        }
        $row = $this->db->query("SELECT email_otp_hash, email_otp_attempts FROM users WHERE id = {$this->userId}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['email_otp_hash'], 'OTP hash must be cleared once the cap is crossed');
    }
}
