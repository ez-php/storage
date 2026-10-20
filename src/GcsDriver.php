<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\UploadedFile;

/**
 * Class GcsDriver
 *
 * Google Cloud Storage driver using the GCS JSON API over cURL, authenticated
 * with a Bearer access token — no SDK, no service-account JWT minting.
 *
 * The caller is responsible for obtaining a valid OAuth2 access token (e.g.
 * via `gcloud auth print-access-token` in development, or a metadata-server /
 * workload-identity token in production) and refreshing it as needed; this
 * driver only ever sends the token it was constructed with.
 *
 * With a service-account client email and private key, `url()` returns an
 * expiring V4 signed URL (GcsSigner, ext-openssl) instead of the public
 * object URL — the key is used for URL signing only, uploads and reads still
 * use the Bearer token.
 *
 * @package EzPhp\Storage
 */
final class GcsDriver implements StorageInterface
{
    private const string DEFAULT_ENDPOINT = 'https://storage.googleapis.com';

    private readonly ?GcsSigner $signer;

    private readonly string $endpoint;

    /**
     * @param string      $bucket      GCS bucket name.
     * @param string      $accessToken OAuth2 Bearer access token.
     * @param string|null $url         Custom public/CDN base URL. When null,
     *                                 `url()` returns a direct public bucket URL.
     * @param (\Closure(string, string, list<string>, string): array{status: int, body: string})|null $transport
     *                                 Replaces the cURL call — receives method, URL, headers
     *                                 (including `Authorization`) and body. For tests; null uses cURL.
     * @param string|null $clientEmail Service-account email for V4 signed URLs (with $privateKey).
     * @param string|null $privateKey  PEM RSA private key of that service account.
     * @param int         $urlExpiry   Signed-URL lifetime in seconds (max 7 days).
     * @param (\Closure(): int)|null $clock Current Unix time for signing; null = time().
     * @param string|null $endpoint    API base URL instead of https://storage.googleapis.com — e.g. a local
     *                                 emulator (fake-gcs-server). Also the base of the public object URL;
     *                                 V4 signed URLs always target storage.googleapis.com.
     */
    public function __construct(
        private readonly string $bucket,
        private readonly string $accessToken,
        private readonly ?string $url = null,
        private readonly ?\Closure $transport = null,
        ?string $clientEmail = null,
        ?string $privateKey = null,
        private readonly int $urlExpiry = 3600,
        private readonly ?\Closure $clock = null,
        ?string $endpoint = null,
    ) {
        $this->endpoint = rtrim($endpoint !== null && $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT, '/');
        $this->signer = $clientEmail !== null && $clientEmail !== '' && $privateKey !== null && $privateKey !== ''
            ? new GcsSigner($clientEmail, $privateKey)
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, string $contents): bool
    {
        $objectPath = $this->assertSafeRelativePath($path);
        $url = $this->endpoint . '/upload/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o?uploadType=media&name=' . rawurlencode($objectPath);

        $response = $this->request('POST', $url, $contents, [
            'Content-Type: application/octet-stream',
        ]);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): string
    {
        $objectPath = $this->assertSafeRelativePath($path);
        $url = $this->endpoint . '/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o/' . rawurlencode($objectPath) . '?alt=media';

        $response = $this->request('GET', $url);

        if ($response['status'] === 404) {
            throw new StorageException("File not found: {$path}");
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new StorageException("Failed to read file: {$path}");
        }

        return $response['body'];
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        $objectPath = $this->assertSafeRelativePath($path);
        $url = $this->endpoint . '/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o/' . rawurlencode($objectPath);

        $response = $this->request('DELETE', $url);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        $objectPath = $this->assertSafeRelativePath($path);
        $url = $this->endpoint . '/storage/v1/b/' . rawurlencode($this->bucket)
            . '/o/' . rawurlencode($objectPath);

        $response = $this->request('GET', $url);

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * {@inheritdoc}
     *
     * A configured CDN/public `$url` base wins; otherwise a V4 signed URL valid for
     * `$urlExpiry` seconds when signing credentials are set, else the public object URL.
     */
    public function url(string $path): string
    {
        $objectPath = $this->assertSafeRelativePath($path);

        if ($this->url !== null) {
            return rtrim($this->url, '/') . '/' . $objectPath;
        }

        if ($this->signer !== null) {
            return $this->signedUrl($path, $this->urlExpiry);
        }

        return $this->endpoint . '/' . $this->bucket . '/' . $objectPath;
    }

    /**
     * An expiring V4 signed GET URL for the object, independent of the configured CDN URL.
     *
     * @param string $path Relative object path.
     * @param int    $ttl  Seconds the URL stays valid (max 7 days).
     *
     * @throws StorageException Without signing credentials, or for an invalid path/expiry/key.
     *
     * @return string
     */
    public function signedUrl(string $path, int $ttl): string
    {
        if ($this->signer === null) {
            throw new StorageException('GCS signed URLs need a service-account client email and private key.');
        }

        $now = $this->clock !== null ? ($this->clock)() : time();

        return $this->signer->signedUrl($this->bucket, $this->assertSafeRelativePath($path), $ttl, $now);
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(string $path): mixed
    {
        $contents = $this->get($path);

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new StorageException("Failed to open stream for: {$path}");
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function putStream(string $path, mixed $stream): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('putStream() requires a valid resource.');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new StorageException("Failed to read stream for: {$path}");
        }

        return $this->put($path, $contents);
    }

    /**
     * {@inheritdoc}
     */
    public function putUploadedFile(string $path, UploadedFile $file): bool
    {
        if (!$file->isValid()) {
            throw new StorageException("Invalid uploaded file: error code {$file->error()}");
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'gcs_upload_');

        if ($tempPath === false) {
            throw new StorageException('Failed to create temporary file for upload.');
        }

        try {
            $file->moveTo($tempPath);

            $contents = file_get_contents($tempPath);

            if ($contents === false) {
                throw new StorageException("Failed to read uploaded file: {$path}");
            }

            return $this->put($path, $contents);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Execute an authenticated cURL request against the GCS JSON API.
     *
     * @param string        $method  HTTP method.
     * @param string        $url     Fully qualified request URL.
     * @param string        $body    Request body, empty for GET/DELETE.
     * @param array<string> $headers Additional request headers.
     *
     * @throws StorageException On cURL initialization or transport failure.
     *
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $url, string $body = '', array $headers = []): array
    {
        if ($method === '' || $url === '') {
            throw new StorageException('Invalid request parameters.');
        }

        $curlHeaders = array_values(array_merge([
            'Authorization: Bearer ' . $this->accessToken,
        ], $headers));

        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $curlHeaders, $body);
        }

        $ch = curl_init();

        if ($ch === false) {
            throw new StorageException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (in_array($method, ['PUT', 'POST'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($response === false) {
            throw new StorageException("cURL error for {$method} {$url}: {$error}");
        }

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }

    /**
     * Reject a path containing `.` or `..` segments, which could otherwise
     * be used to address an unintended object in the same bucket.
     *
     * @param string $path Relative object path as supplied by the caller.
     *
     * @throws StorageException If the path contains a `.` or `..` segment.
     *
     * @return string The path, unmodified once validated.
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
}
