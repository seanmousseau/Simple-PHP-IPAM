<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B10 (#923): unit test for the api_bulk_create() generic extracted from
 * api.php. api.php cannot be require()'d in-process — it runs auth, header()
 * and exit() at the top level — so, like ApiPaginatedQueryTest, this test
 * extracts the single function body from the source and eval()s it into the
 * test process, then exercises it with stub process callables.
 *
 * api_bulk_create() emits the HTTP response itself (api_json + api_error),
 * so the test stubs api_json/api_error to capture output and throw instead
 * of terminating the process. http_response_code() is a PHP builtin — in
 * CLI it records the value and is read back via the same builtin.
 */
final class ApiBulkCreateTest extends TestCase
{
    /** @var array<string, mixed>|null */
    public static $captured;

    public static int $capturedCode = 0;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('api_bulk_create')) {
            $apiPath = __DIR__ . '/../../Simple-PHP-IPAM/api.php';
            $src = file_get_contents($apiPath);
            self::assertNotFalse($src, 'api.php must be readable');

            $start = strpos($src, 'function api_bulk_create');
            self::assertNotFalse($start, 'api_bulk_create not found in api.php');
            $bodyStart = strpos($src, '{', $start);
            self::assertNotFalse($bodyStart);
            $depth = 0;
            $len = strlen($src);
            $end = $bodyStart;
            for ($i = $bodyStart; $i < $len; $i++) {
                if ($src[$i] === '{') {
                    $depth++;
                } elseif ($src[$i] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            self::assertSame(0, $depth, 'unbalanced braces extracting api_bulk_create');
            $fnSrc = substr($src, $start, $end - $start + 1);
            eval($fnSrc); // test harness: api.php has top-level side effects, cannot require()
        }
        self::assertTrue(function_exists('api_bulk_create'), 'helper not defined');
    }

    protected function setUp(): void
    {
        self::$captured = null;
        self::$capturedCode = 0;
    }

    /**
     * Stub process callable: rows with a positive 'n' succeed, others fail.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function stubProcess(array $item): array
    {
        $n = isset($item['n']) ? (int) $item['n'] : 0;
        if ($n <= 0) {
            return ['success' => false, 'error' => 'n must be positive.'];
        }
        return ['success' => true, 'id' => $n];
    }

    public function testMixedValidAndInvalidRows(): void
    {
        $db = new \PDO('sqlite::memory:');
        $body = [
            ['n' => 1],
            ['n' => -5],
            ['n' => 3],
            'not-an-object',
        ];
        try {
            api_bulk_create($db, $body, 100, 'subnet objects', fn(array $r) => $this->stubProcess($r));
        } catch (\Throwable $e) {
            // api_json stub throws to halt — expected.
        }

        $this->assertIsArray(self::$captured);
        $this->assertSame(2, self::$captured['created']);
        $this->assertSame(2, self::$captured['failed']);
        $this->assertCount(4, self::$captured['results']);
        $this->assertSame(['success' => true, 'id' => 1], self::$captured['results'][0]);
        $this->assertSame(['success' => false, 'error' => 'n must be positive.'], self::$captured['results'][1]);
        $this->assertSame(['success' => true, 'id' => 3], self::$captured['results'][2]);
        $this->assertSame(['success' => false, 'error' => 'Item must be an object.'], self::$captured['results'][3]);
        // mixed success+failure -> 207
        $this->assertSame(207, self::$capturedCode);
    }

    public function testAllSucceedReturns201(): void
    {
        $db = new \PDO('sqlite::memory:');
        try {
            api_bulk_create($db, [['n' => 1], ['n' => 2]], 100, 'subnet objects', fn(array $r) => $this->stubProcess($r));
        } catch (\Throwable $e) {
        }
        $this->assertSame(2, self::$captured['created']);
        $this->assertSame(0, self::$captured['failed']);
        $this->assertSame(201, self::$capturedCode);
    }

    public function testAllFailReturns400(): void
    {
        $db = new \PDO('sqlite::memory:');
        try {
            api_bulk_create($db, [['n' => -1]], 100, 'subnet objects', fn(array $r) => $this->stubProcess($r));
        } catch (\Throwable $e) {
        }
        $this->assertSame(0, self::$captured['created']);
        $this->assertSame(1, self::$captured['failed']);
        $this->assertSame(400, self::$capturedCode);
    }

    public function testBulkLimitRejected(): void
    {
        $db = new \PDO('sqlite::memory:');
        $body = [['n' => 1], ['n' => 2], ['n' => 3]];
        try {
            api_bulk_create($db, $body, 2, 'subnet objects', fn(array $r) => $this->stubProcess($r));
        } catch (\Throwable $e) {
        }
        $this->assertSame(400, self::$capturedCode);
        $this->assertIsArray(self::$captured);
        $this->assertSame('Too many items. Maximum is 2 per request.', self::$captured['error']);
    }

    public function testEmptyArrayRejected(): void
    {
        $db = new \PDO('sqlite::memory:');
        try {
            api_bulk_create($db, [], 100, 'subnet objects', fn(array $r) => $this->stubProcess($r));
        } catch (\Throwable $e) {
        }
        $this->assertSame(400, self::$capturedCode);
        $this->assertSame('Array must not be empty.', self::$captured['error']);
    }

    public function testNonListRejectedWithLabel(): void
    {
        $db = new \PDO('sqlite::memory:');
        try {
            api_bulk_create($db, ['k' => 'v'], 100, 'address objects', fn(array $r) => $this->stubProcess($r));
        } catch (\Throwable $e) {
        }
        $this->assertSame(400, self::$capturedCode);
        $this->assertSame('Bulk create expects a JSON array of address objects.', self::$captured['error']);
    }
}

/**
 * Test-local stubs for api.php's response emitters. api_bulk_create() calls
 * these; in the test process they capture instead of terminating. Defined
 * conditionally so they don't collide if api.php was somehow loaded.
 */
if (!function_exists('api_json')) {
    /** @param array<string, mixed> $data */
    function api_json(array $data): never
    {
        ApiBulkCreateTest::$captured = $data;
        ApiBulkCreateTest::$capturedCode = http_response_code() ?: ApiBulkCreateTest::$capturedCode;
        throw new \RuntimeException('api_json halt');
    }
}
if (!function_exists('api_error')) {
    function api_error(int $code, string $message): never
    {
        ApiBulkCreateTest::$capturedCode = $code;
        ApiBulkCreateTest::$captured = ['error' => $message];
        throw new \RuntimeException('api_error halt');
    }
}
