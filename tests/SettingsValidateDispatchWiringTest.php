<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.30.0 ADR-001 Task 5.2c — pins the settings.php page-handler wiring of the
 * logical-type validation dispatch.
 *
 * The page handler runs as a top-level script (require init.php; redirect; exit)
 * and cannot be invoked in isolation from phpunit, so — like
 * SettingsToggleConsistencyTest — this test verifies the wiring by static
 * inspection of settings.php, and exercises the validation SEMANTICS directly
 * through ipam_setting_validate() (the function the handler now dispatches to).
 *
 * What it proves:
 *   1. settings.php dispatches validation through ipam_setting_validate()
 *      keyed on the logical type.
 *   2. The duplicated inline int min/max, JSON-invalid, and enum-membership
 *      checks have been removed from the handler.
 *   3. The integer-FORMAT guard and the stored-invalid-enum-option check stay
 *      inline (they are not ipam_setting_validate()'s job).
 *   4. ipam_setting_validate() rejects an invalid url/email/timezone/cidr and
 *      accepts a valid one — the behaviour now active on the page handler.
 */
class SettingsValidateDispatchWiringTest extends TestCase
{
    private string $settingsPhp;

    protected function setUp(): void
    {
        $this->settingsPhp = (string) file_get_contents(__DIR__ . '/../Simple-PHP-IPAM/settings.php');
        $this->assertNotEmpty($this->settingsPhp, 'settings.php must be readable');
    }

    public function testHandlerDispatchesThroughIpamSettingValidate(): void
    {
        $this->assertMatchesRegularExpression(
            '/ipam_setting_validate\(\s*\$logicalType\s*,\s*\$newValue\s*,\s*\$def\s*\)/',
            $this->settingsPhp,
            'Task 5.2c: the handler must validate via ipam_setting_validate($logicalType, $newValue, $def).'
        );
        // The logical type is sourced from $def['logical_type'] with a fallback
        // to the storage type.
        $this->assertStringContainsString(
            "\$def['logical_type']",
            $this->settingsPhp,
            'Task 5.2c: dispatch must key on the logical_type definition key.'
        );
    }

    public function testInlineDuplicatedChecksAreRemoved(): void
    {
        // The inline int min/max branch used these exact message literals.
        $this->assertStringNotContainsString(
            '"Must be at least {$min}."',
            $this->settingsPhp,
            'Task 5.2c: inline int min check must be removed — ipam_setting_validate() owns it.'
        );
        $this->assertStringNotContainsString(
            '"Must be at most {$max}."',
            $this->settingsPhp,
            'Task 5.2c: inline int max check must be removed — ipam_setting_validate() owns it.'
        );
        // The inline enum submitted-value membership check used this literal.
        $this->assertStringNotContainsString(
            "Must be one of the listed values.",
            $this->settingsPhp,
            'Task 5.2c: inline enum membership check must be removed — ipam_setting_validate() owns it.'
        );
        // ...and the dispatch path's new-style enum message is reachable: an
        // out-of-domain enum value must be rejected with the "Must be one of:"
        // wording that ipam_setting_validate() now owns.
        $enumResult = ipam_setting_validate(
            'enum',
            'nonexistent-option',
            ['options' => ['alpha' => 'Alpha', 'beta' => 'Beta']]
        );
        $this->assertIsString(
            $enumResult,
            'Task 5.2c: an out-of-domain enum value must be rejected by ipam_setting_validate().'
        );
        $this->assertStringContainsString(
            'Must be one of:',
            $enumResult,
            'Task 5.2c: the dispatch path must surface the new-style enum error message.'
        );
    }

    public function testIntegerFormatCheckOwnedByValidator(): void
    {
        // v3.30.0 refactor wave 1 (architecture review Finding 4): the int
        // FORMAT check is now wholly owned by ipam_setting_validate(). Its
        // `int` branch rejects non-integer input ('abc', '1.5') outright; the
        // redundant inline preg_match guard has been removed from the handler.
        $this->assertStringNotContainsString(
            "preg_match('/^-?\\d+\$/', \$raw)",
            $this->settingsPhp,
            'Finding 4: the redundant inline integer-format guard must be removed.'
        );
        $def = ['min' => 0, 'max' => 100];
        $this->assertIsString(
            ipam_setting_validate('int', '1.5', $def),
            'Finding 4: ipam_setting_validate() must reject a fractional string.'
        );
        $this->assertIsString(
            ipam_setting_validate('int', 'abc', $def),
            'Finding 4: ipam_setting_validate() must reject a non-numeric string.'
        );
        $this->assertTrue(
            ipam_setting_validate('int', '42', $def),
            'Finding 4: ipam_setting_validate() must accept an integer-valued string.'
        );
        $this->assertTrue(
            ipam_setting_validate('int', 42, $def),
            'Finding 4: ipam_setting_validate() must accept a native int.'
        );
    }

    public function testStoredInvalidEnumOptionCheckStaysInline(): void
    {
        // ipam_setting_validate() validates the SUBMITTED value only; the
        // "your stored value drifted out of the option domain" case must stay
        // inline in the handler.
        $this->assertStringContainsString(
            'Stored value is not a valid option. Select a valid option to fix it.',
            $this->settingsPhp,
            'Task 5.2c: the stored-invalid-enum-option check must remain inline.'
        );
    }

    /**
     * The newly-active format validation the page handler now enforces.
     *
     * @return list<array{0:string,1:mixed,2:mixed,3:array<string,mixed>}>
     */
    public static function newlyActiveTypeProvider(): array
    {
        return [
            'url'      => ['url', 'https://idp.example.com/auth', 'not-a-url', []],
            'email'    => ['email', 'ops@example.com', 'not-an-email', []],
            'timezone' => ['timezone', 'America/Toronto', 'Mars/Olympus_Mons', []],
            'cidr'     => ['cidr', '10.0.0.0/8', '999.0.0.0/8', []],
            'datetime' => ['datetime', '2026-05-15 09:30:00', 'not-a-date', []],
        ];
    }

    /**
     * @param array<string,mixed> $def
     * @dataProvider newlyActiveTypeProvider
     */
    public function testValidValueOfNewlyActiveTypeAccepted(string $logicalType, mixed $good, mixed $bad, array $def): void
    {
        $this->assertTrue(
            ipam_setting_validate($logicalType, $good, $def),
            "$logicalType: a legitimate value must still save under the new dispatch."
        );
    }

    /**
     * @param array<string,mixed> $def
     * @dataProvider newlyActiveTypeProvider
     */
    public function testInvalidValueOfNewlyActiveTypeRejected(string $logicalType, mixed $good, mixed $bad, array $def): void
    {
        $result = ipam_setting_validate($logicalType, $bad, $def);
        $this->assertIsString(
            $result,
            "$logicalType: an invalid value must be rejected with a field error, not persisted."
        );
        $this->assertNotSame('', $result, "$logicalType: the reject message must be non-empty.");
    }
}
