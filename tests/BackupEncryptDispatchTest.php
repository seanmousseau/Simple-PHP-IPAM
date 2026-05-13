<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Encrypt-write-path dispatch — `ipam_backup_resolve_encrypt_to_tmp()`.
 *
 * History:
 *   - v3.27.1 (Pass A 2026-05-08) extracted the encrypt decision into this
 *     helper and wired it to prefer the backup vault key (IPAMBKP3 stored),
 *     falling back to `app_secret` (legacy IPAMBKP2, `.enc`) — the
 *     orchestrator's inline encrypt block had never been wired to read the
 *     v3.26.0 vault key, so encrypted scheduled backups silently failed on
 *     any install with no `app_secret`.
 *   - v3.28.0 #1164 REMOVED the `app_secret` legacy write fallback. An
 *     encrypted scheduled backup now requires the vault key (stored mode);
 *     an install with only `app_secret` configured fails preflight with an
 *     actionable message. The in-app *reader* still decrypts IPAMBKP1/2
 *     archives and `tools/decrypt-backup.php` is the long-term escape hatch;
 *     v4.0.0 removes the in-app reader for those formats too (cold break).
 *
 * This test pins the post-#1164 contract: the resolver only ever produces
 * an IPAMBKP3 archive (or passes the plaintext through for `unencrypted`),
 * never an `app_secret`-derived IPAMBKP2 archive, and throws an actionable
 * error when encryption is requested with no vault key.
 */
final class BackupEncryptDispatchTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) @unlink($f); // nosemgrep: php.lang.security.unlink-use.unlink-use -- tempnam-generated
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

    private function readMagic(string $path): string
    {
        $fh = fopen($path, 'rb');
        $this->assertNotFalse($fh);
        try {
            return (string) fread($fh, 8);
        } finally {
            fclose($fh);
        }
    }

    public function testStoredEncryptionWithVaultKeyProducesIPAMBKP3(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');
        $result = ipam_backup_resolve_encrypt_to_tmp($src, 'stored', $this->makeVaultKey(), '.sql.gz');
        $this->tmpFiles[] = $result['tmpFile'];

        $this->assertSame('.ipambkp3', $result['extension']);
        $this->assertSame('IPAMBKP3', $this->readMagic($result['tmpFile']));
        $this->assertFileDoesNotExist($src, 'source tmpfile must be consumed (unlinked) by encrypt');
    }

    public function testStoredEncryptionWithoutVaultKeyThrowsActionableError(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');

        try {
            ipam_backup_resolve_encrypt_to_tmp($src, 'stored', null, '.sql.gz');
            $this->fail('Expected RuntimeException — encrypted backup requested with no vault key');
        } catch (\RuntimeException $e) {
            $msg = strtolower($e->getMessage());
            $this->assertStringContainsString('vault', $msg, 'error names the vault key as the fix');
            $this->assertStringContainsString('removed', $msg, 'error states the app_secret write path was removed');
            $this->assertStringContainsString('v3.28.0', $msg, 'error cites the removal version');
        }

        // The source tmp file must be cleaned up even on the throw path so the
        // orchestrator doesn't leak a partial dump under sys_get_temp_dir().
        $this->assertFileDoesNotExist($src, 'source tmpfile must be unlinked on the error path');
    }

    public function testUnencryptedModePassesSourceFileThrough(): void
    {
        $src = $this->stagePlainTmp('SELECT 1; -- synthetic dump');
        // Vault key present but mode=unencrypted ignores it.
        $result = ipam_backup_resolve_encrypt_to_tmp($src, 'unencrypted', $this->makeVaultKey(), '.sql.gz');

        $this->assertSame('.sql.gz', $result['extension'], 'unencrypted mode preserves the dump extension');
        $this->assertSame($src, $result['tmpFile'], 'unencrypted mode passes the source path through unchanged');
        $this->assertFileExists($src);
    }

    /**
     * #1164: no `app_secret`-derived write codec is reachable from the
     * resolver — the only encrypted output it produces is IPAMBKP3. Belt to
     * the behavioural tests above: a source-level check that a future
     * refactor can't quietly re-introduce the IPAMBKP2 (`.enc`) branch.
     */
    public function testResolverHasNoAppSecretWritePath(): void
    {
        $rfn  = new ReflectionFunction('ipam_backup_resolve_encrypt_to_tmp');
        $file = (string) $rfn->getFileName();
        $body = implode("\n", array_slice(
            explode("\n", (string) file_get_contents($file)),
            (int) $rfn->getStartLine() - 1,
            (int) $rfn->getEndLine() - (int) $rfn->getStartLine() + 1
        ));
        $this->assertStringNotContainsString('ipam_backup_encrypt_to_tmp(', $body, 'resolver must not call the IPAMBKP2 (app_secret) writer');
        $this->assertStringNotContainsString("'.enc'", $body, 'resolver must not emit the legacy .enc extension');
    }

    /**
     * Source-level contract test. Locks the wiring between the orchestrator
     * (`ipam_backup_run_for_destination`) and the dispatch helper. If a
     * future refactor inlines or replaces the helper, this forces the author
     * to confirm the new path still routes through vault-key resolution.
     */
    public function testOrchestratorRoutesEncryptThroughResolveHelper(): void
    {
        $rfn  = new ReflectionFunction('ipam_backup_run_for_destination');
        $file = (string) $rfn->getFileName();
        $body = implode("\n", array_slice(
            explode("\n", (string) file_get_contents($file)),
            (int) $rfn->getStartLine() - 1,
            (int) $rfn->getEndLine() - (int) $rfn->getStartLine() + 1
        ));

        $this->assertStringContainsString('ipam_backup_resolve_encrypt_to_tmp(', $body, 'orchestrator must route encryption through the resolve helper');
        $this->assertStringContainsString('ipam_backup_vault_key_get_raw(', $body, 'orchestrator must read the vault key for encryption mode');
    }
}
