<?php

declare(strict_types=1);

namespace EzPhp\Storage;

/**
 * Class GcsSigner
 *
 * Google Cloud Storage V4 signed URLs (`GOOG4-RSA-SHA256`) from a service
 * account's client email and RSA private key, via ext-openssl — no SDK, no
 * OAuth2 token minting. Pure: the timestamp is an argument, there is no I/O.
 *
 * Algorithm (https://cloud.google.com/storage/docs/access-control/signed-urls):
 *   canonical request = VERB \n /bucket/object \n sorted query \n host:… \n\n host \n UNSIGNED-PAYLOAD
 *   string to sign    = GOOG4-RSA-SHA256 \n datetime \n date/auto/storage/goog4_request \n hex(sha256(canonical request))
 *   signature         = hex(RSA-SHA256(string to sign, private key)) → X-Goog-Signature
 *
 * @package EzPhp\Storage
 */
final readonly class GcsSigner
{
    public const string HOST = 'storage.googleapis.com';

    /**
     * Longest expiry Google accepts for a V4 signed URL (7 days).
     */
    public const int MAX_EXPIRY_SECONDS = 604800;

    private const string ALGORITHM = 'GOOG4-RSA-SHA256';

    /**
     * @param string $clientEmail Service-account email (the credential's identity).
     * @param string $privateKey  PEM-encoded RSA private key of that service account.
     */
    public function __construct(
        private string $clientEmail,
        private string $privateKey,
    ) {
    }

    /**
     * Build a V4 signed URL.
     *
     * @param string $bucket        Bucket name.
     * @param string $object        Object path inside the bucket (unencoded, `/`-separated).
     * @param int    $expirySeconds Validity in seconds, 1 … MAX_EXPIRY_SECONDS.
     * @param int    $timestamp     Signing time (Unix seconds).
     * @param string $method        HTTP verb the URL authorizes (GET by default).
     *
     * @throws StorageException When the expiry is out of range or the key cannot sign.
     *
     * @return string
     */
    public function signedUrl(string $bucket, string $object, int $expirySeconds, int $timestamp, string $method = 'GET'): string
    {
        if ($expirySeconds < 1 || $expirySeconds > self::MAX_EXPIRY_SECONDS) {
            throw new StorageException('A GCS V4 signed URL must expire within 1 to ' . self::MAX_EXPIRY_SECONDS . ' seconds.');
        }

        $datetime = gmdate('Ymd\THis\Z', $timestamp);
        $date = substr($datetime, 0, 8);
        $scope = "{$date}/auto/storage/goog4_request";
        $path = '/' . rawurlencode($bucket) . '/' . implode('/', array_map(rawurlencode(...), explode('/', $object)));

        $query = [
            'X-Goog-Algorithm' => self::ALGORITHM,
            'X-Goog-Credential' => "{$this->clientEmail}/{$scope}",
            'X-Goog-Date' => $datetime,
            'X-Goog-Expires' => (string) $expirySeconds,
            'X-Goog-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            $canonicalQuery,
            'host:' . self::HOST . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [self::ALGORITHM, $datetime, $scope, hash('sha256', $canonicalRequest)]);

        return 'https://' . self::HOST . $path . '?' . $canonicalQuery . '&X-Goog-Signature=' . $this->sign($stringToSign);
    }

    /**
     * @param string $stringToSign
     *
     * @throws StorageException
     *
     * @return string Hex-encoded RSA-SHA256 signature.
     */
    private function sign(string $stringToSign): string
    {
        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false || !openssl_sign($stringToSign, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new StorageException('Cannot sign the GCS URL: the service-account private key is invalid.');
        }

        /** @var string $signature */
        return bin2hex($signature);
    }
}
