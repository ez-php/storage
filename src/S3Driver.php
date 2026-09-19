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
    private readonly S3Signer $signer;

    /**
     * @param string      $key       AWS access key ID.
     * @param string      $secret    AWS secret access key.
     * @param string      $region    AWS region (e.g. us-east-1).
     * @param string      $bucket    S3 bucket name.
     * @param string|null $endpoint  Custom endpoint for S3-compatible services (e.g. http://minio:9000).
     * @param string|null $url       Custom public base URL (e.g. CDN). If set, url() returns this instead of a presigned URL.
     * @param int         $urlExpiry Presigned URL validity in seconds. Default: 3600.
     * @param int         $multipartPartSize Part size in bytes for putStream(): streams up to this size use a single
     *                                       PutObject; larger streams use S3 multipart upload with parts of this size.
     *                                       S3 itself requires >= 5 MiB for every part but the last. Default: 8 MiB.
     * @param (\Closure(string, string, list<string>, string): array{status: int, headers: array<string, string>, body: string})|null $transport
     *                                       Replaces the cURL HTTP layer (method, absolute URL, header lines, body →
     *                                       status, lowercased response headers, body). For tests and custom HTTP stacks.
     */
    public function __construct(
        string $key,
        string $secret,
        private readonly string $region,
        private readonly string $bucket,
        private readonly ?string $endpoint = null,
        private readonly ?string $url = null,
        private readonly int $urlExpiry = 3600,
        private readonly int $multipartPartSize = 8_388_608,
        private readonly ?\Closure $transport = null,
    ) {
        $this->signer = new S3Signer($key, $secret, $region);
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
            return rtrim($this->url, '/') . '/' . $this->assertSafeRelativePath($path);
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
     * Streams up to `$multipartPartSize` bytes are uploaded with a single PutObject. Larger streams
     * are read one part at a time and uploaded with S3 multipart upload (initiate, upload parts,
     * complete), so the whole object is never held in memory. A failed multipart upload is aborted
     * (best effort) so no orphaned parts keep accruing storage charges.
     */
    public function putStream(string $path, mixed $stream): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('putStream() requires a valid resource.');
        }

        $first = $this->readChunk($stream, $path);
        $second = $this->readChunk($stream, $path);

        if ($second === '') {
            return $this->put($path, $first);
        }

        return $this->multipartUpload($path, $stream, $first, $second);
    }

    /**
     * Upload an object in parts. `$first` and `$second` are the already-read leading chunks.
     *
     * @param resource $stream Remaining stream after the two leading chunks.
     */
    private function multipartUpload(string $path, mixed $stream, string $first, string $second): bool
    {
        $initiate = $this->request('POST', $path, '', [], ['uploads' => '']);

        if ($initiate['status'] < 200 || $initiate['status'] >= 300
            || preg_match('#<UploadId>([^<]+)</UploadId>#', $initiate['body'], $m) !== 1) {
            return false;
        }

        $uploadId = $m[1];

        try {
            $etags = [];
            $partNumber = 0;

            foreach ($this->chunks($first, $second, $stream, $path) as $chunk) {
                $partNumber++;
                $response = $this->request('PUT', $path, $chunk, [], [
                    'partNumber' => (string) $partNumber,
                    'uploadId' => $uploadId,
                ]);

                if ($response['status'] < 200 || $response['status'] >= 300 || !isset($response['headers']['etag'])) {
                    $this->abortMultipart($path, $uploadId);

                    return false;
                }

                $etags[$partNumber] = $response['headers']['etag'];
            }

            $xml = '<CompleteMultipartUpload>';

            foreach ($etags as $number => $etag) {
                $xml .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
            }

            $xml .= '</CompleteMultipartUpload>';

            $complete = $this->request('POST', $path, $xml, ['content-type' => 'application/xml'], ['uploadId' => $uploadId]);

            // S3 can answer 200 to CompleteMultipartUpload and still report failure in the body.
            if ($complete['status'] < 200 || $complete['status'] >= 300 || str_contains($complete['body'], '<Error>')) {
                $this->abortMultipart($path, $uploadId);

                return false;
            }
        } catch (\Throwable $e) {
            $this->abortMultipart($path, $uploadId);

            throw $e;
        }

        return true;
    }

    /**
     * @param resource $stream
     *
     * @return \Generator<int, string>
     */
    private function chunks(string $first, string $second, mixed $stream, string $path): \Generator
    {
        yield $first;
        yield $second;

        while (($chunk = $this->readChunk($stream, $path)) !== '') {
            yield $chunk;
        }
    }

    /**
     * Read up to one part's worth of bytes (fread may return short reads).
     *
     * @param resource $stream
     */
    private function readChunk(mixed $stream, string $path): string
    {
        $buffer = '';

        while (strlen($buffer) < $this->multipartPartSize && !feof($stream)) {
            $data = fread($stream, max(1, $this->multipartPartSize - strlen($buffer)));

            if ($data === false) {
                throw new StorageException("Failed to read from stream for: {$path}");
            }

            $buffer .= $data;
        }

        return $buffer;
    }

    /**
     * Best-effort abort so failed uploads do not leave billable orphaned parts.
     */
    private function abortMultipart(string $path, string $uploadId): void
    {
        try {
            $this->request('DELETE', $path, '', [], ['uploadId' => $uploadId]);
        } catch (\Throwable) {
            // Nothing more can be done; the original failure is what the caller needs to see.
        }
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
        $objectKey = S3Signer::encodePath('/' . $this->assertSafeRelativePath($path));

        return "https://{$host}{$objectKey}?" . $this->signer->presignQuery($host, $objectKey, $this->urlExpiry, $date);
    }

    /**
     * Execute an authenticated HTTP request against S3.
     *
     * @param non-empty-string     $method  HTTP method (GET, PUT, DELETE, HEAD).
     * @param string               $path    Object path relative to the bucket.
     * @param string               $body    Request body (for PUT).
     * @param array<string,string> $headers Additional headers to sign and send.
     * @param array<string,string> $query   Query-string parameters (signed as part of the canonical request).
     *
     * @throws StorageException On cURL failure.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function request(string $method, string $path, string $body = '', array $headers = [], array $query = []): array
    {
        $host = $this->host();
        $date = gmdate('Ymd\THis\Z');
        $objectKey = S3Signer::encodePath('/' . $this->assertSafeRelativePath($path));

        ksort($query);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        // Normalize header names to lowercase for the canonical request
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $signed = $this->signer->signRequest(
            $method,
            $objectKey,
            $queryString,
            $normalized,
            hash('sha256', $body),
            $host,
            $date,
        );

        $curlHeaders = ['Authorization: ' . $signed['authorization']];
        foreach ($signed['headers'] as $name => $value) {
            if ($name !== 'host') {
                $curlHeaders[] = $name . ': ' . $value;
            }
        }

        $url = 'https://' . $host . $objectKey . ($queryString !== '' ? '?' . $queryString : '');

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $curlHeaders, $body);
        }

        return $this->curl($method, $url, $curlHeaders, $body, $path);
    }

    /**
     * Default HTTP layer: one cURL request.
     *
     * @param non-empty-string $method
     * @param non-empty-string $url
     * @param list<string>     $curlHeaders
     *
     * @throws StorageException On cURL failure.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function curl(string $method, string $url, array $curlHeaders, string $body, string $path): array
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new StorageException('Failed to initialize cURL.');
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if (in_array($method, ['PUT', 'POST'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            throw new StorageException("cURL error for {$method} {$path}: {$error}");
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($response) ? $response : '',
        ];
    }

    /**
     * Reject a path containing `.` or `..` segments, which could otherwise
     * be used to address an unintended object key in the same bucket
     * (e.g. `../other-tenant/secret.txt`).
     *
     * @param string $path Relative object path as supplied by the caller.
     *
     * @throws StorageException If the path contains a `.` or `..` segment.
     *
     * @return string The path, unmodified, once validated.
     */
    private function assertSafeRelativePath(string $path): string
    {
        $trimmed = ltrim($path, '/');

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new StorageException("Invalid path: {$path}");
            }
        }

        return $trimmed;
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
