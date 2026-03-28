<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\S3Driver;

/**
 * Class S3DriverTest
 *
 * Integration tests — skipped unless AWS credentials are present.
 * Set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, and AWS_BUCKET to run.
 *
 * @package Tests
 * @covers \EzPhp\Storage\S3Driver
 */
final class S3DriverTest extends TestCase
{
    private S3Driver $driver;

    protected function setUp(): void
    {
        $key = (string) getenv('AWS_ACCESS_KEY_ID');
        $secret = (string) getenv('AWS_SECRET_ACCESS_KEY');
        $region = (string) (getenv('AWS_DEFAULT_REGION') ?: 'us-east-1');
        $bucket = (string) getenv('AWS_BUCKET');

        if ($key === '' || $secret === '' || $bucket === '') {
            $this->markTestSkipped(
                'S3 credentials not configured — set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET.'
            );
        }

        $this->driver = new S3Driver($key, $secret, $region, $bucket);
    }

    public function testPutGetDeleteCycle(): void
    {
        $path = 'ez-php-test/' . uniqid('file_', true) . '.txt';
        $contents = 'S3 integration test content — ' . time();

        $this->assertTrue($this->driver->put($path, $contents));
        $this->assertTrue($this->driver->exists($path));
        $this->assertSame($contents, $this->driver->get($path));
        $this->assertTrue($this->driver->delete($path));
        $this->assertFalse($this->driver->exists($path));
    }

    public function testPresignedUrlContainsExpectedComponents(): void
    {
        $url = $this->driver->url('some/path.txt');

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
}
