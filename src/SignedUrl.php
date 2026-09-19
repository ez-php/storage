<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\RequestInterface;

/**
 * Class SignedUrl
 *
 * Expiring, tamper-proof links for files served by your own route (typically
 * `StorageResponse::serve()`), for drivers without native presigning — the `LocalDriver`.
 * (`S3Driver::url()` already returns a presigned URL.)
 *
 *     $signer = new SignedUrl($secret, 'https://app.test/files');
 *     $link   = $signer->make('reports/2026.pdf', ttl: 600);
 *     // https://app.test/files/reports/2026.pdf?expires=1789…&signature=9f…
 *
 *     // in the route handler:
 *     if (!$signer->verifyRequest($path, $request)) { return new Response('Forbidden', 403); }
 *
 * The signature is HMAC-SHA256 over `path \n expires` with your secret, so neither the path nor
 * the expiry can be changed without invalidating it; the comparison is constant-time. A link is
 * valid until its expiry — there is no single-use tracking.
 *
 * @package EzPhp\Storage
 */
final class SignedUrl
{
    /**
     * Shortest accepted secret, in bytes: a short secret makes the HMAC guessable.
     */
    public const int MIN_SECRET_BYTES = 16;

    private readonly string $baseUrl;

    /**
     * SignedUrl Constructor
     *
     * @param string                $secret  Signing secret, at least MIN_SECRET_BYTES bytes.
     * @param string                $baseUrl URL the storage path is appended to (no trailing slash needed).
     * @param (\Closure(): int)|null $clock  Returns the current Unix time; defaults to `time()` (for tests).
     *
     * @throws \InvalidArgumentException When the secret is too short.
     */
    public function __construct(
        private readonly string $secret,
        string $baseUrl,
        private readonly ?\Closure $clock = null,
    ) {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException('The signing secret must be at least ' . self::MIN_SECRET_BYTES . ' bytes long.');
        }

        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Build a signed URL for a storage path.
     *
     * @param string $path Relative storage path.
     * @param int    $ttl  Seconds the link stays valid.
     *
     * @throws StorageException When the path contains a `.` or `..` segment.
     *
     * @return string
     */
    public function make(string $path, int $ttl): string
    {
        $path = self::normalise($path);
        $expires = $this->now() + $ttl;

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $path)));

        return $this->baseUrl . '/' . $encoded . '?' . http_build_query([
            'expires' => $expires,
            'signature' => $this->signature($path, $expires),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Check a link's parts.
     *
     * @param string          $path      Relative storage path the link is for.
     * @param int|string|null $expires   The `expires` query value.
     * @param string|null     $signature The `signature` query value.
     *
     * @return bool True only for an unexpired link whose signature matches this exact path and expiry.
     */
    public function verify(string $path, int|string|null $expires, ?string $signature): bool
    {
        if ($signature === null || $signature === '' || $expires === null || !is_numeric($expires)) {
            return false;
        }

        $expiresAt = (int) $expires;

        if ($expiresAt < $this->now()) {
            return false;
        }

        try {
            $path = self::normalise($path);
        } catch (StorageException) {
            return false;
        }

        return hash_equals($this->signature($path, $expiresAt), $signature);
    }

    /**
     * Check the `expires` and `signature` query parameters of a request.
     *
     * @param string           $path
     * @param RequestInterface $request
     *
     * @return bool
     */
    public function verifyRequest(string $path, RequestInterface $request): bool
    {
        $expires = $request->query('expires');
        $signature = $request->query('signature');

        return $this->verify(
            $path,
            is_int($expires) || is_string($expires) ? $expires : null,
            is_string($signature) ? $signature : null,
        );
    }

    /**
     * @param string $path
     * @param int    $expires
     *
     * @return string
     */
    private function signature(string $path, int $expires): string
    {
        return hash_hmac('sha256', $path . "\n" . $expires, $this->secret);
    }

    /**
     * @return int
     */
    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /**
     * @param string $path
     *
     * @throws StorageException
     *
     * @return string
     */
    private static function normalise(string $path): string
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
