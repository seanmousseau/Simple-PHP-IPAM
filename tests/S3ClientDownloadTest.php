<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for S3Client::download body-leak (#788).
 *
 * Until v3.19.1 the curl handle was configured with both CURLOPT_FILE => $fh
 * and CURLOPT_RETURNTRANSFER => false. On PHP 8.4+ the explicit false
 * overrides CURLOPT_FILE and streams the response body to PHP stdout
 * instead of writing it to the file handle, leaking the entire downloaded
 * payload into the HTTP response of any caller (verify on
 * remote_backups.php, restore staging via download_remote_backup.php,
 * etc.). This pin keeps a future regression of the same kind out of the
 * tree.
 *
 * The test runs a tiny ad-hoc HTTP server bound to 127.0.0.1 in the same
 * process, has S3Client download a known-content file from it, and
 * asserts (a) the destination file received the bytes, and (b) absolutely
 * no bytes leaked into the PHP output buffer. The S3Client is constructed
 * with a fake AWS endpoint that points at the local test server — auth
 * succeeds because the test server doesn't validate signatures, but the
 * fix being tested is the file-handle redirection, not auth.
 */
final class S3ClientDownloadTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ipam-s3dl-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $f from glob() of test-controlled tmpDir
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Spawns a one-shot HTTP server on a random local port that serves
     * \$payload at the request path /test-bucket/test.bin and exits after
     * one request. Returns the port.
     */
    private function spawnFakeS3(string $payload): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            $this->fail("could not bind: $errstr ($errno)");
        }
        $name = stream_socket_get_name($sock, false);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: serve one request and exit.
            $client = stream_socket_accept($sock, 5);
            if ($client !== false) {
                fread($client, 4096); // discard request
                $resp = "HTTP/1.1 200 OK\r\n"
                      . "Content-Length: " . strlen($payload) . "\r\n"
                      . "Content-Type: application/octet-stream\r\n"
                      . "Connection: close\r\n\r\n"
                      . $payload;
                fwrite($client, $resp);
                fclose($client);
            }
            fclose($sock);
            exit(0);
        }
        fclose($sock);
        return $port;
    }

    public function testDownloadWritesToFileWithNoBodyLeak(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork required');
        }

        $payload = "IPAMBKP2" . random_bytes(2048); // realistic-ish encrypted-backup shape
        $port    = $this->spawnFakeS3($payload);

        $client = new S3Client([
            'endpoint'   => "http://127.0.0.1:$port",
            'region'     => 'us-east-1',
            'bucket'     => 'test-bucket',
            'prefix'     => '',
            'access_key' => 'AKIAIOSFODNN7EXAMPLE',
            'secret_key' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ]);

        $dst = $this->tmpDir . '/got.bin';

        ob_start();
        $ok = $client->download('test.bin', $dst);
        $leaked = ob_get_clean();

        $this->assertTrue($ok, 'download() should report success');
        $this->assertSame(
            strlen($payload),
            (int) filesize($dst),
            'destination file must contain the full payload'
        );
        $this->assertSame(
            hash('sha256', $payload),
            hash_file('sha256', $dst),
            'destination file content must match the fixture'
        );
        $this->assertSame(
            '',
            $leaked,
            "PHP stdout MUST NOT receive any of the response body — got " . strlen($leaked) . " leaked bytes"
        );

        // reap the helper child to keep the test runner clean
        pcntl_waitpid(-1, $status, WNOHANG);
    }
}
