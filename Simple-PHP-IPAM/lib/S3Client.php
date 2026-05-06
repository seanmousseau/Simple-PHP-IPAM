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
        // v3.25.0 #852 range-resume: try up to 3 attempts, appending to a
        // sidecar partial file via Range: bytes=offset- on retries. Sidecar
        // persists across calls when this method throws so a subsequent
        // invocation finishes the transfer; successful completion always
        // cleans up. If the server returns 200 with a non-zero offset
        // request (Range ignored), the sidecar is rewritten from scratch.
        $sidecarPath = $destPath . '.partial';
        $maxAttempts = 3;
        $lastError   = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $resumeOffset = 0;
            if (is_file($sidecarPath)) {
                $size = @filesize($sidecarPath);
                if (is_int($size) && $size > 0) {
                    $resumeOffset = $size;
                }
            }

            try {
                $result = $this->downloadAttempt($remoteName, $sidecarPath, $resumeOffset);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                if ($attempt < $maxAttempts) {
                    usleep(200000);
                    continue;
                }
                // Preserve the sidecar across thrown failures so the next
                // download() invocation resumes from the partial bytes.
                // (CR #1096 major finding 2026-05-06.) downloadAttempt only
                // unlinks the sidecar when it explicitly resets it for
                // server-ignored-Range or non-retryable HTTP — its own
                // throw paths leave the sidecar intact already.
                throw $e;
            }

            if ($result === 'not_found') {
                @unlink($sidecarPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $sidecarPath is locally-derived from $destPath
                return false;
            }
            if ($result === 'complete' || $result === 'restart_full') {
                if (!@rename($sidecarPath, $destPath)) {
                    @unlink($sidecarPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- $sidecarPath is locally-derived from $destPath
                    throw new RuntimeException('S3Client::download: cannot finalize destination');
                }
                return true;
            }
        }

        // All attempts produced an unexpected return value (none of
        // complete / restart_full / not_found and no exception). Treat as
        // exhausted; preserve sidecar for cross-call resume.
        throw new RuntimeException("S3Client::download: exhausted retries (last: {$lastError})");
    }

    /**
     * Single signed-GET attempt for download(). Returns one of:
     *   'complete', 'restart_full', 'not_found'.
     * Throws RuntimeException on transport errors or non-2xx/non-404 codes.
     */
    private function downloadAttempt(string $remoteName, string $sidecarPath, int $resumeOffset): string
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

        if ($resumeOffset > 0) {
            $headers['range'] = 'bytes=' . $resumeOffset . '-';
            // SigV4 requires lexicographic ordering of CanonicalHeaders +
            // SignedHeaders. PHP preserves insertion order so 'range' lands
            // last; ksort restores 'host;range;x-amz-content-sha256;x-amz-date'.
            // Without this S3 rejects with SignatureDoesNotMatch (CR #1096
            // critical finding 2026-05-06).
            ksort($headers);
            // Re-sign with the new header set so SigV4 covers it.
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
            $sts   = self::stringToSign('AWS4-HMAC-SHA256', $datetime, $credScope, hash('sha256', $canonReq));
            $sig   = self::signature($this->secretKey, $date, $this->region, 's3', $sts);
            $authz = self::authorizationHeader($this->accessKey, $credScope, $signedHeaders, $sig);
            $curlHeaders = $this->buildCurlHeaders($headers, $authz);
        }

        $mode = $resumeOffset > 0 ? 'ab' : 'wb';
        $fh = fopen($sidecarPath, $mode);
        if ($fh === false) {
            throw new RuntimeException('S3Client::download: cannot open partial sidecar for writing');
        }

        $statusCode = 0;
        $headerCb = function ($_curl, string $hdr) use (&$statusCode): int {
            $line = trim($hdr);
            if (strncasecmp($line, 'HTTP/', 5) === 0) {
                $parts = explode(' ', $line, 3);
                if (isset($parts[1]) && ctype_digit($parts[1])) {
                    $statusCode = (int) $parts[1];
                }
            }
            return strlen($hdr);
        };

        $ch = curl_init();
        $errno  = 0;
        $errmsg = '';
        $code   = 0;
        try {
            // CURLOPT_FILE redirects body bytes into $fh. Must NOT also set
            // CURLOPT_RETURNTRANSFER explicitly — see download() history note.
            curl_setopt_array($ch, [
                CURLOPT_URL            => $this->nonEmptyUrl($url),
                CURLOPT_HTTPGET        => true,
                CURLOPT_HTTPHEADER     => $curlHeaders,
                CURLOPT_FILE           => $fh,
                CURLOPT_HEADERFUNCTION => $headerCb,
                CURLOPT_FAILONERROR    => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 600,
            ]);
            $ok     = curl_exec($ch);
            $errno  = curl_errno($ch);
            $errmsg = curl_error($ch);
            $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code === 0 && $statusCode > 0) {
                $code = $statusCode;
            }
        } finally {
            curl_close($ch);
            fclose($fh);
        }

        if ($ok === false || $errno !== 0) {
            throw new RuntimeException("S3Client::download: curl error ({$errno}): {$errmsg}");
        }

        if ($code === 206) {
            return 'complete';
        }
        if ($code === 200) {
            if ($resumeOffset > 0) {
                // Server ignored Range: it returned the full body and we
                // appended it after stale bytes [0..$resumeOffset). Re-extract
                // the appended body to the front of the sidecar.
                $bodySize = @filesize($sidecarPath);
                $bodyOnly = is_int($bodySize) ? max(0, $bodySize - $resumeOffset) : 0;
                if ($bodyOnly > 0 && @rename($sidecarPath, $sidecarPath . '.swap')) {
                    $src = @fopen($sidecarPath . '.swap', 'rb');
                    $dst = @fopen($sidecarPath, 'wb');
                    if ($src !== false && $dst !== false) {
                        @fseek($src, $resumeOffset);
                        stream_copy_to_stream($src, $dst);
                        fclose($src);
                        fclose($dst);
                    }
                    @unlink($sidecarPath . '.swap'); // nosemgrep: php.lang.security.unlink-use.unlink-use -- locally-derived path
                    return 'restart_full';
                }
                @unlink($sidecarPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- locally-derived path
                throw new RuntimeException('S3Client::download: server ignored Range; sidecar reset');
            }
            return 'complete';
        }
        if ($code === 404) {
            @unlink($sidecarPath); // nosemgrep: php.lang.security.unlink-use.unlink-use -- locally-derived path
            return 'not_found';
        }

        // Non-2xx/non-404: preserve the sidecar so cross-call resume can
        // pick up where this attempt left off. (CR #1096 major finding.)
        throw new RuntimeException("S3Client::download: HTTP {$code}");
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
    public function listObjects(): array
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
                $key      = (string) $item->Key;
                // Strip exact prefix string (NOT a character mask — ltrim was wrong).
                $name     = ($this->prefix !== '' && str_starts_with($key, $this->prefix))
                    ? substr($key, strlen($this->prefix))
                    : $key;
                $results[] = [
                    'name'          => $name,
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
    /**
     * Build an AWS SigV4 canonical request.
     *
     * **Contract:** `$headers` MUST end with a single `\n` — i.e. the output of
     * `buildCanonicalHeaders()`, where every header line (including the last)
     * is terminated with `\n`. The implementation relies on this so the implode
     * separator alone produces the spec-mandated `\n\n` between the headers
     * block and SignedHeaders. Passing headers without a trailing `\n` will
     * produce a one-newline boundary and fail signing on every S3 server.
     */
    public static function canonicalRequest(
        string $method,
        string $uri,
        string $queryString,
        string $headers,
        string $signedHeaders,
        string $payloadHash
    ): string {
        // Per AWS SigV4 spec: each canonical-header line ends in \n (terminator), then
        // a single \n separates the headers block from SignedHeaders. Since $headers
        // already terminates with \n, implode's separator alone yields the required
        // \n\n. An extra '' element here would emit \n\n\n and break every signature.
        // Verified against the AWS SigV4 official test suite (tests/S3CanonicalRequestTest).
        return implode("\n", [
            $method,
            $uri,
            $queryString,
            $headers,
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
        // Truncate to 500 chars and redact only known-sensitive content. The
        // previous blanket `[A-Za-z0-9+/]{20,}` rule destroyed anything long
        // and alphanumeric — bucket names without dashes, S3 keys, the error
        // Code itself ("SignatureDoesNotMatch" is 21 chars). Two-stage
        // approach now: (1) context-aware element redaction; (2) defense in
        // depth against 64-char hex (HMAC-SHA256 signature length).
        $truncated = substr($body, 0, 500);
        $sensitive = '(Signature|AWSAccessKeyId|SignatureProvided|StringToSign|StringToSignBytes|CanonicalRequest)';
        $redacted  = preg_replace(
            '#<' . $sensitive . '\b[^>]*>[^<]*</\1>#i',
            '<$1>[redacted]</$1>',
            $truncated
        );
        $redacted = preg_replace('/\b[a-fA-F0-9]{64}\b/', '[redacted]', $redacted ?? $truncated);
        return is_string($redacted) ? $redacted : '[unparseable error]';
    }
}
