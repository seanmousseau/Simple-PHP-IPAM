<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib/backup.php';

/**
 * Column-kind override map — targeted conformance (#1124).
 *
 * The full conformance test class — walk every column on every shipped
 * table, dump a synthetic row with non-UTF-8 binary, assert clean
 * round-trip across all three engines — is deferred to v3.28.0 test-
 * tooling baseline. This narrower test instead locks the v3.27.2
 * override map: every (table, column) pair currently classified as
 * binary by the override branch must round-trip cleanly when seeded
 * with non-UTF-8 bytes.
 *
 * If a future change removes an override entry, this test fails — forcing
 * the change author to either reinstate the override OR demonstrate the
 * column has been renamed to follow the _bin suffix convention. Either
 * way the writer no longer silently drops the row.
 */
class IPAMBKL1ColumnKindOverrideTest extends TestCase
{
    /**
     * @return array<string, array{0:string, 1:string, 2:callable(PDO,string):int}>
     */
    public static function overrideEntries(): array
    {
        return [
            'webauthn_credentials.credential_id' => [
                'webauthn_credentials',
                'credential_id',
                self::class . '::seedWebauthn',
            ],
            'webauthn_credentials.public_key' => [
                'webauthn_credentials',
                'public_key',
                self::class . '::seedWebauthn',
            ],
        ];
    }

    /**
     * Each (table, column) entry in the override map produces a clean
     * dump then dry-run round-trip when the column carries non-UTF-8 binary.
     *
     * @dataProvider overrideEntries
     * @param callable(PDO,string):int $seed
     */
    public function testOverrideEntryRoundTrips(string $table, string $column, callable $seed): void
    {
        // Sanity gate: the override classifier must report binary for this
        // (table, column). If a future refactor moves the override away,
        // this assertion fires before the round-trip even runs.
        $this->assertSame(
            'binary',
            ipam_logical_column_kind($column, $table),
            "override classifier must return 'binary' for $table.$column"
        );

        $db = $this->freshDb();
        $expectedRowsInTable = $seed($db, $column);
        $this->assertGreaterThan(0, $expectedRowsInTable,
            "fixture seeding for $table.$column must insert at least one row");

        $fixture = tempnam(sys_get_temp_dir(), 'ipambkl1_override_');
        $this->assertNotFalse($fixture);
        ipam_backup_logical_dump($db, $fixture);

        $dryRun = ipam_restore_logical_dry_run($db, $fixture);
        $warnings = $dryRun['warnings'] ?? [];
        $this->assertIsArray($warnings);

        $relevant = array_filter($warnings, static fn($w) =>
            is_string($w) && (
                str_contains($w, 'Body checksum does not match footer') ||
                str_contains($w, 'disagrees with footer total_rows')
            )
        );
        $this->assertSame([], array_values($relevant),
            "dump+dry-run for $table.$column must produce no checksum or row-count drift");

        // Confirm the row reaches the dry-run per-table summary, not just
        // the writer pass-1 count. Catches the silent-drop regression.
        $tablesByName = [];
        foreach ($dryRun['tables'] ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $tablesByName[$row['name']] = $row;
            }
        }
        $this->assertArrayHasKey($table, $tablesByName,
            "dry-run per-table summary must include $table");
        $this->assertSame($expectedRowsInTable, $tablesByName[$table]['backup_rows'] ?? null,
            "dry-run backup_rows for $table must equal seeded count (no silent drop on $column)");
    }

    /**
     * Seed one webauthn_credentials row with non-UTF-8 binary in the
     * named column. The other columns get UTF-8-safe placeholders so a
     * regression in only one override entry surfaces independently.
     */
    public static function seedWebauthn(PDO $db, string $column): int
    {
        $db->exec(
            "INSERT INTO users (username, password_hash, role, is_active) " .
            "VALUES ('webauthn-test', '!disabled', 'admin', 1)"
        );
        $userId = (int) $db->lastInsertId();

        $cred = 'ascii-cred-id';
        $pk   = 'ascii-public-key';
        $bin  = "\x00\x01\xff\xfe" . str_repeat("\xc3\x28", 16); // overlong UTF-8 trap
        if ($column === 'credential_id') {
            $cred = $bin;
        } elseif ($column === 'public_key') {
            $pk = $bin;
        }

        $st = $db->prepare(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, name) " .
            "VALUES (:uid, :cid, :pk, 1, 'override-test')"
        );
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->bindValue(':cid', $cred, PDO::PARAM_LOB);
        $st->bindValue(':pk',  $pk,   PDO::PARAM_STR);
        $st->execute();

        return 1;
    }

    private function freshDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        return $db;
    }
}
