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
        $this->toolPath = dirname(dirname(__DIR__)) . '/Simple-PHP-IPAM/tools/decrypt-backup.php';
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

    public function testHelpFlagExitsZeroWithUsageOnStdout(): void
    {
        // v3.28.0 (#1165): --help is a success path — usage to STDOUT, exit 0.
        [$code, $stdout, $stderr] = $this->runTool(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('usage:', $stdout);
        $this->assertSame('', $stderr);
    }

    public function testNoArgsExitsTwoWithUsage(): void
    {
        [$code, , $stderr] = $this->runTool([]);
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

    // ── v3.28.0 (#1165) hardening regression tests ───────────────────────

    /** Micro-fixture: encrypt a known ~50-byte plaintext under app_secret (IPAMBKP2). */
    private function microV2(string $appSecret): string
    {
        $src = $this->tmpDir . '/micro-src-' . bin2hex(random_bytes(3)) . '.bin';
        $enc = $this->tmpDir . '/micro-' . bin2hex(random_bytes(3)) . '.enc';
        file_put_contents($src, str_repeat('AB', 25)); // 50 bytes
        backup_encrypt_stream($src, $enc, $appSecret);
        return $enc;
    }

    public function testStdoutOutputStreamsPlaintext(): void
    {
        $secret = bin2hex(random_bytes(16));
        $enc    = $this->microV2($secret);
        [$code, $stdout, $stderr] = $this->runTool(['--in', $enc, '--out', '-', '--app-secret', $secret]);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertSame(str_repeat('AB', 25), $stdout);
        // No summary line should pollute stdout when -- the stream IS the output.
        $this->assertStringNotContainsString('decrypted', $stdout);
    }

    public function testOutputCollisionRefusedWithoutForce(): void
    {
        $secret = bin2hex(random_bytes(16));
        $enc    = $this->microV2($secret);
        $dst    = $this->tmpDir . '/exists.bin';
        file_put_contents($dst, 'pre-existing bytes');

        [$code, , $stderr] = $this->runTool(['--in', $enc, '--out', $dst, '--app-secret', $secret]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('--force', $stderr);
        // Untouched.
        $this->assertSame('pre-existing bytes', file_get_contents($dst));

        // ...and --force overwrites.
        [$code2] = $this->runTool(['--in', $enc, '--out', $dst, '--force', '--app-secret', $secret]);
        $this->assertSame(0, $code2);
        $this->assertSame(str_repeat('AB', 25), file_get_contents($dst));
    }

    public function testConflictingCredentialsExitsTwo(): void
    {
        $secret = bin2hex(random_bytes(16));
        $enc    = $this->microV2($secret);
        [$code, , $stderr] = $this->runTool([
            '--in', $enc, '--out', $this->tmpDir . '/d.bin',
            '--app-secret', $secret,
            '--vault-key', base64_encode(random_bytes(BACKUP_VAULT_KEY_LEN)),
        ]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('at most one', $stderr);
    }

    public function testWrongCredentialTypeOnStoredArchiveExitsTwo(): void
    {
        // IPAMBKP3 stored archive but operator passes --app-secret.
        $src = $this->tmpDir . '/s.bin';
        $enc = $this->tmpDir . '/s.bkp3';
        file_put_contents($src, 'abcdef');
        backup_encrypt_stream_v3($src, $enc, BACKUP_V3_MODE_STORED, null, random_bytes(BACKUP_VAULT_KEY_LEN));

        [$code, , $stderr] = $this->runTool(['--in', $enc, '--out', $this->tmpDir . '/d.bin', '--app-secret', bin2hex(random_bytes(16))]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('different format', $stderr);
        $this->assertFileDoesNotExist($this->tmpDir . '/d.bin');
    }

    public function testBareGzipPassthrough(): void
    {
        $plain = str_repeat('SQLite-ish bytes ', 10);
        $gz    = gzencode($plain, 9);
        $this->assertIsString($gz);
        $in  = $this->tmpDir . '/bare.sql.gz';
        $out = $this->tmpDir . '/bare.out';
        file_put_contents($in, $gz);

        [$code, $stdout, $stderr] = $this->runTool(['--in', $in, '--out', $out]);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertSame($gz, file_get_contents($out)); // verbatim copy
        $this->assertStringContainsString('decrypted', $stdout);
    }

    public function testCredentialSuppliedToNoCredArchiveExitsTwo(): void
    {
        $src = $this->tmpDir . '/u.bin';
        $enc = $this->tmpDir . '/u.bku1';
        file_put_contents($src, 'no-cred wrapper');
        backup_unencrypted_wrap_stream($src, $enc);

        [$code, , $stderr] = $this->runTool(['--in', $enc, '--out', $this->tmpDir . '/d.bin', '--app-secret', bin2hex(random_bytes(16))]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('no credential', $stderr);
    }

    public function testEmptyInputFileExitsTwo(): void
    {
        $in = $this->tmpDir . '/empty.bin';
        file_put_contents($in, '');
        [$code, , $stderr] = $this->runTool(['--in', $in, '--out', $this->tmpDir . '/d.bin', '--app-secret', 'x']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('empty', $stderr);
    }

    public function testTamperedV2ArchiveLeavesNoPartialOutput(): void
    {
        $secret = bin2hex(random_bytes(16));
        $enc    = $this->microV2($secret);
        $bytes  = file_get_contents($enc);
        $this->assertIsString($bytes);
        // flip a byte in the ciphertext region (past the magic+salt+iv header).
        $pos = intdiv(strlen($bytes), 2);
        $bytes[$pos] = chr(ord($bytes[$pos]) ^ 0xFF);
        $tampered = $this->tmpDir . '/tampered.enc';
        file_put_contents($tampered, $bytes);

        $out = $this->tmpDir . '/should-not-exist.bin';
        [$code, , $stderr] = $this->runTool(['--in', $tampered, '--out', $out, '--app-secret', $secret]);
        $this->assertSame(3, $code);
        $this->assertStringContainsString('decrypt failed', $stderr);
        $this->assertFileDoesNotExist($out);
    }

    public function testUnrecognisedMagicExitsTwo(): void
    {
        $in = $this->tmpDir . '/garbage.bin';
        file_put_contents($in, "\x00\x01\x02NOT-A-BACKUP\xff\xfe");
        [$code, , $stderr] = $this->runTool(['--in', $in, '--out', $this->tmpDir . '/d.bin']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('unrecognised', $stderr);
    }

    public function testBareSqliteFileCopiedVerbatim(): void
    {
        // A raw SQLite file (the legacy "local backup" format — matrix ID L0).
        // The tool detects "no envelope" from the 16-byte magic window
        // ("SQLite format 3\0") and copies it through verbatim, exit 0, no
        // credential. Regression guard for the 9→16-byte sniff-window fix:
        // a 9-byte read truncated the 16-byte SQLite header and the file was
        // rejected as "unrecognised".
        $src = $this->tmpDir . '/legacy.sqlite';
        $dec = $this->tmpDir . '/copy.bin';
        file_put_contents($src, "SQLite format 3\x00" . str_repeat("\x00", 8192) . 'tail-' . bin2hex(random_bytes(8)));
        [$code, $stdout, $stderr] = $this->runTool(['--in', $src, '--out', $dec]);
        $this->assertSame(0, $code, "stderr: $stderr");
        $this->assertFileExists($dec);
        $this->assertSame(file_get_contents($src), file_get_contents($dec), 'bare SQLite file must be copied byte-for-byte');
        $this->assertStringContainsString('decrypted', $stdout);
    }
}
