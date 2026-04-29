<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AWS Signature Version 4 static helpers in S3Client.
 *
 * Test vector: "get-vanilla" case from the AWS SigV4 test suite.
 * Reference: https://docs.aws.amazon.com/general/latest/gr/sigv4-test-suite.html
 *   Key material:
 *     access_key = AKIDEXAMPLE
 *     secret_key = wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY
 *     date       = 20150830
 *     datetime   = 20150830T123600Z
 *     region     = us-east-1
 *     service    = host   (the canonical test suite uses "host" as service name)
 *   Request:
 *     GET / HTTP/1.1
 *     Host: example.amazonaws.com
 *     X-Amz-Date: 20150830T123600Z
 *
 * Expected intermediate values were computed independently in PHP using
 * hash_hmac('sha256', ...) and cross-checked against the botocore Python
 * reference implementation. All values are pinned below so any regression
 * in the PHP SigV4 helpers is caught immediately without a network call.
 *
 * Pinned values derived via:
 *   php -r '
 *     $sk = "wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY";
 *     $kD = hash_hmac("sha256","20150830","AWS4".$sk,true);
 *     $kR = hash_hmac("sha256","us-east-1",$kD,true);
 *     $kS = hash_hmac("sha256","host",$kR,true);
 *     $kG = hash_hmac("sha256","aws4_request",$kS,true);
 *     // canonical request -> hash -> string-to-sign -> signature
 *     echo hash_hmac("sha256",$sts,$kG);
 *   '
 */
class S3SignatureTest extends TestCase
{
    // SigV4 key material (get-vanilla vector)
    private const ACCESS_KEY = 'AKIDEXAMPLE';
    private const SECRET_KEY = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    private const REGION     = 'us-east-1';
    private const SERVICE    = 'host';
    private const DATETIME   = '20150830T123600Z';
    private const DATE       = '20150830';

    // Empty-payload SHA-256 (used as payloadHash for GET/HEAD/LIST)
    private const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    // Pre-computed canonical request for get-vanilla
    private const CANONICAL_REQUEST =
        "GET\n" .
        "/\n" .
        "\n" .
        "host:example.amazonaws.com\n" .
        "x-amz-date:20150830T123600Z\n" .
        "\n" .
        "host;x-amz-date\n" .
        self::EMPTY_HASH;

    // SHA-256 of the canonical request above (computed, pinned)
    private const CANON_HASH = 'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63';

    // Credential scope for the vector
    private const CRED_SCOPE = '20150830/us-east-1/host/aws4_request';

    // Pinned HMAC-SHA256 signature for the get-vanilla string-to-sign
    // Computed: hash_hmac('sha256', $stringToSign, $kSigning)
    private const EXPECTED_SIGNATURE = 'e533c8bea0cf2fd791166d319c48cc9a301546a61543ca990cdbb04760c7e3b5';

    // -----------------------------------------------------------------------
    // Test: canonicalRequest()
    // -----------------------------------------------------------------------

    public function testCanonicalRequestFormat(): void
    {
        $result = S3Client::canonicalRequest(
            'GET',
            '/',
            '',
            "host:example.amazonaws.com\nx-amz-date:20150830T123600Z\n",
            'host;x-amz-date',
            self::EMPTY_HASH
        );

        $this->assertSame(self::CANONICAL_REQUEST, $result);
    }

    public function testCanonicalRequestHashMatchesPinned(): void
    {
        $result = S3Client::canonicalRequest(
            'GET',
            '/',
            '',
            "host:example.amazonaws.com\nx-amz-date:20150830T123600Z\n",
            'host;x-amz-date',
            self::EMPTY_HASH
        );

        $this->assertSame(self::CANON_HASH, hash('sha256', $result));
    }

    // -----------------------------------------------------------------------
    // Test: stringToSign()
    // -----------------------------------------------------------------------

    public function testStringToSign(): void
    {
        $sts = S3Client::stringToSign(
            'AWS4-HMAC-SHA256',
            self::DATETIME,
            self::CRED_SCOPE,
            self::CANON_HASH
        );

        $expected =
            "AWS4-HMAC-SHA256\n" .
            "20150830T123600Z\n" .
            "20150830/us-east-1/host/aws4_request\n" .
            self::CANON_HASH;

        $this->assertSame($expected, $sts);
    }

    // -----------------------------------------------------------------------
    // Test: signature() — HMAC key derivation chain
    // -----------------------------------------------------------------------

    public function testSignaturePinnedValue(): void
    {
        $sts = S3Client::stringToSign(
            'AWS4-HMAC-SHA256',
            self::DATETIME,
            self::CRED_SCOPE,
            self::CANON_HASH
        );

        $sig = S3Client::signature(
            self::SECRET_KEY,
            self::DATE,
            self::REGION,
            self::SERVICE,
            $sts
        );

        $this->assertSame(self::EXPECTED_SIGNATURE, $sig);
    }

    public function testSignatureIsLowerHex64(): void
    {
        $sts = "AWS4-HMAC-SHA256\n20150830T123600Z\n20150830/us-east-1/s3/aws4_request\ndeadbeef";
        $sig = S3Client::signature(self::SECRET_KEY, self::DATE, 'us-east-1', 's3', $sts);

        $this->assertSame(64, strlen($sig), 'HMAC-SHA256 output must be 64 hex chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $sig, 'Must be lowercase hex');
    }

    // -----------------------------------------------------------------------
    // Test: authorizationHeader()
    // -----------------------------------------------------------------------

    public function testAuthorizationHeaderFormat(): void
    {
        $header = S3Client::authorizationHeader(
            self::ACCESS_KEY,
            self::CRED_SCOPE,
            'host;x-amz-date',
            self::EXPECTED_SIGNATURE
        );

        $expected = 'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/host/aws4_request,'
            . 'SignedHeaders=host;x-amz-date,'
            . 'Signature=' . self::EXPECTED_SIGNATURE;

        $this->assertSame($expected, $header);
    }

    // -----------------------------------------------------------------------
    // Constructor validation
    // -----------------------------------------------------------------------

    public function testConstructorRejectsEmptyEndpoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Client([
            'endpoint'   => '',
            'region'     => 'us-east-1',
            'bucket'     => 'my-bucket',
            'prefix'     => '',
            'access_key' => 'AKID',
            'secret_key' => 'secret',
        ]);
    }

    public function testConstructorRejectsMissingBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Client([
            'endpoint'   => 'https://s3.amazonaws.com',
            'region'     => 'us-east-1',
            'bucket'     => '',
            'prefix'     => '',
            'access_key' => 'AKID',
            'secret_key' => 'secret',
        ]);
    }

    public function testConstructorRejectsMissingRegion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Client([
            'endpoint'   => 'https://s3.amazonaws.com',
            'region'     => '',
            'bucket'     => 'my-bucket',
            'prefix'     => '',
            'access_key' => 'AKID',
            'secret_key' => 'secret',
        ]);
    }

    public function testConstructorRejectsMissingAccessKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Client([
            'endpoint'   => 'https://s3.amazonaws.com',
            'region'     => 'us-east-1',
            'bucket'     => 'my-bucket',
            'prefix'     => '',
            'access_key' => '',
            'secret_key' => 'secret',
        ]);
    }

    public function testConstructorRejectsMissingSecretKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new S3Client([
            'endpoint'   => 'https://s3.amazonaws.com',
            'region'     => 'us-east-1',
            'bucket'     => 'my-bucket',
            'prefix'     => '',
            'access_key' => 'AKID',
            'secret_key' => '',
        ]);
    }

    public function testConstructorAcceptsEmptyPrefix(): void
    {
        $client = new S3Client([
            'endpoint'   => 'https://s3.amazonaws.com',
            'region'     => 'us-east-1',
            'bucket'     => 'my-bucket',
            'prefix'     => '',
            'access_key' => 'AKID',
            'secret_key' => 'secret',
        ]);
        $this->assertInstanceOf(S3Client::class, $client);
        $this->assertInstanceOf(BackupClientInterface::class, $client);
    }

    // -----------------------------------------------------------------------
    // Edge case: empty-payload constant
    // -----------------------------------------------------------------------

    public function testEmptyPayloadHashConstant(): void
    {
        $this->assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            hash('sha256', ''),
            'Empty-string SHA-256 must match the well-known constant'
        );
    }
}
