<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * ADR-004 Phase 5 Task 5.2b — verifies the settings-layer extraction from
 * lib.php landed cleanly (#907, #915). The functions must (a) still exist in
 * the global namespace and (b) be declared in
 * Simple-PHP-IPAM/lib/settings.php rather than lib.php (proves the move was a
 * real move, not a copy).
 *
 * The behavioural coverage lives in tests/SettingsTest.php (registry, codec,
 * accessors) and tests/SettingDispatchTest.php (the new ADR-001 11-type
 * dispatch layer). This file only enforces the physical location of the code.
 *
 * Note on the cache split (#915): the old 5-param ipam_setting_cache_storage()
 * is gone, replaced by the ipam_setting_cache_get / _set / _clear trio plus
 * the ipam_setting_cache_store / _key helpers. ipam_setting_cache_bust()
 * remains the public name. All live in lib/settings.php.
 */
final class SettingsExtractionParityTest extends TestCase
{
    /** @return list<string> */
    private function settingsFunctions(): array
    {
        return [
            // The 13 public functions moved from lib.php.
            'ipam_setting_definitions',
            'ipam_setting_groups',
            'ipam_setting_config_fallback',
            'ipam_setting_encode',
            'ipam_setting_decode',
            'ipam_setting_infer_type',
            'ipam_setting',
            'ipam_setting_set',
            'ipam_setting_cache_bust',
            'ipam_setting_all',
            'ipam_setting_source',
            'ipam_setting_deprecated_keys',
            'ipam_setting_options',
            // New in this task — the frozen seed registry + ADR-001 dispatch.
            'ipam_setting_definitions_seed',
            'ipam_setting_storage_type',
            'ipam_setting_validate',
            // #915 — the cache trio that replaced ipam_setting_cache_storage().
            'ipam_setting_cache_get',
            'ipam_setting_cache_set',
            'ipam_setting_cache_clear',
        ];
    }

    public function testSettingsFunctionsAreDefined(): void
    {
        foreach ($this->settingsFunctions() as $fn) {
            $this->assertTrue(function_exists($fn), "$fn should be defined");
        }
    }

    public function testSettingsFunctionsLiveInSettingsFile(): void
    {
        foreach ($this->settingsFunctions() as $fn) {
            $declarer = (new ReflectionFunction($fn))->getFileName();
            $this->assertNotFalse($declarer);
            $this->assertStringContainsString(
                '/lib/settings.php',
                (string)$declarer,
                "$fn should be declared in lib/settings.php, not " . (string)$declarer
            );
        }
    }

    public function testOldCacheStorageFunctionIsGone(): void
    {
        // #915 — the multi-mode helper must not survive the split.
        $this->assertFalse(
            function_exists('ipam_setting_cache_storage'),
            'ipam_setting_cache_storage() should have been removed by the #915 split'
        );
    }

    public function testIpamConfigStaleKeysStaysInLibPhp(): void
    {
        // ipam_config_stale_keys() is interleaved among the settings functions
        // but is a config concern — it must NOT have moved to lib/settings.php.
        $this->assertTrue(function_exists('ipam_config_stale_keys'));
        $declarer = (new ReflectionFunction('ipam_config_stale_keys'))->getFileName();
        $this->assertNotFalse($declarer);
        $this->assertStringContainsString('/lib.php', (string)$declarer);
        $this->assertStringNotContainsString('/lib/settings.php', (string)$declarer);
    }
}
