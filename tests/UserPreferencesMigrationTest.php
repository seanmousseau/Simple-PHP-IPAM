<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/migrations.php';

use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 Task 5.3 Chunk 2 (ADR-002) — the `3.30.0-user-preferences` migration.
 *
 * Asserts that the closure:
 *   - creates the user_preferences table when absent (SQLite);
 *   - backfills one `theme` row per user from users.theme with the correct value;
 *   - is idempotent — a second run produces no new rows and no value change;
 *   - skips the backfill gracefully when users.theme is absent.
 *
 * SQLite-only: the migration closure is multi-engine but the test harness
 * mirrors the project precedent (SettingDefinitionsMigrationTest) which runs
 * SQLite unconditionally and gates MySQL/Postgres on env vars.
 */
final class UserPreferencesMigrationTest extends TestCase
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
        $this->assertArrayHasKey('3.30.0-user-preferences', $migs);
        /** @var \Closure(PDO): void $closure */
        $closure = $migs['3.30.0-user-preferences'];
        return $closure;
    }

    /**
     * Seed a minimal users table (with theme column, matching the pre-chunk-4
     * schema) and insert the provided user rows.
     *
     * @param array<int, array{username: string, theme: string}> $users
     */
    private function seedUsersTable(PDO $db, array $users): void
    {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS users ("
            . "  id            INTEGER PRIMARY KEY AUTOINCREMENT,"
            . "  username      TEXT NOT NULL UNIQUE,"
            . "  password_hash TEXT NOT NULL DEFAULT 'x',"
            . "  role          TEXT NOT NULL DEFAULT 'admin',"
            . "  is_active     INTEGER NOT NULL DEFAULT 1,"
            . "  name          TEXT NOT NULL DEFAULT '',"
            . "  email         TEXT NOT NULL DEFAULT '',"
            . "  theme         TEXT NOT NULL DEFAULT 'auto',"
            . "  created_at    TEXT NOT NULL DEFAULT (datetime('now')),"
            . "  updated_at    TEXT NOT NULL DEFAULT (datetime('now'))"
            . ")"
        );
        $ins = $db->prepare("INSERT INTO users (username, theme) VALUES (:username, :theme)");
        foreach ($users as $u) {
            $ins->execute([':username' => $u['username'], ':theme' => $u['theme']]);
        }
    }

    // -------------------------------------------------------------------------
    // Test: table created and theme rows backfilled with correct values
    // -------------------------------------------------------------------------

    public function testUserPreferencesTableCreatedAndThemeBackfilled(): void
    {
        $db = $this->openConnection();
        $this->seedUsersTable($db, [
            ['username' => 'alice', 'theme' => 'auto'],
            ['username' => 'bob',   'theme' => 'dark'],
            ['username' => 'carol', 'theme' => 'light'],
        ]);

        $migration = $this->loadMigration();
        $migration($db);

        // Table must now exist.
        $exists = (bool) $db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='user_preferences'"
        )->fetchColumn();
        $this->assertTrue($exists, 'user_preferences table must be created by the migration');

        // Exactly one theme row per user.
        $countRow = $db->query(
            "SELECT COUNT(*) AS c FROM user_preferences WHERE key = 'theme'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($countRow);
        $this->assertSame(3, (int) $countRow['c'], 'exactly one theme row per user must be backfilled');

        // Each user's theme value must match what was in users.theme.
        $expected = [
            'alice' => 'auto',
            'bob'   => 'dark',
            'carol' => 'light',
        ];
        foreach ($expected as $username => $theme) {
            $stmt = $db->prepare(
                "SELECT up.value FROM user_preferences up "
                . "JOIN users u ON u.id = up.user_id "
                . "WHERE u.username = :u AND up.key = 'theme'"
            );
            $stmt->execute([':u' => $username]);
            $got = $stmt->fetchColumn();
            $this->assertSame(
                $theme,
                $got,
                "user_preferences.value for '{$username}' must equal users.theme ('{$theme}')"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Test: idempotency — second run must not duplicate rows or change values
    // -------------------------------------------------------------------------

    public function testMigrationIsIdempotent(): void
    {
        $db = $this->openConnection();
        $this->seedUsersTable($db, [
            ['username' => 'dave', 'theme' => 'dark'],
            ['username' => 'eve',  'theme' => 'light'],
        ]);

        $migration = $this->loadMigration();
        $migration($db);

        $firstRow = $db->query(
            "SELECT COUNT(*) AS c FROM user_preferences WHERE key = 'theme'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($firstRow);
        $first = (int) $firstRow['c'];

        // Replay — WHERE NOT EXISTS guard must prevent duplicate rows.
        $migration($db);

        $secondRow = $db->query(
            "SELECT COUNT(*) AS c FROM user_preferences WHERE key = 'theme'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($secondRow);
        $second = (int) $secondRow['c'];

        $this->assertSame($first, $second, 'replay must not change the theme row count');

        // Values must be byte-identical after replay.
        $rows = $db->query(
            "SELECT u.username, up.value FROM user_preferences up "
            . "JOIN users u ON u.id = up.user_id "
            . "WHERE up.key = 'theme' ORDER BY u.username ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows, 'exactly two theme rows must survive replay');
        // ORDER BY username ASC: dave < eve
        $this->assertSame('dark',  $rows[0]['value'], "dave's theme must be unchanged after replay");
        $this->assertSame('light', $rows[1]['value'], "eve's theme must be unchanged after replay");
    }

    // -------------------------------------------------------------------------
    // Test: graceful no-op when users.theme column is absent
    // -------------------------------------------------------------------------

    public function testMigrationSkipsBackfillWhenThemeColumnAbsent(): void
    {
        $db = $this->openConnection();

        // Minimal users table WITHOUT the theme column — simulates a fixture
        // that marks 1.11 as applied but didn't actually run it.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS users ("
            . "  id            INTEGER PRIMARY KEY AUTOINCREMENT,"
            . "  username      TEXT NOT NULL UNIQUE,"
            . "  password_hash TEXT NOT NULL DEFAULT 'x'"
            . ")"
        );
        $db->exec("INSERT INTO users (username) VALUES ('frank')");

        $migration = $this->loadMigration();

        // Must not throw even though users.theme is absent.
        $migration($db);

        $countRow = $db->query(
            "SELECT COUNT(*) AS c FROM user_preferences"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($countRow);
        $this->assertSame(
            0,
            (int) $countRow['c'],
            'no rows must be inserted when users.theme column is absent'
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['ipam_dialect']);
    }
}
