<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.29.0 #902 — sanity stub for the formerly-mega MigrationTest.php.
 *
 * The 22 test methods that used to live here were split into focused
 * suites under tests/Migration/. This stub stays so any external CI
 * script or contributor muscle-memory pointing at MigrationTest still
 * resolves to a real class; it just asserts the split files exist.
 *
 * If you're hunting for a specific migration test, check:
 *   - tests/Migration/SqliteOnlyClosuresTest.php — VRF rebuild + PRAGMA FK
 *   - tests/Migration/EngineParityTest.php       — idempotency / replay
 *   - tests/Migration/SettingsCascadeTest.php    — v2.6/v3.13 settings
 *   - tests/Migration/MfaTest.php                — passkeys, preferred MFA
 *   - tests/Migration/BackupTest.php             — v3.17+ backup chain
 *   - tests/Migration/IpStorageTest.php          — v2.9.0 BLOB affinity
 *   - tests/Migration/MiscTest.php               — devices, custom-fields, TOTP
 */
final class MigrationTest extends TestCase
{
    public function testSplitFilesExist(): void
    {
        $dir = __DIR__ . '/Migration';
        foreach ([
            'Base.php',
            'SqliteOnlyClosuresTest.php',
            'EngineParityTest.php',
            'SettingsCascadeTest.php',
            'MfaTest.php',
            'BackupTest.php',
            'IpStorageTest.php',
            'MiscTest.php',
        ] as $f) {
            $this->assertFileExists($dir . '/' . $f, "split file $f must exist");
        }
    }
}
