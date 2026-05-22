<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 2 Task 2.1 — verifies the utils extraction from lib.php
 * landed cleanly. The functions must (a) still exist in the global
 * namespace and (b) be declared in Simple-PHP-IPAM/lib/utils.php rather
 * than lib.php (proves the move was a real move, not a copy).
 *
 * tests/bootstrap.php requires lib.php; lib.php in turn requires
 * lib/utils.php, so by the time this test runs every util function
 * should be in scope from its new home.
 */
final class UtilsExtractionParityTest extends TestCase
{
    public function testUtilsFunctionsAreDefined(): void
    {
        $functions = [
            'e',
            'to_int',
            'to_str',
            'q_int',
            'format_bytes',
            'ipam_normalise_version',
            'base64url_encode',
            'base64url_decode',
        ];
        foreach ($functions as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testUtilsFunctionsLiveInUtilsFile(): void
    {
        $functions = [
            'e',
            'to_int',
            'to_str',
            'q_int',
            'format_bytes',
            'ipam_normalise_version',
            'base64url_encode',
            'base64url_decode',
        ];
        foreach ($functions as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/utils.php',
                (string)$declarer,
                "$fn should be declared in lib/utils.php, not " . (string)$declarer
            );
        }
    }
}
