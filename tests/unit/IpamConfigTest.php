<?php
declare(strict_types=1);

namespace Ipam\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class IpamConfigTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['config'] = [
            'force_https' => true,
            'session_name' => 'TEST',
            'db' => ['driver' => 'sqlite', 'dsn' => ':memory:'],
        ];
        if (function_exists('ipam_config_invalidate_cache')) {
            \ipam_config_invalidate_cache();
        }
    }

    public function testNullKeyReturnsWholeArray(): void
    {
        $this->assertSame($GLOBALS['config'], \ipam_config());
    }

    public function testDirectKey(): void
    {
        $this->assertTrue(\ipam_config('force_https'));
    }

    public function testNullDefault(): void
    {
        $this->assertNull(\ipam_config('nonexistent'));
        $this->assertSame('fallback', \ipam_config('nonexistent', 'fallback'));
    }

    public function testNestedAccess(): void
    {
        $this->assertSame('sqlite', \ipam_config_nested('db', 'driver'));
        $this->assertNull(\ipam_config_nested('db', 'nonexistent'));
        $this->assertNull(\ipam_config_nested('nonexistent'));
    }

    public function testNestedEmptyPathReturnsNull(): void
    {
        $this->assertNull(\ipam_config_nested());
    }

    public function testKeyExistsWithFalsyValueIsNotDefault(): void
    {
        $GLOBALS['config']['force_https'] = false;
        \ipam_config_invalidate_cache();
        $this->assertFalse(\ipam_config('force_https', 'DEFAULT_NOT_RETURNED'));

        $GLOBALS['config']['empty_string'] = '';
        \ipam_config_invalidate_cache();
        $this->assertSame('', \ipam_config('empty_string', 'DEFAULT_NOT_RETURNED'));

        $GLOBALS['config']['null_value'] = null;
        \ipam_config_invalidate_cache();
        $this->assertNull(\ipam_config('null_value', 'DEFAULT_NOT_RETURNED'));
    }

    public function testCacheInvalidatesOnGlobalReassign(): void
    {
        $this->assertTrue(\ipam_config('force_https'));
        $GLOBALS['config'] = ['force_https' => false];
        $this->assertFalse(\ipam_config('force_https'));
    }

    public function testExplicitInvalidateForcesRefresh(): void
    {
        $this->assertSame('TEST', \ipam_config('session_name'));
        $GLOBALS['config']['session_name'] = 'CHANGED';
        \ipam_config_invalidate_cache();
        $this->assertSame('CHANGED', \ipam_config('session_name'));
    }
}
