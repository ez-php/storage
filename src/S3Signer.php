<?php

declare(strict_types=1);

namespace EzPhp\Storage;

/**
 * Class S3Signer
 *
 * AWS Signature Version 4 for S3: signs requests (Authorization header) and
 * builds presigned GET query strings. Pure computation over the credentials it
 * was given — no I/O, no clock (the caller passes the timestamp) — so it can be
 * tested against fixed vectors independently of `S3Driver`.
 *
 * @package EzPhp\Storage
 */
final class S3Signer
{
    private const ALGO = 'AWS4-HMAC-SHA256';

    private const SERVICE = 's3';

    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * S3Signer Constructor
     *
     * @param string $key    AWS access key ID.
     * @param string $secret AWS secret access key.
     * @param string $region AWS region (e.g. us-east-1).
     */
    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $region,
    ) {
    }

    /**
     * Sign a request.
     *
     * @param non-empty-string     $method        HTTP method.
     * @param string               $objectKey     URI-encoded, leading-slash object path (see encodePath()).
     * @param string               $queryString   Canonical (sorted, RFC 3986 encoded) query string; may be empty.
     * @param array<string,string> $headers       Extra headers to sign, names already lowercased.
     * @param string               $payloadHash   Hex SHA-256 of the request body.
     * @param string               $host          Host header value.
     * @param string               $date          Full ISO8601 datetime, e.g. 20240101T120000Z.
     *
     * @return array{authorization: string, headers: array<string, string>} The Authorization header value and
     *         every signed header (including `host`, `x-amz-date`, `x-amz-content-sha256`), sorted by name.
     */
    public function signRequest(
        string $method,
        string $objectKey,
        string $queryString,
        array $headers,
        string $payloadHash,
        string $host,
        string $date,
    ): array {
        $dateShort = substr($date, 0, 8);

        $signedHeaders = array_merge($headers, [
            'host' => $host,
            'x-amz-date' => $date,
            'x-amz-content-sha256' => $payloadHash,
        ]);

        ksort($signedHeaders);

        $signedHeaderNames = implode(';', array_keys($signedHeaders));

        $canonicalHeaders = '';
        foreach ($signedHeaders as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim($value) . "\n";
        }

        $canonicalRequest = implode("\n", [
            $method,
            $objectKey,
            $queryString,
            $canonicalHeaders,
            $signedHeaderNames,
            $payloadHash,
        ]);

        $signature = $this->sign($canonicalRequest, $date, $dateShort);

        $authorization = self::ALGO
            . " Credential={$this->key}/{$this->scope($dateShort)},"
            . " SignedHeaders={$signedHeaderNames},"
            . " Signature={$signature}";

        return ['authorization' => $authorization, 'headers' => $signedHeaders];
    }

    /**
     * Build the query string of a presigned GET URL, signature included.
     *
     * @param string $host      Host header value.
     * @param string $objectKey URI-encoded, leading-slash object path (see encodePath()).
     * @param int    $expires   Validity in seconds.
     * @param string $date      Full ISO8601 datetime, e.g. 20240101T120000Z.
     *
     * @return string Query string ending in `X-Amz-Signature=<hex>`.
     */
    public function presignQuery(string $host, string $objectKey, int $expires, string $date): string
    {
        $dateShort = substr($date, 0, 8);

        $queryParams = [
            'X-Amz-Algorithm' => self::ALGO,
            'X-Amz-Credential' => "{$this->key}/{$this->scope($dateShort)}",
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];

        ksort($queryParams);
        $queryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [
            'GET',
            $objectKey,
            $queryString,
            "host:{$host}\n",
            'host',
            self::UNSIGNED_PAYLOAD,
        ]);

        return $queryString . '&X-Amz-Signature=' . $this->sign($canonicalRequest, $date, $dateShort);
    }

    /**
     * URI-percent-encode an object key path for AWS Signature V4.
     *
     * SigV4's canonical-URI construction requires each path segment to be
     * percent-encoded per RFC 3986 (uppercase hex, unreserved characters
     * A-Za-z0-9-_.~ left unencoded) before it is used in the canonical request
     * — and, since the actual request URL must match what was signed, the same
     * encoding is applied to the URL sent to S3. `/` segment separators are
     * preserved, never encoded. `rawurlencode()` already implements exactly
     * this unreserved-character set, so each segment is encoded independently
     * and rejoined — a single un-split rawurlencode() would also escape `/`.
     * S3 does not double-encode the canonical URI (unlike most other AWS
     * services), so this must be applied exactly once.
     *
     * @param string $path Leading-slash object key, e.g. '/my folder/file+name.txt'.
     *
     * @return string
     */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * Credential scope, e.g. 20240101/us-east-1/s3/aws4_request.
     *
     * @param string $dateShort Date portion only (e.g. 20240101).
     *
     * @return string
     */
    private function scope(string $dateShort): string
    {
        return "{$dateShort}/{$this->region}/" . self::SERVICE . '/aws4_request';
    }

    /**
     * Compute an AWS Signature V4 hex signature.
     *
     * @param string $canonicalRequest The canonical request string.
     * @param string $date             Full ISO8601 datetime (e.g. 20240101T120000Z).
     * @param string $dateShort        Date portion only (e.g. 20240101).
     *
     * @return string Lowercase hex signature.
     */
    private function sign(string $canonicalRequest, string $date, string $dateShort): string
    {
        $stringToSign = implode("\n", [
            self::ALGO,
            $date,
            $this->scope($dateShort),
            hash('sha256', $canonicalRequest),
        ]);

        return hash_hmac('sha256', $stringToSign, $this->signingKey($dateShort));
    }

    /**
     * Derive the AWS Signature V4 signing key for the given date and region.
     *
     * @param string $dateShort Date portion (e.g. 20240101).
     *
     * @return string Binary signing key.
     */
    private function signingKey(string $dateShort): string
    {
        $kDate = hash_hmac('sha256', $dateShort, 'AWS4' . $this->secret, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
