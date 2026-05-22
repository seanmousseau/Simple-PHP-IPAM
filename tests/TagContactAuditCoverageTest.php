<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tests\Helpers\InMemoryDb;

require_once __DIR__ . '/bootstrap.php';

/**
 * v3.35.0 #1292 — diff-based audit-log coverage for browser-form tag and
 * contact attach/detach events.
 *
 * Verifies that save_tags_for_entity() and save_contacts_for_entity() emit
 * audit_log rows matching the canonical API shape (action=tag.attach /
 * tag.detach / contact.attach / contact.detach, entity_type = 'subnet' etc.,
 * entity_id = the row ID, details = "tag_id=N" or "contact_id=N role=<role>").
 *
 * The API shape is defined by api_audit() in api.php:
 *   action      => 'tag.attach' | 'tag.detach'
 *   entity_type => 'subnet' | 'address'
 *   entity_id   => <integer>
 *   details     => "tag_id=<N>"
 *
 * For contacts (no existing API attach/detach endpoints; browser is the only
 * path — shape is defined here and must stay stable):
 *   action      => 'contact.attach' | 'contact.detach'
 *   entity_type => 'site' | 'subnet'
 *   entity_id   => <integer>
 *   details     => "contact_id=<N> role=<role>"
 */
final class TagContactAuditCoverageTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = InMemoryDb::withMigrations();

        // Seed a subnet (binary fields required by schema NOT NULL)
        $this->db->exec(
            "INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
             VALUES ('10.0.0.0/24', 4, '10.0.0.0', X'0a000000', 24, 'test')"
        );
        // Seed an address (needs a subnet FK)
        $this->db->exec(
            "INSERT INTO addresses (subnet_id, ip, ip_bin, status)
             VALUES (1, '10.0.0.1', X'0a000001', 'used')"
        );
        // Seed a site
        $this->db->exec("INSERT INTO sites (name) VALUES ('site-a')");
        // Seed three tags
        $this->db->exec("INSERT INTO tags (name, colour) VALUES ('alpha','#111111'),('beta','#222222'),('gamma','#333333')");
        // Seed two contacts
        $this->db->exec("INSERT INTO contacts (name, email) VALUES ('Alice','alice@example.com'),('Bob','bob@example.com')");
    }

    // -----------------------------------------------------------------------
    // Tag tests
    // -----------------------------------------------------------------------

    /**
     * First save attaches 2 tags; second save replaces with a different set:
     * detaches the dropped tag, attaches the new one. Total 4 audit rows.
     */
    public function testSaveTagsEmitsAttachDetachDelta(): void
    {
        // First call: attach tag 1 and tag 2 to subnet 1 (IDs are 1-indexed by autoincrement)
        save_tags_for_entity($this->db, 'subnet', 1, [1, 2]);

        // Second call: keep tag 2, drop tag 1, add tag 3
        save_tags_for_entity($this->db, 'subnet', 1, [2, 3]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db
            ->query("SELECT action, entity_type, entity_id, details FROM audit_log WHERE action LIKE 'tag.%' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        // First call: tag.attach tag 1, tag.attach tag 2  (2 rows)
        // Second call: tag.detach tag 1, tag.attach tag 3 (2 rows)
        $this->assertCount(4, $rows, 'Expected 4 audit rows: 2 attach + 1 detach + 1 attach');

        $actions = array_column($rows, 'action');
        $this->assertSame(
            ['tag.attach', 'tag.attach', 'tag.detach', 'tag.attach'],
            $actions,
            'Audit action sequence must be attach/attach/detach/attach'
        );

        // Verify entity shape matches API canonical format
        foreach ($rows as $row) {
            $this->assertSame('subnet', $row['entity_type']);
            $this->assertSame(1, (int) $row['entity_id']);
        }

        // Verify details strings match API format "tag_id=N"
        $this->assertSame('tag_id=1', $rows[0]['details']);
        $this->assertSame('tag_id=2', $rows[1]['details']);
        $this->assertSame('tag_id=1', $rows[2]['details']); // detach of tag 1
        $this->assertSame('tag_id=3', $rows[3]['details']); // attach of tag 3
    }

    /**
     * When suppressAudit=true no audit rows must be written.
     * This is used by the api.php address-create/update paths where the
     * parent address.create / address.update row already carries the full
     * tag set — per-tag deltas would double-audit.
     */
    public function testSuppressAuditFlagSuppressesTagEvents(): void
    {
        save_tags_for_entity($this->db, 'address', 1, [1], true);

        $count = (int) $this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'tag.%'")
            ->fetchColumn();

        $this->assertSame(0, $count, 'suppressAudit=true must produce zero tag audit rows');
    }

    /**
     * An idempotent re-save (same tag set) must not emit any audit events.
     */
    public function testNoOpReSaveEmitsNoTagEvents(): void
    {
        save_tags_for_entity($this->db, 'subnet', 1, [1, 2]);

        $before = (int) $this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'tag.%'")
            ->fetchColumn();

        // Exact same set — no delta
        save_tags_for_entity($this->db, 'subnet', 1, [1, 2]);

        $after = (int) $this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'tag.%'")
            ->fetchColumn();

        $this->assertSame($before, $after, 'No-op re-save must not emit attach/detach events');
    }

    /**
     * Clearing all tags emits one detach per removed tag, nothing else.
     */
    public function testClearAllTagsEmitsDetachOnly(): void
    {
        save_tags_for_entity($this->db, 'subnet', 1, [1, 2, 3]);
        save_tags_for_entity($this->db, 'subnet', 1, []);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db
            ->query("SELECT action, details FROM audit_log WHERE action LIKE 'tag.%' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        // 3 attaches + 3 detaches = 6
        $this->assertCount(6, $rows);
        $actions = array_column($rows, 'action');
        $this->assertSame(
            ['tag.attach', 'tag.attach', 'tag.attach', 'tag.detach', 'tag.detach', 'tag.detach'],
            $actions
        );
    }

    /**
     * Tags on address entity type work the same as subnet.
     */
    public function testSaveTagsWorksForAddressEntityType(): void
    {
        save_tags_for_entity($this->db, 'address', 1, [1, 2]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db
            ->query("SELECT action, entity_type, entity_id FROM audit_log WHERE action LIKE 'tag.%' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('tag.attach', $row['action']);
            $this->assertSame('address', $row['entity_type']);
            $this->assertSame(1, (int) $row['entity_id']);
        }
    }

    // -----------------------------------------------------------------------
    // Contact tests
    // -----------------------------------------------------------------------

    /**
     * First save attaches 2 contacts; second replaces with a different set:
     * detaches the dropped contact, attaches the new one.
     */
    public function testSaveContactsEmitsAttachDetachDelta(): void
    {
        // First call: assign contacts 1 and 2 (IDs from seed) to subnet 1
        save_contacts_for_entity($this->db, 'subnet', 1, [
            ['contact_id' => 1, 'role' => 'primary'],
            ['contact_id' => 2, 'role' => 'backup'],
        ]);

        // Second call: drop contact 1, keep contact 2
        save_contacts_for_entity($this->db, 'subnet', 1, [
            ['contact_id' => 2, 'role' => 'backup'],
        ]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db
            ->query("SELECT action, entity_type, entity_id, details FROM audit_log WHERE action LIKE 'contact.%' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        // First call: 2 attaches. Second call: 1 detach (contact 1 removed).
        $this->assertCount(3, $rows, 'Expected 3 audit rows: 2 attach + 1 detach');

        $actions = array_column($rows, 'action');
        $this->assertSame(
            ['contact.attach', 'contact.attach', 'contact.detach'],
            $actions
        );

        foreach ($rows as $row) {
            $this->assertSame('subnet', $row['entity_type']);
            $this->assertSame(1, (int) $row['entity_id']);
        }

        // details must include contact_id
        $this->assertStringContainsString('contact_id=1', $rows[0]['details']);
        $this->assertStringContainsString('contact_id=2', $rows[1]['details']);
        $this->assertStringContainsString('contact_id=1', $rows[2]['details']); // detach contact 1
    }

    /**
     * Contact suppressAudit flag suppresses all events.
     */
    public function testSaveContactsSuppressFlagWorks(): void
    {
        save_contacts_for_entity($this->db, 'site', 1, [
            ['contact_id' => 1, 'role' => 'primary'],
        ], true);

        $count = (int) $this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'contact.%'")
            ->fetchColumn();

        $this->assertSame(0, $count, 'suppressAudit=true must produce zero contact audit rows');
    }

    /**
     * Idempotent contact re-save (same contact IDs) must not emit events.
     * Role changes on an existing contact are not tracked as contact.update
     * events — only ID-level attach/detach is diffed (#1292 scope).
     */
    public function testNoOpContactReSaveEmitsNoEvents(): void
    {
        $contacts = [
            ['contact_id' => 1, 'role' => 'primary'],
            ['contact_id' => 2, 'role' => 'backup'],
        ];

        save_contacts_for_entity($this->db, 'subnet', 1, $contacts);

        $before = (int) $this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'contact.%'")
            ->fetchColumn();

        save_contacts_for_entity($this->db, 'subnet', 1, $contacts);

        $after = (int) $this->db
            ->query("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'contact.%'")
            ->fetchColumn();

        $this->assertSame($before, $after, 'No-op contact re-save must not emit attach/detach events');
    }

    /**
     * Site entity type works the same as subnet for contacts.
     */
    public function testSaveContactsWorksForSiteEntityType(): void
    {
        save_contacts_for_entity($this->db, 'site', 1, [
            ['contact_id' => 1, 'role' => 'noc'],
        ]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $this->db
            ->query("SELECT action, entity_type, entity_id, details FROM audit_log WHERE action LIKE 'contact.%' ORDER BY id")
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('contact.attach', $rows[0]['action']);
        $this->assertSame('site', $rows[0]['entity_type']);
        $this->assertSame(1, (int) $rows[0]['entity_id']);
        $this->assertStringContainsString('contact_id=1', $rows[0]['details']);
        $this->assertStringContainsString('role=noc', $rows[0]['details']);
    }
}
