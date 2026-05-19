<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * T3 (#895): coverage for CSV-import row-error reporting in lib/csv_import.php.
 *
 * Test gap #895 — ipam_csv_import_analyze() per-row error handling was
 * untested. This fixture-based suite verifies that a CSV containing a mix of
 * valid and broken rows is processed row-by-row: bad rows are reported
 * individually with the correct row number and a meaningful reason, valid
 * rows still get processed, and the error/success counts survive through the
 * apply step and the saved result file.
 *
 * Mirrors CsvImportTest's bootstrap, in-memory-SQLite seeding and result-file
 * cleanup conventions. Exercises the real ipam_csv_import_* functions against
 * a real seeded database and a real fixture CSV — no mocks.
 */
final class CsvImportRowErrorTest extends TestCase
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

    /**
     * Wizard session-state for the import-with-errors fixture.
     *
     * @return array<string, mixed>
     */
    private function errorWiz(): array
    {
        return [
            'tmp_path'   => __DIR__ . '/fixtures/csv/import-with-errors.csv',
            'delimiter'  => ',',
            'has_header' => 'yes',
            'mapping'    => ['ip' => '0', 'hostname' => '1', 'owner' => '2', 'cidr' => '3', 'custom_fields' => '4'],
            'dup_mode'   => 'skip',
        ];
    }

    /**
     * Index plan rows by the hostname they carry, so individual rows can be
     * asserted regardless of ordering.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function byHostname(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) ($r['hostname'] ?? '')] = $r;
        }
        return $out;
    }

    public function testAnalyzeReportsEachErrorClassWithoutAborting(): void
    {
        $fixture = __DIR__ . '/fixtures/csv/import-with-errors.csv';
        self::assertFileExists($fixture);

        $plan = ipam_csv_import_analyze($this->db, $this->errorWiz());

        // 8 data rows parsed; the analysis runs every row — no early abort.
        $summary = $plan['summary'];
        self::assertSame(8, $summary['parsed']);
        self::assertCount(8, $plan['rows']);

        // 4 hard-invalid rows: bad IP, bad CIDR, IP-not-in-CIDR, bad JSON.
        self::assertSame(4, $summary['invalid']);
        // The in-CSV duplicate is a skip (not an invalid).
        self::assertSame(1, $summary['duplicate_in_csv']);
        self::assertSame(1, $summary['skip']);
        // 3 valid rows resolve to creates.
        self::assertSame(3, $summary['create']);
    }

    public function testEachBadRowHasCorrectRowNumberAndReason(): void
    {
        $plan = ipam_csv_import_analyze($this->db, $this->errorWiz());
        $rows = self::byHostname($plan['rows']);

        // row_num counts every CSV line including the header, so the first
        // data row is row 2.
        $expected = [
            'good-a'    => ['row' => 2, 'action' => 'create',  'reason' => null],
            'bad-ip'    => ['row' => 3, 'action' => 'invalid', 'reason' => 'Invalid IP'],
            'good-b'    => ['row' => 4, 'action' => 'create',  'reason' => null],
            'bad-cidr'  => ['row' => 5, 'action' => 'invalid', 'reason' => 'Invalid CIDR'],
            'wrong-net' => ['row' => 6, 'action' => 'invalid', 'reason' => 'IP does not belong to provided CIDR'],
            'bad-json'  => ['row' => 7, 'action' => 'invalid', 'reason' => 'custom_fields: invalid JSON'],
            'dup-row'   => ['row' => 8, 'action' => 'skip',    'reason' => 'Duplicate row in CSV'],
            'good-c'    => ['row' => 9, 'action' => 'create',  'reason' => null],
        ];

        foreach ($expected as $hostname => $want) {
            self::assertArrayHasKey($hostname, $rows, "row '$hostname' must be present in plan");
            $row = $rows[$hostname];
            self::assertSame($want['row'], $row['row_num'], "row_num for '$hostname'");
            self::assertSame($want['action'], $row['final_action'], "final_action for '$hostname'");
            if ($want['reason'] !== null) {
                self::assertSame($want['reason'], $row['reason'], "reason for '$hostname'");
            }
        }

        // The in-CSV duplicate carries the dedicated display_action.
        self::assertSame('duplicate_in_csv', $rows['dup-row']['display_action']);
        // Bad rows preserve the offending raw IP so the operator can locate it.
        self::assertSame('not-an-ip', $rows['bad-ip']['ip_raw']);
    }

    public function testValidRowsStillProcessedDespiteBadRows(): void
    {
        $plan = ipam_csv_import_analyze($this->db, $this->errorWiz());
        $rows = self::byHostname($plan['rows']);

        // The three good rows all resolved to a CIDR and a create action,
        // proving a bad row in between did not abort the run.
        foreach (['good-a', 'good-b', 'good-c'] as $hostname) {
            self::assertSame('create', $rows[$hostname]['final_action']);
            self::assertSame('10.50.60.0/24', $rows[$hostname]['resolved_cidr']);
        }
    }

    public function testApplyHonoursRowErrorsAndCountsAreCorrect(): void
    {
        $plan   = ipam_csv_import_analyze($this->db, $this->errorWiz());
        $result = ipam_csv_import_apply_plan($this->db, $plan);

        $summary = $result['summary'];
        // Only the 3 valid rows are created; subnet created once.
        self::assertSame(1, $summary['created_subnets']);
        self::assertSame(3, $summary['created_addresses']);
        self::assertSame(0, $summary['updated_addresses']);
        // 4 invalid + 1 in-CSV duplicate = 5 skipped rows.
        self::assertSame(5, $summary['skipped_rows']);
        self::assertSame(0, $summary['conflicts']);
        self::assertCount(8, $result['rows']);

        // Database side-effects: exactly the 3 valid addresses landed.
        $addrCount = (int) $this->db->query("SELECT COUNT(*) FROM addresses")->fetchColumn();
        self::assertSame(3, $addrCount);
        $hostnames = $this->db->query("SELECT hostname FROM addresses ORDER BY hostname")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['good-a', 'good-b', 'good-c'], $hostnames);

        self::removeTmpFile((string) $result['result_path']);
    }

    public function testRowErrorsSurviveToSavedResultFile(): void
    {
        $plan   = ipam_csv_import_analyze($this->db, $this->errorWiz());
        $result = ipam_csv_import_apply_plan($this->db, $plan);

        $resultPath = (string) $result['result_path'];
        self::assertFileExists($resultPath);

        $loaded = ipam_csv_import_load_result($resultPath);
        self::assertSame($result['summary'], $loaded['summary']);
        self::assertCount(8, $loaded['rows']);

        // Index the persisted result rows by row_num and confirm each bad row
        // round-tripped its final_result and a non-empty reason.
        $byNum = [];
        foreach ($loaded['rows'] as $r) {
            $byNum[(int) $r['row_num']] = $r;
        }

        $expectedResults = [
            2 => 'created',
            3 => 'invalid',
            4 => 'created',
            5 => 'invalid',
            6 => 'invalid',
            7 => 'invalid',
            8 => 'skip',
            9 => 'created',
        ];
        foreach ($expectedResults as $rowNum => $finalResult) {
            self::assertArrayHasKey($rowNum, $byNum, "result row $rowNum must be persisted");
            self::assertSame($finalResult, $byNum[$rowNum]['final_result'], "final_result for row $rowNum");
            if ($finalResult !== 'created') {
                self::assertNotSame('', (string) $byNum[$rowNum]['reason'], "row $rowNum must carry a reason");
            }
        }

        self::removeTmpFile($resultPath);
    }
}
