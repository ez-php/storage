<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\UploadedFile;

/**
 * Class S3Driver
 *
 * Stores files on S3-compatible storage (AWS S3, MinIO, Cloudflare R2, etc.)
 * using cURL and AWS Signature Version 4.
 *
 * Uses virtual-hosted-style URLs: https://{bucket}.s3.{region}.amazonaws.com/{key}.
 * A custom endpoint overrides the default AWS endpoint (e.g. for MinIO).
 *
 * @package EzPhp\Storage
 */
final class S3Driver implements StorageInterface
{
    private const ALGO = 'AWS4-HMAC-SHA256';

    private const SERVICE = 's3';

    private const UNSIGNED_PAYLOAD = 'UNSIGNED-PAYLOAD';

    /**
     * @param string      $key       AWS access key ID.
     * @param string      $secret    AWS secret access key.
     * @param string      $region    AWS region (e.g. us-east-1).
     * @param string      $bucket    S3 bucket name.
     * @param string|null $endpoint  Custom endpoint for S3-compatible services (e.g. http://minio:9000).
     * @param string|null $url       Custom public base URL (e.g. CDN). If set, url() returns this instead of a presigned URL.
     * @param int         $urlExpiry Presigned URL validity in seconds. Default: 3600.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $region,
        private readonly string $bucket,
        private readonly ?string $endpoint = null,
        private readonly ?string $url = null,
        private readonly int $urlExpiry = 3600,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, string $contents): bool
    {
        $response = $this->request('PUT', $path, $contents, [
            'content-type' => 'application/octet-stream',
        ]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): string
    {
        $response = $this->request('GET', $path);

        if ($response['status'] === 404) {
            throw new StorageException("File not found: {$path}");
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new StorageException(
                "Failed to read file: {$path} (HTTP {$response['status']})"
            );
        }

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        $response = $this->request('DELETE', $path);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        $response = $this->request('HEAD', $path);

        return $response['status'] === 200;
    }

    /**
     * {@inheritdoc}
     *
     * Returns a presigned GET URL unless a custom $url base is configured,
     * in which case it returns {url}/{path}.
     */
    public function url(string $path): string
    {
        if ($this->url !== null) {
            return rtrim($this->url, '/') . '/' . ltrim($path, '/');
        }

        return $this->presignedUrl($path);
    }

    /**
     * {@inheritdoc}
     *
     * Moves the uploaded file to a temporary path, reads its contents,
     * uploads to S3, and cleans up the temporary file.
     */
    public function putUploadedFile(string $path, UploadedFile $file): bool
    {
        if (!$file->isValid()) {
            throw new StorageException("Invalid uploaded file: error code {$file->error()}");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 's3upload_');

        if ($tmpPath === false) {
            throw new StorageException('Failed to create temporary file for S3 upload.');
        }

        try {
            $file->moveTo($tmpPath);

            $contents = file_get_contents($tmpPath);

            if ($contents === false) {
                throw new StorageException(
                    "Failed to read uploaded file: {$file->originalName()}"
                );
            }

            return $this->put($path, $contents);
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * {@inheritdoc}
     *
     * Downloads the S3 object body into a PHP in-memory stream (php://memory)
     * and returns the rewound resource. For very large objects consider using
     * pre-signed URLs and streaming directly from the CDN instead.
     */
    public function getStream(string $path): mixed
    {
        $response = $this->request('GET', $path);

        if ($response['status'] === 404) {
            throw new StorageException("File not found: {$path}");
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new StorageException(
                "Failed to read file: {$path} (HTTP {$response['status']})"
            );
        }

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new StorageException("Failed to allocate memory stream for: {$path}");
        }

        fwrite($stream, $response['body']);
        rewind($stream);

        return $stream;
    }

    /**
     * {@inheritdoc}
     *
     * Reads the stream into memory and uploads via the S3 PutObject API.
     * For very large files consider using S3 multipart upload instead.
     */
    public function putStream(string $path, mixed $stream): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('putStream() requires a valid resource.');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new StorageException("Failed to read from stream for: {$path}");
        }

        return $this->put($path, $contents);
    }

    /**
     * Generate a presigned GET URL for the given object path.
     *
     * @param string $path Relative object path.
     *
     * @return string Presigned URL valid for $urlExpiry seconds.
     */
    private function presignedUrl(string $path): string
    {
        $host = $this->host();
        $date = gmdate('Ymd\THis\Z');
        $dateShort = substr($date, 0, 8);
        $objectKey = '/' . ltrim($path, '/');
        $scope = "{$dateShort}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $credential = "{$this->key}/{$scope}";

        $queryParams = [
            'X-Amz-Algorithm' => self::ALGO,
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $this->urlExpiry,
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

        $signature = $this->sign($canonicalRequest, $date, $dateShort);

        return "https://{$host}{$objectKey}?{$queryString}&X-Amz-Signature={$signature}";
    }

    /**
     * Execute an authenticated HTTP request against S3.
     *
     * @param non-empty-string     $method  HTTP method (GET, PUT, DELETE, HEAD).
     * @param string               $path    Object path relative to the bucket.
     * @param string               $body    Request body (for PUT).
     * @param array<string,string> $headers Additional headers to sign and send.
     *
     * @throws StorageException On cURL failure.
     *
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): array
    {
        $host = $this->host();
        $date = gmdate('Ymd\THis\Z');
        $dateShort = substr($date, 0, 8);
        $objectKey = '/' . ltrim($path, '/');
        $payloadHash = hash('sha256', $body);

        // Normalize header names to lowercase for canonical request
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $signedHeaders = array_merge($normalized, [
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
            '',
            $canonicalHeaders,
            $signedHeaderNames,
            $payloadHash,
        ]);

        $signature = $this->sign($canonicalRequest, $date, $dateShort);

        $scope = "{$dateShort}/{$this->region}/" . self::SERVICE . '/aws4_request';
        $authorization = self::ALGO
            . " Credential={$this->key}/{$scope},"
            . " SignedHeaders={$signedHeaderNames},"
            . " Signature={$signature}";

        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($signedHeaders as $name => $value) {
            if ($name !== 'host') {
                $curlHeaders[] = $name . ': ' . $value;
            }
        }

        $url = 'https://' . $host . $objectKey;

        $ch = curl_init();

        if ($ch === false) {
            throw new StorageException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (in_array($method, ['PUT', 'POST'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new StorageException("cURL error for {$method} {$path}: {$error}");
        }

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
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
        $scope = "{$dateShort}/{$this->region}/" . self::SERVICE . '/aws4_request';

        $stringToSign = implode("\n", [
            self::ALGO,
            $date,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        return hash_hmac('sha256', $stringToSign, $this->signingKey($dateShort));
    }

    /**
     * Derive the AWS Signature V4 signing key for the current date and region.
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

    /**
     * Determine the S3 endpoint hostname (without scheme).
     *
     * Uses the configured custom endpoint if set, otherwise derives
     * the virtual-hosted-style AWS hostname.
     *
     * @return string Hostname without https:// prefix.
     */
    private function host(): string
    {
        if ($this->endpoint !== null) {
            $host = preg_replace('#^https?://#', '', $this->endpoint);

            return rtrim((string) $host, '/');
        }

        return "{$this->bucket}.s3.{$this->region}.amazonaws.com";
    }
}
