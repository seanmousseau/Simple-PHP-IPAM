<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * Regression for #795 — S3Client::sanitizeErrorBody() previously redacted any
 * 20+ char run of [A-Za-z0-9+/], which silently destroyed bucket names,
 * request IDs, and other diagnostic content in S3 error responses. The
 * tightened version uses context-aware redaction (known-sensitive XML
 * elements) plus a 64-hex-char defense-in-depth filter for HMAC-SHA256
 * signatures.
 */
final class S3ErrorRedactionTest extends TestCase
{
    /** @return string */
    private function invoke(string $body): string
    {
        // PHP 8.1+: ReflectionMethod no longer requires setAccessible() for
        // private methods; setAccessible() is a no-op deprecated in 8.5.
        $rm = new ReflectionMethod(S3Client::class, 'sanitizeErrorBody');
        // Construct minimally — sanitizeErrorBody does not read instance state
        $client = new S3Client([
            'endpoint' => 'https://s3.example.com',
            'bucket' => 'b',
            'region' => 'us-east-1',
            'access_key' => 'k',
            'secret_key' => 's',
        ]);
        $out = $rm->invoke($client, $body);
        $this->assertIsString($out);
        return $out;
    }

    public function testParsableXmlErrorReturnsCodeAndMessage(): void
    {
        $body = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error>"
              . "<Code>NoSuchKey</Code>"
              . "<Message>The specified key does not exist.</Message>"
              . "<Key>my-prod-bucket-2026/backups/ipam-20260430.sql.gz</Key>"
              . "<RequestId>9F37D9F89C123456</RequestId>"
              . "</Error>";
        $out = $this->invoke($body);
        $this->assertSame('NoSuchKey: The specified key does not exist.', $out);
    }

    public function testFallbackPreservesBucketNameInUnparseableBody(): void
    {
        // Truncated / malformed body — simplexml fails, falls into the regex path.
        $body = "<Error><Code>AccessDenied</Code><Message>Access Denied to bucket my-prod-bucket-2026</Message";
        $out = $this->invoke($body);
        $this->assertStringContainsString('my-prod-bucket-2026', $out, 'bucket name must survive fallback redaction');
        $this->assertStringContainsString('Access Denied', $out);
    }

    public function testFallbackRedacts64CharHexSignature(): void
    {
        // Realistic AWS HMAC-SHA256 signature shape (64 hex chars).
        $sig = str_repeat('a', 16) . str_repeat('1', 16) . str_repeat('b', 16) . str_repeat('2', 16);
        $this->assertSame(64, strlen($sig));
        $body = "Garbage proxy response with leaked signature {$sig} embedded";
        $out = $this->invoke($body);
        $this->assertStringNotContainsString($sig, $out, '64-char hex signature must be redacted');
        $this->assertStringContainsString('[redacted]', $out);
        $this->assertStringContainsString('Garbage proxy response', $out, 'surrounding diagnostic text must survive');
    }

    public function testFallbackRedactsSignatureXmlElement(): void
    {
        // Prepend non-XML noise so simplexml fails and the fallback path runs,
        // but keep the sensitive elements well-formed so the context-aware
        // redaction can match them (truncated tags would not match — that case
        // is covered by the 64-hex defense-in-depth filter).
        $body = "HTTP/1.1 403 Forbidden\nServer: nginx\n\n"
              . "<Error><Code>SignatureDoesNotMatch</Code>"
              . "<AWSAccessKeyId>AKIAEXAMPLEKEY12345</AWSAccessKeyId>"
              . "<Signature>thisIsAnAwsSignatureValueThatShouldBeRedactedNow</Signature>"
              . "</Error>";
        $out = $this->invoke($body);
        $this->assertStringNotContainsString('thisIsAnAwsSignatureValueThatShouldBeRedactedNow', $out);
        $this->assertStringNotContainsString('AKIAEXAMPLEKEY12345', $out);
        $this->assertStringContainsString('SignatureDoesNotMatch', $out, 'error code itself must survive');
    }

    public function testNonHexLongRunsArePreserved(): void
    {
        // Long alphanumeric runs that are NOT 64-char hex — typical of S3 keys,
        // bucket names with timestamps, ETags (32-hex but with quotes).
        $body = "<Message>The object key prod-server-cluster-2026-04-30T08-15-22Z-backup.sql.gz was not found</Message";
        $out = $this->invoke($body);
        $this->assertStringContainsString('prod-server-cluster-2026-04-30T08-15-22Z-backup.sql.gz', $out);
    }
}
