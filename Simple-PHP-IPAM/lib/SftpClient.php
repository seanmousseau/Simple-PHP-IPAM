<?php
declare(strict_types=1);

use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\Common\PrivateKey;

/**
 * SFTP backup transport client using phpseclib3.
 *
 * Introduced in v3.17.0 (#693) as the SFTP transport for the web-based
 * backup/restore feature. Implements BackupClientInterface.
 *
 * Design decisions:
 *   - Wraps phpseclib3\Net\SFTP — avoids hand-rolling SSH packet framing,
 *     key exchange, and cipher negotiation (error-prone, security-sensitive).
 *   - Pure PHP — no ext-ssh2 required.
 *   - Fingerprint pinning: if 'fingerprint' is set in config, the SHA-256
 *     fingerprint of the server's host key must match or the connection is
 *     refused. Prevents MITM on unattended backup jobs.
 *   - Connection is established lazily on first use and cached for the
 *     lifetime of the client instance (typically one backup/restore job).
 *   - Auth priority: private_key (PEM text) takes precedence over password
 *     when both are supplied.
 *   - Error messages never include credential material (partial passwords,
 *     key text, fingerprint fragments). Always throw RuntimeException with
 *     a sanitized message.
 *
 * No namespace — project convention (see CLAUDE.md "Namespaces are not used").
 */
class SftpClient implements BackupClientInterface
{
    private string $host;
    private int $port;
    private string $username;
    private ?string $password;
    private ?string $privateKey;
    private string $remotePath;
    private ?string $fingerprint;

    /** Cached SFTP connection, established on first use. */
    private ?SFTP $sftp = null;

    /**
     * @param array<string,mixed> $cfg Decoded destination config JSON with keys:
     *   host         string   required  — hostname or IP
     *   port         int      default 22
     *   username     string   required
     *   password     ?string  default null  — used when private_key is not set
     *   private_key  ?string  default null  — PEM-encoded private key; takes precedence over password
     *   remote_path  string   required  — directory on remote (normalised to end with '/')
     *   fingerprint  ?string  default null  — expected SHA-256 host key fingerprint hex (without colons)
     * @throws InvalidArgumentException on missing / empty required keys or no auth method
     */
    public function __construct(array $cfg)
    {
        $host       = $cfg['host']        ?? '';
        $username   = $cfg['username']    ?? '';
        $remotePath = $cfg['remote_path'] ?? '';

        if (!is_string($host)       || $host       === '') {
            throw new InvalidArgumentException("SftpClient: missing or empty required config key 'host'");
        }
        if (!is_string($username)   || $username   === '') {
            throw new InvalidArgumentException("SftpClient: missing or empty required config key 'username'");
        }
        if (!is_string($remotePath) || $remotePath === '') {
            throw new InvalidArgumentException("SftpClient: missing or empty required config key 'remote_path'");
        }

        // At least one auth method is required
        $password   = isset($cfg['password'])    && is_string($cfg['password'])    ? $cfg['password']    : null;
        $privateKey = isset($cfg['private_key']) && is_string($cfg['private_key']) ? $cfg['private_key'] : null;

        if ($password === null && $privateKey === null) {
            throw new InvalidArgumentException(
                "SftpClient: at least one of 'password' or 'private_key' must be provided"
            );
        }

        $port = isset($cfg['port']) && is_int($cfg['port']) ? $cfg['port'] : 22;

        $fingerprint = isset($cfg['fingerprint']) && is_string($cfg['fingerprint'])
            ? $cfg['fingerprint']
            : null;

        $this->host        = $host;
        $this->port        = $port;
        $this->username    = $username;
        $this->password    = $password;
        $this->privateKey  = $privateKey;
        $this->remotePath  = rtrim($remotePath, '/') . '/';
        $this->fingerprint = $fingerprint;
    }

    // -----------------------------------------------------------------------
    // BackupClientInterface implementation
    // -----------------------------------------------------------------------

    /**
     * {@inheritDoc}
     *
     * Upload a local file to the remote path via SFTP.
     * Returns file size and SHA-256 checksum computed from the local file.
     *
     * @return array{size:int,checksum:string}
     * @throws RuntimeException on transport error
     */
    public function upload(string $localPath, string $remoteName): array
    {
        if (!is_readable($localPath)) {
            throw new RuntimeException("SftpClient::upload: cannot read local file");
        }

        $size = filesize($localPath);
        if ($size === false) {
            throw new RuntimeException("SftpClient::upload: cannot stat local file");
        }

        $checksum = hash_file('sha256', $localPath);
        if ($checksum === false) {
            throw new RuntimeException("SftpClient::upload: cannot hash local file");
        }

        $sftp   = $this->connect();
        $remote = $this->remotePath . $remoteName;

        $ok = $sftp->put($remote, $localPath, SFTP::SOURCE_LOCAL_FILE);
        if ($ok === false) {
            throw new RuntimeException("SftpClient::upload: put failed for remote '$remoteName'");
        }

        return ['size' => $size, 'checksum' => $checksum];
    }

    /**
     * {@inheritDoc}
     *
     * Download a remote file to a local path.
     * Returns false if the remote file does not exist (stat returns false).
     * Throws RuntimeException on any other transport error.
     *
     * On failure the partially-written $destPath file is left in place.
     * The caller is responsible for cleanup — RestoreEngine wraps every
     * download in a try/finally that unlinks the temp file. Cleanup is not
     * done here because any unlink() on a caller-supplied path would require
     * path-traversal validation that belongs at the call site, not inside
     * a transport client. This mirrors the same design decision in S3Client.
     *
     * @throws RuntimeException on transport error
     */
    public function download(string $remoteName, string $destPath): bool
    {
        $sftp   = $this->connect();
        $remote = $this->remotePath . $remoteName;

        // stat() returns false for missing files — use it as the not-found check.
        $statResult = $sftp->stat($remote);
        if ($statResult === false) {
            return false;
        }

        $ok = $sftp->get($remote, $destPath);
        if ($ok === false) {
            throw new RuntimeException("SftpClient::download: get failed for remote '$remoteName'");
        }

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * List all files in the remote path. Skips '.' and '..' entries.
     * SFTP does not expose checksums natively — checksum is always null.
     *
     * @return list<array{name:string,size:int,last_modified:string,checksum:?string}>
     * @throws RuntimeException on transport error
     */
    public function listObjects(): array
    {
        $sftp = $this->connect();

        $raw = $sftp->rawlist($this->remotePath);
        if ($raw === false) {
            throw new RuntimeException(
                "SftpClient::listObjects: rawlist failed for path '{$this->remotePath}'"
            );
        }

        $results = [];
        foreach ($raw as $name => $entry) {
            // rawlist returns string keys with mixed-typed values; narrow carefully.
            $nameStr = (string) $name;
            if ($nameStr === '.' || $nameStr === '..') {
                continue;
            }

            // Each entry is an array with 'size', 'mtime', etc. — guard before access.
            if (!is_array($entry)) {
                continue;
            }

            $size  = isset($entry['size'])  && is_int($entry['size'])  ? $entry['size']  : 0;
            $mtime = isset($entry['mtime']) && is_int($entry['mtime']) ? $entry['mtime'] : 0;

            $results[] = [
                'name'          => $nameStr,
                'size'          => $size,
                'last_modified' => gmdate('Y-m-d\TH:i:s\Z', $mtime),
                'checksum'      => null,
            ];
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     *
     * Delete a single remote file. Returns true on success.
     *
     * @throws RuntimeException on transport error
     */
    public function delete(string $remoteName): bool
    {
        $sftp   = $this->connect();
        $remote = $this->remotePath . $remoteName;

        $ok = $sftp->delete($remote);
        if ($ok === false) {
            throw new RuntimeException("SftpClient::delete: failed for remote '$remoteName'");
        }

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Connect and stat the remote path to verify reachability and credentials.
     * Never exposes credential material in the returned message.
     *
     * @return array{ok:bool,message:string,latency_ms:?int}
     */
    public function test(): array
    {
        $start = microtime(true);

        try {
            $sftp   = $this->connect();
            $result = $sftp->stat($this->remotePath);
            $latency = (int) round((microtime(true) - $start) * 1000);

            if ($result === false) {
                return [
                    'ok'         => false,
                    'message'    => 'connected but remote path not found',
                    'latency_ms' => $latency,
                ];
            }

            return ['ok' => true, 'message' => 'connected', 'latency_ms' => $latency];

        } catch (RuntimeException $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            return [
                'ok'         => false,
                'message'    => $e->getMessage(),
                'latency_ms' => $latency,
            ];
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Establish (or return cached) SFTP connection.
     *
     * Auth priority: private_key (PEM text) → password.
     * If a fingerprint is configured, verifies SHA-256 of the server host key.
     *
     * @throws RuntimeException on connection failure, fingerprint mismatch, or auth failure
     */
    private function connect(): SFTP
    {
        if ($this->sftp !== null) {
            return $this->sftp;
        }

        try {
            $sftp = new SFTP($this->host, $this->port);
        } catch (\Throwable $e) {
            throw new RuntimeException("SftpClient: could not reach host '{$this->host}:{$this->port}'");
        }

        // Fingerprint pinning: verify SHA-256 of the server's host key before auth.
        // Use phpseclib's PublicKeyLoader + getFingerprint() so the computed
        // fingerprint matches what users get from `ssh-keygen -lf -E sha256` and
        // OpenSSH known_hosts conventions.
        if ($this->fingerprint !== null) {
            $hostKey = $sftp->getServerPublicHostKey();
            if ($hostKey === false) {
                throw new RuntimeException("SftpClient: could not retrieve server host key");
            }
            try {
                $publicKey = PublicKeyLoader::load($hostKey);
            } catch (\Throwable $e) {
                throw new RuntimeException("SftpClient: could not load server host key for fingerprint check");
            }
            // The Fingerprint trait is mixed into concrete public-key types (RSA/EC/DSA/etc.);
            // PHPStan only sees the abstract AsymmetricKey return type, so assert it.
            if (!method_exists($publicKey, 'getFingerprint')) {
                throw new RuntimeException("SftpClient: loaded host key does not expose getFingerprint()");
            }
            $fp       = $publicKey->getFingerprint('sha256');
            $actual   = strtolower(str_replace(':', '', is_string($fp) ? $fp : ''));
            $expected = strtolower(str_replace(':', '', $this->fingerprint));
            if (!hash_equals($expected, $actual)) {
                throw new RuntimeException("SftpClient: host key fingerprint mismatch");
            }
        }

        // Authenticate: private key takes precedence over password.
        if ($this->privateKey !== null) {
            try {
                $key = PublicKeyLoader::load($this->privateKey);
            } catch (\Throwable $e) {
                throw new RuntimeException("SftpClient: failed to load private key");
            }
            if (!$key instanceof PrivateKey) {
                throw new RuntimeException("SftpClient: private key is not a usable private key");
            }
            $ok = $sftp->login($this->username, $key);
        } else {
            // $this->password cannot be null here: constructor enforces at least one auth method.
            $ok = $sftp->login($this->username, (string) $this->password);
        }

        if ($ok === false) {
            throw new RuntimeException("SftpClient: authentication failed");
        }

        $this->sftp = $sftp;
        return $this->sftp;
    }
}
