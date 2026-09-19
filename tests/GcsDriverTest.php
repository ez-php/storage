<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\GcsDriver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class GcsDriverTest
 *
 * Integration tests against a real Google Cloud Storage bucket, skipped
 * unless credentials are configured — same pattern as S3DriverTest, since
 * GcsDriver has no mockable HTTP seam either.
 *
 * @package Tests
 */
#[CoversClass(GcsDriver::class)]
final class GcsDriverTest extends TestCase
{
    private GcsDriver $driver;

    protected function setUp(): void
    {
        $bucket = (string) getenv('GCS_BUCKET');
        $accessToken = (string) getenv('GCS_ACCESS_TOKEN');

        if ($bucket === '' || $accessToken === '') {
            $this->markTestSkipped(
                'GCS credentials not configured — set GCS_BUCKET, GCS_ACCESS_TOKEN.'
            );
        }

        $this->driver = new GcsDriver($bucket, $accessToken);
    }

    public function testPutGetDeleteCycle(): void
    {
        $path = 'ez-php-test/' . uniqid('file_', true) . '.txt';
        $contents = 'GCS integration test content — ' . time();

        $this->assertTrue($this->driver->put($path, $contents));
        $this->assertTrue($this->driver->exists($path));
        $this->assertSame($contents, $this->driver->get($path));
        $this->assertTrue($this->driver->delete($path));
        $this->assertFalse($this->driver->exists($path));
    }

    public function testUrlContainsBucketAndPath(): void
    {
        $url = $this->driver->url('some/path.txt');

        $this->assertStringContainsString('some/path.txt', $url);
    }

    public function testDeleteOfMissingObjectReturnsFalse(): void
    {
        $this->assertFalse($this->driver->delete('ez-php-test/' . uniqid('missing_', true) . '.txt'));
    }
}
