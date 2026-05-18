<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class SecretEncryptionTest extends TestCase
{
    public function testKeyIsSecretboxKeyLength(): void
    {
        $key = ipam_secret_key();
        self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($key));
    }

    public function testKeyIsDeterministicForSameAppSecret(): void
    {
        self::assertSame(ipam_secret_key(), ipam_secret_key());
    }
}
