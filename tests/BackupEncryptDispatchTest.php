<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Encrypt-write-path dispatch (Bug from Pass A 2026-05-08).
 *
 * The backup orchestrator (lib/backup.php:316 ipam_backup_run_for_destination)
 * historically read $config['app_secret'] only and called
 * ipam_backup_encrypt_to_tmp (which produces IPAMBKP2). When app_secret was
 * empty — the documented v3.26.0+ state for any install that follows the
 * vault-relocation guidance — the orchestrator threw and the backup
 * silently failed (compounded by Pass A observability gaps O1–O5).
 *
 * The IPAMBKP3 codec landed in v3.24.0 and the backup_vault_key
 * infrastructure landed in v3.26.0, but the orchestrator's encrypt site
 * was never wired to use either.
 *
 * This test pins the v3.27.1 fix: encrypt dispatch must prefer the vault
 * key (producing IPAMBKP3 stored archives) and fall back to app_secret
 * (legacy IPAMBKP2 — preserves working installs that still set it). With
 * neither, the operator gets an actionable error instead of silent fail.
 *
 * Pass A evidence:
 *   docs/superpowers/plans/2026-05-08-v3.27.1-hotfix.md §6.1
 *   releases/ipam-3.27.1/regression-evidence/passA/PASS-A-SUMMARY.md
 */
final class BackupEncryptDispatchTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        $this->tmpFiles = [];
    }

    /** Stage a tmp .sql.gz with synthetic SQL. Returns the tempnam path. */
    private function stagePlainTmp(string $contents): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rt_ipam_dispatch_src_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $contents);
        $this->tmpFiles[] = $tmp;
        return $tmp;
    }

    private function makeVaultKey(): string
    {
        // 32 bytes of deterministic test material. Not a real key.
        return str_repeat("\x42", 32);
    }

    private function makeAppSecret(): string
    {
        return str_repeat('a', 64); // 32 bytes hex; matches prod app_secret shape
    }

    private function readMagic(string $path): string
    {
        $fh = fopen($path, 'rb');
        $this->assertNotFalse($fh);
        try {
            $bytes = (string) fread($fh, 8);
            return $bytes;
        } finally {
            fclose($fh);
        }
    }

    public function testStoredEncryptionWithVaultKeyProducesIPAMBKP3(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');
        $result = ipam_backup_resolve_encrypt_to_tmp(
            $src,
            'stored',
            $this->makeVaultKey(),
            '',                  // app_secret empty — Sean's prod state
            '.sql.gz'
        );
        $this->tmpFiles[] = $result['tmpFile'];

        $this->assertStringEndsWith('.ipambkp3', $result['extension']);
        $this->assertSame('IPAMBKP3', $this->readMagic($result['tmpFile']));
        $this->assertFileDoesNotExist($src, 'source tmpfile must be consumed (unlinked) by encrypt');
    }

    public function testStoredEncryptionWithoutVaultKeyFallsBackToAppSecretAndProducesIPAMBKP2(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');
        $result = ipam_backup_resolve_encrypt_to_tmp(
            $src,
            'stored',
            null,                 // no vault key
            $this->makeAppSecret(),
            '.sql.gz'
        );
        $this->tmpFiles[] = $result['tmpFile'];

        $this->assertSame('.enc', $result['extension'], 'legacy fallback uses .enc suffix');
        // IPAMBKP2 is the streaming format produced by backup_encrypt_stream;
        // its magic appears at byte 0.
        $this->assertSame('IPAMBKP2', $this->readMagic($result['tmpFile']));
        $this->assertFileDoesNotExist($src, 'source tmpfile must be consumed by encrypt');
    }

    public function testStoredEncryptionWithNeitherKeyThrowsActionableError(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');

        try {
            ipam_backup_resolve_encrypt_to_tmp($src, 'stored', null, '', '.sql.gz');
            $this->fail('Expected RuntimeException — neither vault_key nor app_secret configured');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('vault', strtolower($msg), 'error message should reference the vault key as the recommended fix');
            $this->assertStringContainsString('app_secret', $msg, 'error message should mention app_secret as the legacy alternative');
        }

        // The source tmp file must be cleaned up even on the throw path so
        // the orchestrator doesn't leak partial dumps under sys_get_temp_dir().
        $this->assertFileDoesNotExist($src, 'source tmpfile must be unlinked on error path');
    }

    public function testUnencryptedModePassesSourceFileThrough(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');
        $result = ipam_backup_resolve_encrypt_to_tmp(
            $src,
            'unencrypted',
            $this->makeVaultKey(),  // vault present but mode=unencrypted ignores it
            $this->makeAppSecret(),
            '.sql.gz'
        );

        $this->assertSame('.sql.gz', $result['extension'], 'unencrypted mode preserves dump extension');
        $this->assertSame($src, $result['tmpFile'], 'unencrypted mode passes source path through unchanged');
        $this->assertFileExists($src);
    }

    public function testVaultKeyTakesPrecedenceOverAppSecretWhenBothPresent(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');
        $result = ipam_backup_resolve_encrypt_to_tmp(
            $src,
            'stored',
            $this->makeVaultKey(),
            $this->makeAppSecret(),
            '.sql.gz'
        );
        $this->tmpFiles[] = $result['tmpFile'];

        // When both keys exist, vault key wins → IPAMBKP3, not IPAMBKP2.
        // Documents the "vault key is the modern path" decision and prevents
        // a future regression that silently picks app_secret.
        $this->assertSame('.ipambkp3', $result['extension']);
        $this->assertSame('IPAMBKP3', $this->readMagic($result['tmpFile']));
    }

    /**
     * Source-level contract test. Locks the wiring between the orchestrator
     * (`ipam_backup_run_for_destination`) and the new dispatch helper. If a
     * future refactor inlines or replaces the helper, this test forces the
     * author to confirm the new path still routes through vault-key-first
     * resolution.
     */
    public function testOrchestratorRoutesEncryptThroughResolveHelper(): void
    {
        $rfn = new ReflectionFunction('ipam_backup_run_for_destination');
        $file = (string) $rfn->getFileName();
        $start = (int) $rfn->getStartLine();
        $end = (int) $rfn->getEndLine();
        $body = implode("\n", array_slice(
            explode("\n", (string) file_get_contents($file)),
            $start - 1,
            $end - $start + 1
        ));

        $this->assertStringContainsString(
            'ipam_backup_resolve_encrypt_to_tmp(',
            $body,
            'orchestrator must route encryption through the resolve helper so vault-key-vs-app_secret is one decision site'
        );
        $this->assertStringContainsString(
            'ipam_backup_vault_key_get_raw(',
            $body,
            'orchestrator must read the vault key (not just app_secret) for encryption mode'
        );
    }
}
