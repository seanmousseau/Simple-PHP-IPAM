<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ipam_destination_test_now() — the centralised destination-probe helper
 * shared between manual Test clicks and the auto-on-save path (#787).
 *
 * Two layers of assertion:
 *   1. Function shape (signature, return contract).
 *   2. Behaviour against an in-memory SQLite seeded with a 'local' destination
 *      pointing at an existing writable tmp dir → must round-trip ok=true with
 *      a non-negative latency_ms.
 */
final class DestinationTestNowTest extends TestCase
{
    public function testSignatureMatchesExpectedShape(): void
    {
        $r = new ReflectionFunction('ipam_destination_test_now');
        $params = $r->getParameters();
        $this->assertCount(3, $params, 'expected (PDO, int, string) signature');
        $this->assertSame('db',           $params[0]->getName());
        $this->assertSame('destId',       $params[1]->getName());
        $this->assertSame('triggeredBy',  $params[2]->getName());
        $this->assertTrue($params[2]->isOptional(), 'triggeredBy must default to "manual"');
    }

    public function testTestDestinationPhpDelegatesToHelper(): void
    {
        // Lock in that the public endpoint delegates rather than re-implementing the probe.
        $source = file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/test_destination.php');
        $this->assertIsString($source);
        $this->assertStringContainsString(
            'ipam_destination_test_now(',
            $source,
            'test_destination.php must delegate to the shared helper'
        );
    }

    public function testReturnsOkForWritableLocalDirectory(): void
    {
        // LocalBackupClient confines the path under <app>/data/, so seed a
        // test sub-directory there and clean it up after.
        $base = dirname(__DIR__) . '/Simple-PHP-IPAM/data';
        $sub  = $base . '/pwtest-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($sub, 0700, true));
        try {
            $db = $this->seedDb($sub);
            $result = ipam_destination_test_now($db, 1, 'auto-on-save');
            $this->assertTrue($result['ok'], 'local probe must succeed for writable dir');
            $this->assertIsString($result['message']);
            $this->assertNotNull($result['latency_ms']);
            $this->assertGreaterThanOrEqual(0, $result['latency_ms']);
        } finally {
            @rmdir($sub);
        }
    }

    public function testReturnsErrorForMissingDestination(): void
    {
        $db = $this->seedDb('/tmp');
        $result = ipam_destination_test_now($db, 999, 'manual');
        $this->assertFalse($result['ok']);
        $this->assertSame('Destination not found', $result['message']);
    }

    public function testRejectsNonPositiveId(): void
    {
        $db = $this->seedDb('/tmp');
        $result = ipam_destination_test_now($db, 0, 'manual');
        $this->assertFalse($result['ok']);
        $this->assertSame('Invalid destination id', $result['message']);
    }

    /** Build an in-memory SQLite with the minimum schema the helper touches. */
    private function seedDb(string $localPath): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE backup_destinations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type TEXT NOT NULL,
            config TEXT NOT NULL DEFAULT '{}',
            encrypt INTEGER NOT NULL DEFAULT 1,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            entity_type TEXT,
            entity_id INTEGER,
            user_id INTEGER,
            username TEXT,
            ip TEXT,
            user_agent TEXT,
            details TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");
        $cfg = json_encode(['path' => $localPath]);
        $stmt = $db->prepare(
            "INSERT INTO backup_destinations (id, name, type, config) VALUES (1, 'local', 'local', :c)"
        );
        $stmt->execute([':c' => $cfg]);
        return $db;
    }
}
