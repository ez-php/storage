<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\S3Driver;

/**
 * Class S3DriverTest
 *
 * Live integration test against a real bucket — skipped unless AWS credentials
 * are present (set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET). The
 * credential-free checks (URL building, path traversal, key encoding) live in
 * S3DriverOfflineTest so they always run.
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
}
