<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 Task 5.3 Chunk 3 (ADR-002) — unit tests for the user_preferences
 * module (lib/user_preferences.php) and static-inspection wiring tests for
 * the user_preference.php endpoint.
 *
 * Module tests: SQLite in-memory PDO, same harness style as
 * UserPreferencesMigrationTest / SettingDefinitionsMigrationTest.
 *
 * Endpoint wiring tests: static source inspection, same pattern as
 * SettingsValidateDispatchWiringTest — the endpoint runs as a top-level
 * script (require init.php then redirect then exit) and cannot be invoked
 * from phpunit, so we assert on the source text instead.
 */
final class UserPreferencesTest extends TestCase
{
    private PDO    $db;
    private string $endpointSrc = '';

    // -------------------------------------------------------------------------
    // Harness helpers
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Simple-PHP-IPAM/lib/user_preferences.php';

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Create the user_preferences table matching the migration schema.
        $db->exec(
            "CREATE TABLE IF NOT EXISTS user_preferences ("
            . "  user_id    INTEGER NOT NULL,"
            . "  \"key\"    TEXT NOT NULL,"
            . "  value      TEXT,"
            . "  updated_at TEXT NOT NULL DEFAULT (datetime('now')),"
            . "  PRIMARY KEY (user_id, \"key\")"
            . ")"
        );

        $GLOBALS['db'] = $db;
        unset($GLOBALS['ipam_dialect']);
        ipam_dialect_from_config(['db_driver' => 'sqlite']);

        $this->db = $db;

        // Load endpoint source once here so all wiring tests can use it.
        $src = file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/user_preference.php');
        $this->assertNotEmpty($src, 'user_preference.php must be readable');
        $this->endpointSrc = (string) $src;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['db'], $GLOBALS['ipam_dialect']);
    }

    // -------------------------------------------------------------------------
    // Test: round-trip — set then get returns the stored value
    // -------------------------------------------------------------------------

    public function testSetAndGetRoundTrip(): void
    {
        ipam_user_preference_set($this->db, 1, 'theme', 'dark');
        $got = ipam_user_preference_get($this->db, 1, 'theme');
        $this->assertSame('dark', $got, 'get() must return the value written by set()');
    }

    // -------------------------------------------------------------------------
    // Test: UPSERT idempotence — second set updates in place, no duplicate row
    // -------------------------------------------------------------------------

    public function testUpsertUpdatesExistingRow(): void
    {
        ipam_user_preference_set($this->db, 2, 'theme', 'light');
        ipam_user_preference_set($this->db, 2, 'theme', 'dark');

        // Exactly one row for this (user_id, key) pair.
        $countRow = $this->db->query(
            "SELECT COUNT(*) AS c FROM user_preferences WHERE user_id = 2 AND \"key\" = 'theme'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($countRow);
        $this->assertSame(1, (int) $countRow['c'], 'exactly one row must exist after two set() calls');

        // The stored value must be the second (newer) one.
        $this->assertSame(
            'dark',
            ipam_user_preference_get($this->db, 2, 'theme'),
            'value must reflect the most-recent set() call'
        );
    }

    // -------------------------------------------------------------------------
    // Test: absent key returns null
    // -------------------------------------------------------------------------

    public function testGetAbsentKeyReturnsNull(): void
    {
        $got = ipam_user_preference_get($this->db, 99, 'theme');
        $this->assertNull($got, 'get() must return null when no row exists for (user_id, key)');
    }

    // -------------------------------------------------------------------------
    // Test: two users with the same key are independent
    // -------------------------------------------------------------------------

    public function testDifferentUsersAreIndependent(): void
    {
        ipam_user_preference_set($this->db, 10, 'theme', 'light');
        ipam_user_preference_set($this->db, 11, 'theme', 'dark');

        $this->assertSame(
            'light',
            ipam_user_preference_get($this->db, 10, 'theme'),
            "user 10's theme must be unaffected by user 11's write"
        );
        $this->assertSame(
            'dark',
            ipam_user_preference_get($this->db, 11, 'theme'),
            "user 11's theme must be unaffected by user 10's write"
        );

        // Two rows total — one per user.
        $countRow = $this->db->query(
            "SELECT COUNT(*) AS c FROM user_preferences WHERE \"key\" = 'theme'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($countRow);
        $this->assertSame(2, (int) $countRow['c'], 'each user must have their own independent row');
    }

    // =========================================================================
    // Endpoint wiring tests (static source inspection)
    // =========================================================================

    public function testEndpointHasSessionGate(): void
    {
        $this->assertStringContainsString(
            'is_logged_in()',
            $this->endpointSrc,
            'user_preference.php must gate all requests behind is_logged_in()'
        );
    }

    public function testEndpointHasCsrfRequire(): void
    {
        $this->assertStringContainsString(
            'csrf_require()',
            $this->endpointSrc,
            'user_preference.php must call csrf_require() on POST requests'
        );
    }

    public function testEndpointHasKeyAllowlist(): void
    {
        $this->assertStringContainsString(
            "'theme'",
            $this->endpointSrc,
            "user_preference.php must include 'theme' in the key allowlist"
        );
        $this->assertMatchesRegularExpression(
            '/IPAM_PREF_ALLOWLIST\s*=\s*\[/',
            $this->endpointSrc,
            'user_preference.php must define IPAM_PREF_ALLOWLIST as the key allowlist constant'
        );
    }

    public function testEndpointHas405MethodGuard(): void
    {
        $this->assertStringContainsString(
            '405',
            $this->endpointSrc,
            'user_preference.php must return HTTP 405 for disallowed methods'
        );
        // Both allowed methods must be listed in the guard condition.
        $this->assertMatchesRegularExpression(
            '/\$method\s*!==\s*[\'"]POST[\'"]\s*&&\s*\$method\s*!==\s*[\'"]GET[\'"]/',
            $this->endpointSrc,
            'user_preference.php must guard against non-POST/GET methods'
        );
    }
}
