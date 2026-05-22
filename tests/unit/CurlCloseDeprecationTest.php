<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * #1130 (v3.27.3) — `curl_close()` is deprecated in PHP 8.5 and a no-op
 * since PHP 8.0. Every site of `curl_close($ch);` in the codebase must
 * be removed; the handle auto-closes when it goes out of scope.
 *
 * This test is a source-level scan: any reintroduction of `curl_close(`
 * anywhere under Simple-PHP-IPAM/ fails CI.
 */
final class CurlCloseDeprecationTest extends TestCase
{
    public function testNoCurlCloseAnywhereInApp(): void
    {
        $root = __DIR__ . '/../../Simple-PHP-IPAM';
        $hits = [];
        $rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getRealPath();
            if (str_contains($path, '/vendor/') || str_contains($path, '/data/')) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (preg_match_all('/\bcurl_close\s*\(/', $contents, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as $hit) {
                    $line = substr_count(substr($contents, 0, (int) $hit[1]), "\n") + 1;
                    $hits[] = "$path:$line";
                }
            }
        }
        $this->assertSame(
            [],
            $hits,
            "#1130: curl_close() must not appear anywhere in Simple-PHP-IPAM/. "
          . "Found:\n" . implode("\n", $hits)
        );
    }
}
