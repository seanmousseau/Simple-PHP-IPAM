<?php
declare(strict_types=1);

namespace Tests\Migration;

use PDO;
use PDOException;

/**
 * v3.29.0 #902 — split from MigrationTest.
 *
 * Cluster: catch-all — v3.2.0-devices, v3.5.0-custom-fields, and the
 * v3.6.0 TOTP/rate-limit/lockout scaffold.
 */
final class MiscTest extends Base
{
    public function testDevicesMigrationAddsColumnsAndPreservesAddresses(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $this->assertSame(
            5,
            (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn(),
            '3.2.0-devices must not delete any addresses'
        );

        $addrCols = array_column(
            $db->query("PRAGMA table_info(addresses)")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertContains('device_id',    $addrCols, 'addresses.device_id must exist after 3.2.0-devices');
        $this->assertContains('interface_id', $addrCols, 'addresses.interface_id must exist after 3.2.0-devices');

        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $this->assertContains('devices',           $tables, 'devices table must exist after 3.2.0-devices');
        $this->assertContains('device_interfaces', $tables, 'device_interfaces table must exist after 3.2.0-devices');
        $this->assertContains('password_reset_tokens', $tables, 'password_reset_tokens table must exist after 3.2.0-password-reset');
    }

    public function testCustomFieldsMigrationCreatesSchemaAndPreservesData(): void
    {
        $db = $this->makePreVrfDb();

        $subnetCols = array_column($db->query("PRAGMA table_info(subnets)")->fetchAll(), 'name');
        $this->assertNotContains('custom_fields', $subnetCols, 'precondition: subnets.custom_fields must not exist yet');
        $addrCols = array_column($db->query("PRAGMA table_info(addresses)")->fetchAll(), 'name');
        $this->assertNotContains('custom_fields', $addrCols, 'precondition: addresses.custom_fields must not exist yet');

        $subnetCountBefore  = (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn();
        $addressCountBefore = (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn();

        \apply_migrations($db);

        $tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
        $this->assertContains('custom_field_defs', $tables, 'custom_field_defs table must be created');

        $defCols = array_column($db->query("PRAGMA table_info(custom_field_defs)")->fetchAll(), 'name');
        foreach (['id', 'entity_type', 'key', 'label', 'type', 'options', 'sort_order', 'is_required', 'is_deleted', 'created_at', 'updated_at'] as $expected) {
            $this->assertContains($expected, $defCols, "custom_field_defs.{$expected} must exist");
        }

        $subnetColInfo = $db->query("PRAGMA table_info(subnets)")->fetchAll();
        $subnetCustom  = null;
        foreach ($subnetColInfo as $c) {
            if ((string)$c['name'] === 'custom_fields') {
                $subnetCustom = $c;
                break;
            }
        }
        $this->assertNotNull($subnetCustom, 'subnets.custom_fields must be added');
        $this->assertSame(1, (int)$subnetCustom['notnull'], 'subnets.custom_fields must be NOT NULL');
        $this->assertSame("'{}'", (string)$subnetCustom['dflt_value'], 'subnets.custom_fields default must be {}');

        $addrColInfo = $db->query("PRAGMA table_info(addresses)")->fetchAll();
        $addrCustom  = null;
        foreach ($addrColInfo as $c) {
            if ((string)$c['name'] === 'custom_fields') {
                $addrCustom = $c;
                break;
            }
        }
        $this->assertNotNull($addrCustom, 'addresses.custom_fields must be added');
        $this->assertSame(1, (int)$addrCustom['notnull'], 'addresses.custom_fields must be NOT NULL');
        $this->assertSame("'{}'", (string)$addrCustom['dflt_value'], 'addresses.custom_fields default must be {}');

        $this->assertSame(
            $subnetCountBefore,
            (int)$db->query("SELECT count(*) FROM subnets")->fetchColumn(),
            'subnets row count must be preserved across custom-fields migration'
        );
        $this->assertSame(
            $addressCountBefore,
            (int)$db->query("SELECT count(*) FROM addresses")->fetchColumn(),
            'addresses row count must be preserved across custom-fields migration'
        );

        $nonDefaultSubnet = (int)$db->query("SELECT count(*) FROM subnets WHERE custom_fields != '{}'")->fetchColumn();
        $this->assertSame(0, $nonDefaultSubnet, 'pre-existing subnets must carry default {} after migration');
        $nonDefaultAddr = (int)$db->query("SELECT count(*) FROM addresses WHERE custom_fields != '{}'")->fetchColumn();
        $this->assertSame(0, $nonDefaultAddr, 'pre-existing addresses must carry default {} after migration');
    }

    public function testCustomFieldsUniqueConstraintScopesByEntityType(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $ins = $db->prepare(
            "INSERT INTO custom_field_defs (entity_type, key, label, type)
             VALUES (:et, :k, :lbl, 'text')"
        );
        $ins->execute([':et' => 'subnet',  ':k' => 'cost_centre', ':lbl' => 'Cost centre']);
        $ins->execute([':et' => 'address', ':k' => 'cost_centre', ':lbl' => 'Cost centre']);

        $count = (int)$db->query("SELECT count(*) FROM custom_field_defs WHERE key='cost_centre'")->fetchColumn();
        $this->assertSame(2, $count, 'same key must be usable across different entity_types');

        $this->expectException(PDOException::class);
        $ins->execute([':et' => 'subnet', ':k' => 'cost_centre', ':lbl' => 'Cost centre (dup)']);
    }

    public function testCustomFieldsMigrationIsIdempotent(): void
    {
        $db = $this->makePreVrfDb();
        \apply_migrations($db);

        $db->prepare(
            "INSERT INTO custom_field_defs (entity_type, key, label, type)
             VALUES ('subnet', 'sla_tier', 'SLA tier', 'text')"
        )->execute();
        $defCountBefore = (int)$db->query("SELECT count(*) FROM custom_field_defs")->fetchColumn();

        $db->prepare("DELETE FROM schema_migrations WHERE version = '3.5.0-custom-fields'")->execute();
        \apply_migrations($db);

        $defCountAfter = (int)$db->query("SELECT count(*) FROM custom_field_defs")->fetchColumn();
        $this->assertSame($defCountBefore, $defCountAfter, 'idempotent re-run must not drop existing definitions');

        $subnetCols = array_column($db->query("PRAGMA table_info(subnets)")->fetchAll(), 'name');
        $this->assertSame(1, count(array_filter($subnetCols, fn($c) => $c === 'custom_fields')));
        $addrCols = array_column($db->query("PRAGMA table_info(addresses)")->fetchAll(), 'name');
        $this->assertSame(1, count(array_filter($addrCols, fn($c) => $c === 'custom_fields')));

        $marker = $db->query(
            "SELECT 1 FROM schema_migrations WHERE version = '3.5.0-custom-fields'"
        )->fetchColumn();
        $this->assertNotFalse($marker, 'schema_migrations marker must be re-recorded after idempotent re-run');
    }

    public function testV360MigrationsApply(): void
    {
        $db = $this->makePreVrfDb();

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
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

        $cols = array_column($db->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        $this->assertContains('totp_secret_enc', $cols, 'totp_secret_enc column missing');
        $this->assertContains('totp_enabled', $cols, 'totp_enabled column missing');
        $this->assertContains('failed_auth_count', $cols, 'failed_auth_count column missing');
        $this->assertContains('locked_until', $cols, 'locked_until column missing');
        $this->assertContains('lock_reason', $cols, 'lock_reason column missing');

        $tables = array_column(
            $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(),
            'name'
        );
        $this->assertContains('totp_backup_codes', $tables, 'totp_backup_codes table missing');
        $this->assertContains('rate_limit_buckets', $tables, 'rate_limit_buckets table missing');

        \apply_migrations($db);
        $this->assertTrue(true, 'Second apply_migrations call should not throw');

        $db->exec("INSERT INTO users (username, password_hash, role) VALUES ('testv360', 'hash', 'readonly')");
        $row = $db->query("SELECT totp_enabled, failed_auth_count FROM users WHERE username='testv360'")->fetch();
        $this->assertEquals(0, (int)$row['totp_enabled']);
        $this->assertEquals(0, (int)$row['failed_auth_count']);
    }
}
