<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Simple-PHP-IPAM/lib.php';

/**
 * Tri-state UI ↔ DB encoding for per-schedule notify overrides (#825 / E3b).
 * The Notifications-tab form posts 'inherit' / 'on' / 'off' radio values;
 * these helpers translate to the storage form (NULL / 1 / 0) and back so
 * the form round-trips faithfully across save → reload.
 */
class NotifyOverrideTristateTest extends TestCase
{
    public function testToDbMapsKnownValues(): void
    {
        $this->assertSame(1, ipam_admin_notify_tristate_to_db('on'));
        $this->assertSame(0, ipam_admin_notify_tristate_to_db('off'));
        $this->assertNull(ipam_admin_notify_tristate_to_db('inherit'));
    }

    public function testToDbDefaultsToNullOnUnknown(): void
    {
        $this->assertNull(ipam_admin_notify_tristate_to_db(''));
        $this->assertNull(ipam_admin_notify_tristate_to_db('garbage'));
        $this->assertNull(ipam_admin_notify_tristate_to_db(null));
        $this->assertNull(ipam_admin_notify_tristate_to_db(1));   // non-string
    }

    public function testFromDbMapsStorageValues(): void
    {
        $this->assertSame('inherit', ipam_admin_notify_tristate_from_db(null));
        $this->assertSame('on',      ipam_admin_notify_tristate_from_db(1));
        $this->assertSame('on',      ipam_admin_notify_tristate_from_db('1'));
        $this->assertSame('off',     ipam_admin_notify_tristate_from_db(0));
        $this->assertSame('off',     ipam_admin_notify_tristate_from_db('0'));
    }

    public function testFromDbInheritsOnUnknown(): void
    {
        $this->assertSame('inherit', ipam_admin_notify_tristate_from_db('garbage'));
        $this->assertSame('inherit', ipam_admin_notify_tristate_from_db([]));
    }

    public function testRoundTrip(): void
    {
        foreach (['inherit', 'on', 'off'] as $v) {
            $this->assertSame(
                $v,
                ipam_admin_notify_tristate_from_db(ipam_admin_notify_tristate_to_db($v)),
                "round-trip must preserve '$v'"
            );
        }
    }
}
