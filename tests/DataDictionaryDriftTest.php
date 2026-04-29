<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tools/generate-data-dictionary.php';

/**
 * Drift protection for docs/internal/data-dictionary.md.
 *
 * The data dictionary is a generated artefact, derived from the three
 * schema files (schema.sql / schema.mysql.sql / schema.pgsql.sql) by
 * tools/generate-data-dictionary.php. This test calls data_dictionary_render()
 * directly (in-process — no subprocess, no shell) and asserts the committed
 * file is byte-for-byte identical to the freshly-rendered output.
 *
 * Sibling protection to SchemaParityTest: where parity asserts the three
 * engines describe the same shape, this asserts the markdown reference
 * still reflects that shape. A schema change with no dictionary refresh
 * fails this test, prompting the author to run the generator before push.
 */
final class DataDictionaryDriftTest extends TestCase
{
    public function testDataDictionaryIsUpToDate(): void
    {
        $expected = data_dictionary_render();

        $path = __DIR__ . '/../docs/internal/data-dictionary.md';
        self::assertFileExists($path, 'data-dictionary.md is missing — run: php tools/generate-data-dictionary.php');

        $actual = (string)file_get_contents($path);

        self::assertSame(
            $expected,
            $actual,
            "data-dictionary.md is stale.\n"
            . "Re-run: php tools/generate-data-dictionary.php"
        );
    }
}
