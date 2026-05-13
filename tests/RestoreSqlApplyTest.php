<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

use PHPUnit\Framework\TestCase;

/**
 * Round-trip tests for the Database-format ( .sql.gz / `ipam_db_dump_stream`)
 * branch of ipam_restore_apply() — the path the Restore wizard uses when the
 * staged archive is a SQLite SQL dump (and what the IPAMBKP1/IPAMBKP2 .enc
 * archives decrypt to).
 *
 * Before v3.28.0 this path was broken: ipam_db_dump_stream() emits its own
 * `PRAGMA foreign_keys=OFF; BEGIN TRANSACTION; … COMMIT;` and ships the
 * schema as `CREATE TABLE` (no `DROP`), so replaying it onto a live install
 * died on `BEGIN TRANSACTION` ("cannot start a transaction within a
 * transaction") and — had it got past that — on `table X already exists`.
 * The whole-chunk `str_starts_with(ltrim(...), '--')` skip also silently
 * dropped every comment-prefixed `CREATE TABLE`. ipam_restore_apply() now
 * drops the existing user tables first and filters replay statements through
 * ipam_restore_normalize_replay_statement().
 *
 * SQLite-only — the Database-format apply path is sqlite-only by design
 * (MySQL/Postgres restore goes through `mysql`/`psql` in restore.php).
 */
final class RestoreSqlApplyTest extends TestCase
{
    private string $tmpDir;
    /** @var list<string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        $this->tmpDir = realpath(__DIR__ . '/../Simple-PHP-IPAM/data/tmp')
            ?: throw new RuntimeException('Simple-PHP-IPAM/data/tmp must exist for restore tests');
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $f) {
            $real = realpath($f);
            if ($real === false) continue;
            if (!str_starts_with($real, $this->tmpDir . '/')) continue;
            if (is_file($real)) @unlink($real); // nosemgrep: php.lang.security.unlink-use.unlink-use -- staged test fixture under data/tmp/
        }
        $this->fixtures = [];
    }

    private function freshDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Match ipam_db()'s connection config — ipam_db_dump_stream() relies on
        // the connection's default fetch mode being associative (bare ->fetchAll()).
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $db->exec($schema);
        apply_migrations($db);
        return $db;
    }

    private function seedSource(PDO $db): int
    {
        $db->exec("INSERT INTO users (username, password_hash, role, is_active) VALUES ('admin','bogus','admin',1)");
        $db->exec("INSERT INTO sites (name, description) VALUES ('hq','HQ root')");
        $db->exec("INSERT INTO vrfs (name, description) VALUES ('default','Default VRF')");

        $st = $db->prepare(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description) " .
            "VALUES ('10.2.0.0/24', 4, '10.2.0.0', :nb, 24, 'office')"
        );
        ipam_bind_binary($st, ':nb', (string) inet_pton('10.2.0.0'));
        $st->execute();
        $sid = (int) $db->lastInsertId();

        foreach (['10.2.0.7', '10.2.0.200'] as $ip) {
            $st = $db->prepare(
                "INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status) VALUES (:s,:ip,:b,:h,'used')"
            );
            $st->bindValue(':s', $sid, PDO::PARAM_INT);
            $st->bindValue(':ip', $ip);
            ipam_bind_binary($st, ':b', (string) inet_pton($ip));
            $st->bindValue(':h', 'host-' . $ip);
            $st->execute();
        }
        return $sid;
    }

    /** Dump $src to a gzipped .sql.gz fixture under data/tmp/, return its path. */
    private function stageDump(PDO $src): string
    {
        $gz = gzencode(ipam_db_dump($src));
        if ($gz === false) {
            $this->fail('gzencode failed in fixture setup');
        }
        $path = $this->tmpDir . '/test-restore-sqlapply-' . bin2hex(random_bytes(6)) . '.sql.gz';
        file_put_contents($path, $gz);
        $this->fixtures[] = $path;
        return $path;
    }

    public function testRestoreReplacesSchemaAndData(): void
    {
        $src = $this->freshDb();
        $this->seedSource($src);
        $dump = $this->stageDump($src);

        // Target already carries the full schema (init.php-style) and some
        // pre-existing rows the restore must wipe — this is the real
        // "restore over a live install" scenario.
        $target = $this->freshDb();
        $target->exec("INSERT INTO sites (name, description) VALUES ('STALE','to be wiped')");
        $target->exec("INSERT INTO users (username, password_hash, role, is_active) VALUES ('stale','x','readonly',1)");

        $result = ipam_restore_apply($target, $dump, 'office-backup.sql.gz');
        $this->assertGreaterThan(0, $result['statements'], 'replayed at least one statement');

        // Source data is present.
        $this->assertSame(1, (int) $target->query("SELECT COUNT(*) FROM subnets")->fetchColumn());
        $this->assertSame(2, (int) $target->query("SELECT COUNT(*) FROM addresses")->fetchColumn());
        $this->assertSame('10.2.0.0/24', (string) $target->query("SELECT cidr FROM subnets")->fetchColumn());

        // ...and the target's pre-existing rows are gone (replace, not merge).
        $siteNames = $target->query("SELECT name FROM sites ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['hq'], $siteNames);
        $this->assertSame(0, (int) $target->query("SELECT COUNT(*) FROM users WHERE username='stale'")->fetchColumn());
        $this->assertSame(1, (int) $target->query("SELECT COUNT(*) FROM users WHERE username='admin'")->fetchColumn());

        // Binary IPs survive the X'..' hex literal round-trip.
        $bin = $target->query("SELECT ip_bin FROM addresses WHERE ip='10.2.0.7'")->fetchColumn();
        $this->assertSame(inet_pton('10.2.0.7'), $bin);

        // Schema came through at v3.28.0 (the new state tables exist) and the
        // post-restore apply_migrations() recorded the chain.
        $this->assertNotFalse($target->query("SELECT COUNT(*) FROM rate_limit_dampener"));
        $this->assertNotFalse($target->query("SELECT COUNT(*) FROM backup_state"));
        $this->assertGreaterThan(0, (int) $target->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn());
    }

    public function testRestoredAuditLogIsAppendOnlyAndCarriesRestoreRow(): void
    {
        $src = $this->freshDb();
        $this->seedSource($src);
        $dump = $this->stageDump($src);

        $target = $this->freshDb();
        ipam_restore_apply($target, $dump, 'office-backup.sql.gz');

        // The restore writes a db.restore audit row.
        $this->assertSame(
            1,
            (int) $target->query("SELECT COUNT(*) FROM audit_log WHERE action='db.restore'")->fetchColumn()
        );

        // The audit_log ABORT triggers were recreated from the dump.
        $this->expectException(\PDOException::class);
        $target->exec("DELETE FROM audit_log");
    }

    public function testRestoreIsIdempotentAcrossRepeatedApplies(): void
    {
        $src = $this->freshDb();
        $this->seedSource($src);
        $dump = $this->stageDump($src);

        $target = $this->freshDb();
        ipam_restore_apply($target, $dump, 'b.sql.gz');
        ipam_restore_apply($target, $dump, 'b.sql.gz'); // second restore must not collide on the schema

        $this->assertSame(1, (int) $target->query("SELECT COUNT(*) FROM subnets")->fetchColumn());
        $this->assertSame(2, (int) $target->query("SELECT COUNT(*) FROM addresses")->fetchColumn());
        // A Database-format restore is a full replace — audit_log is dropped and
        // recreated from the dump (which has no db.restore rows), then this
        // restore appends its own. So each apply lands exactly one db.restore row.
        $this->assertSame(
            1,
            (int) $target->query("SELECT COUNT(*) FROM audit_log WHERE action='db.restore'")->fetchColumn()
        );
    }

    public function testRefusesNonIpamDumpAndLeavesDbUntouched(): void
    {
        // A file that's valid SQL but not a full IPAM dump (no core CREATE
        // TABLEs). With the drop-then-replay flow this would otherwise wipe
        // the live schema and commit an almost-empty restore; the apply path
        // must refuse BEFORE dropping anything.
        $bogusSql = "-- looks like a dump but isn't\nCREATE TABLE notipam (id INTEGER);\nINSERT INTO notipam VALUES (1);\n";
        $gz = gzencode($bogusSql);
        if ($gz === false) {
            $this->fail('gzencode failed in fixture setup');
        }
        $path = $this->tmpDir . '/test-restore-sqlapply-bogus-' . bin2hex(random_bytes(6)) . '.sql.gz';
        file_put_contents($path, $gz);
        $this->fixtures[] = $path;

        $target = $this->freshDb();
        $target->exec("INSERT INTO sites (name, description) VALUES ('keepme','must survive a refused restore')");
        $before = (int) $target->query("SELECT COUNT(*) FROM sites")->fetchColumn();

        try {
            ipam_restore_apply($target, $path, 'bogus.sql.gz');
            $this->fail('ipam_restore_apply() should have refused a non-IPAM dump');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/does not look like a full Simple PHP IPAM/', $e->getMessage());
        }

        // Nothing dropped, nothing replayed — the live schema + data survive.
        $this->assertSame($before, (int) $target->query("SELECT COUNT(*) FROM sites")->fetchColumn());
        $this->assertSame('keepme', (string) $target->query("SELECT name FROM sites WHERE name='keepme'")->fetchColumn());
        $this->assertNotFalse($target->query("SELECT COUNT(*) FROM subnets")); // table still exists
    }

    /** @dataProvider createdTablesCases */
    public function testSqltextCreatedTables(string $sql, array $expected): void
    {
        $gz = gzencode($sql);
        $this->assertNotFalse($gz);
        $path = $this->tmpDir . '/test-restore-sqlapply-ct-' . bin2hex(random_bytes(6)) . '.sql.gz';
        file_put_contents($path, $gz);
        $this->fixtures[] = $path;
        $this->assertSame($expected, array_keys(ipam_restore_sqltext_created_tables($path)));
    }

    /** @return iterable<string,array{0:string,1:list<string>}> */
    public static function createdTablesCases(): iterable
    {
        yield 'comment-prefixed + quoted'   => ["-- Table: Subnets\nCREATE TABLE \"Subnets\" (id INTEGER);\n", ['subnets']];
        yield 'IF NOT EXISTS'               => ["CREATE TABLE IF NOT EXISTS users (id INTEGER);\n", ['users']];
        yield 'multiple, header + pragmas'  => ["-- dump\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nCREATE TABLE a (i INT);\nINSERT INTO a VALUES (1);\nCREATE TABLE b (i INT);\nCOMMIT;\n", ['a', 'b']];
        yield 'no CREATE TABLE'             => ["-- just a note\nINSERT INTO whatever VALUES (1);\n", []];
    }

    // ── ipam_restore_normalize_replay_statement() — pure unit cases ──────────

    /** @return iterable<string,array{0:string,1:?string}> */
    public static function replayStatementCases(): iterable
    {
        yield 'comment-prefixed CREATE'      => ["-- Table: foo\nCREATE TABLE \"foo\" (id INTEGER);", 'CREATE TABLE "foo" (id INTEGER);'];
        yield 'multiple leading comments'    => ["  \n-- a\n-- b\nINSERT INTO x VALUES (1);", 'INSERT INTO x VALUES (1);'];
        yield 'trailing-only comment'        => ['-- just a note', null];
        yield 'comment, no final newline'    => ["-- header\nSELECT 1;", 'SELECT 1;'];
        yield 'blank'                        => ["   \n  ", null];
        yield 'BEGIN TRANSACTION'            => ['BEGIN TRANSACTION;', null];
        yield 'bare BEGIN'                   => ['BEGIN;', null];
        yield 'BEGIN IMMEDIATE'              => ['BEGIN IMMEDIATE;', null];
        yield 'COMMIT'                       => ['COMMIT;', null];
        yield 'END TRANSACTION'              => ['END TRANSACTION;', null];
        yield 'ROLLBACK'                     => ['ROLLBACK;', null];
        yield 'PRAGMA foreign_keys'          => ['PRAGMA foreign_keys=OFF;', null];
        yield 'PRAGMA after comments'        => ["-- Simple PHP IPAM database dump\n-- Generated: 2026-01-01\n\nPRAGMA foreign_keys=OFF;", null];
        yield 'inner -- not stripped'        => ["INSERT INTO x VALUES ('a -- not a comment');", "INSERT INTO x VALUES ('a -- not a comment');"];
        yield 'CREATE TRIGGER with BEGIN..END' => [
            "CREATE TRIGGER t BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT,'no'); END;",
            "CREATE TRIGGER t BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT,'no'); END;",
        ];
        yield 'plain INSERT untouched'       => ['INSERT INTO subnets (cidr) VALUES (\'10.0.0.0/8\');', 'INSERT INTO subnets (cidr) VALUES (\'10.0.0.0/8\');'];
    }

    /** @dataProvider replayStatementCases */
    public function testNormalizeReplayStatement(string $in, ?string $expected): void
    {
        $this->assertSame($expected, ipam_restore_normalize_replay_statement($in));
    }
}
