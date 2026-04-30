<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for S3Client::download body-leak (#788).
 *
 * Until v3.19.1 the curl handle was configured with both
 *   CURLOPT_FILE           => $fh
 *   CURLOPT_RETURNTRANSFER => false
 * On PHP 8.4+ the explicit `false` overrides CURLOPT_FILE and streams the
 * response body to PHP stdout instead of writing it to the file handle —
 * leaking the entire downloaded payload into the HTTP response of any
 * caller (verify on remote_backups.php, restore staging, etc.).
 *
 * This test is a static-source assertion, not a behaviour test, because
 * the project's coding guideline forbids tests that require live network
 * calls or an external HTTP server. End-to-end coverage of the download
 * behaviour against a real S3 endpoint is tracked as #789 (MinIO
 * integration test in CI).
 *
 * What we pin here: in the body of S3Client::download(), there is exactly
 * one curl_setopt_array invocation, and within its argument array
 * CURLOPT_FILE appears AND CURLOPT_RETURNTRANSFER does NOT appear. The
 * presence of both together — even with RETURNTRANSFER set to its
 * documented default — is the exact regression that broke v3.17–v3.19,
 * and a source-level pin keeps it out of the tree without requiring
 * pcntl_fork or socket binding.
 */
final class S3ClientDownloadTest extends TestCase
{
    public function testDownloadCurlSetoptDoesNotPairFileWithReturntransfer(): void
    {
        $ref    = new ReflectionMethod(S3Client::class, 'download');
        $file   = $ref->getFileName();
        $start  = $ref->getStartLine();
        $end    = $ref->getEndLine();
        $this->assertNotFalse($file, 'S3Client::download must be defined in a file');

        $lines  = file($file);
        $this->assertNotFalse($lines, 'must be able to read S3Client source');
        $body   = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        // Strip line comments and block comments so explanatory prose mentioning
        // CURLOPT_RETURNTRANSFER doesn't trip the regression assertion below.
        $code = preg_replace('~/\*.*?\*/~s', '', $body) ?? $body;
        $code = preg_replace('~//[^\n]*~', '', $code) ?? $code;
        $code = preg_replace('~^\s*\*[^\n]*$~m', '', $code) ?? $code;

        $this->assertMatchesRegularExpression(
            '/CURLOPT_FILE\s*=>/',
            $code,
            'S3Client::download must use CURLOPT_FILE to redirect the response body to a file handle'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_RETURNTRANSFER\s*=>/',
            $code,
            'S3Client::download MUST NOT pass CURLOPT_RETURNTRANSFER alongside CURLOPT_FILE — '
            . 'on PHP 8.4+ the explicit value (even false, the default) overrides CURLOPT_FILE and '
            . 'streams the response body to stdout instead of the file handle. See #788.'
        );
    }
}
