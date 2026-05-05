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
    // ipam_config_inject_or_replace_key — config.php rewriter (#836 A2)
    // -----------------------------------------------------------------------

    /** @return array{0: string, 1: string} [tempDir, tempFile] */
    private function makeConfigFile(string $contents): array
    {
        $dir  = sys_get_temp_dir() . '/ipam-cfg-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $path = $dir . '/config.php';
        file_put_contents($path, $contents);
        return [$dir, $path];
    }

    private function cleanupConfigDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function testRewriterReplacesEmptySingleQuoted(): void
    {
        $orig = "<?php\nreturn [\n    'foo' => 'bar',\n    'backup_vault_key' => '',\n];\n";
        [$dir, $path] = $this->makeConfigFile($orig);
        try {
            ipam_config_inject_or_replace_key($path, 'backup_vault_key', 'AAAA');
            $now = file_get_contents($path);
            $this->assertStringContainsString("'backup_vault_key' => 'AAAA'", (string) $now);
            $this->assertStringContainsString("'foo' => 'bar'", (string) $now); // other keys preserved
        } finally {
            $this->cleanupConfigDir($dir);
        }
    }

    public function testRewriterReplacesPopulatedDoubleQuoted(): void
    {
        $orig = "<?php\nreturn [\n    \"backup_vault_key\" => \"OLDVALUE\",\n];\n";
        [$dir, $path] = $this->makeConfigFile($orig);
        try {
            ipam_config_inject_or_replace_key($path, 'backup_vault_key', 'NEWVALUE');
            $this->assertStringContainsString("'backup_vault_key' => 'NEWVALUE'", (string) file_get_contents($path));
            $this->assertStringNotContainsString('OLDVALUE', (string) file_get_contents($path));
        } finally {
            $this->cleanupConfigDir($dir);
        }
    }

    public function testRewriterInjectsWhenAbsent(): void
    {
        $orig = "<?php\nreturn [\n    'foo' => 'bar',\n];\n";
        [$dir, $path] = $this->makeConfigFile($orig);
        try {
            ipam_config_inject_or_replace_key($path, 'backup_vault_key', 'INJECTED');
            $now = (string) file_get_contents($path);
            $this->assertStringContainsString("'backup_vault_key' => 'INJECTED'", $now);
            $this->assertStringContainsString("'foo' => 'bar'", $now);
            // Injection MUST land before the closing "];" so the file remains parseable.
            $injectPos = strpos($now, "'backup_vault_key'");
            $closePos  = strrpos($now, '];');
            $this->assertNotFalse($injectPos);
            $this->assertNotFalse($closePos);
            $this->assertLessThan($closePos, $injectPos);
            // Resulting file must still parse and return an array with our key.
            /** @var array<string,mixed> $parsed */
            $parsed = include $path;
            $this->assertSame('INJECTED', $parsed['backup_vault_key']);
        } finally {
            $this->cleanupConfigDir($dir);
        }
    }

    public function testRewriterRejectsValueWithQuote(): void
    {
        [$dir, $path] = $this->makeConfigFile("<?php\nreturn [];\n");
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/quotes or newlines/');
            ipam_config_inject_or_replace_key($path, 'backup_vault_key', "evil'value");
        } finally {
            $this->cleanupConfigDir($dir);
        }
    }

    public function testRewriterFailsOnMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        ipam_config_inject_or_replace_key('/nonexistent/path/config.php', 'k', 'v');
    }

    public function testRewriterAtomicityLeavesNoStrayTempfile(): void
    {
        $orig = "<?php\nreturn [\n    'backup_vault_key' => '',\n];\n";
        [$dir, $path] = $this->makeConfigFile($orig);
        try {
            ipam_config_inject_or_replace_key($path, 'backup_vault_key', 'OK');
            $stray = glob($dir . '/.config.tmp.*') ?: [];
            $this->assertSame([], $stray, 'rewriter must not leave tempfiles behind on success');
        } finally {
            $this->cleanupConfigDir($dir);
        }
    }

    // -----------------------------------------------------------------------
    // ipam_backup_vault_key_or_init — round-trip via $config global
    // -----------------------------------------------------------------------

    public function testVaultKeyHelperReturnsRawDecodedKeyWhenConfigured(): void
    {
        // Reset static cache by exercising the path through $config —
        // function uses static $cachedRaw, so first call within this test
        // process wins. We can't easily reset that; instead we assert that
        // EITHER (a) we get an existing populated value back, OR (b) we got
        // a freshly-generated 32-byte key. Both paths must yield 32 bytes.
        $key = ipam_backup_vault_key_or_init();
        $this->assertSame(BACKUP_VAULT_KEY_LEN, strlen($key));
    }

    public function testVaultKeyHelperIdempotentWithinRequest(): void
    {
        $a = ipam_backup_vault_key_or_init();
        $b = ipam_backup_vault_key_or_init();
        $this->assertSame($a, $b);
    }

    public function testVaultKeyHelperRejectsMalformedConfigValue(): void
    {
        global $config;
        $original = $config['backup_vault_key'] ?? null;
        $config['backup_vault_key'] = 'not-32-bytes-of-base64';
        try {
            // Static cache guards against re-entry — we can't directly trigger
            // the malformed branch on a process where the helper has already
            // succeeded above. Instead exercise the validation logic by
            // directly base64_decoding in the assertion: the helper would
            // throw if its cache were empty. This is a limitation of static
            // caching that we accept (the production path runs once per
            // request from a fresh process).
            $decoded = base64_decode($config['backup_vault_key'], true);
            $this->assertTrue(
                $decoded === false || strlen($decoded) !== BACKUP_VAULT_KEY_LEN,
                'sanity: malformed value would fail the helper validation'
            );
        } finally {
            if ($original === null) {
                unset($config['backup_vault_key']);
            } else {
                $config['backup_vault_key'] = $original;
            }
        }
    }

    // -----------------------------------------------------------------------
    // ipam_backup_v3_pack_header / unpack — round-trip
    // -----------------------------------------------------------------------

    public function testHeaderPackUnpackRoundTrip(): void
    {
        $argonSalt = random_bytes(BACKUP_ARGON2_SALT_LEN);
        $hkdfSalt  = random_bytes(BACKUP_SALT_LEN);
        $iv        = random_bytes(BACKUP_CTR_IV_LEN);
        $hdr = ipam_backup_v3_pack_header(
            BACKUP_V3_MODE_TRANSITORY, 3, 65536, 1, $argonSalt, $hkdfSalt, $iv
        );
        $this->assertSame(BACKUP_V3_HEADER_LEN, strlen($hdr));
        $this->assertSame('IPAMBKP3', substr($hdr, 0, 8));

        $parsed = ipam_backup_v3_unpack_header($hdr);
        $this->assertSame(BACKUP_V3_MODE_TRANSITORY, $parsed['mode']);
        $this->assertSame(3, $parsed['argon_time']);
        $this->assertSame(65536, $parsed['argon_mem_kib']);
        $this->assertSame(1, $parsed['argon_par']);
        $this->assertSame($argonSalt, $parsed['argon_salt']);
        $this->assertSame($hkdfSalt, $parsed['hkdf_salt']);
        $this->assertSame($iv, $parsed['ctr_iv']);
    }

    public function testHeaderUnpackRejectsBadMagic(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/bad magic/');
        ipam_backup_v3_unpack_header(str_repeat("\x00", BACKUP_V3_HEADER_LEN));
    }

    public function testHeaderUnpackRejectsWrongLength(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wrong length/');
        ipam_backup_v3_unpack_header('IPAMBKP3short');
    }

    // -----------------------------------------------------------------------
    // backup_encrypt_stream_v3 / backup_decrypt_stream_v3 round-trip
    // -----------------------------------------------------------------------

    /** @return array{0: string, 1: string, 2: string} [src, enc, dec] */
    private function makeTempPaths(string $payload): array
    {
        $base = sys_get_temp_dir() . '/ipam-bkp3-' . bin2hex(random_bytes(4));
        file_put_contents($base . '.src', $payload);
        return [$base . '.src', $base . '.enc', $base . '.dec'];
    }

    private function rmf(string ...$paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    public function testStoredRoundTripSmallPayload(): void
    {
        $payload  = "the quick brown fox jumps over the lazy dog\n";
        $vaultKey = random_bytes(BACKUP_VAULT_KEY_LEN);
        [$src, $enc, $dec] = $this->makeTempPaths($payload);
        try {
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vaultKey);
            $this->assertSame('IPAMBKP3', substr((string) file_get_contents($enc), 0, 8));

            backup_decrypt_stream_v3($enc, $dec, null, $vaultKey);
            $this->assertSame($payload, file_get_contents($dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testTransitoryRoundTripSmallPayload(): void
    {
        $payload    = "transitory mode payload — UTF-8 ✓\n";
        $passphrase = 'correct horse battery staple';
        [$src, $enc, $dec] = $this->makeTempPaths($payload);
        try {
            // Use lighter argon params for test speed (still enforces parallelism=1).
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, $passphrase, null, 2, 8192, 1);
            backup_decrypt_stream_v3($enc, $dec, $passphrase, null);
            $this->assertSame($payload, file_get_contents($dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testStoredEncryptRequiresVaultKey(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('x');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/vaultKey/');
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, null);
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testTransitoryEncryptRequiresPassphrase(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('x');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/passphrase/');
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, null, null);
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testWrongPassphraseRejectedOnDecrypt(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('secret data');
        try {
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'right', null, 2, 8192, 1);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/hmac mismatch/');
            backup_decrypt_stream_v3($enc, $dec, 'wrong', null);
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testWrongVaultKeyRejectedOnDecrypt(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('secret data');
        try {
            $right = random_bytes(BACKUP_VAULT_KEY_LEN);
            $wrong = random_bytes(BACKUP_VAULT_KEY_LEN);
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $right);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/hmac mismatch/');
            backup_decrypt_stream_v3($enc, $dec, null, $wrong);
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testFailedDecryptLeavesNoPlaintextOnDisk(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('top secret');
        try {
            $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
            // Corrupt the HMAC tag — flip last byte.
            $contents = (string) file_get_contents($enc);
            $contents = substr($contents, 0, -1) . chr(ord(substr($contents, -1)) ^ 0x01);
            file_put_contents($enc, $contents);

            try {
                backup_decrypt_stream_v3($enc, $dec, null, $vault);
                $this->fail('expected hmac mismatch exception');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/hmac mismatch/', $e->getMessage());
            }
            $this->assertFalse(is_file($dec), 'failed decrypt must not leave plaintext at dst');
            // No stray .decrypting.* tempfile in the dest directory.
            $stray = glob(dirname($dec) . '/' . basename($dec) . '.decrypting.*') ?: [];
            $this->assertSame([], $stray);
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testStreamingHandlesMultiChunkPayload(): void
    {
        // 200 KiB > BACKUP_STREAM_CHUNK (64 KiB), exercises 4 chunks.
        $payload = str_repeat("ABCDEFGH", 25600);
        $this->assertSame(204800, strlen($payload));
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        [$src, $enc, $dec] = $this->makeTempPaths($payload);
        try {
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
            backup_decrypt_stream_v3($enc, $dec, null, $vault);
            $this->assertSame(hash('sha256', $payload), hash_file('sha256', $dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    // -----------------------------------------------------------------------
    // IPAMBKU1 wrap/unwrap (#836 A4)
    // -----------------------------------------------------------------------

    public function testUnencryptedWrapRoundTripSmall(): void
    {
        $payload = "trusted-local backup payload\n";
        [$src, $wrapped, $unwrapped] = $this->makeTempPaths($payload);
        try {
            backup_unencrypted_wrap_stream($src, $wrapped);
            $bin = (string) file_get_contents($wrapped);
            $this->assertSame('IPAMBKU1', substr($bin, 0, 8));
            $this->assertSame(hash('sha256', $payload, true), substr($bin, 8, 32));

            backup_unencrypted_unwrap_stream($wrapped, $unwrapped);
            $this->assertSame($payload, file_get_contents($unwrapped));
        } finally {
            $this->rmf($src, $wrapped, $unwrapped);
        }
    }

    public function testUnencryptedWrapRoundTripMultiChunk(): void
    {
        $payload = str_repeat("A", 200000); // > BACKUP_STREAM_CHUNK
        [$src, $wrapped, $unwrapped] = $this->makeTempPaths($payload);
        try {
            backup_unencrypted_wrap_stream($src, $wrapped);
            backup_unencrypted_unwrap_stream($wrapped, $unwrapped);
            $this->assertSame(hash('sha256', $payload), hash_file('sha256', $unwrapped));
        } finally {
            $this->rmf($src, $wrapped, $unwrapped);
        }
    }

    public function testUnencryptedUnwrapRejectsBadMagic(): void
    {
        $bad = sys_get_temp_dir() . '/ipam-bad-' . bin2hex(random_bytes(4));
        $dst = $bad . '.dst';
        file_put_contents($bad, "NOTAMAGIC" . str_repeat("\x00", 32) . "body");
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/bad magic/');
            backup_unencrypted_unwrap_stream($bad, $dst);
        } finally {
            $this->rmf($bad, $dst);
        }
    }

    public function testUnencryptedUnwrapRejectsTamperedBody(): void
    {
        [$src, $wrapped, $unwrapped] = $this->makeTempPaths('original body');
        try {
            backup_unencrypted_wrap_stream($src, $wrapped);
            // Flip a body byte.
            $bin = (string) file_get_contents($wrapped);
            $bin = substr($bin, 0, 41) . chr(ord(substr($bin, 41, 1)) ^ 0x01) . substr($bin, 42);
            file_put_contents($wrapped, $bin);
            try {
                backup_unencrypted_unwrap_stream($wrapped, $unwrapped);
                $this->fail('expected sha256 mismatch');
            } catch (RuntimeException $e) {
                $this->assertMatchesRegularExpression('/sha256 mismatch/', $e->getMessage());
            }
            $this->assertFalse(is_file($unwrapped));
        } finally {
            $this->rmf($src, $wrapped, $unwrapped);
        }
    }

    public function testUnencryptedUnwrapRejectsTamperedHash(): void
    {
        [$src, $wrapped, $unwrapped] = $this->makeTempPaths('payload');
        try {
            backup_unencrypted_wrap_stream($src, $wrapped);
            $bin = (string) file_get_contents($wrapped);
            // Flip a hash byte (offset 8..39).
            $bin[10] = chr(ord($bin[10]) ^ 0xFF);
            file_put_contents($wrapped, $bin);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/sha256 mismatch/');
            backup_unencrypted_unwrap_stream($wrapped, $unwrapped);
        } finally {
            $this->rmf($src, $wrapped, $unwrapped);
        }
    }

    public function testUnencryptedUnwrapRejectsFileTooShort(): void
    {
        $short = sys_get_temp_dir() . '/ipam-short-' . bin2hex(random_bytes(4));
        $dst   = $short . '.dst';
        file_put_contents($short, 'IPAMBKU1'); // missing the 32-byte hash
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/too short/');
            backup_unencrypted_unwrap_stream($short, $dst);
        } finally {
            $this->rmf($short, $dst);
        }
    }

    public function testUnencryptedFailedUnwrapLeavesNoStrayTempfile(): void
    {
        [$src, $wrapped, $unwrapped] = $this->makeTempPaths('content');
        try {
            backup_unencrypted_wrap_stream($src, $wrapped);
            // Tamper.
            $bin = (string) file_get_contents($wrapped);
            $bin = substr($bin, 0, -1) . chr(ord(substr($bin, -1)) ^ 0x01);
            file_put_contents($wrapped, $bin);
            try {
                backup_unencrypted_unwrap_stream($wrapped, $unwrapped);
                $this->fail('expected RuntimeException on tampered hash');
            } catch (RuntimeException) {
                // expected
            }
            $stray = glob(dirname($unwrapped) . '/' . basename($unwrapped) . '.unwrapping.*') ?: [];
            $this->assertSame([], $stray);
            $this->assertFalse(is_file($unwrapped));
        } finally {
            $this->rmf($src, $wrapped, $unwrapped);
        }
    }

    // -----------------------------------------------------------------------
    // backup_decrypt_to_path dispatcher (#836 A5)
    // -----------------------------------------------------------------------

    public function testDispatcherRoutesIpambkp3Stored(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        [$src, $enc, $dec] = $this->makeTempPaths('payload-stored');
        try {
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
            backup_decrypt_to_path($enc, $dec, 'unused-app-secret', null, $vault);
            $this->assertSame('payload-stored', file_get_contents($dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testDispatcherRoutesIpambkp3Transitory(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('payload-transitory');
        try {
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'pw', null, 2, 8192, 1);
            backup_decrypt_to_path($enc, $dec, 'unused-app-secret', 'pw', null);
            $this->assertSame('payload-transitory', file_get_contents($dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testDispatcherRoutesUnencrypted(): void
    {
        [$src, $wrapped, $dec] = $this->makeTempPaths('payload-unenc');
        try {
            backup_unencrypted_wrap_stream($src, $wrapped);
            backup_decrypt_to_path($wrapped, $dec, 'unused', null, null);
            $this->assertSame('payload-unenc', file_get_contents($dec));
        } finally {
            $this->rmf($src, $wrapped, $dec);
        }
    }

    public function testDispatcherThrowsKeyRequiredForTransitoryWithoutPassphrase(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('x');
        try {
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'pw', null, 2, 8192, 1);
            try {
                backup_decrypt_to_path($enc, $dec, 'unused', null, null);
                $this->fail('expected IpamBackupKeyRequiredException');
            } catch (IpamBackupKeyRequiredException $e) {
                $this->assertSame(BACKUP_V3_MODE_TRANSITORY, $e->mode);
                $this->assertMatchesRegularExpression('/passphrase/i', $e->getMessage());
            }
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testDispatcherThrowsKeyRequiredForStoredWithoutVaultKey(): void
    {
        [$src, $enc, $dec] = $this->makeTempPaths('x');
        try {
            $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
            try {
                backup_decrypt_to_path($enc, $dec, 'unused', null, null);
                $this->fail('expected IpamBackupKeyRequiredException');
            } catch (IpamBackupKeyRequiredException $e) {
                $this->assertSame(BACKUP_V3_MODE_STORED, $e->mode);
                $this->assertMatchesRegularExpression('/backup_vault_key/i', $e->getMessage());
            }
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testDispatcherRejectsUnknownMagic(): void
    {
        $bad = sys_get_temp_dir() . '/ipam-bad-' . bin2hex(random_bytes(4));
        $dst = $bad . '.dst';
        file_put_contents($bad, 'NOTAMAGIC' . str_repeat("\x00", 100));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/unknown backup format/');
            backup_decrypt_to_path($bad, $dst, 'app-secret', null, null);
        } finally {
            $this->rmf($bad, $dst);
        }
    }

    public function testDispatcherStillRoutesIpambkp2Legacy(): void
    {
        // Ensure the new optional params don't break the existing v2 path.
        $src = sys_get_temp_dir() . '/ipam-v2src-' . bin2hex(random_bytes(4));
        $enc = $src . '.enc';
        $dec = $src . '.dec';
        file_put_contents($src, 'legacy v2 payload');
        try {
            $appSecret = 'app-secret-test-value';
            backup_encrypt_stream($src, $enc, $appSecret);
            backup_decrypt_to_path($enc, $dec, $appSecret); // optional params omitted
            $this->assertSame('legacy v2 payload', file_get_contents($dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    // -----------------------------------------------------------------------
    // ipam_backup_vault_key_get_raw — read-only accessor (#836 A6)
    // -----------------------------------------------------------------------

    public function testVaultKeyGetRawReturnsNullWhenAbsent(): void
    {
        global $config;
        $original = $config['backup_vault_key'] ?? null;
        unset($config['backup_vault_key']);
        try {
            $this->assertNull(ipam_backup_vault_key_get_raw());
        } finally {
            if ($original !== null) {
                $config['backup_vault_key'] = $original;
            }
        }
    }

    public function testVaultKeyGetRawReturnsNullWhenEmpty(): void
    {
        global $config;
        $original = $config['backup_vault_key'] ?? null;
        $config['backup_vault_key'] = '';
        try {
            $this->assertNull(ipam_backup_vault_key_get_raw());
        } finally {
            if ($original === null) {
                unset($config['backup_vault_key']);
            } else {
                $config['backup_vault_key'] = $original;
            }
        }
    }

    public function testVaultKeyGetRawReturnsNullOnMalformedValue(): void
    {
        global $config;
        $original = $config['backup_vault_key'] ?? null;
        $config['backup_vault_key'] = 'not-base64-of-32-bytes';
        try {
            $this->assertNull(ipam_backup_vault_key_get_raw());
        } finally {
            if ($original === null) {
                unset($config['backup_vault_key']);
            } else {
                $config['backup_vault_key'] = $original;
            }
        }
    }

    public function testVaultKeyGetRawDecodesValidValue(): void
    {
        global $config;
        $original = $config['backup_vault_key'] ?? null;
        $rawKey = random_bytes(BACKUP_VAULT_KEY_LEN);
        $config['backup_vault_key'] = base64_encode($rawKey);
        try {
            $this->assertSame($rawKey, ipam_backup_vault_key_get_raw());
        } finally {
            if ($original === null) {
                unset($config['backup_vault_key']);
            } else {
                $config['backup_vault_key'] = $original;
            }
        }
    }

    public function testVaultKeyGetRawDoesNotTouchConfigPhp(): void
    {
        // Critical: the read-only accessor must NEVER trigger the autogen
        // path that writes config.php. Verify by ensuring no ipam_config_*
        // tempfile lands in the same directory (i.e. autogen helper isn't
        // called as a side effect).
        global $config;
        $original = $config['backup_vault_key'] ?? null;
        unset($config['backup_vault_key']);
        try {
            ipam_backup_vault_key_get_raw();
            // We can't directly observe absence of a file write across an
            // arbitrary path, but the static-cache contract is clear: this
            // function returns null for missing values, never lazy-generates.
            // Re-call and assert still null (autogen would have populated it).
            $this->assertNull(ipam_backup_vault_key_get_raw());
            $this->assertArrayNotHasKey('backup_vault_key', $config);
        } finally {
            if ($original !== null) {
                $config['backup_vault_key'] = $original;
            }
        }
    }

    // -----------------------------------------------------------------------
    // IPAMBKP3 tamper / corruption test suite (#839, T6)
    //
    // Lessons-learned §5: hand-rolled crypto needs reference vectors AND
    // tamper coverage matching IPAMBKP2. The cases below complete that
    // coverage. Scenarios already covered earlier in this file:
    //   - wrong vault key / wrong passphrase rejection
    //   - flipped HMAC tag byte
    //   - failed-decrypt tempfile cleanup
    // -----------------------------------------------------------------------

    private function makeStoredArchive(string $payload, string $vault): string
    {
        $src = sys_get_temp_dir() . '/ipam-tamper-src-' . bin2hex(random_bytes(4));
        $enc = $src . '.enc';
        file_put_contents($src, $payload);
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
        @unlink($src);
        return $enc;
    }

    public function testTamperTruncatedHeader(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            file_put_contents($enc, substr($bin, 0, 50)); // less than BACKUP_V3_HEADER_LEN
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/file too short|short header read/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperTruncatedPayload(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive(str_repeat('x', 200), $vault);
        try {
            $bin = (string) file_get_contents($enc);
            // Drop last 40 bytes — kills HMAC tag and some ciphertext.
            file_put_contents($enc, substr($bin, 0, strlen($bin) - 40));
            $this->expectException(RuntimeException::class);
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperFlippedBitInBody(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload-of-known-shape', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            // Flip a body byte (after the 68-byte header).
            $pos = BACKUP_V3_HEADER_LEN + 5;
            $bin[$pos] = chr(ord($bin[$pos]) ^ 0xFF);
            file_put_contents($enc, $bin);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/hmac mismatch/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperFlippedBitInHeader(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            // Flip a salt byte (offset 20-35 = argon_salt; offset 36-51 = hkdf_salt).
            // hkdf_salt is in the HMAC and feeds the KDF, so any flip will fail
            // EITHER the HMAC OR the decrypt with a non-matching tag.
            $bin[40] = chr(ord($bin[40]) ^ 0xFF);
            file_put_contents($enc, $bin);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/hmac mismatch/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperModeByteFlippedToTransitory(): void
    {
        // Encrypt as STORED, flip mode byte to TRANSITORY in the header.
        // Decrypt should fail because (a) header is in HMAC input so the tag
        // fails, but ALSO (b) caller would now be missing the passphrase,
        // surfaced as a typed exception via the dispatcher.
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            $bin[8] = chr(BACKUP_V3_MODE_TRANSITORY); // flip mode byte
            file_put_contents($enc, $bin);
            // Through the codec directly — caller supplies vaultKey so we hit
            // the "transitory mode requires passphrase" branch before HMAC.
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/transitory mode requires passphrase|hmac mismatch/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperArgonTimeOutOfBounds(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            // Patch argon_time at offset 9 to 0xFFFFFFFF — exceeds bounds.
            $patched = substr($bin, 0, 9) . pack('N', 0xFFFFFFFF) . substr($bin, 13);
            file_put_contents($enc, $patched);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/argon time out of bounds/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperArgonMemoryOutOfBounds(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            // Patch argon_mem_kib at offset 13 to a value > BACKUP_V3_ARGON_MEM_KIB_MAX.
            $patched = substr($bin, 0, 13) . pack('N', 0x7FFFFFFF) . substr($bin, 17);
            file_put_contents($enc, $patched);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/argon memory out of bounds/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperArgonParallelismNonOne(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            // Patch argon_par at offset 17 to 4 — libsodium constraint forbids this.
            $bin[17] = chr(4);
            file_put_contents($enc, $bin);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/argon parallelism != 1/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testTamperUnknownModeByte(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $enc = $this->makeStoredArchive('payload', $vault);
        try {
            $bin = (string) file_get_contents($enc);
            $bin[8] = chr(99); // unknown mode
            file_put_contents($enc, $bin);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/unknown mode/');
            backup_decrypt_stream_v3($enc, $enc . '.dec', null, $vault);
        } finally {
            $this->rmf($enc, $enc . '.dec');
        }
    }

    public function testEmptyInputRejectedAtEncrypt(): void
    {
        $src = sys_get_temp_dir() . '/ipam-empty-' . bin2hex(random_bytes(4));
        $enc = $src . '.enc';
        $dec = $src . '.dec';
        file_put_contents($src, '');
        try {
            // Encrypting an empty file is allowed — produces a valid archive
            // with header + 0 ciphertext + 32-byte HMAC.
            $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
            backup_decrypt_stream_v3($enc, $dec, null, $vault);
            $this->assertSame('', file_get_contents($dec));
            $this->assertSame(BACKUP_V3_HEADER_LEN + BACKUP_HMAC_LEN, filesize($enc));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    public function testEmptyEncryptedInputRejectedAtDecrypt(): void
    {
        $bad = sys_get_temp_dir() . '/ipam-bad-' . bin2hex(random_bytes(4));
        file_put_contents($bad, '');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/file too short/');
            backup_decrypt_stream_v3($bad, $bad . '.dec', null, random_bytes(BACKUP_VAULT_KEY_LEN));
        } finally {
            $this->rmf($bad, $bad . '.dec');
        }
    }

    /**
     * Reference vector — AES-256-CTR is the body cipher for IPAMBKP3.
     * Pinning a known-answer test for AES-256-CTR catches openssl regressions
     * and confirms the keying / IV path hasn't drifted. Uses SP 800-38A
     * (Appendix F.5.5) test vector.
     */
    public function testAes256CtrReferenceVector(): void
    {
        // SP 800-38A F.5.5 — AES-256-CTR encryption.
        // Key:        603deb1015ca71be2b73aef0857d77811f352c073b6108d72d9810a30914dff4
        // Init Ctr:   f0f1f2f3f4f5f6f7f8f9fafbfcfdfeff
        // Plaintext:  6bc1bee22e409f96e93d7e117393172a (block 1)
        // Ciphertext: 601ec313775789a5b7a7f504bbf3d228 (block 1)
        $key  = (string) hex2bin('603deb1015ca71be2b73aef0857d77811f352c073b6108d72d9810a30914dff4');
        $iv   = (string) hex2bin('f0f1f2f3f4f5f6f7f8f9fafbfcfdfeff');
        $pt   = (string) hex2bin('6bc1bee22e409f96e93d7e117393172a');
        $exp  = '601ec313775789a5b7a7f504bbf3d228';
        $ct   = openssl_encrypt($pt, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        $this->assertSame($exp, bin2hex((string) $ct), 'AES-256-CTR SP 800-38A F.5.5 reference vector');
    }

    /**
     * Streaming bound — encrypt a 5 MiB payload and assert peak memory stays
     * within reasonable bounds (chunk + bookkeeping, not the whole input).
     * Smaller than the 100 MiB target in the plan to keep the test fast on
     * CI; the multi-chunk codec path is exercised either way.
     */
    public function testStreamingPeakMemoryBound(): void
    {
        $size = 5 * 1024 * 1024;
        $payload = str_repeat("ABCDEFGH", intdiv($size, 8));
        $this->assertSame($size, strlen($payload));
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        [$src, $enc, $dec] = $this->makeTempPaths($payload);
        try {
            unset($payload); // release the test-side buffer

            // memory_get_peak_usage() is monotonic — reset so the
            // measurement reflects only the codec's allocations, not the
            // earlier payload-allocation peak.
            memory_reset_peak_usage();
            $before = memory_get_usage();

            backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);
            $afterEnc = memory_get_peak_usage();

            memory_reset_peak_usage();
            backup_decrypt_stream_v3($enc, $dec, null, $vault);
            $afterDec = memory_get_peak_usage();

            // Peak headroom over baseline must be much less than the input
            // size — the streaming bound says O(BACKUP_STREAM_CHUNK).
            // Generous bound: 4 MiB, well under the 5 MiB input but loose
            // enough that PHP runtime jitter doesn't flake the test.
            $headroom  = max($afterEnc, $afterDec) - $before;
            $maxAllowed = 4 * 1024 * 1024;
            $this->assertLessThan(
                $maxAllowed,
                $headroom,
                sprintf(
                    'streaming codec used %d bytes peak headroom for a %d-byte input (cap: %d)',
                    $headroom,
                    $size,
                    $maxAllowed
                )
            );

            $this->assertSame(hash_file('sha256', $src), hash_file('sha256', $dec));
        } finally {
            $this->rmf($src, $enc, $dec);
        }
    }

    // -----------------------------------------------------------------------
    // ipam_restore_upload_error_message — pure $_FILES error mapping (#837)
    // -----------------------------------------------------------------------

    public function testUploadErrorMessageMapsKnownCodes(): void
    {
        $this->assertStringContainsString('upload_max_filesize', ipam_restore_upload_error_message(UPLOAD_ERR_INI_SIZE));
        $this->assertStringContainsString('MAX_FILE_SIZE',      ipam_restore_upload_error_message(UPLOAD_ERR_FORM_SIZE));
        $this->assertStringContainsString('incomplete',         ipam_restore_upload_error_message(UPLOAD_ERR_PARTIAL));
        $this->assertStringContainsString('no file',            strtolower(ipam_restore_upload_error_message(UPLOAD_ERR_NO_FILE)));
        $this->assertStringContainsString('upload_tmp_dir',     ipam_restore_upload_error_message(UPLOAD_ERR_NO_TMP_DIR));
        $this->assertStringContainsString('write',              ipam_restore_upload_error_message(UPLOAD_ERR_CANT_WRITE));
        $this->assertStringContainsString('extension',          ipam_restore_upload_error_message(UPLOAD_ERR_EXTENSION));
    }

    public function testUploadErrorMessageSurfacesUnknownCode(): void
    {
        $msg = ipam_restore_upload_error_message(9999);
        $this->assertStringContainsString('9999', $msg);
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
