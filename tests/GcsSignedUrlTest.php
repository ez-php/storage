<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\GcsDriver;
use EzPhp\Storage\GcsSigner;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * GCS V4 signed URLs: the URL shape, and the signature verified with the
 * public half of a freshly generated key against an independently rebuilt
 * string to sign.
 *
 * @package Tests
 */
#[CoversClass(GcsSigner::class)]
#[CoversClass(GcsDriver::class)]
#[UsesClass(StorageException::class)]
final class GcsSignedUrlTest extends TestCase
{
    private const int TIME = 1_768_478_400; // 2026-01-15T12:00:00Z

    private static string $privateKey = '';

    private static string $publicKey = '';

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        self::assertIsString($pem);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        self::$privateKey = $pem;
        self::$publicKey = $details['key'];
    }

    public function testSignedUrlHasTheV4QueryAndAVerifiableSignature(): void
    {
        $signer = new GcsSigner('svc@project.iam.gserviceaccount.com', self::$privateKey);

        $url = $signer->signedUrl('my-bucket', 'reports/Q1 2026.pdf', 900, self::TIME);

        $parts = parse_url($url);
        self::assertIsArray($parts);
        self::assertSame('storage.googleapis.com', $parts['host'] ?? null);
        self::assertSame('/my-bucket/reports/Q1%202026.pdf', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('GOOG4-RSA-SHA256', $query['X-Goog-Algorithm'] ?? null);
        self::assertSame('svc@project.iam.gserviceaccount.com/20260115/auto/storage/goog4_request', $query['X-Goog-Credential'] ?? null);
        self::assertSame('20260115T120000Z', $query['X-Goog-Date'] ?? null);
        self::assertSame('900', $query['X-Goog-Expires'] ?? null);
        self::assertSame('host', $query['X-Goog-SignedHeaders'] ?? null);

        $signedQuery = substr((string) ($parts['query'] ?? ''), 0, (int) strpos((string) ($parts['query'] ?? ''), '&X-Goog-Signature='));
        $canonical = "GET\n/my-bucket/reports/Q1%202026.pdf\n{$signedQuery}\nhost:storage.googleapis.com\n\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "GOOG4-RSA-SHA256\n20260115T120000Z\n20260115/auto/storage/goog4_request\n" . hash('sha256', $canonical);

        $signature = $query['X-Goog-Signature'] ?? '';
        self::assertIsString($signature);
        self::assertSame(1, openssl_verify($stringToSign, (string) hex2bin($signature), self::$publicKey, OPENSSL_ALGO_SHA256));
    }

    public function testExpiryMustBeWithinSevenDays(): void
    {
        $signer = new GcsSigner('svc@x', self::$privateKey);

        $this->expectException(StorageException::class);

        $signer->signedUrl('b', 'o', GcsSigner::MAX_EXPIRY_SECONDS + 1, self::TIME);
    }

    public function testInvalidKeyIsReported(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('private key is invalid');

        (new GcsSigner('svc@x', 'not a key'))->signedUrl('b', 'o', 60, self::TIME);
    }

    public function testDriverUrlIsSignedWithCredentialsAndPublicWithout(): void
    {
        $signing = new GcsDriver('my-bucket', 'token', clientEmail: 'svc@x', privateKey: self::$privateKey, urlExpiry: 120, clock: static fn (): int => self::TIME);
        $plain = new GcsDriver('my-bucket', 'token');
        $cdn = new GcsDriver('my-bucket', 'token', 'https://cdn.test', clientEmail: 'svc@x', privateKey: self::$privateKey);

        self::assertStringContainsString('X-Goog-Expires=120', $signing->url('a/b.txt'));
        self::assertStringStartsWith('https://storage.googleapis.com/my-bucket/a/b.txt?', $signing->signedUrl('a/b.txt', 60));
        self::assertSame('https://storage.googleapis.com/my-bucket/a/b.txt', $plain->url('a/b.txt'));
        self::assertSame('https://cdn.test/a/b.txt', $cdn->url('a/b.txt'), 'a CDN base wins over signing');
    }

    public function testDriverSignedUrlWithoutCredentialsOrWithTraversalThrows(): void
    {
        try {
            (new GcsDriver('b', 't'))->signedUrl('a.txt', 60);
            self::fail('StorageException expected');
        } catch (StorageException $e) {
            self::assertStringContainsString('service-account', $e->getMessage());
        }

        $this->expectException(StorageException::class);

        (new GcsDriver('b', 't', clientEmail: 'svc@x', privateKey: self::$privateKey))->signedUrl('../secret', 60);
    }
}
