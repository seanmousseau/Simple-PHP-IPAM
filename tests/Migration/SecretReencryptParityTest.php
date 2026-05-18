<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.31.0 #1234 (ADR-001 pt 2) - closure '3.31.0-reencrypt-settings-secrets'.
 *
 * Proves the one-shot re-encrypt migration:
 *   - Re-encrypt: a pre-existing plaintext managed-secret row (oidc.client_secret)
 *     is rewritten in place into an IPAMSEC1 envelope; the decrypted value
 *     round-trips back to the original plaintext.
 *   - Idempotence: a second apply_migrations() pass leaves the already-enveloped
 *     value byte-for-byte unchanged (no double-encryption).
 *
 * Engine-parametric: sqlite always; mysql/pgsql only when IPAM_MYSQL_DSN /
 * IPAM_PGSQL_DSN are set (idioms mirror MigrationFreshInstallMultiDriverTest).
 *
 * Harness: the codebase has no "run a single named migration" entrypoint, so
 * each scenario builds a DB at the pre-3.31.0 state - engine schema applied,
 * every migration key EXCEPT '3.31.0-reencrypt-settings-secrets' pre-stamped
 * into schema_migrations - seeds a plaintext secret row, then runs the full
 * apply_migrations(). Only the un-stamped new closure replays. This is the
 * EngineParityTest::testAllMigrationsAreIdempotentOnFreshSchema idiom.
 */
final class SecretReencryptParityTest extends TestCase
{
    private const MIGRATION_KEY = '3.31.0-reencrypt-settings-secrets';

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/Simple-PHP-IPAM/lib.php';
        require_once dirname(__DIR__, 2) . '/Simple-PHP-IPAM/migrations.php';
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engineProvider(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'mysql'  => ['mysql'],
            'pgsql'  => ['pgsql'],
        ];
    }

    #[DataProvider('engineProvider')]
    public function testReencryptsPlaintextSecret(string $engine): void
    {
        [$db, $restore] = $this->buildPreMigrationDb($engine);
        try {
            $this->seedPlaintextSecret($db, 'oidc.client_secret', 'plaintext-secret');

            \apply_migrations($db);

            $stored = $this->fetchSecret($db, 'oidc.client_secret');
            $this->assertIsString($stored);
            $this->assertStringStartsWith(
                IPAM_SECRET_ENVELOPE_PREFIX,
                $stored,
                "On $engine the plaintext secret must be re-encrypted into an IPAMSEC1 envelope"
            );
            $this->assertSame(
                'plaintext-secret',
                \ipam_secret_decrypt($stored),
                "On $engine the re-encrypted envelope must decrypt back to the original plaintext"
            );
        } finally {
            $restore();
        }
    }

    #[DataProvider('engineProvider')]
    public function testIsIdempotent(string $engine): void
    {
        [$db, $restore] = $this->buildPreMigrationDb($engine);
        try {
            $this->seedPlaintextSecret($db, 'oidc.client_secret', 'plaintext-secret');

            \apply_migrations($db);
            $first = $this->fetchSecret($db, 'oidc.client_secret');
            $this->assertIsString($first);
            $this->assertStringStartsWith(IPAM_SECRET_ENVELOPE_PREFIX, $first);

            // Clear only the new closure's stamp so apply_migrations() replays
            // it (and nothing else); the row is already enveloped.
            $this->unstampMigration($db);
            \apply_migrations($db);
            $second = $this->fetchSecret($db, 'oidc.client_secret');

            $this->assertSame(
                $first,
                $second,
                "On $engine a second run must not re-encrypt an already-enveloped value (no double-encryption)"
            );
            $this->assertSame('plaintext-secret', \ipam_secret_decrypt((string) $second));
        } finally {
            $restore();
        }
    }

    /**
     * Build a DB at the pre-3.31.0 state for $engine and return it alongside a
     * teardown closure that restores polluted $GLOBALS / drops engine tables.
     *
     * @return array{0: PDO, 1: callable():void}
     */
    private function buildPreMigrationDb(string $engine): array
    {
        $root = dirname(__DIR__, 2) . '/Simple-PHP-IPAM';

        $hadDialect  = array_key_exists('ipam_dialect', $GLOBALS);
        $prevDialect = $GLOBALS['ipam_dialect'] ?? null;
        $hadConfig   = array_key_exists('config', $GLOBALS);
        $prevConfig  = $GLOBALS['config'] ?? null;

        // ipam_secret_encrypt() derives its key from app_secret. Pin a fixed,
        // valid (>=16 raw bytes) value so the migration and the test's own
        // decrypt assertions agree regardless of test execution order.
        $GLOBALS['config'] = [
            'proxy_trust' => false,
            'app_secret'  => base64_encode(str_repeat("\x2a", 32)),
        ];

        require_once $root . '/dialects/Dialect.php';
        require_once $root . '/dialects/DialectValidator.php';

        if ($engine === 'sqlite') {
            require_once $root . '/dialects/SqliteDialect.php';
            $GLOBALS['ipam_dialect'] = new \SqliteDialect();

            $db = new PDO('sqlite::memory:');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->exec('PRAGMA foreign_keys = ON');

            $schema = file_get_contents($root . '/schema.sql');
            $this->assertNotFalse($schema);
            $db->exec($schema);

            $this->stampAllExceptNew($db);

            $restore = function () use ($hadDialect, $prevDialect, $hadConfig, $prevConfig): void {
                $this->restoreGlobals($hadDialect, $prevDialect, $hadConfig, $prevConfig);
            };
            return [$db, $restore];
        }

        // mysql / pgsql - gated on a live DSN.
        $envKey = $engine === 'mysql' ? 'IPAM_MYSQL_DSN' : 'IPAM_PGSQL_DSN';
        $dsn = getenv($envKey);
        if ($dsn === false || $dsn === '') {
            $this->restoreGlobals($hadDialect, $prevDialect, $hadConfig, $prevConfig);
            $this->markTestSkipped("$envKey not set; skipping $engine leg (set the DSN to run locally).");
        }

        $user = (string) (getenv('IPAM_' . strtoupper($engine) . '_USER') ?: '');
        $pass = (string) (getenv('IPAM_' . strtoupper($engine) . '_PASS') ?: '');
        try {
            $db = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            $this->restoreGlobals($hadDialect, $prevDialect, $hadConfig, $prevConfig);
            $this->markTestSkipped("Cannot connect to $engine DSN: " . $e->getMessage());
        }

        if ($engine === 'mysql') {
            require_once $root . '/dialects/MysqlDialect.php';
            $GLOBALS['ipam_dialect'] = new \MysqlDialect();
        } else {
            require_once $root . '/dialects/PgsqlDialect.php';
            $GLOBALS['ipam_dialect'] = new \PgsqlDialect();
        }

        $this->dropAllTables($db, $engine);
        $schemaFile = $engine === 'mysql' ? '/schema.mysql.sql' : '/schema.pgsql.sql';
        $schema = file_get_contents($root . $schemaFile);
        $this->assertNotFalse($schema);
        $db->exec($schema);

        $this->stampAllExceptNew($db);

        $restore = function () use ($db, $engine, $hadDialect, $prevDialect, $hadConfig, $prevConfig): void {
            $this->dropAllTables($db, $engine);
            $this->restoreGlobals($hadDialect, $prevDialect, $hadConfig, $prevConfig);
        };
        return [$db, $restore];
    }

    /**
     * Pre-stamp every migration version EXCEPT the new closure into
     * schema_migrations, so apply_migrations() replays only that one.
     */
    private function stampAllExceptNew(PDO $db): void
    {
        $this->assertContains(
            self::MIGRATION_KEY,
            array_keys(\ipam_migrations()),
            'The 3.31.0-reencrypt-settings-secrets migration key must exist in ipam_migrations()'
        );

        $db->exec('DELETE FROM schema_migrations');
        $ignore = \ipam_dialect()->upsert_or_ignore('schema_migrations', ['version']);
        $stamp  = $db->prepare("INSERT INTO schema_migrations (version) VALUES (:v) $ignore");
        foreach (array_keys(\ipam_migrations()) as $ver) {
            if ($ver === self::MIGRATION_KEY) {
                continue;
            }
            $stamp->execute([':v' => $ver]);
        }
    }

    /** Remove just the new closure's stamp so apply_migrations() replays it. */
    private function unstampMigration(PDO $db): void
    {
        $del = $db->prepare('DELETE FROM schema_migrations WHERE version = :v');
        $del->execute([':v' => self::MIGRATION_KEY]);
    }

    /** Insert a plaintext (non-enveloped) global settings row. */
    private function seedPlaintextSecret(PDO $db, string $key, string $value): void
    {
        $kc  = \ipam_key_col();
        $ins = $db->prepare(
            "INSERT INTO settings (tenant_id, $kc, value, updated_at) "
            . 'VALUES (NULL, :k, :v, :ts)'
        );
        $ins->execute([
            ':k'  => $key,
            ':v'  => $value,
            ':ts' => '2026-05-17 00:00:00',
        ]);
    }

    private function fetchSecret(PDO $db, string $key): ?string
    {
        $kc  = \ipam_key_col();
        $sel = $db->prepare("SELECT value FROM settings WHERE $kc = :k AND tenant_id IS NULL");
        $sel->execute([':k' => $key]);
        $value = $sel->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function dropAllTables(PDO $db, string $engine): void
    {
        if ($engine === 'mysql') {
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            $rows = $db->query(
                'SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
            )->fetchAll();
            foreach ($rows as $r) {
                $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', (string) $r['n']) . '`');
            }
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($engine === 'pgsql') {
            $db->exec('DROP SCHEMA public CASCADE');
            $db->exec('CREATE SCHEMA public');
        }
    }

    /**
     * @param mixed $prevDialect
     * @param mixed $prevConfig
     */
    private function restoreGlobals(bool $hadDialect, $prevDialect, bool $hadConfig, $prevConfig): void
    {
        if ($hadDialect) {
            $GLOBALS['ipam_dialect'] = $prevDialect;
        } else {
            unset($GLOBALS['ipam_dialect']);
        }
        if ($hadConfig) {
            $GLOBALS['config'] = $prevConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }
}
