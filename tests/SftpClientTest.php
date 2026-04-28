<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Constructor-level unit tests for SftpClient.
 *
 * No real network connections are made. These tests verify that the
 * constructor accepts valid config and rejects invalid config with the
 * correct exception type and without touching the network.
 *
 * Introduced in v3.17.0 (#693).
 */
final class SftpClientTest extends TestCase
{
    /** @return array<string,mixed> */
    private function baseConfig(): array
    {
        return [
            'host'        => 'example.com',
            'username'    => 'user',
            'password'    => 'pw',
            'remote_path' => '/backups',
        ];
    }

    public function testConstructorAcceptsPasswordAuth(): void
    {
        $c = new SftpClient($this->baseConfig());
        $this->assertInstanceOf(SftpClient::class, $c);
    }

    public function testConstructorAcceptsKeyAuth(): void
    {
        $cfg = $this->baseConfig();
        unset($cfg['password']);
        $cfg['private_key'] = "-----BEGIN OPENSSH PRIVATE KEY-----\nfake\n-----END OPENSSH PRIVATE KEY-----";
        $c = new SftpClient($cfg);
        $this->assertInstanceOf(SftpClient::class, $c);
    }

    public function testConstructorRejectsMissingHost(): void
    {
        $cfg = $this->baseConfig();
        unset($cfg['host']);
        $this->expectException(InvalidArgumentException::class);
        new SftpClient($cfg);
    }

    public function testConstructorRejectsMissingUsername(): void
    {
        $cfg = $this->baseConfig();
        unset($cfg['username']);
        $this->expectException(InvalidArgumentException::class);
        new SftpClient($cfg);
    }

    public function testConstructorRejectsBothPasswordAndKeyMissing(): void
    {
        $cfg = $this->baseConfig();
        unset($cfg['password']);
        $this->expectException(InvalidArgumentException::class);
        new SftpClient($cfg);
    }

    public function testConstructorRejectsMissingRemotePath(): void
    {
        $cfg = $this->baseConfig();
        unset($cfg['remote_path']);
        $this->expectException(InvalidArgumentException::class);
        new SftpClient($cfg);
    }
}
