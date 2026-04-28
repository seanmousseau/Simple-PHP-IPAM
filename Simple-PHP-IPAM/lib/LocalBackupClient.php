<?php
declare(strict_types=1);

final class LocalBackupClient implements BackupClientInterface
{
    private string $directory; // canonical absolute path, ends with '/'

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        if (!isset($config['path']) || !is_string($config['path']) || $config['path'] === '') {
            throw new InvalidArgumentException('LocalBackupClient: path is required');
        }
        $raw = $config['path'];
        // Resolve relative paths relative to Simple-PHP-IPAM/
        if (!str_starts_with($raw, '/')) {
            $raw = dirname(__DIR__) . '/' . $raw;
        }
        // Canonicalize ../ without requiring directory to exist
        $parts = [];
        foreach (explode('/', $raw) as $seg) {
            if ($seg === '..') { array_pop($parts); }
            elseif ($seg !== '' && $seg !== '.') { $parts[] = $seg; }
        }
        $canonical = '/' . implode('/', $parts);

        // Path-traversal guard: must be under <app>/data/
        $appData = dirname(__DIR__) . '/data';
        if (!str_starts_with($canonical . '/', $appData . '/')) {
            throw new InvalidArgumentException('LocalBackupClient: path must be under data/');
        }
        if (!is_dir($canonical)) {
            if (!@mkdir($canonical, 0775, true) && !is_dir($canonical)) {
                throw new RuntimeException('LocalBackupClient: cannot create directory');
            }
        }
        $this->directory = rtrim($canonical, '/') . '/';
    }

    /** @return array{size:int,checksum:string} */
    public function upload(string $localPath, string $remoteName): array
    {
        $this->guardName($remoteName);
        $dest = $this->directory . $remoteName;
        if (!@copy($localPath, $dest)) {
            throw new RuntimeException('LocalBackupClient: copy failed');
        }
        $size = filesize($dest);
        if ($size === false) {
            throw new RuntimeException('LocalBackupClient: filesize failed');
        }
        $checksum = hash_file('sha256', $dest);
        if ($checksum === false) {
            throw new RuntimeException('LocalBackupClient: checksum failed');
        }
        return ['size' => $size, 'checksum' => $checksum];
    }

    public function download(string $remoteName, string $destPath): bool
    {
        $this->guardName($remoteName);
        $src = $this->directory . $remoteName;
        if (!is_file($src)) return false;
        if (!@copy($src, $destPath)) {
            throw new RuntimeException('LocalBackupClient: download copy failed');
        }
        return true;
    }

    /** @return list<array{name:string,size:int,last_modified:string,checksum:?string}> */
    public function list(): array
    {
        $entries = [];
        $iter = @scandir($this->directory);
        if ($iter === false) return [];
        foreach ($iter as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $this->directory . $name;
            if (!is_file($full)) continue;
            $size = filesize($full); if ($size === false) $size = 0;
            $mtime = filemtime($full); if ($mtime === false) $mtime = time();
            $entries[] = [
                'name' => $name,
                'size' => $size,
                'last_modified' => gmdate('Y-m-d\TH:i:s\Z', $mtime),
                'checksum' => null,
            ];
        }
        // Sort newest first
        usort($entries, fn($a, $b) => strcmp($b['last_modified'], $a['last_modified']));
        return $entries;
    }

    public function delete(string $remoteName): bool
    {
        $this->guardName($remoteName);
        $target = $this->directory . $remoteName;
        if (!is_file($target)) return false;
        // Resolve to canonical path and verify containment before deletion.
        // realpath() is the explicit sanitizer recognised by the project semgrep
        // rules (ipam-unlink-user-path); it also prevents any residual traversal
        // that guardName() might theoretically miss.
        $safe = realpath($target);
        if ($safe === false || !str_starts_with($safe . '/', $this->directory)) {
            return false;
        }
        return @unlink($safe); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $safe is realpath()-validated and confirmed under $this->directory
    }

    /** @return array{ok:bool,message:string,latency_ms:int} */
    public function test(): array
    {
        $start = (int) (microtime(true) * 1000);
        $ok = is_dir($this->directory) && is_writable($this->directory);
        $latency = (int) (microtime(true) * 1000) - $start;
        return [
            'ok' => $ok,
            'message' => $ok ? 'directory writable' : 'directory not writable',
            'latency_ms' => $latency,
        ];
    }

    private function guardName(string $name): void
    {
        if ($name === '' || str_contains($name, '/') || str_contains($name, "\0") || str_starts_with($name, '.')) {
            throw new InvalidArgumentException('LocalBackupClient: invalid remote name');
        }
    }
}
