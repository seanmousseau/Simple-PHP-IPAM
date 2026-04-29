<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use PHPUnit\Framework\TestCase;

final class BackupEncryptionTest extends TestCase
{
    private const SECRET = 'test-secret-do-not-use-in-prod-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    // ── Round-trip tests ────────────────────────────────────────────────────

    public function testRoundTripAscii(): void
    {
        $plain  = 'Hello, IPAM backup! Special chars: <>&"\'' . str_repeat('A', 1000);
        $cipher = backup_encrypt($plain, self::SECRET);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, backup_decrypt($cipher, self::SECRET));
    }

    public function testRoundTrip1Mb(): void
    {
        // Full byte range including nulls and high bytes to mirror real SQLite/backup payloads.
        $plain  = random_bytes(1024 * 1024);
        $cipher = backup_encrypt($plain, self::SECRET);
        $this->assertSame($plain, backup_decrypt($cipher, self::SECRET));
    }

    public function testRoundTripBinary(): void
    {
        // Mimics SQLite WAL bytes: random + null runs + random
        $plain  = random_bytes(64 * 1024) . str_repeat("\x00", 8) . random_bytes(64 * 1024);
        $cipher = backup_encrypt($plain, self::SECRET);
        $this->assertNotSame($plain, $cipher);
        $this->assertSame($plain, backup_decrypt($cipher, self::SECRET));
    }

    // ── Key derivation stability ─────────────────────────────────────────────

    public function testHkdfKeyStableForSameSecretAndPurpose(): void
    {
        $plain   = 'stable key test payload';
        $cipher1 = backup_encrypt($plain, self::SECRET);
        $cipher2 = backup_encrypt($plain, self::SECRET);
        // Both must decrypt successfully (same key both times)
        $this->assertSame($plain, backup_decrypt($cipher1, self::SECRET));
        $this->assertSame($plain, backup_decrypt($cipher2, self::SECRET));
        // IVs are random so ciphertexts differ
        $this->assertNotSame($cipher1, $cipher2);
    }

    public function testDifferentSecretsProduceDifferentKeys(): void
    {
        $plain   = 'cross-secret test';
        $cipher  = backup_encrypt($plain, self::SECRET);
        $this->expectException(RuntimeException::class);
        backup_decrypt($cipher, self::SECRET . '-other');
    }

    // ── Tamper detection ────────────────────────────────────────────────────

    public function testBitFlipInCiphertextBodyThrows(): void
    {
        $cipher = backup_encrypt('tamper body test', self::SECRET);
        // Flip a byte well inside the ciphertext body (past magic+IV+tag)
        $flipOffset = strlen(BACKUP_MAGIC) + BACKUP_IV_LEN + BACKUP_TAG_LEN + 1;
        $tampered   = substr_replace($cipher, chr(ord($cipher[$flipOffset]) ^ 0xFF), $flipOffset, 1);
        $this->expectException(RuntimeException::class);
        backup_decrypt($tampered, self::SECRET);
    }

    public function testBitFlipInTagThrows(): void
    {
        $cipher = backup_encrypt('tamper tag test', self::SECRET);
        // Flip a byte inside the GCM tag
        $tagOffset = strlen(BACKUP_MAGIC) + BACKUP_IV_LEN;
        $tampered  = substr_replace($cipher, chr(ord($cipher[$tagOffset]) ^ 0x01), $tagOffset, 1);
        $this->expectException(RuntimeException::class);
        backup_decrypt($tampered, self::SECRET);
    }

    public function testTruncationThrows(): void
    {
        $cipher   = backup_encrypt('truncation test', self::SECRET);
        $truncated = substr($cipher, 0, (int)(strlen($cipher) / 2));
        $this->expectException(RuntimeException::class);
        backup_decrypt($truncated, self::SECRET);
    }

    public function testIvStripThrows(): void
    {
        $cipher  = backup_encrypt('iv strip test', self::SECRET);
        // Strip the IV bytes — forces wrong IV into openssl_decrypt → auth-tag failure (not magic or length).
        $stripped = BACKUP_MAGIC . substr($cipher, strlen(BACKUP_MAGIC) + BACKUP_IV_LEN);
        $this->expectException(RuntimeException::class);
        backup_decrypt($stripped, self::SECRET);
    }

    // ── Magic header ────────────────────────────────────────────────────────

    public function testMagicHeaderPresent(): void
    {
        $cipher = backup_encrypt('magic test', self::SECRET);
        $this->assertStringStartsWith(BACKUP_MAGIC, $cipher);
    }

    public function testWrongMagicThrows(): void
    {
        $cipher   = backup_encrypt('wrong magic', self::SECRET);
        $bad      = 'BADMAGIC' . substr($cipher, strlen(BACKUP_MAGIC));
        $this->expectException(RuntimeException::class);
        backup_decrypt($bad, self::SECRET);
    }

    // ── Plain-text input guard ───────────────────────────────────────────────

    public function testPlainTextInputToDecryptThrows(): void
    {
        // A plain SQLite dump would never start with "IPAMBKP1"; passing it
        // to backup_decrypt() must throw a RuntimeException, not silently
        // return garbage or a PHP notice.
        $plainSqliteDump = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n-- data\nCOMMIT;\n";
        $this->expectException(RuntimeException::class);
        backup_decrypt($plainSqliteDump, self::SECRET);
    }
}
