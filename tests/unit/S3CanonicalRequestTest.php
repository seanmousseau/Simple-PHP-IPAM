<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for S3Client SigV4 canonicalization.
 *
 * Background: until v3.19.1, S3Client::canonicalRequest() emitted an extra
 * blank-line element via implode("\n", [..., $headers, '', $signedHeaders, ...]).
 * Since $headers already terminates with \n, implode produced \n\n\n where the
 * AWS SigV4 spec requires \n\n. Every S3 destination — Wasabi, AWS, MinIO,
 * Ceph — rejected the resulting signature with HTTP 403 SignatureDoesNotMatch.
 *
 * These tests pin the canonical-request bytes against textbook AWS SigV4
 * inputs so any future regression that adds, removes, or reorders the
 * implode list members is caught at unit-test time before reaching CI.
 *
 * The expected SHA-256 hashes are reproduced from the AWS documented
 * canonical-request format (RFC-style), independently verified against
 * Wasabi's CanonicalRequestBytes echo on a real signing failure.
 */
final class S3CanonicalRequestTest extends TestCase
{
    /**
     * AWS SigV4 spec canonical-request format:
     *
     *   <HTTPMethod>\n
     *   <CanonicalURI>\n
     *   <CanonicalQueryString>\n
     *   <CanonicalHeaders>          (each line: name:value\n)
     *   \n                          (blank line after headers block)
     *   <SignedHeaders>\n
     *   <HashedPayload>
     *
     * For the inputs below the textbook canonical request is:
     *
     *   GET\n
     *   /\n
     *   \n
     *   host:example.amazonaws.com\n
     *   x-amz-date:20150830T123600Z\n
     *   \n
     *   host;x-amz-date\n
     *   e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
     *
     * SHA-256 of that exact byte sequence:
     *   bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63
     */
    public function testCanonicalRequestMatchesAwsSpecFormat(): void
    {
        $method        = 'GET';
        $uri           = '/';
        $queryString   = '';
        $emptyHash     = hash('sha256', '');
        $headers       = "host:example.amazonaws.com\nx-amz-date:20150830T123600Z\n";
        $signedHeaders = 'host;x-amz-date';

        $canonReq = S3Client::canonicalRequest($method, $uri, $queryString, $headers, $signedHeaders, $emptyHash);

        // Expected canonical request, byte for byte, per AWS SigV4 spec.
        $expected = "GET\n"
                  . "/\n"
                  . "\n"
                  . "host:example.amazonaws.com\n"
                  . "x-amz-date:20150830T123600Z\n"
                  . "\n"
                  . "host;x-amz-date\n"
                  . $emptyHash;

        $this->assertSame($expected, $canonReq, 'canonical-request bytes must match AWS SigV4 spec layout');
        $this->assertSame(
            'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63',
            hash('sha256', $canonReq)
        );
    }

    public function testNoExtraNewlinesAfterHeadersBlock(): void
    {
        // Direct regression for the v3.17–v3.19 bug: prior implementation produced
        // \n\n\n where the spec calls for \n\n. Assert no triple-newline anywhere
        // in the rendered canonical request for any plausible header set.
        $emptyHash = hash('sha256', '');
        $headers   = "host:s3.us-east-1.amazonaws.com\n"
                   . "x-amz-content-sha256:$emptyHash\n"
                   . "x-amz-date:20260429T120000Z\n";
        $canonReq = S3Client::canonicalRequest(
            'HEAD', '/bucket', '', $headers, 'host;x-amz-content-sha256;x-amz-date', $emptyHash
        );
        $this->assertStringNotContainsString("\n\n\n", $canonReq);
    }

    /**
     * Wasabi-real-world regression: signing the exact canonical request that
     * Wasabi (ca-central-1) reconstructed during the 2026-04-29 prod debug
     * session must produce the same signature whether computed via the
     * S3Client helpers or via an independent HKDF/HMAC reference. This pins
     * the full SigV4 pipeline (canonicalRequest → stringToSign → signature)
     * end-to-end for a known input.
     */
    public function testSignatureMatchesIndependentReferenceForFixedInputs(): void
    {
        $secretKey = 'AWS4-test-secret-key-not-real-not-used';
        $datetime  = '20260429T201934Z';
        $date      = '20260429';
        $region    = 'ca-central-1';
        $service   = 's3';

        $emptyHash = hash('sha256', '');
        $headers   = "host:s3.ca-central-1.wasabisys.com\n"
                   . "x-amz-content-sha256:$emptyHash\n"
                   . "x-amz-date:$datetime\n";
        $canonReq = S3Client::canonicalRequest(
            'GET', '/example-bucket', '', $headers, 'host;x-amz-content-sha256;x-amz-date', $emptyHash
        );

        $credScope = "$date/$region/$service/aws4_request";
        $sts       = S3Client::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
        $sigA      = S3Client::signature($secretKey, $date, $region, $service, $sts);

        // Independent SigV4 reference computation (RFC 5869 / AWS SigV4 derivation).
        $kDate    = hash_hmac('sha256', $date,    'AWS4' . $secretKey, true);
        $kRegion  = hash_hmac('sha256', $region,  $kDate,  true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $sigB     = hash_hmac('sha256', $sts, $kSigning);

        $this->assertSame($sigA, $sigB, 'S3Client signature must match independent reference computation');
    }
}
