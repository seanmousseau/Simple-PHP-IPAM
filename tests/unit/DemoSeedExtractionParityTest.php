<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class DemoSeedExtractionParityTest extends TestCase
{
    public function testDemoSeedDataLivesInDedicatedModule(): void
    {
        $ref = new \ReflectionFunction('demo_seed_data');
        self::assertSame(
            realpath(__DIR__ . '/../../Simple-PHP-IPAM/lib/demo_seed.php'),
            realpath((string) $ref->getFileName()),
            'demo_seed_data() must be defined in lib/demo_seed.php'
        );
    }

    public function testLibPhpNoLongerDefinesDemoSeed(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../Simple-PHP-IPAM/lib.php');
        self::assertStringNotContainsString('function demo_seed_data', $src);
    }
}
