<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers ipam_destination_merge_secrets() — the three-branch matrix from #793:
 *   - field omitted from $_POST  → preserve existing
 *   - field present, empty string → preserve existing
 *   - field present, non-empty   → use submitted value
 *
 * Applied to s3_secret_key, sftp_password, and sftp_private_key.
 */
final class DestinationSecretMergeTest extends TestCase
{
    public function testS3SecretOmittedPreservesExisting(): void
    {
        $post     = ['name' => 's3-prod', 's3_endpoint' => 'https://s3.example.com'];
        $existing = ['secret_key' => 'STORED_SECRET'];

        $result = ipam_destination_merge_secrets($post, $existing, 's3');

        $this->assertSame('STORED_SECRET', $result['s3_secret_key']);
    }

    public function testS3SecretBlankPreservesExisting(): void
    {
        $post     = ['s3_secret_key' => ''];
        $existing = ['secret_key' => 'STORED_SECRET'];

        $result = ipam_destination_merge_secrets($post, $existing, 's3');

        $this->assertSame('STORED_SECRET', $result['s3_secret_key']);
    }

    public function testS3SecretNonEmptyReplaces(): void
    {
        $post     = ['s3_secret_key' => 'NEW_SECRET'];
        $existing = ['secret_key' => 'STORED_SECRET'];

        $result = ipam_destination_merge_secrets($post, $existing, 's3');

        $this->assertSame('NEW_SECRET', $result['s3_secret_key']);
    }

    public function testS3OmittedWithNoExistingLeavesAbsent(): void
    {
        $post     = ['name' => 's3-prod'];
        $existing = []; // no stored secret either

        $result = ipam_destination_merge_secrets($post, $existing, 's3');

        $this->assertArrayNotHasKey('s3_secret_key', $result);
    }

    public function testSftpPasswordOmittedPreservesExisting(): void
    {
        $post     = ['sftp_host' => 'sftp.example.com'];
        $existing = ['password' => 'STORED_PW'];

        $result = ipam_destination_merge_secrets($post, $existing, 'sftp');

        $this->assertSame('STORED_PW', $result['sftp_password']);
    }

    public function testSftpPasswordBlankPreservesExisting(): void
    {
        $post     = ['sftp_password' => ''];
        $existing = ['password' => 'STORED_PW'];

        $result = ipam_destination_merge_secrets($post, $existing, 'sftp');

        $this->assertSame('STORED_PW', $result['sftp_password']);
    }

    public function testSftpPasswordNonEmptyReplaces(): void
    {
        $post     = ['sftp_password' => 'NEW_PW'];
        $existing = ['password' => 'STORED_PW'];

        $result = ipam_destination_merge_secrets($post, $existing, 'sftp');

        $this->assertSame('NEW_PW', $result['sftp_password']);
    }

    public function testSftpPrivateKeyOmittedPreservesExisting(): void
    {
        $post     = ['sftp_host' => 'sftp.example.com'];
        $existing = ['private_key' => "-----BEGIN OPENSSH-----\nSTORED\n-----END-----"];

        $result = ipam_destination_merge_secrets($post, $existing, 'sftp');

        $this->assertSame(
            "-----BEGIN OPENSSH-----\nSTORED\n-----END-----",
            $result['sftp_private_key']
        );
    }

    public function testSftpPrivateKeyBlankPreservesExisting(): void
    {
        $post     = ['sftp_private_key' => ''];
        $existing = ['private_key' => "-----BEGIN OPENSSH-----\nSTORED\n-----END-----"];

        $result = ipam_destination_merge_secrets($post, $existing, 'sftp');

        $this->assertSame(
            "-----BEGIN OPENSSH-----\nSTORED\n-----END-----",
            $result['sftp_private_key']
        );
    }

    public function testSftpPrivateKeyNonEmptyReplaces(): void
    {
        $post     = ['sftp_private_key' => "-----BEGIN OPENSSH-----\nNEW\n-----END-----"];
        $existing = ['private_key' => "-----BEGIN OPENSSH-----\nSTORED\n-----END-----"];

        $result = ipam_destination_merge_secrets($post, $existing, 'sftp');

        $this->assertSame(
            "-----BEGIN OPENSSH-----\nNEW\n-----END-----",
            $result['sftp_private_key']
        );
    }

    public function testLocalTypeIsNoOp(): void
    {
        $post     = ['local_path' => 'data/backups'];
        $existing = ['path' => 'data/backups'];

        $result = ipam_destination_merge_secrets($post, $existing, 'local');

        $this->assertSame($post, $result);
    }

    public function testS3TypeDoesNotTouchSftpFields(): void
    {
        $post     = ['s3_secret_key' => ''];
        $existing = ['secret_key' => 'S3_STORED', 'password' => 'SFTP_STORED'];

        $result = ipam_destination_merge_secrets($post, $existing, 's3');

        $this->assertSame('S3_STORED', $result['s3_secret_key']);
        $this->assertArrayNotHasKey('sftp_password', $result);
    }
}
