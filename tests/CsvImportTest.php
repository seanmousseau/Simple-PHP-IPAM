<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * B13 (#925): coverage for the CSV-import wizard logic extracted from
 * import_csv.php into lib/csv_import.php (ADR-004).
 *
 * Verifies the module is a clean, require-able unit (no top-level side
 * effects) and exercises the parse-plan-apply happy path against a seeded
 * in-memory SQLite database using a small fixture CSV.
 *
 * import_csv.php itself cannot be loaded in-process (runs auth, header() and
 * exit() at the top level); the point of B13 is that lib/csv_import.php CAN
 * be — bootstrap.php loads it via lib.php and the functions resolve here.
 */
final class CsvImportTest extends TestCase
{
    private \PDO $db;

    /** @var mixed */
    private $previousGlobalDb = null;

    private bool $hadGlobalDb = false;

    protected function setUp(): void
    {
        $this->hadGlobalDb      = array_key_exists('db', $GLOBALS);
        $this->previousGlobalDb = $this->hadGlobalDb ? $GLOBALS['db'] : null;

        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec((string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/schema.sql'));
        $GLOBALS['db'] = $this->db;
    }

    protected function tearDown(): void
    {
        if ($this->hadGlobalDb) {
            $GLOBALS['db'] = $this->previousGlobalDb;
        } else {
            unset($GLOBALS['db']);
        }
    }

    /**
     * Remove a server-generated result file produced by
     * ipam_csv_import_save_result() (random bin2hex name under data/tmp/).
     * Not user input — kept as a single annotated call site.
     */
    private static function removeTmpFile(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- server-generated random path under data/tmp/, test cleanup
            unlink($path);
        }
    }

    public function testModuleFunctionsLiveInDedicatedModule(): void
    {
        $expected = realpath(__DIR__ . '/../Simple-PHP-IPAM/lib/csv_import.php');
        $functions = [
            'ipam_csv_import_analyze',
            'ipam_csv_import_apply_plan',
            'ipam_csv_import_resolve_device',
            'ipam_csv_import_save_result',
            'ipam_csv_import_load_result',
            'ipam_csv_import_result_path',
        ];
        foreach ($functions as $fn) {
            self::assertTrue(function_exists($fn), "$fn must be defined");
            $ref = new \ReflectionFunction($fn);
            self::assertSame($expected, realpath((string) $ref->getFileName()), "$fn must live in lib/csv_import.php");
        }
    }

    public function testImportCsvPhpNoLongerDefinesExtractedLogic(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/import_csv.php');
        // The old in-page logic helpers must be gone.
        self::assertStringNotContainsString('function analyze_import', $src);
        self::assertStringNotContainsString('function save_import_result', $src);
        self::assertStringNotContainsString('function load_result_file', $src);
        // Render glue must remain.
        self::assertStringContainsString('function render_preview_table', $src);
        self::assertStringContainsString('function action_class', $src);
    }

    public function testParsePlanApplyHappyPath(): void
    {
        $fixture = __DIR__ . '/fixtures/csv/import-happy-path.csv';
        self::assertFileExists($fixture);

        // Mirror the $wiz session-state array the wizard builds across steps 1-2.
        $wiz = [
            'tmp_path'   => $fixture,
            'delimiter'  => ',',
            'has_header' => 'yes',
            'mapping'    => ['ip' => '0', 'hostname' => '1', 'owner' => '2', 'cidr' => '3'],
            'dup_mode'   => 'skip',
        ];

        // --- Step 3: dry-run analysis ---
        $plan = ipam_csv_import_analyze($this->db, $wiz);

        self::assertArrayHasKey('meta', $plan);
        self::assertArrayHasKey('summary', $plan);
        self::assertArrayHasKey('rows', $plan);
        self::assertSame('skip', $plan['meta']['dup_mode']);

        $summary = $plan['summary'];
        // Fixture: 4 data rows -> 2 creates, 1 in-CSV duplicate (skip), 1 invalid IP.
        self::assertSame(4, $summary['parsed']);
        self::assertSame(2, $summary['create']);
        self::assertSame(1, $summary['skip']);
        self::assertSame(1, $summary['duplicate_in_csv']);
        self::assertSame(1, $summary['invalid']);
        // needs_subnet_create counts CIDR resolution before in-CSV dup detection:
        // rows 1, 2 and 3 all resolve to the not-yet-existing 10.20.30.0/24.
        self::assertSame(3, $summary['needs_subnet_create']);
        self::assertCount(4, $plan['rows']);

        // --- Step 4: apply ---
        $applyResult = ipam_csv_import_apply_plan($this->db, $plan);

        $applySummary = $applyResult['summary'];
        self::assertSame(1, $applySummary['created_subnets']);
        self::assertSame(2, $applySummary['created_addresses']);
        self::assertSame(0, $applySummary['updated_addresses']);
        self::assertSame(2, $applySummary['skipped_rows']); // dup-in-csv + invalid
        self::assertSame(0, $applySummary['conflicts']);
        self::assertCount(4, $applyResult['rows']);

        // Result file written and round-trippable.
        $resultPath = (string) $applyResult['result_path'];
        self::assertFileExists($resultPath);
        $loaded = ipam_csv_import_load_result($resultPath);
        self::assertSame($applySummary, $loaded['summary']);
        self::removeTmpFile($resultPath);

        // Database side-effects: the subnet and two addresses now exist.
        $subnetCount = (int) $this->db->query("SELECT COUNT(*) FROM subnets WHERE cidr='10.20.30.0/24'")->fetchColumn();
        self::assertSame(1, $subnetCount);
        $addrCount = (int) $this->db->query("SELECT COUNT(*) FROM addresses")->fetchColumn();
        self::assertSame(2, $addrCount);

        $hostnames = $this->db->query("SELECT hostname FROM addresses ORDER BY ip")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['host-a', 'host-b'], $hostnames);
    }

    public function testApplyUpdatesExistingAddressUnderOverwriteMode(): void
    {
        $fixture = __DIR__ . '/fixtures/csv/import-happy-path.csv';
        $wiz = [
            'tmp_path'   => $fixture,
            'delimiter'  => ',',
            'has_header' => 'yes',
            'mapping'    => ['ip' => '0', 'hostname' => '1', 'owner' => '2', 'cidr' => '3'],
            'dup_mode'   => 'skip',
        ];

        // First import seeds the rows.
        $apply1 = ipam_csv_import_apply_plan($this->db, ipam_csv_import_analyze($this->db, $wiz));
        self::removeTmpFile((string) $apply1['result_path']);

        // Second pass with overwrite mode: both existing addresses become updates.
        $wiz['dup_mode'] = 'overwrite';
        $plan2 = ipam_csv_import_analyze($this->db, $wiz);
        self::assertSame(2, $plan2['summary']['update']);
        self::assertSame(0, $plan2['summary']['create']);

        $apply2 = ipam_csv_import_apply_plan($this->db, $plan2);
        self::assertSame(2, $apply2['summary']['updated_addresses']);
        self::assertSame(0, $apply2['summary']['created_addresses']);
        self::removeTmpFile((string) $apply2['result_path']);

        // Still only two addresses — updates, not inserts.
        $addrCount = (int) $this->db->query("SELECT COUNT(*) FROM addresses")->fetchColumn();
        self::assertSame(2, $addrCount);
    }
}
