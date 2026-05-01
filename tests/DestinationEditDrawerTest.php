<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Simple-PHP-IPAM/lib.php';
require_once __DIR__ . '/../Simple-PHP-IPAM/lib/backup_admin_destinations.php';

final class DestinationEditDrawerTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec(file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $this->db->exec("INSERT INTO backup_destinations (id, name, type, config) VALUES (1, 'pw-local', 'local', '{\"path\":\"/tmp\"}')");
        $this->db->exec("INSERT INTO backup_schedules (id, destination_id, frequency, time_of_day) VALUES (1, 1, 'daily', '02:00')");
    }

    public function testDestinationFormHasExpectedFields(): void
    {
        $html = ipam_render_destination_edit_drawer($this->db, 1, 'destination');
        $this->assertNotNull($html);
        $this->assertStringContainsString('action="backup_admin.php?tab=destinations"', $html);
        $this->assertStringContainsString('name="action" value="update_destination"', $html);
        $this->assertStringContainsString('name="id" value="1"', $html);
        $this->assertStringContainsString('name="name"', $html);
        $this->assertStringContainsString('pw-local', $html);
    }

    public function testScheduleFormHasExpectedFields(): void
    {
        $html = ipam_render_destination_edit_drawer($this->db, 1, 'schedule');
        $this->assertNotNull($html);
        $this->assertStringContainsString('name="action" value="update_schedule"', $html);
        $this->assertStringContainsString('name="frequency"', $html);
        $this->assertStringContainsString('value="daily"', $html);
        $this->assertStringContainsString('name="time_of_day"', $html);
        $this->assertStringContainsString('value="02:00"', $html);
    }

    public function testUnknownFormReturnsNull(): void
    {
        $this->assertNull(ipam_render_destination_edit_drawer($this->db, 1, 'bogus'));
    }

    public function testUnknownDestinationReturnsNull(): void
    {
        $this->assertNull(ipam_render_destination_edit_drawer($this->db, 999, 'destination'));
    }
}
