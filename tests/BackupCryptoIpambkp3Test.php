<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * v3.24.0 IPAMBKP3 / IPAMBKU1 codec tests (#836, #838, #839).
 *
 * This file covers the shared cryptographic helpers introduced for the new
 * format. Per-codec round-trip and tamper tests will land alongside the
 * codec implementation (#839).
 *
 * Hand-rolled-crypto guardrail (lessons-learned §5, v3.19.1 SigV4 lesson):
 * tests must include reference-vector or independent-implementation checks,
 * not only round-trip self-tests.
 */
class BackupCryptoIpambkp3Test extends TestCase
{
    // -----------------------------------------------------------------------
    // ipam_assert_random_bytes_available — #838 B-P1-35
    // -----------------------------------------------------------------------

    public function testRandomBytesAssertSucceedsOnSupportedBuild(): void
    {
        // PHP 8.2 always has random_bytes; this is a smoke check that the
        // probe returns without throwing on the build the test suite runs on.
        ipam_assert_random_bytes_available();
        ipam_assert_random_bytes_available(); // idempotent
        $this->expectNotToPerformAssertions();
    }

    // -----------------------------------------------------------------------
    // ipam_argon2id_derive — RFC 9106 / libsodium Argon2id v1.3
    // -----------------------------------------------------------------------

    public function testArgon2idIsDeterministic(): void
    {
        $salt = str_repeat("\x02", BACKUP_ARGON2_SALT_LEN);
        $a = ipam_argon2id_derive('correct horse battery staple', $salt, 2, 8192, 1, 32);
        $b = ipam_argon2id_derive('correct horse battery staple', $salt, 2, 8192, 1, 32);
        $this->assertSame($a, $b);
        $this->assertSame(32, strlen($a));
    }

    public function testArgon2idDifferentSaltProducesDifferentOutput(): void
    {
        $a = ipam_argon2id_derive('pw', str_repeat("\x02", 16), 2, 8192, 1, 32);
        $b = ipam_argon2id_derive('pw', str_repeat("\x03", 16), 2, 8192, 1, 32);
        $this->assertNotSame($a, $b);
    }

    public function testArgon2idDifferentPassphraseProducesDifferentOutput(): void
    {
        $salt = str_repeat("\x02", 16);
        $a = ipam_argon2id_derive('alpha', $salt, 2, 8192, 1, 32);
        $b = ipam_argon2id_derive('beta',  $salt, 2, 8192, 1, 32);
        $this->assertNotSame($a, $b);
    }

    /**
     * Independent-implementation check: our wrapper must produce the same
     * output as a direct sodium_crypto_pwhash() call with equivalent args.
     * Catches wrapper bugs (memory unit confusion, algo constant drift) that
     * a round-trip-only test would miss.
     */
    public function testArgon2idMatchesDirectSodiumCall(): void
    {
        $salt   = str_repeat("\x02", 16);
        $time   = 2;
        $memKib = 8192;
        $outLen = 32;

        $viaWrapper = ipam_argon2id_derive('passphrase', $salt, $time, $memKib, 1, $outLen);
        $viaSodium  = sodium_crypto_pwhash(
            $outLen,
            'passphrase',
            $salt,
            $time,
            $memKib * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $this->assertSame(bin2hex($viaSodium), bin2hex($viaWrapper));
    }

    public function testArgon2idRejectsEmptyPassphrase(): void
    {
        $this->expectException(RuntimeException::class);
        ipam_argon2id_derive('', str_repeat("\x02", 16), 2, 8192, 1, 32);
    }

    public function testArgon2idRejectsShortSalt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/salt must be exactly/');
        ipam_argon2id_derive('pw', str_repeat("\x02", 8), 2, 8192, 1, 32);
    }

    public function testArgon2idRejectsLongSalt(): void
    {
        $this->expectException(RuntimeException::class);
        ipam_argon2id_derive('pw', str_repeat("\x02", 32), 2, 8192, 1, 32);
    }

    public function testArgon2idRejectsTimeBelowOne(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/time must be >= 1/');
        ipam_argon2id_derive('pw', str_repeat("\x02", 16), 0, 8192, 1, 32);
    }

    public function testArgon2idRejectsParallelismOtherThanOne(): void
    {
        // libsodium's pwhash API does not expose Argon2's parallelism
        // parameter — fixed at 1. Our wrapper enforces this so the
        // header-recorded value cannot drift from the value used to compute
        // the tag. Loosen this constraint only when sodium exposes the param.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/parallelism must be 1/');
        ipam_argon2id_derive('pw', str_repeat("\x02", 16), 2, 8192, 4, 32);
    }

    public function testArgon2idRejectsTinyMemory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/memoryKib must be >= 8/');
        ipam_argon2id_derive('pw', str_repeat("\x02", 16), 2, 4, 1, 32);
    }

    public function testArgon2idRejectsShortOutput(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/outLen must be >= 16/');
        ipam_argon2id_derive('pw', str_repeat("\x02", 16), 2, 8192, 1, 8);
    }

    public function testArgon2idHonoursOutputLength(): void
    {
        $salt = str_repeat("\x02", 16);
        $this->assertSame(16, strlen(ipam_argon2id_derive('pw', $salt, 2, 8192, 1, 16)));
        $this->assertSame(64, strlen(ipam_argon2id_derive('pw', $salt, 2, 8192, 1, 64)));
    }

    // -----------------------------------------------------------------------
    // Constant sanity — header layout is load-bearing for IPAMBKP3 dispatch
    // -----------------------------------------------------------------------

    public function testV3Constants(): void
    {
        $this->assertSame('IPAMBKP3', BACKUP_MAGIC_V3);
        $this->assertSame('IPAMBKU1', BACKUP_MAGIC_UNENC);
        $this->assertSame(8, strlen(BACKUP_MAGIC_V3));
        $this->assertSame(8, strlen(BACKUP_MAGIC_UNENC));
        $this->assertSame(1, BACKUP_V3_MODE_STORED);
        $this->assertSame(2, BACKUP_V3_MODE_TRANSITORY);
        $this->assertSame(16, BACKUP_ARGON2_SALT_LEN);
        $this->assertSame(32, BACKUP_VAULT_KEY_LEN);
        // Header layout is load-bearing — if any field width changes, the
        // codec must be updated in lockstep. Documented total: 68 bytes.
        $expected = strlen(BACKUP_MAGIC_V3)        // magic
                  + 1                              // mode
                  + 4 + 4 + 1                      // argon t/m/p
                  + BACKUP_V3_RESERVED_LEN         // reserved
                  + BACKUP_ARGON2_SALT_LEN         // argon salt
                  + BACKUP_SALT_LEN                // hkdf salt (16, reused from v2)
                  + BACKUP_CTR_IV_LEN;             // CTR IV (16)
        $this->assertSame(BACKUP_V3_HEADER_LEN, $expected);
    }
}
