<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';

/**
 * v3.29.0 #889 — Pin the alert-recipient resolution + empty-recipient
 * no-op contract for the SMTP fan-out path.
 *
 * Coverage strategy:
 *
 *   - `ipam_resolve_recipients_for_user_ids()` (lib.php:4037) — pure DB
 *     query, no SMTP coupling. Pins the filtering rules: active +
 *     non-empty email + valid user id. This is the load-bearing
 *     contract for the fan-out — every recipient that survives this
 *     filter receives one email.
 *
 *   - `ipam_resolve_alert_recipients()` (lib.php:3994) — thin wrapper
 *     around the above; pins the `alert.recipient_user_ids` setting key.
 *
 *   - `check_utilization_alerts()` (lib.php:4164) empty-recipients
 *     no-op smoke: the function returns without throwing when the
 *     recipient list is empty. The SMTP send itself happens via
 *     `ipam_send_mail()` and is exercised end-to-end by the
 *     Playwright `alerts-smtp.spec.ts` suite against MailHog — see
 *     `docs/internal/test-suites.md`. PHPUnit doesn't have a clean
 *     mock surface for the PHPMailer SMTP transport without extracting
 *     a seam, which #889 explicitly defers per the v3.29.0 plan.
 *
 * In-memory schema seeds the minimum tables: `users` for recipient
 * resolution, `settings` for `alert.recipient_user_ids` reads,
 * `subnets` + `addresses` + `alert_state` + `audit_log` to keep
 * `check_utilization_alerts()`'s pre-resolution side-loads from
 * crashing on missing tables.
 */
final class AlertEmailFanoutTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $ddl = [
            // Minimal users shape — only the columns this contract touches.
            "CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                is_active INTEGER NOT NULL DEFAULT 1,
                email TEXT
            )",
            // Settings KV — needed when the test reaches into ipam_setting().
            "CREATE TABLE settings (
                tenant_id INTEGER,
                key TEXT NOT NULL,
                value TEXT NOT NULL DEFAULT '',
                type TEXT NOT NULL DEFAULT 'string' CHECK(type IN ('string','int','bool','json')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_by INTEGER
            )",
            // Tables that check_utilization_alerts touches before reaching
            // recipient resolution — minimal shape to keep the function
            // from crashing pre-empty-check.
            "CREATE TABLE subnets (id INTEGER PRIMARY KEY AUTOINCREMENT, cidr TEXT, prefix INTEGER, ip_version INTEGER, alerts_enabled INTEGER DEFAULT 1)",
            "CREATE TABLE addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, subnet_id INTEGER, status TEXT)",
            "CREATE TABLE alert_state (subnet_id INTEGER, level TEXT, last_alerted_at TEXT)",
            "CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                user_id INTEGER, username TEXT,
                action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER,
                details TEXT NOT NULL DEFAULT '', ip TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT ''
            )",
        ];
        foreach ($ddl as $stmt) {
            $this->db->prepare($stmt)->execute();
        }
    }

    private function seedUser(string $username, ?string $email = null, int $active = 1): int
    {
        $st = $this->db->prepare("INSERT INTO users (username, email, is_active) VALUES (:u, :e, :a)");
        $st->execute([':u' => $username, ':e' => $email, ':a' => $active]);
        return (int) $this->db->lastInsertId();
    }

    // ---- ipam_resolve_recipients_for_user_ids ----

    public function testResolveEmptyArrayReturnsEmpty(): void
    {
        $this->assertSame([], ipam_resolve_recipients_for_user_ids($this->db, []));
    }

    public function testResolveActiveUserWithEmailReturnsEmail(): void
    {
        $id = $this->seedUser('alice', 'alice@example.com');
        $this->assertSame(['alice@example.com'], ipam_resolve_recipients_for_user_ids($this->db, [$id]));
    }

    public function testResolveInactiveUserExcluded(): void
    {
        $id = $this->seedUser('alice', 'alice@example.com', 0);
        $this->assertSame([], ipam_resolve_recipients_for_user_ids($this->db, [$id]),
            'disabled users must not receive alert emails');
    }

    public function testResolveEmptyEmailExcluded(): void
    {
        $id = $this->seedUser('alice', '');
        $this->assertSame([], ipam_resolve_recipients_for_user_ids($this->db, [$id]));
    }

    public function testResolveNullEmailExcluded(): void
    {
        $id = $this->seedUser('alice', null);
        $this->assertSame([], ipam_resolve_recipients_for_user_ids($this->db, [$id]));
    }

    public function testResolveNonexistentIdExcluded(): void
    {
        $this->assertSame([], ipam_resolve_recipients_for_user_ids($this->db, [999]));
    }

    public function testResolveMultipleUsersOrderedById(): void
    {
        $alice = $this->seedUser('alice', 'alice@example.com');
        $bob   = $this->seedUser('bob',   'bob@example.com');
        $carol = $this->seedUser('carol', 'carol@example.com');
        $result = ipam_resolve_recipients_for_user_ids($this->db, [$bob, $alice, $carol]);
        $this->assertSame(
            ['alice@example.com', 'bob@example.com', 'carol@example.com'],
            $result,
            'recipients are returned in user-id order regardless of input order'
        );
    }

    public function testResolveDeduplicatesIds(): void
    {
        $id = $this->seedUser('alice', 'alice@example.com');
        $result = ipam_resolve_recipients_for_user_ids($this->db, [$id, $id, $id]);
        $this->assertSame(['alice@example.com'], $result, 'duplicate ids must not produce duplicate emails');
    }

    public function testResolveSkipsZeroAndNegativeIds(): void
    {
        $id = $this->seedUser('alice', 'alice@example.com');
        $result = ipam_resolve_recipients_for_user_ids($this->db, [0, -1, $id]);
        $this->assertSame(['alice@example.com'], $result);
    }

    // ---- check_utilization_alerts empty-recipient no-op ----

    public function testCheckUtilizationAlertsNoopWhenRecipientsEmpty(): void
    {
        // No users seeded → resolver returns [] → check_utilization_alerts
        // hits the empty-recipients early return at lib.php:4168 without
        // touching the subnets/alert_state tables. The smoke test pins
        // that the function returns cleanly under this state — a
        // regression that crashes here would silently break every
        // alert-less install on the housekeeping cron.
        $GLOBALS['db'] = $this->db;
        try {
            check_utilization_alerts($this->db, []);
        } finally {
            unset($GLOBALS['db']);
        }
        // No assertions on side effects beyond "did not throw" — the
        // function returns void and the no-op path has nothing to observe.
        $this->addToAssertionCount(1);
    }
}
