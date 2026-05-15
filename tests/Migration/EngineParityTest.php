<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: closures that test multi-engine parity / replay safety. The
 * "fresh schema idempotency" check guards against new migrations that
 * lack a sqlite_master / information_schema existence probe and would
 * therefore re-fail on upgrade-from-fresh installs.
 */
final class EngineParityTest extends Base
{
    public function testMigrationsAreIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $first = \apply_migrations($db);

        $this->assertSame([], $first, 'Second call must apply no migrations');
        $this->assertSame(5, (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn());
        $this->assertSame(2, (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn());
    }

    public function testAllMigrationsAreIdempotentOnFreshSchema(): void
    {
        require_once dirname(__DIR__, 2) . '/Simple-PHP-IPAM/lib.php';
        require_once dirname(__DIR__, 2) . '/Simple-PHP-IPAM/migrations.php';

        $hadConfig  = array_key_exists('config', $GLOBALS);
        $prevConfig = $GLOBALS['config'] ?? null;
        $hadDialect  = array_key_exists('ipam_dialect', $GLOBALS);
        $prevDialect = $GLOBALS['ipam_dialect'] ?? null;

        $GLOBALS['config'] = ['proxy_trust' => false];

        $db = $this->makeDb();
        $schema = file_get_contents(dirname(__DIR__, 2) . '/Simple-PHP-IPAM/schema.sql');
        $this->assertNotFalse($schema);
        $db->exec($schema);
        require_once dirname(__DIR__, 2) . '/Simple-PHP-IPAM/dialects/SqliteDialect.php';
        $GLOBALS['ipam_dialect'] = new \SqliteDialect();

        $ignore = \ipam_dialect()->upsert_or_ignore('schema_migrations', ['version']);
        $stamp = $db->prepare("INSERT INTO schema_migrations (version) VALUES (:v) $ignore");
        foreach (array_keys(\ipam_migrations()) as $ver) {
            $stamp->execute([':v' => $ver]);
        }

        $snapshot = function (PDO $db): array {
            $tables = [];
            $rows = $db->query(
                "SELECT name FROM sqlite_master "
                . "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' "
                . "ORDER BY name"
            )->fetchAll();
            foreach ($rows as $r) {
                $tn = (string)$r['name'];
                $cols = [];
                foreach ($db->query("PRAGMA table_info(\"$tn\")")->fetchAll() as $c) {
                    $cols[(string)$c['name']] = [
                        'type'    => strtoupper((string)$c['type']),
                        'notnull' => (int)$c['notnull'],
                        'pk'      => (int)$c['pk'],
                    ];
                }
                ksort($cols);
                $tables[$tn] = $cols;
            }
            return $tables;
        };
        $before = $snapshot($db);
        $migCountBefore = (int)$db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();

        $db->exec("DELETE FROM schema_migrations");
        \apply_migrations($db);

        $after = $snapshot($db);
        $migCountAfter = (int)$db->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();

        $this->assertSame(
            $before,
            $after,
            'apply_migrations() must be a no-op on top of the fresh schema'
        );
        $this->assertGreaterThan(
            $migCountBefore - 1,
            $migCountAfter,
            'every migration closure must re-stamp itself in schema_migrations after replay'
        );

        if ($hadConfig) {
            $GLOBALS['config'] = $prevConfig;
        } else {
            unset($GLOBALS['config']);
        }
        if ($hadDialect) {
            $GLOBALS['ipam_dialect'] = $prevDialect;
        } else {
            unset($GLOBALS['ipam_dialect']);
        }
    }
}
