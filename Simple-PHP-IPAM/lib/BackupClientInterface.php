<?php
declare(strict_types=1);

/**
 * Contract for all backup transport clients (S3-compatible, SFTP, local
 * filesystem). Introduced in v3.17.0 (#692) as the entry point for the
 * web-based backup/restore feature. Follows the same narrow-interface
 * pattern as the Dialect family (see CLAUDE.md "When to use classes vs
 * functions"). No namespace — project convention (see CLAUDE.md).
 */
interface BackupClientInterface
{
    /**
     * Upload a local file to the remote destination.
     *
     * Streams the file; callers must not read it entirely into memory.
     *
     * @return array{size:int,checksum:string}
     */
    public function upload(string $localPath, string $remoteName): array;

    /**
     * Download a remote file to a local path.
     *
     * Returns true on success, false if the file does not exist (404).
     * Throws RuntimeException on any other transport error.
     */
    public function download(string $remoteName, string $destPath): bool;

    /**
     * List all backup objects stored at this destination.
     *
     * @return list<array{name:string,size:int,last_modified:string,checksum:?string}>
     */
    public function listObjects(): array;

    /**
     * Delete a single remote file by name.
     *
     * Returns true on success. Throws RuntimeException on unexpected errors.
     */
    public function delete(string $remoteName): bool;

    /**
     * Probe the destination for reachability and valid credentials.
     *
     * Never exposes access keys, secret keys, or signature material in the
     * returned message — sanitize before returning.
     *
     * @return array{ok:bool,message:string,latency_ms:?int}
     */
    public function test(): array;
}
