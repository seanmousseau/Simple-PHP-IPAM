<?php
declare(strict_types=1);

namespace Tests\Migration;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: v3.15.0-passkeys (#688) and v3.16.0-preferred-mfa-method (#746).
 * v3.6.0 TOTP migrations live in MiscTest (testV360MigrationsApply).
 */
final class MfaTest extends Base
{
    public function testPasskeysMigrationAddsWebAuthnCredentialsTable(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $cols = [];
        foreach ($db->query("PRAGMA table_info(webauthn_credentials)")->fetchAll() as $row) {
            $cols[] = $row['name'];
        }
        sort($cols);
        $expected = ['created_at', 'credential_id', 'id', 'last_used_at', 'name', 'public_key', 'sign_count', 'user_id'];
        $this->assertSame($expected, $cols);

        $db->exec("DELETE FROM schema_migrations WHERE version = '3.15.0-passkeys'");
        \apply_migrations($db);
        $cols2 = [];
        foreach ($db->query("PRAGMA table_info(webauthn_credentials)")->fetchAll() as $row) {
            $cols2[] = $row['name'];
        }
        sort($cols2);
        $this->assertSame($expected, $cols2);
    }

    public function testV316PreferredMfaMethodColumnAdded(): void
    {
        $db = $this->makePreVrfDb();
        $db->exec("
            CREATE TABLE users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role          TEXT NOT NULL DEFAULT 'admin',
                is_active     INTEGER NOT NULL DEFAULT 1,
                created_at    TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        \apply_migrations($db);

        $cols = $db->query("PRAGMA table_info(users)")->fetchAll();
        $match = array_values(array_filter(
            $cols,
            static fn(array $c): bool => (string)$c['name'] === 'preferred_mfa_method'
        ));
        $this->assertNotEmpty($match, 'preferred_mfa_method column missing on users');
        $this->assertSame(0, (int)$match[0]['notnull'], 'preferred_mfa_method must be nullable');

        $db->exec("INSERT INTO users (username, password_hash, role) VALUES ('v316user', 'h', 'admin')");
        $val = $db->query("SELECT preferred_mfa_method FROM users WHERE username='v316user'")->fetchColumn();
        $this->assertNull($val, 'preferred_mfa_method must default to NULL for new rows');

        $db->exec("DELETE FROM schema_migrations WHERE version = '3.16.0-preferred-mfa-method'");
        \apply_migrations($db);
        $colsAgain = $db->query("PRAGMA table_info(users)")->fetchAll();
        $countAgain = count(array_filter(
            $colsAgain,
            static fn(array $c): bool => (string)$c['name'] === 'preferred_mfa_method'
        ));
        $this->assertSame(1, $countAgain, 'preferred_mfa_method must still appear exactly once after re-run');
    }
}
