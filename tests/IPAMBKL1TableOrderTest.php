<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup.php';

/**
 * Topo-sort tests for IPAMBKL1's table_order header field.
 *
 * Spec: docs/internal/ipambkl1-format.md → "Header object" → table_order.
 *
 * The function under test (ipam_logical_table_order) returns the canonical
 * list of tables to dump and replay, in parents-first FK-safe order. Tests
 * validate the order against the *actual* live schema (in-memory SQLite +
 * apply_migrations) so a future schema change that adds a new table or FK
 * fails this test loudly rather than silently corrupting dumps.
 *
 * Self-referential tables (sites → sites) are allowed at any position;
 * they replay in two passes per the format spec.
 */
class IPAMBKL1TableOrderTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql');
        $this->db->exec($schema);
        $this->db->exec('PRAGMA foreign_keys = ON');
        ensure_migrations_table($this->db);
        apply_migrations($this->db);
    }

    public function testReturnsNonEmptyArrayOfStrings(): void
    {
        $order = ipam_logical_table_order($this->db);
        $this->assertNotEmpty($order);
        foreach ($order as $name) {
            $this->assertIsString($name);
            $this->assertNotSame('', $name);
        }
    }

    public function testIncludesEveryUserTableFromLiveSchema(): void
    {
        $order = ipam_logical_table_order($this->db);
        $rows = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"
        );
        $live = $rows ? $rows->fetchAll(PDO::FETCH_COLUMN) : [];
        $live = array_filter($live, fn($v) => is_string($v));

        sort($live);
        $orderSorted = $order;
        sort($orderSorted);

        $this->assertSame(
            $live,
            $orderSorted,
            'table_order must match the live schema set exactly — neither missing nor extra tables'
        );
    }

    public function testExcludesSqliteInternalTables(): void
    {
        $order = ipam_logical_table_order($this->db);
        foreach ($order as $name) {
            $this->assertStringStartsNotWith(
                'sqlite_',
                $name,
                "internal table '$name' must not appear in table_order"
            );
        }
    }

    public function testEveryFKTargetPrecedesItsReferrer(): void
    {
        $order = ipam_logical_table_order($this->db);
        $position = array_flip($order);

        foreach ($order as $table) {
            $fkRows = $this->db->query("PRAGMA foreign_key_list(\"$table\")");
            $fks = $fkRows ? $fkRows->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($fks as $fk) {
                $target = $fk['table'] ?? null;
                if (!is_string($target) || $target === '') continue;
                if ($target === $table) continue; // self-ref allowed (two-pass at restore)

                $this->assertArrayHasKey(
                    $target,
                    $position,
                    "FK target '$target' (referenced by '$table') is missing from table_order"
                );
                $this->assertLessThan(
                    $position[$table],
                    $position[$target],
                    "FK target '$target' must precede referrer '$table' in table_order " .
                    "(target at index {$position[$target]}, referrer at index {$position[$table]})"
                );
            }
        }
    }

    public function testIsDeterministicAcrossCalls(): void
    {
        $a = ipam_logical_table_order($this->db);
        $b = ipam_logical_table_order($this->db);
        $this->assertSame($a, $b, 'order must be deterministic — no randomness, no PDO state dependency');
    }

    public function testReturnsUniqueValues(): void
    {
        $order = ipam_logical_table_order($this->db);
        $this->assertSame(count($order), count(array_unique($order)), 'no duplicate table names');
    }
}
