<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * v3.29.0 #894 — Pin the custom-fields type-system validation contract.
 *
 * Two validators in lib.php enforce the same type system but have different
 * coercion / acceptance semantics:
 *
 *   - validate_custom_fields_payload()      ← form-POST entry point.
 *     Raw values are strings (everything came through $_POST); the validator
 *     accepts numeric strings for type=number and coerces them.
 *
 *   - validate_custom_fields_api_payload()  ← JSON API entry point.
 *     Values are already typed (int/float/bool/string/null) and the validator
 *     rejects type mismatches with no coercion. Unknown keys are rejected.
 *
 * Supported types (per schema.sql column comment):
 *   text | number | date | boolean | select
 *
 * Tests below pin happy-path + at-least-one-invalid-input per type, the
 * empty-value branch ("" → required throws, "" → optional → null), the
 * boolean special case (presence-based for the form path), and unknown-key
 * rejection for the API path.
 */
final class CustomFieldsValidationTest extends TestCase
{
    /**
     * @param array<string,mixed> $extras
     * @return array<string,mixed>
     */
    private function def(string $key, string $type, bool $required = false, array $extras = []): array
    {
        return array_merge([
            'key'         => $key,
            'type'        => $type,
            'is_required' => $required ? 1 : 0,
            'options'     => null,
        ], $extras);
    }

    // ─── validate_custom_fields_payload() — form POST path ────────────────

    /**
     * @return list<array{string,string,mixed}>
     */
    public static function formAcceptedProvider(): array
    {
        return [
            'text accepts arbitrary string'              => ['text',    'hello world', 'hello world'],
            'number accepts integer string'              => ['number',  '42',          42],
            'number accepts float string'                => ['number',  '3.14',        3.14],
            'date accepts YYYY-MM-DD'                    => ['date',    '2026-05-14',  '2026-05-14'],
        ];
    }

    #[DataProvider('formAcceptedProvider')]
    public function testFormPathAcceptsValidPerType(string $type, string $raw, mixed $expected): void
    {
        $defs   = [$this->def('field', $type)];
        $result = validate_custom_fields_payload($defs, ['field' => $raw]);
        $this->assertSame($expected, $result['field']);
    }

    /**
     * @return list<array{string,string}>
     */
    public static function formRejectedProvider(): array
    {
        return [
            'number rejects non-numeric'   => ['number', 'not-a-number'],
            'date rejects partial date'    => ['date',   '2026-05'],
            'date rejects slash-separated' => ['date',   '2026/05/14'],
        ];
    }

    #[DataProvider('formRejectedProvider')]
    public function testFormPathRejectsInvalidPerType(string $type, string $raw): void
    {
        $defs = [$this->def('field', $type)];
        $this->expectException(\InvalidArgumentException::class);
        validate_custom_fields_payload($defs, ['field' => $raw]);
    }

    public function testFormPathSelectAcceptsAllowedOption(): void
    {
        $defs = [$this->def('env', 'select', false, [
            'options' => json_encode(['prod', 'staging', 'dev']),
        ])];
        $result = validate_custom_fields_payload($defs, ['env' => 'staging']);
        $this->assertSame('staging', $result['env']);
    }

    public function testFormPathSelectRejectsValueOutsideAllowedOptions(): void
    {
        $defs = [$this->def('env', 'select', false, [
            'options' => json_encode(['prod', 'staging', 'dev']),
        ])];
        $this->expectException(\InvalidArgumentException::class);
        validate_custom_fields_payload($defs, ['env' => 'qa']);
    }

    public function testFormPathBooleanIsPresenceBased(): void
    {
        $defs = [$this->def('flag', 'boolean')];
        // Form path: any "truthy" string sets true; absent / '' / '0' set false.
        $this->assertTrue(validate_custom_fields_payload($defs,  ['flag' => '1'])['flag']);
        $this->assertTrue(validate_custom_fields_payload($defs,  ['flag' => 'on'])['flag']);
        $this->assertFalse(validate_custom_fields_payload($defs, [])['flag']);
        $this->assertFalse(validate_custom_fields_payload($defs, ['flag' => ''])['flag']);
        $this->assertFalse(validate_custom_fields_payload($defs, ['flag' => '0'])['flag']);
    }

    public function testFormPathEmptyOptionalYieldsNull(): void
    {
        $defs   = [$this->def('note', 'text', false)];
        $result = validate_custom_fields_payload($defs, ['note' => '']);
        $this->assertArrayHasKey('note', $result);
        $this->assertNull($result['note']);
    }

    public function testFormPathEmptyRequiredThrows(): void
    {
        $defs = [$this->def('note', 'text', true)];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('this field is required');
        validate_custom_fields_payload($defs, ['note' => '']);
    }

    public function testFormPathUnknownTypeDropsField(): void
    {
        // Switch has no case for unknown types → field never written to $result.
        $defs   = [$this->def('mystery', 'json')];
        $result = validate_custom_fields_payload($defs, ['mystery' => 'whatever']);
        $this->assertArrayNotHasKey('mystery', $result);
    }

    // ─── validate_custom_fields_api_payload() — JSON API path ─────────────

    /**
     * @return list<array{string,mixed,mixed}>
     */
    public static function apiAcceptedProvider(): array
    {
        return [
            'text accepts string'        => ['text',    'hello',     'hello'],
            'number accepts int'         => ['number',  42,          42],
            'number accepts float'       => ['number',  3.14,        3.14],
            'date accepts ISO date'      => ['date',    '2026-05-14','2026-05-14'],
            'boolean accepts true'       => ['boolean', true,        true],
            'boolean accepts false'      => ['boolean', false,       false],
        ];
    }

    #[DataProvider('apiAcceptedProvider')]
    public function testApiPathAcceptsValidPerType(string $type, mixed $val, mixed $expected): void
    {
        $defs   = [$this->def('field', $type)];
        $result = validate_custom_fields_api_payload($defs, ['field' => $val]);
        $this->assertSame($expected, $result['field']);
    }

    /**
     * @return list<array{string,mixed}>
     */
    public static function apiRejectedProvider(): array
    {
        return [
            'text rejects int'              => ['text',    42],
            'number rejects numeric string' => ['number',  '42'],          // strict: no coercion on API path
            'date rejects malformed'        => ['date',    '14-05-2026'],
            'date rejects non-string'       => ['date',    20260514],
            'boolean rejects int 1'         => ['boolean', 1],              // strict: no truthy coercion
            'boolean rejects string "true"' => ['boolean', 'true'],
        ];
    }

    #[DataProvider('apiRejectedProvider')]
    public function testApiPathRejectsInvalidPerType(string $type, mixed $val): void
    {
        $defs = [$this->def('field', $type)];
        $this->expectException(\InvalidArgumentException::class);
        validate_custom_fields_api_payload($defs, ['field' => $val]);
    }

    public function testApiPathSelectAcceptsAllowedOption(): void
    {
        $defs = [$this->def('env', 'select', false, [
            'options' => json_encode(['prod', 'staging']),
        ])];
        $result = validate_custom_fields_api_payload($defs, ['env' => 'prod']);
        $this->assertSame('prod', $result['env']);
    }

    public function testApiPathSelectRejectsValueOutsideAllowedOptions(): void
    {
        $defs = [$this->def('env', 'select', false, [
            'options' => json_encode(['prod', 'staging']),
        ])];
        $this->expectException(\InvalidArgumentException::class);
        validate_custom_fields_api_payload($defs, ['env' => 'qa']);
    }

    public function testApiPathRejectsUnknownKey(): void
    {
        $defs = [$this->def('known', 'text')];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown custom field key');
        validate_custom_fields_api_payload($defs, ['known' => 'ok', 'mystery' => 'x']);
    }

    public function testApiPathAbsentOptionalYieldsNull(): void
    {
        $defs   = [$this->def('note', 'text', false)];
        $result = validate_custom_fields_api_payload($defs, []);
        $this->assertArrayHasKey('note', $result);
        $this->assertNull($result['note']);
    }

    public function testApiPathNullValueOnRequiredThrows(): void
    {
        $defs = [$this->def('note', 'text', true)];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('this field is required');
        validate_custom_fields_api_payload($defs, ['note' => null]);
    }

    public function testApiPathAbsentRequiredThrows(): void
    {
        $defs = [$this->def('note', 'text', true)];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('this field is required');
        validate_custom_fields_api_payload($defs, []);
    }

    public function testApiPathUnknownTypeDropsField(): void
    {
        // Like the form path, unknown types are silently dropped (no case in switch).
        $defs   = [$this->def('mystery', 'json')];
        $result = validate_custom_fields_api_payload($defs, ['mystery' => 'whatever']);
        $this->assertArrayNotHasKey('mystery', $result);
    }
}
