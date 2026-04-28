<?php
declare(strict_types=1);

/**
 * Hand-rolled AWS Signature Version 4 S3-compatible client.
 *
 * Introduced in v3.17.0 (#692) as the S3 transport for the web-based
 * backup/restore feature. Implements BackupClientInterface.
 *
 * Design decisions:
 *   - Hand-rolled rather than using an SDK — per CLAUDE.md dep policy, S3 is
 *     narrow enough (4 operations + test) that implementing Sig V4 in ~300
 *     lines is safer than a heavy SDK with transitive deps.
 *   - Uses ext-curl for all HTTP I/O; ext-curl is always available.
 *   - Streaming upload: CURLOPT_INFILE avoids buffering large files in memory.
 *   - CURLOPT_FAILONERROR = false everywhere so we can read the 4xx body.
 *   - URL style: path-style (`endpoint/bucket/key`) for maximum compatibility
 *     with MinIO, Ceph, Wasabi, and other S3-compatible services. AWS
 *     virtual-hosted style (`bucket.endpoint/key`) is only used when the
 *     endpoint is the AWS S3 global endpoint or a region endpoint (detected
 *     by the bucket name having no dots and the endpoint matching *.amazonaws.com).
 *
 * Sig V4 reference:
 *   https://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
 *
 * No namespace — project convention (see CLAUDE.md "Namespaces are not used").
 */
class S3Client implements BackupClientInterface
{
    // SHA-256 of the empty string — used as payloadHash for GET/HEAD/DELETE/LIST
    private const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $prefix;
    private string $accessKey;
    private string $secretKey;
    /** true = virtual-hosted style (bucket.host/key), false = path-style (host/bucket/key) */
    private bool $virtualHosted;

    /**
     * @param array<string,mixed> $cfg Decoded destination config JSON with keys:
     *   endpoint   string  e.g. 'https://s3.amazonaws.com' or 'http://minio:9000'
     *   region     string  e.g. 'us-east-1'
     *   bucket     string
     *   prefix     string  trailing-slash prefix, e.g. 'ipam/' or ''
     *   access_key string
     *   secret_key string
     * @throws InvalidArgumentException on missing / empty required keys
     */
    public function __construct(array $cfg)
    {
        // Validate and extract each required key into a typed local variable.
        // We cannot use (string) casts on mixed array values at level 9, so we
        // validate with is_string() first and assign from the narrowed local.
        $endpoint  = $cfg['endpoint']   ?? '';
        $region    = $cfg['region']     ?? '';
        $bucket    = $cfg['bucket']     ?? '';
        $accessKey = $cfg['access_key'] ?? '';
        $secretKey = $cfg['secret_key'] ?? '';

        if (!is_string($endpoint)  || $endpoint  === '') {
            throw new InvalidArgumentException("S3Client: missing or empty required config key 'endpoint'");
        }
        if (!is_string($region)    || $region    === '') {
            throw new InvalidArgumentException("S3Client: missing or empty required config key 'region'");
        }
        if (!is_string($bucket)    || $bucket    === '') {
            throw new InvalidArgumentException("S3Client: missing or empty required config key 'bucket'");
        }
        if (!is_string($accessKey) || $accessKey === '') {
            throw new InvalidArgumentException("S3Client: missing or empty required config key 'access_key'");
        }
        if (!is_string($secretKey) || $secretKey === '') {
            throw new InvalidArgumentException("S3Client: missing or empty required config key 'secret_key'");
        }

        $this->endpoint  = rtrim($endpoint, '/');
        $this->region    = $region;
        $this->bucket    = $bucket;
        $this->prefix    = isset($cfg['prefix']) && is_string($cfg['prefix']) ? $cfg['prefix'] : '';
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;

        // Use virtual-hosted style only for AWS S3 endpoints (*.amazonaws.com
        // without a port number and a bucket name that contains no dots).
        // Everything else (MinIO, Ceph, custom ports) uses path-style.
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $this->virtualHosted = (
            str_ends_with($host, '.amazonaws.com') &&
            strpos($this->bucket, '.') === false &&
            parse_url($this->endpoint, PHP_URL_PORT) === null
        );
    }

    // -----------------------------------------------------------------------
    // BackupClientInterface implementation
    // -----------------------------------------------------------------------

    /**
     * {@inheritDoc}
     *
     * PUT object to S3. Streams from disk — no full-file buffering.
     *
     * @return array{size:int,checksum:string}
     * @throws RuntimeException on non-2xx response
     */
    public function upload(string $localPath, string $remoteName): array
    {
        if (!is_readable($localPath)) {
            throw new RuntimeException("S3Client::upload: cannot read local file");
        }

        $size        = filesize($localPath);
        $payloadHash = hash_file('sha256', $localPath);
        if ($size === false || $payloadHash === false) {
            throw new RuntimeException("S3Client::upload: cannot stat or hash local file");
        }

        $objectKey = $this->prefix . $remoteName;
        $url       = $this->objectUrl($objectKey);
        $datetime  = $this->utcDatetime();
        $date      = substr($datetime, 0, 8);
        $host      = (string) parse_url($url, PHP_URL_HOST);

        $headers = [
            'content-length' => (string) $size,
            'content-type'   => 'application/octet-stream',
            'host'           => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'    => $datetime,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonHeaders  = $this->buildCanonicalHeaders($headers);

        $canonReq = self::canonicalRequest(
            'PUT',
            $this->urlPath($url),
            (string) parse_url($url, PHP_URL_QUERY),
            $canonHeaders,
            $signedHeaders,
            $payloadHash
        );

        $credScope = $this->credentialScope($date, 's3');
        $sts       = self::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
        $sig       = self::signature($this->secretKey, $date, $this->region, 's3', $sts);
        $authz     = self::authorizationHeader($this->accessKey, $credScope, $signedHeaders, $sig);

        $curlHeaders = $this->buildCurlHeaders($headers, $authz);

        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException("S3Client::upload: fopen failed");
        }

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $this->nonEmptyUrl($url),
                CURLOPT_PUT            => true,
                CURLOPT_INFILE         => $fh,
                CURLOPT_INFILESIZE     => $size,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR    => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 300,
            ]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
            fclose($fh);
        }

        if ($code < 200 || $code >= 300) {
            // Strip credentials from error: include only HTTP code + sanitized XML
            $safe = $this->sanitizeErrorBody($body);
            throw new RuntimeException("S3Client::upload: HTTP $code — $safe");
        }

        return ['size' => $size, 'checksum' => $payloadHash];
    }

    /**
     * {@inheritDoc}
     *
     * GET object from S3. Streams to $destPath.
     * Returns false on 404; throws on other errors.
     *
     * On failure the partially-written $destPath file is left in place.
     * The caller is responsible for cleanup — RestoreEngine wraps every
     * download in a try/finally that unlinks the temp file. Cleanup is not
     * done here because any unlink() on a caller-supplied path would require
     * path-traversal validation that belongs at the call site, not inside
     * a transport client.
     *
     * @throws RuntimeException on non-2xx/non-404 response or curl error
     */
    public function download(string $remoteName, string $destPath): bool
    {
        $objectKey = $this->prefix . $remoteName;
        $url       = $this->objectUrl($objectKey);
        $datetime  = $this->utcDatetime();
        $date      = substr($datetime, 0, 8);
        $host      = (string) parse_url($url, PHP_URL_HOST);

        $headers = [
            'host'       => $host,
            'x-amz-content-sha256' => self::EMPTY_HASH,
            'x-amz-date' => $datetime,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonHeaders  = $this->buildCanonicalHeaders($headers);

        $canonReq = self::canonicalRequest(
            'GET',
            $this->urlPath($url),
            (string) parse_url($url, PHP_URL_QUERY),
            $canonHeaders,
            $signedHeaders,
            self::EMPTY_HASH
        );

        $credScope = $this->credentialScope($date, 's3');
        $sts       = self::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
        $sig       = self::signature($this->secretKey, $date, $this->region, 's3', $sts);
        $authz     = self::authorizationHeader($this->accessKey, $credScope, $signedHeaders, $sig);

        $curlHeaders = $this->buildCurlHeaders($headers, $authz);

        $fh = fopen($destPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException("S3Client::download: cannot open destination for writing");
        }

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $this->nonEmptyUrl($url),
                CURLOPT_HTTPGET        => true,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_FILE           => $fh,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FAILONERROR    => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 600,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
            fclose($fh);
        }

        if ($code === 200) {
            return true;
        }

        if ($code === 404) {
            return false;
        }

        throw new RuntimeException("S3Client::download: HTTP $code");
    }

    /**
     * {@inheritDoc}
     *
     * ListObjectsV2 with paging. Handles >1000 objects via IsTruncated +
     * NextContinuationToken as required by the S3 API. Filters by $prefix so
     * only objects under this destination's prefix are returned.
     *
     * @return list<array{name:string,size:int,last_modified:string,checksum:?string}>
     * @throws RuntimeException on HTTP error or XML parse failure
     */
    public function list(): array
    {
        $results           = [];
        $continuationToken = null;

        do {
            $query = 'list-type=2&prefix=' . rawurlencode($this->prefix);
            if ($continuationToken !== null) {
                $query .= '&continuation-token=' . rawurlencode($continuationToken);
            }

            $url      = $this->bucketUrl() . '?' . $query;
            $datetime = $this->utcDatetime();
            $date     = substr($datetime, 0, 8);
            $host     = (string) parse_url($url, PHP_URL_HOST);

            $headers = [
                'host'       => $host,
                'x-amz-content-sha256' => self::EMPTY_HASH,
                'x-amz-date' => $datetime,
            ];

            $signedHeaders = implode(';', array_keys($headers));
            $canonHeaders  = $this->buildCanonicalHeaders($headers);

            // Query string must be canonicalized in sorted order for signing
            $canonQuery = $this->buildCanonicalQueryString($query);

            $canonReq = self::canonicalRequest(
                'GET',
                $this->urlPath($this->bucketUrl()),
                $canonQuery,
                $canonHeaders,
                $signedHeaders,
                self::EMPTY_HASH
            );

            $credScope = $this->credentialScope($date, 's3');
            $sts       = self::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
            $sig       = self::signature($this->secretKey, $date, $this->region, 's3', $sts);
            $authz     = self::authorizationHeader($this->accessKey, $credScope, $signedHeaders, $sig);

            $curlHeaders = $this->buildCurlHeaders($headers, $authz);

            $ch = curl_init();
            try {
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $this->nonEmptyUrl($url),
                    CURLOPT_HTTPGET        => true,
                    CURLOPT_HTTPHEADER     => $curlHeaders,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FAILONERROR    => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_TIMEOUT        => 60,
                ]);
                $body = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            } finally {
                curl_close($ch);
            }

            if ($code !== 200) {
                throw new RuntimeException("S3Client::list: HTTP $code — " . $this->sanitizeErrorBody($body));
            }

            $xml = @simplexml_load_string($body);
            if (!($xml instanceof SimpleXMLElement)) {
                throw new RuntimeException("S3Client::list: failed to parse XML response");
            }

            foreach ($xml->Contents as $item) {
                $etag     = isset($item->ETag) ? trim((string) $item->ETag, '"') : null;
                $results[] = [
                    'name'          => ltrim((string) $item->Key, $this->prefix),
                    'size'          => (int) (string) $item->Size,
                    'last_modified' => (string) $item->LastModified,
                    'checksum'      => $etag !== '' ? $etag : null,
                ];
            }

            $isTruncated       = strtolower((string) $xml->IsTruncated) === 'true';
            $continuationToken = $isTruncated && isset($xml->NextContinuationToken)
                ? (string) $xml->NextContinuationToken
                : null;
        } while ($continuationToken !== null);

        return $results;
    }

    /**
     * {@inheritDoc}
     *
     * DELETE a single object. Returns true on 204; throws otherwise.
     *
     * @throws RuntimeException on HTTP error
     */
    public function delete(string $remoteName): bool
    {
        $objectKey = $this->prefix . $remoteName;
        $url       = $this->objectUrl($objectKey);
        $datetime  = $this->utcDatetime();
        $date      = substr($datetime, 0, 8);
        $host      = (string) parse_url($url, PHP_URL_HOST);

        $headers = [
            'host'       => $host,
            'x-amz-content-sha256' => self::EMPTY_HASH,
            'x-amz-date' => $datetime,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonHeaders  = $this->buildCanonicalHeaders($headers);

        $canonReq = self::canonicalRequest(
            'DELETE',
            $this->urlPath($url),
            '',
            $canonHeaders,
            $signedHeaders,
            self::EMPTY_HASH
        );

        $credScope = $this->credentialScope($date, 's3');
        $sts       = self::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
        $sig       = self::signature($this->secretKey, $date, $this->region, 's3', $sts);
        $authz     = self::authorizationHeader($this->accessKey, $credScope, $signedHeaders, $sig);

        $curlHeaders = $this->buildCurlHeaders($headers, $authz);

        $ch = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $this->nonEmptyUrl($url),
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR    => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($ch);
        }

        if ($code === 204) {
            return true;
        }

        throw new RuntimeException("S3Client::delete: HTTP $code");
    }

    /**
     * {@inheritDoc}
     *
     * HEAD on the bucket root to verify reachability and credentials.
     * Returns latency in milliseconds. Never exposes key material in message.
     *
     * @return array{ok:bool,message:string,latency_ms:?int}
     */
    public function test(): array
    {
        $url      = $this->bucketUrl();
        $datetime = $this->utcDatetime();
        $date     = substr($datetime, 0, 8);
        $host     = (string) parse_url($url, PHP_URL_HOST);

        $headers = [
            'host'       => $host,
            'x-amz-content-sha256' => self::EMPTY_HASH,
            'x-amz-date' => $datetime,
        ];

        $signedHeaders = implode(';', array_keys($headers));
        $canonHeaders  = $this->buildCanonicalHeaders($headers);

        $canonReq = self::canonicalRequest(
            'HEAD',
            $this->urlPath($url),
            '',
            $canonHeaders,
            $signedHeaders,
            self::EMPTY_HASH
        );

        $credScope = $this->credentialScope($date, 's3');
        $sts       = self::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
        $sig       = self::signature($this->secretKey, $date, $this->region, 's3', $sts);
        $authz     = self::authorizationHeader($this->accessKey, $credScope, $signedHeaders, $sig);

        $curlHeaders = $this->buildCurlHeaders($headers, $authz);

        $start = microtime(true);
        $ch    = curl_init();
        try {
            curl_setopt_array($ch, [
                CURLOPT_URL            => $this->nonEmptyUrl($url),
                CURLOPT_NOBODY         => true,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FAILONERROR    => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
        } finally {
            curl_close($ch);
        }
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($curlErr !== '') {
            return ['ok' => false, 'message' => 'connection error', 'latency_ms' => null];
        }

        $messageMap = [
            200 => 'connected',
            403 => 'access denied — check credentials',
            404 => 'bucket not found',
            301 => 'bucket region mismatch — check region setting',
        ];

        $ok      = ($code === 200);
        $message = $messageMap[$code] ?? "unexpected HTTP $code";

        return ['ok' => $ok, 'message' => $message, 'latency_ms' => $latency];
    }

    // -----------------------------------------------------------------------
    // Static SigV4 helpers — public for unit tests
    // @internal
    // -----------------------------------------------------------------------

    /**
     * Build the AWS SigV4 canonical request string.
     *
     * @internal
     * @param string $method        HTTP method (uppercase)
     * @param string $uri           URL path (percent-encoded, slashes preserved)
     * @param string $queryString   Canonical query string (sorted, percent-encoded)
     * @param string $headers       Canonical headers block (lowercase name:value\n per header, sorted)
     * @param string $signedHeaders Semicolon-separated lowercase header names, sorted
     * @param string $payloadHash   Hex SHA-256 of the request body (EMPTY_HASH for empty body)
     */
    public static function canonicalRequest(
        string $method,
        string $uri,
        string $queryString,
        string $headers,
        string $signedHeaders,
        string $payloadHash
    ): string {
        return implode("\n", [
            $method,
            $uri,
            $queryString,
            $headers,   // already ends with \n per each header
            '',          // blank line after headers block
            $signedHeaders,
            $payloadHash,
        ]);
    }

    /**
     * Build the AWS SigV4 string-to-sign.
     *
     * @internal
     * @param string $algorithm           e.g. 'AWS4-HMAC-SHA256'
     * @param string $datetime            ISO8601 UTC e.g. '20150830T123600Z'
     * @param string $credentialScope     e.g. '20150830/us-east-1/s3/aws4_request'
     * @param string $canonicalRequestHash hex SHA-256 of the canonical request
     */
    public static function stringToSign(
        string $algorithm,
        string $datetime,
        string $credentialScope,
        string $canonicalRequestHash
    ): string {
        return implode("\n", [$algorithm, $datetime, $credentialScope, $canonicalRequestHash]);
    }

    /**
     * Derive the SigV4 signing key and compute the request signature.
     *
     * Key derivation chain: kSecret → kDate → kRegion → kService → kSigning
     * All intermediate HMACs use binary output (true); final result is hex.
     *
     * @internal
     * @return string 64-char lowercase hex HMAC-SHA256
     */
    public static function signature(
        string $secretKey,
        string $date,
        string $region,
        string $service,
        string $stringToSign
    ): string {
        $kSecret  = 'AWS4' . $secretKey;
        $kDate    = hash_hmac('sha256', $date,          $kSecret,  true);
        $kRegion  = hash_hmac('sha256', $region,        $kDate,    true);
        $kService = hash_hmac('sha256', $service,       $kRegion,  true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * Build the Authorization header value.
     *
     * @internal
     * @param string $accessKey       AWS access key ID
     * @param string $credentialScope e.g. '20150830/us-east-1/s3/aws4_request'
     * @param string $signedHeaders   semicolon-separated lowercase header names
     * @param string $signatureHex    64-char hex signature
     */
    public static function authorizationHeader(
        string $accessKey,
        string $credentialScope,
        string $signedHeaders,
        string $signatureHex
    ): string {
        return sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s,SignedHeaders=%s,Signature=%s',
            $accessKey,
            $credentialScope,
            $signedHeaders,
            $signatureHex
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * URL to the bucket root (no trailing slash, no object key).
     * Virtual-hosted: https://bucket.s3.region.amazonaws.com
     * Path-style:     https://endpoint/bucket
     */
    private function bucketUrl(): string
    {
        if ($this->virtualHosted) {
            $parsed = parse_url($this->endpoint);
            $scheme = is_array($parsed) && isset($parsed['scheme']) ? (string) $parsed['scheme'] : 'https';
            $host   = is_array($parsed) && isset($parsed['host'])   ? (string) $parsed['host']   : '';
            return $scheme . '://' . $this->bucket . '.' . $host;
        }
        return $this->endpoint . '/' . rawurlencode($this->bucket);
    }

    /**
     * Full URL for a specific object key.
     * Slashes within the key are preserved (not percent-encoded).
     */
    private function objectUrl(string $key): string
    {
        $encodedKey = $this->encodeObjectKey($key);
        return $this->bucketUrl() . '/' . $encodedKey;
    }

    /**
     * Percent-encode an S3 object key: encode each path component with
     * rawurlencode, then re-join with literal slashes.
     */
    private function encodeObjectKey(string $key): string
    {
        $parts = explode('/', $key);
        return implode('/', array_map('rawurlencode', $parts));
    }

    /**
     * Extract the URL path for use in the canonical request.
     * Returns '/' if no path component is present.
     */
    private function urlPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return (is_string($path) && $path !== '') ? $path : '/';
    }

    /**
     * Build the canonical headers string from a sorted lowercase name=>value map.
     * Each header line is "name:trimmed-value\n".
     *
     * @param array<string,string> $headers Already sorted, lowercase keys
     */
    private function buildCanonicalHeaders(array $headers): string
    {
        $out = '';
        foreach ($headers as $name => $value) {
            $out .= $name . ':' . trim($value) . "\n";
        }
        return $out;
    }

    /**
     * Build canonical query string for SigV4: sort parameters by key,
     * percent-encode both key and value, join as key=value&key=value.
     */
    private function buildCanonicalQueryString(string $rawQuery): string
    {
        if ($rawQuery === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $rawQuery) as $part) {
            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[rawurlencode(rawurldecode($k))] = rawurlencode(rawurldecode($v));
        }
        ksort($pairs);
        $out = [];
        foreach ($pairs as $k => $v) {
            $out[] = $k . '=' . $v;
        }
        return implode('&', $out);
    }

    /**
     * Build the curl HTTPHEADER array from the signed headers map plus Authorization.
     *
     * @param  array<string,string> $headers Lowercase name => value map
     * @return list<string>
     */
    private function buildCurlHeaders(array $headers, string $authorization): array
    {
        $lines = ['Authorization: ' . $authorization];
        foreach ($headers as $name => $value) {
            // Convert lowercase header name to Title-Case for HTTP/1.1 compat
            $titleName = implode('-', array_map('ucfirst', explode('-', $name)));
            $lines[]   = $titleName . ': ' . $value;
        }
        return $lines;
    }

    /**
     * Assert a URL is non-empty and return it as a non-empty-string.
     * PHPStan cannot prove that the output of bucketUrl()/objectUrl() is
     * non-empty from string concatenation alone. An explicit runtime guard
     * provides the proof without casts or suppressions.
     *
     * @return non-empty-string
     * @throws RuntimeException if the URL is somehow empty (configuration bug)
     */
    private function nonEmptyUrl(string $url): string
    {
        if ($url === '') {
            throw new RuntimeException('S3Client: constructed an empty URL — check endpoint/bucket config');
        }
        return $url;
    }

    /**
     * Credential scope: date/region/service/aws4_request
     */
    private function credentialScope(string $date, string $service): string
    {
        return "$date/{$this->region}/$service/aws4_request";
    }

    /**
     * Current UTC datetime in SigV4 format: 'Ymd\THis\Z'
     */
    private function utcDatetime(): string
    {
        return gmdate('Ymd\THis\Z');
    }

    /**
     * Strip credential and signature material from S3 error bodies.
     * Returns only the XML <Code> and <Message> if parseable, or a generic
     * truncated string. Never returns access keys or signature material.
     */
    private function sanitizeErrorBody(string $body): string
    {
        $xml = @simplexml_load_string($body);
        if ($xml instanceof SimpleXMLElement) {
            $code    = isset($xml->Code)    ? (string) $xml->Code    : '';
            $message = isset($xml->Message) ? (string) $xml->Message : '';
            if ($code !== '') {
                return "$code: $message";
            }
        }
        // Return first 200 chars of raw body stripped of anything that looks
        // like a key or signature (sequences of 20+ hex or base64 chars)
        $safe = preg_replace('/[A-Za-z0-9+\/]{20,}/', '[redacted]', substr($body, 0, 500));
        return is_string($safe) ? $safe : '[unparseable error]';
    }
}
