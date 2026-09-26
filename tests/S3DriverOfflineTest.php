<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\S3Driver;
use EzPhp\Storage\StorageException;

/**
 * Class S3DriverOfflineTest
 *
 * S3Driver behaviour that needs no bucket or network: presigned-URL building,
 * custom URLs, path-traversal rejection and object-key encoding. Uses dummy
 * credentials, so it always runs (unlike the live S3DriverTest).
 *
 * @package Tests
 * @covers \EzPhp\Storage\S3Driver
 */
final class S3DriverOfflineTest extends TestCase
{
    public function testPresignedUrlContainsExpectedComponents(): void
    {
        $url = (new S3Driver('key', 'secret', 'us-east-1', 'my-bucket'))->url('some/path.txt');

        $this->assertStringContainsString('some/path.txt', $url);
        $this->assertStringContainsString('X-Amz-Signature', $url);
        $this->assertStringContainsString('X-Amz-Expires', $url);
        $this->assertStringContainsString('X-Amz-Credential', $url);
    }

    public function testCustomUrlReturnsDirectPath(): void
    {
        $driver = new S3Driver('key', 'secret', 'us-east-1', 'my-bucket', null, 'https://cdn.example.com');

        $this->assertSame('https://cdn.example.com/images/photo.jpg', $driver->url('images/photo.jpg'));
    }

    public function testCustomUrlRejectsPathTraversal(): void
    {
        $driver = new S3Driver('key', 'secret', 'us-east-1', 'my-bucket', null, 'https://cdn.example.com');

        $this->expectException(StorageException::class);
        $driver->url('../other-tenant/secret.txt');
    }

    public function testPresignedUrlRejectsPathTraversal(): void
    {
        // No custom $url configured, so url() falls through to presignedUrl(),
        // which must reject the traversal before ever contacting S3.
        $driver = new S3Driver('key', 'secret', 'us-east-1', 'my-bucket');

        $this->expectException(StorageException::class);
        $driver->url('../other-tenant/secret.txt');
    }

    public function testPutRejectsPathTraversal(): void
    {
        $driver = new S3Driver('key', 'secret', 'us-east-1', 'my-bucket');

        $this->expectException(StorageException::class);
        $driver->put('../other-tenant/secret.txt', 'contents');
    }

    /**
     * Regression test: object keys containing a space or `+` must be
     * percent-encoded in the presigned URL, matching what was actually signed
     * in the canonical request — otherwise the URL sent to a client and the
     * signature computed for it disagree, and real AWS S3 returns 403.
     */
    public function testPresignedUrlPercentEncodesSpecialCharactersInObjectKey(): void
    {
        $driver = new S3Driver('key', 'secret', 'us-east-1', 'my-bucket');

        $url = $driver->url('my folder/file+name.txt');
        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->assertStringContainsString('my%20folder/file%2Bname.txt', $path);
        $this->assertStringNotContainsString(' ', $path);
        $this->assertStringNotContainsString('+', $path);
    }
}
