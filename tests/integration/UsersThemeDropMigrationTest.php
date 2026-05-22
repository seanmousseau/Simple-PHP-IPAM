<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../Simple-PHP-IPAM/migrations.php';

use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 Task 5.3 Chunk 4b (ADR-002) — the `3.30.0-user-preferences-drop-theme` migration.
 *
 * Asserts that the closure:
 *   - drops the `theme` column from the `users` table;
 *   - preserves all seeded user rows with id and other column values intact
 *     (v2.2.1-wipe-class data-preservation assertion);
 *   - recreates the `idx_users_oidc_sub` partial index after the SQLite rebuild;
 *   - recreates the `users_updated_at` trigger after the SQLite rebuild;
 *   - is idempotent — a second run is a no-op.
 *
 * SQLite-only: the migration closure is multi-engine but the test harness
 * mirrors the project precedent (UserPreferencesMigrationTest) which runs
 * SQLite unconditionally.
 */
final class UsersThemeDropMigrationTest extends TestCase
{
    private function openConnection(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $GLOBALS['db'] = $db;
        unset($GLOBALS['ipam_dialect']);
        ipam_dialect_from_config(['db_driver' => 'sqlite']);
        return $db;
    }

    /**
     * @return \Closure(PDO): void
     */
    private function loadMigration(): \Closure
    {
        $migs = ipam_migrations();
        $this->assertArrayHasKey('3.30.0-user-preferences-drop-theme', $migs);
        /** @var \Closure(PDO): void $closure */
        $closure = $migs['3.30.0-user-preferences-drop-theme'];
        return $closure;
    }

    /**
     * Seed a users table WITH the theme column (pre-chunk-4 shape) including
     * the partial index and updated_at trigger so the SQLite rebuild path is
     * genuinely exercised.
     *
     * @param array<int, array{username: string, theme: string, oidc_sub: string|null}> $users
     */
    private function seedUsersTable(PDO $db, array $users): void
    {
        // Full-shape users table including the theme column we are about to drop.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS users ("
            . "  id                       INTEGER PRIMARY KEY AUTOINCREMENT,"
            . "  username                 TEXT NOT NULL UNIQUE,"
            . "  password_hash            TEXT NOT NULL DEFAULT 'x',"
            . "  role                     TEXT NOT NULL DEFAULT 'admin',"
            . "  is_active                INTEGER NOT NULL DEFAULT 1,"
            . "  name                     TEXT NOT NULL DEFAULT '',"
            . "  email                    TEXT NOT NULL DEFAULT '',"
            . "  oidc_sub                 TEXT,"
            . "  last_login_at            TEXT,"
            . "  password_changed_at      TEXT,"
            . "  theme                    TEXT NOT NULL DEFAULT 'auto',"
            . "  timezone                 TEXT,"
            . "  pending_email            TEXT,"
            . "  pending_email_token_hash TEXT,"
            . "  pending_email_expires_at TEXT,"
            . "  totp_secret_enc          TEXT,"
            . "  totp_enabled             INTEGER NOT NULL DEFAULT 0,"
            . "  failed_auth_count        INTEGER NOT NULL DEFAULT 0,"
            . "  locked_until             TEXT,"
            . "  lock_reason              TEXT,"
            . "  email_otp_enabled        INTEGER NOT NULL DEFAULT 0,"
            . "  email_otp_hash           TEXT,"
            . "  email_otp_expires_at     TEXT,"
            . "  email_otp_attempts       INTEGER NOT NULL DEFAULT 0,"
            . "  preferred_mfa_method     TEXT,"
            . "  created_at               TEXT NOT NULL DEFAULT (datetime('now')),"
            . "  updated_at               TEXT NOT NULL DEFAULT (datetime('now'))"
            . ")"
        );
        // Partial index — this is what blocks SQLite DROP COLUMN and forces the rebuild.
        $db->exec(
            "CREATE UNIQUE INDEX IF NOT EXISTS idx_users_oidc_sub "
            . "ON users(oidc_sub) WHERE oidc_sub IS NOT NULL"
        );
        // updated_at trigger — must survive the rebuild.
        $db->exec(
            "CREATE TRIGGER IF NOT EXISTS users_updated_at "
            . "AFTER UPDATE ON users "
            . "FOR EACH ROW BEGIN "
            . "UPDATE users SET updated_at = datetime('now') WHERE id = OLD.id; "
            . "END"
        );

        $ins = $db->prepare(
            "INSERT INTO users (username, theme, oidc_sub) VALUES (:username, :theme, :oidc_sub)"
        );
        foreach ($users as $u) {
            $ins->execute([
                ':username' => $u['username'],
                ':theme'    => $u['theme'],
                ':oidc_sub' => $u['oidc_sub'],
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Test: theme column is dropped and all user rows survive intact
    // -------------------------------------------------------------------------

    public function testThemeColumnDroppedAndRowsPreserved(): void
    {
        $db = $this->openConnection();
        $this->seedUsersTable($db, [
            ['username' => 'alice', 'theme' => 'auto',  'oidc_sub' => null],
            ['username' => 'bob',   'theme' => 'dark',  'oidc_sub' => 'sub-bob-001'],
            ['username' => 'carol', 'theme' => 'light', 'oidc_sub' => null],
        ]);

        $migration = $this->loadMigration();
        $migration($db);

        // --- theme column must be gone ---
        $colInfo = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($colInfo, 'name');
        $this->assertNotContains('theme', $colNames, 'users.theme column must be absent after migration');

        // --- all three user rows must survive (v2.2.1-wipe-class assertion) ---
        $count = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertSame(3, $count, 'all seeded user rows must survive the table rebuild');

        // --- row values must be intact ---
        $rows = $db->query(
            "SELECT id, username, oidc_sub FROM users ORDER BY id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('alice', $rows[0]['username'], "alice's username must be preserved");
        $this->assertNull($rows[0]['oidc_sub'],           "alice's oidc_sub must be NULL");
        $this->assertSame('bob',   $rows[1]['username'], "bob's username must be preserved");
        $this->assertSame('sub-bob-001', $rows[1]['oidc_sub'], "bob's oidc_sub must be preserved");
        $this->assertSame('carol', $rows[2]['username'], "carol's username must be preserved");

        // --- IDs must be stable (sequential from seed order) ---
        $this->assertSame('1', (string) $rows[0]['id'], 'alice must retain id=1');
        $this->assertSame('2', (string) $rows[1]['id'], 'bob must retain id=2');
        $this->assertSame('3', (string) $rows[2]['id'], 'carol must retain id=3');

        // --- idx_users_oidc_sub partial index must be recreated with its WHERE clause ---
        $idxRow = $db->query(
            "SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_users_oidc_sub'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse(
            $idxRow,
            'idx_users_oidc_sub partial index must be recreated after the table rebuild'
        );
        $this->assertStringContainsStringIgnoringCase(
            'WHERE oidc_sub IS NOT NULL',
            (string) $idxRow['sql'],
            'idx_users_oidc_sub must retain its partial-index WHERE clause after the table rebuild'
        );

        // --- users_updated_at trigger must be recreated ---
        $trigRows = $db->query(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name='users_updated_at'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(
            1,
            $trigRows,
            'users_updated_at trigger must be recreated after the table rebuild'
        );
    }

    // -------------------------------------------------------------------------
    // Test: idempotency — second run is a no-op
    // -------------------------------------------------------------------------

    public function testMigrationIsIdempotent(): void
    {
        $db = $this->openConnection();
        $this->seedUsersTable($db, [
            ['username' => 'dave', 'theme' => 'dark',  'oidc_sub' => null],
            ['username' => 'eve',  'theme' => 'light', 'oidc_sub' => null],
        ]);

        $migration = $this->loadMigration();

        // First run — drops theme column.
        $migration($db);

        $colsAfterFirst = array_column(
            $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertNotContains('theme', $colsAfterFirst, 'theme must be gone after first run');

        $countAfterFirst = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertSame(2, $countAfterFirst, 'both seeded rows must survive the first run');

        // Second run — must be a no-op (early return when theme column is absent).
        $migration($db);

        $colsAfterSecond = array_column(
            $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertNotContains('theme', $colsAfterSecond, 'theme must still be absent after second run');

        $countAfterSecond = (int) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'row count must be unchanged by idempotent replay'
        );

        // Verify usernames are intact after second run too.
        $names = array_column(
            $db->query("SELECT username FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC),
            'username'
        );
        $this->assertSame(['dave', 'eve'], $names, 'usernames must survive idempotent replay');

        // --- idx_users_oidc_sub must still exist after idempotent replay ---
        $idxRow = $db->query(
            "SELECT sql FROM sqlite_master WHERE type='index' AND name='idx_users_oidc_sub'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse(
            $idxRow,
            'idx_users_oidc_sub must still exist after idempotent replay'
        );

        // --- users_updated_at trigger must still exist after idempotent replay ---
        $trigRow = $db->query(
            "SELECT name FROM sqlite_master WHERE type='trigger' AND name='users_updated_at'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse(
            $trigRow,
            'users_updated_at trigger must still exist after idempotent replay'
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['ipam_dialect']);
    }
}
