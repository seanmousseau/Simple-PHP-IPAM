<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for tools/decrypt-backup.php (#1043, v3.24.0).
 *
 * The tool runs in a subprocess so we exercise it the way operators do:
 * argv parsing, stdout/stderr separation, exit codes. Each test makes
 * its own fixture archive via the in-process codec, then shells out to
 * decrypt and asserts the round-trip plaintext matches.
 */
class DecryptToolTest extends TestCase
{
    private string $toolPath;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->toolPath = dirname(__DIR__) . '/tools/decrypt-backup.php';
        $this->tmpDir   = sys_get_temp_dir() . '/ipam-decrypt-tool-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * @param list<string> $args
     * @return array{int,string,string} [exitCode, stdout, stderr]
     */
    private function runTool(array $args, ?string $stdin = null, array $env = []): array
    {
        $cmd = array_merge(['php', $this->toolPath], $args);

        $procEnv = $_ENV;
        foreach ($env as $k => $v) {
            $procEnv[$k] = $v;
        }

        $proc = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            $procEnv
        );
        if (!is_resource($proc)) {
            $this->fail('proc_open failed');
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return [$code, $stdout, $stderr];
    }

    public function testHelpFlagExitsTwo(): void
    {
        [$code, , $stderr] = $this->runTool(['--help']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage:', $stderr);
    }

    public function testMissingInExitsTwo(): void
    {
        [$code, , $stderr] = $this->runTool(['--out', '/tmp/x']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('usage:', $stderr);
    }

    public function testStoredModeRoundTripWithBase64Key(): void
    {
        $payload = "stored-mode CLI roundtrip ✓\n";
        $vault   = random_bytes(BACKUP_VAULT_KEY_LEN);
        $src     = $this->tmpDir . '/src.txt';
        $enc     = $this->tmpDir . '/enc.bkp3';
        $dec     = $this->tmpDir . '/dec.txt';
        file_put_contents($src, $payload);
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);

        [$code, $stdout, $stderr] = $this->runTool([
            '--in', $enc,
            '--out', $dec,
            '--vault-key', base64_encode($vault),
        ]);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertSame($payload, file_get_contents($dec));
        $this->assertStringContainsString('decrypted', $stdout);
    }

    public function testStoredModeRoundTripWithHexKey(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $src   = $this->tmpDir . '/src.txt';
        $enc   = $this->tmpDir . '/enc.bkp3';
        $dec   = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'hex-key path');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);

        [$code] = $this->runTool([
            '--in', $enc,
            '--out', $dec,
            '--vault-key', bin2hex($vault),
        ]);
        $this->assertSame(0, $code);
        $this->assertSame('hex-key path', file_get_contents($dec));
    }

    public function testTransitoryModeRoundTripWithArgPassphrase(): void
    {
        $src = $this->tmpDir . '/src.txt';
        $enc = $this->tmpDir . '/enc.bkp3';
        $dec = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'transitory roundtrip');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'passphrase-x', null, 2, 8192, 1);

        [$code, , $stderr] = $this->runTool([
            '--in', $enc,
            '--out', $dec,
            '--passphrase', 'passphrase-x',
        ]);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertSame('transitory roundtrip', file_get_contents($dec));
    }

    public function testTransitoryModeRoundTripWithEnvPassphrase(): void
    {
        $src = $this->tmpDir . '/src.txt';
        $enc = $this->tmpDir . '/enc.bkp3';
        $dec = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'env passphrase path');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'envpass', null, 2, 8192, 1);

        [$code, , $stderr] = $this->runTool([
            '--in', $enc,
            '--out', $dec,
        ], null, ['IPAM_BACKUP_PASSPHRASE' => 'envpass']);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertSame('env passphrase path', file_get_contents($dec));
    }

    public function testStoredArchiveWithoutVaultKeyExitsThree(): void
    {
        $vault = random_bytes(BACKUP_VAULT_KEY_LEN);
        $src   = $this->tmpDir . '/src.txt';
        $enc   = $this->tmpDir . '/enc.bkp3';
        $dec   = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'x');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, $vault);

        [$code, , $stderr] = $this->runTool(['--in', $enc, '--out', $dec]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('--vault-key', $stderr);
    }

    public function testTransitoryArchiveWithoutPassphraseExitsThree(): void
    {
        $src = $this->tmpDir . '/src.txt';
        $enc = $this->tmpDir . '/enc.bkp3';
        $dec = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'x');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'pw', null, 2, 8192, 1);

        [$code, , $stderr] = $this->runTool(['--in', $enc, '--out', $dec]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('--passphrase', $stderr);
    }

    public function testWrongPassphraseExitsThree(): void
    {
        $src = $this->tmpDir . '/src.txt';
        $enc = $this->tmpDir . '/enc.bkp3';
        $dec = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'secret');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_TRANSITORY, 'right', null, 2, 8192, 1);

        [$code, , $stderr] = $this->runTool([
            '--in', $enc,
            '--out', $dec,
            '--passphrase', 'wrong',
        ]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('decrypt failed', $stderr);
    }

    public function testIpambku1RoundTripNoCredential(): void
    {
        $src = $this->tmpDir . '/src.txt';
        $enc = $this->tmpDir . '/enc.bku1';
        $dec = $this->tmpDir . '/dec.txt';
        file_put_contents($src, 'unencrypted wrapper');
        backup_unencrypted_wrap_stream($src, $enc);

        [$code, , $stderr] = $this->runTool(['--in', $enc, '--out', $dec]);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertSame('unencrypted wrapper', file_get_contents($dec));
    }

    public function testInvalidVaultKeyEncodingExitsTwo(): void
    {
        $src = $this->tmpDir . '/src.txt';
        $enc = $this->tmpDir . '/enc.bkp3';
        file_put_contents($src, 'x');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, random_bytes(32));

        [$code, , $stderr] = $this->runTool([
            '--in', $enc,
            '--out', $this->tmpDir . '/dec.txt',
            '--vault-key', 'not-base64-not-hex',
        ]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--vault-key', $stderr);
    }
}
