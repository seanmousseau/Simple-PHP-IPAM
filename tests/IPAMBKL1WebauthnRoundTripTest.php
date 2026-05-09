<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * Webauthn-credentials round-trip parity (#1124).
 *
 * Reproduces the v3.27.1 production-shaped finding: ipam_logical_column_kind()
 * classifies columns by name suffix (`_bin` => binary). The two binary columns
 * on `webauthn_credentials` (`credential_id` BLOB and `public_key` TEXT-declared
 * but COSE-binary) violate the convention. Pre-fix, raw bytes flowed into
 * json_encode(), which returns false on non-UTF-8 input. The writer's
 * `(string) json_encode(...)` cast silently became `""`, the row degraded to
 * a blank body line, $totalRows++ and hash_update("\n") still fired, and the
 * reader's `if ($trim === '') continue;` skipped without counting/hashing.
 *
 * Net effect on the deployed v3.27.1 sqlite test instance: backup of an
 * install with 2 enrolled passkeys produced an off-by-2 row count and a
 * checksum mismatch, blocking apply via the v3.26.0 footer guard.
 *
 * Expected behaviour after #1124's fix lands:
 *   - webauthn_credentials rows round-trip cleanly through dump then dry-run.
 *   - dry-run warnings array is empty (no checksum drift, no row-count drift).
 *   - the per-table backup_rows count for webauthn_credentials matches the
 *     count that was seeded.
 *
 * This test deliberately uses non-UTF-8 binary bytes in both columns —
 * the pre-fix failure mode requires those bytes (UTF-8-safe text doesn't
 * trip json_encode). Round-tripping with all-ASCII text would silently pass
 * pre-fix and provide no regression coverage. The fixture mirrors what
 * lbuchs/webauthn writes in production: random-looking bytes including high
 * bits and null bytes.
 */
class IPAMBKL1WebauthnRoundTripTest extends TestCase
{
    public function testWebauthnCredentialsRoundTripWithoutChecksumDrift(): void
    {
        $source = $this->freshDb();
        $this->seedTwoPasskeys($source);

        $fixture = tempnam(sys_get_temp_dir(), 'ipambkl1_1124_');
        $this->assertNotFalse($fixture, 'tempnam() allocation must succeed');

        // Writer side: dump returns the writer internal totals. Pre-fix this
        // already counts rows it failed to encode, so total_rows > actual body
        // line count.
        $writerMeta = ipam_backup_logical_dump($source, $fixture);
        $this->assertSame(2, $writerMeta['row_counts']['webauthn_credentials'] ?? null,
            'writer pass-1 count of webauthn_credentials must equal seeded rows');

        // Reader side: dry-run walks the gzipped body, recomputes the SHA-256
        // checksum, and counts body lines. The two warnings emitted pre-fix
        // are the exact symptom strings produced at lib/backup.php:3427 and
        // :3432 — assert their absence rather than just .empty() so a future
        // refactor that renames the warnings is forced to update this test.
        $dryRun = ipam_restore_logical_dry_run($source, $fixture);

        $warnings = $dryRun['warnings'] ?? [];
        $this->assertIsArray($warnings);

        $checksumDrift = array_filter($warnings, static fn($w) =>
            is_string($w) && str_contains($w, 'Body checksum does not match footer'));
        $rowCountDrift = array_filter($warnings, static fn($w) =>
            is_string($w) && str_contains($w, 'disagrees with footer total_rows'));

        $this->assertSame([], array_values($checksumDrift),
            '#1124: dry-run checksum must match footer when webauthn_credentials rows are present');
        $this->assertSame([], array_values($rowCountDrift),
            '#1124: dry-run body row count must match footer total_rows when webauthn_credentials rows are present');

        // Per-table backup_rows assertion — locks in that the rows were not
        // dropped to blank lines (the pre-fix silent-skip mechanism would
        // produce backup_rows=0 here while writer pass-1 still reported 2).
        $tablesByName = [];
        foreach ($dryRun['tables'] ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $tablesByName[$row['name']] = $row;
            }
        }
        $this->assertArrayHasKey('webauthn_credentials', $tablesByName,
            '#1124: dry-run must report webauthn_credentials in per-table summary (rows > 0)');
        $this->assertSame(2, $tablesByName['webauthn_credentials']['backup_rows'] ?? null,
            '#1124: dry-run must count 2 webauthn_credentials body rows, not 0 (silent-drop symptom)');
    }

    /**
     * Insert exactly two webauthn_credentials rows with non-UTF-8 binary
     * payloads in both `credential_id` (BLOB) and `public_key` (TEXT-declared
     * but binary in production). The first byte sequence carries every high
     * bit (0xff) and an embedded null; the second is the round-trip vector
     * documented in CLAUDE.md (Round-trip test vectors) for binary-column
     * coverage. Either alone trips json_encode pre-fix.
     */
    private function seedTwoPasskeys(PDO $db): void
    {
        // First row: one user is required because of FK to users.id.
        $db->exec(
            "INSERT INTO users (username, password_hash, role, is_active) " .
            "VALUES ('passkey-user', '!disabled', 'admin', 1)"
        );
        $userId = (int) $db->lastInsertId();

        $payloads = [
            ['name' => 'YubiKey-1', 'cred' => "\x00\x01\xff\xfe" . random_bytes(28),
             'pk'   => "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_repeat("\xff", 32) . "\x22\x58\x20" . str_repeat("\x00", 32)],
            ['name' => 'TouchID',   'cred' => "\x10\x00\x0d\xb8" . random_bytes(28),
             'pk'   => "\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . random_bytes(32)         . "\x22\x58\x20" . random_bytes(32)],
        ];
        $st = $db->prepare(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, name) " .
            "VALUES (:uid, :cid, :pk, :sc, :nm)"
        );
        foreach ($payloads as $i => $p) {
            $st->bindValue(':uid', $userId, PDO::PARAM_INT);
            $st->bindValue(':cid', $p['cred'], PDO::PARAM_LOB);
            $st->bindValue(':pk',  $p['pk'],   PDO::PARAM_STR);
            $st->bindValue(':sc',  $i + 1,     PDO::PARAM_INT);
            $st->bindValue(':nm',  $p['name'], PDO::PARAM_STR);
            $st->execute();
        }
    }

    private function freshDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        $db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($db);
        apply_migrations($db);
        return $db;
    }
}
