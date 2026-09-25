<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\GcsDriver;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for GcsDriver against a fake transport — no GCS credentials needed.
 *
 * GcsDriverTest only runs against a live bucket and is skipped everywhere else,
 * so these cover request construction and status mapping.
 *
 * @package Tests
 */
#[CoversClass(GcsDriver::class)]
final class GcsDriverTransportTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $requests = [];

    /** @var list<array{status: int, body: string}> */
    private array $responses = [];

    public function testPutUploadsMediaWithBearerToken(): void
    {
        $this->responses = [['status' => 200, 'body' => '{}']];

        $this->assertTrue($this->driver()->put('dir/a b.txt', 'hello'));

        $request = $this->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(
            'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=media&name=dir%2Fa%20b.txt',
            $request['url'],
        );
        $this->assertContains('Authorization: Bearer token-123', $request['headers']);
        $this->assertContains('Content-Type: application/octet-stream', $request['headers']);
        $this->assertSame('hello', $request['body']);
    }

    public function testPutReturnsFalseOnErrorStatus(): void
    {
        $this->responses = [['status' => 403, 'body' => 'forbidden']];

        $this->assertFalse($this->driver()->put('a.txt', 'x'));
    }

    public function testGetReturnsBodyViaAltMedia(): void
    {
        $this->responses = [['status' => 200, 'body' => 'contents']];

        $this->assertSame('contents', $this->driver()->get('dir/file.txt'));
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame(
            'https://storage.googleapis.com/storage/v1/b/my-bucket/o/dir%2Ffile.txt?alt=media',
            $this->requests[0]['url'],
        );
    }

    public function testGetThrowsNotFoundOn404(): void
    {
        $this->responses = [['status' => 404, 'body' => '']];

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File not found: missing.txt');

        $this->driver()->get('missing.txt');
    }

    public function testGetThrowsOnOtherErrorStatus(): void
    {
        $this->responses = [['status' => 500, 'body' => '']];

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Failed to read file: a.txt');

        $this->driver()->get('a.txt');
    }

    public function testDeleteAndExistsMapStatusCodes(): void
    {
        $this->responses = [
            ['status' => 204, 'body' => ''],
            ['status' => 404, 'body' => ''],
            ['status' => 200, 'body' => '{}'],
            ['status' => 404, 'body' => ''],
        ];
        $driver = $this->driver();

        $this->assertTrue($driver->delete('a.txt'));
        $this->assertFalse($driver->delete('a.txt'));
        $this->assertTrue($driver->exists('a.txt'));
        $this->assertFalse($driver->exists('a.txt'));
        $this->assertSame('DELETE', $this->requests[0]['method']);
        $this->assertSame('https://storage.googleapis.com/storage/v1/b/my-bucket/o/a.txt', $this->requests[0]['url']);
    }

    public function testStreamsRoundTripThroughPutAndGet(): void
    {
        $this->responses = [['status' => 200, 'body' => '{}'], ['status' => 200, 'body' => 'streamed']];
        $driver = $this->driver();

        $in = fopen('php://memory', 'r+b');
        $this->assertIsResource($in);
        fwrite($in, 'streamed');
        rewind($in);

        $this->assertTrue($driver->putStream('s.txt', $in));
        $this->assertSame('streamed', $this->requests[0]['body']);

        $out = $driver->getStream('s.txt');
        $this->assertSame('streamed', stream_get_contents($out));
    }

    public function testUrlUsesBucketOrCustomBase(): void
    {
        $this->assertSame('https://storage.googleapis.com/my-bucket/img/a.png', $this->driver()->url('/img/a.png'));

        $cdn = new GcsDriver('my-bucket', 'token-123', 'https://cdn.example.com/');
        $this->assertSame('https://cdn.example.com/img/a.png', $cdn->url('img/a.png'));
    }

    public function testPathTraversalIsRejectedBeforeAnyRequest(): void
    {
        try {
            $this->driver()->get('a/../secret.txt');
            $this->fail('Expected StorageException');
        } catch (StorageException $e) {
            $this->assertStringContainsString('Invalid path', $e->getMessage());
        }

        $this->assertSame([], $this->requests);
    }

    private function driver(): GcsDriver
    {
        return new GcsDriver(
            'my-bucket',
            'token-123',
            transport: function (string $method, string $url, array $headers, string $body): array {
                $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                return array_shift($this->responses) ?? ['status' => 500, 'body' => 'no response queued'];
            },
        );
    }
}
