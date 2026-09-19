<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\S3Driver;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class S3DriverMultipartTest
 *
 * Exercises S3Driver::putStream()'s multipart upload against an in-memory fake
 * S3 transport injected into the driver — no network, no credentials.
 *
 * @package Tests
 */
#[CoversClass(S3Driver::class)]
final class S3DriverMultipartTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $calls = [];

    /** @var array<string, array{status: int, headers: array<string, string>, body: string}> keyed "METHOD marker" */
    private array $overrides = [];

    private function driver(int $partSize = 4): S3Driver
    {
        $this->calls = [];

        return new S3Driver(
            'AKEY',
            'SECRET',
            'us-east-1',
            'bucket',
            multipartPartSize: $partSize,
            transport: function (string $method, string $url, array $headers, string $body): array {
                $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

                foreach ($this->overrides as $needle => $response) {
                    [$m, $marker] = explode(' ', $needle, 2);

                    if ($m === $method && str_contains($url, $marker)) {
                        return $response;
                    }
                }

                if ($method === 'POST' && str_contains($url, '?uploads=')) {
                    return ['status' => 200, 'headers' => [], 'body' => '<InitiateMultipartUploadResult><UploadId>UP1</UploadId></InitiateMultipartUploadResult>'];
                }

                if ($method === 'PUT' && str_contains($url, 'partNumber=')) {
                    $number = preg_match('/partNumber=(\d+)/', $url, $m) === 1 ? $m[1] : '0';

                    return ['status' => 200, 'headers' => ['etag' => '"etag-' . $number . '"'], 'body' => ''];
                }

                if ($method === 'POST' && str_contains($url, 'uploadId=UP1')) {
                    return ['status' => 200, 'headers' => [], 'body' => '<CompleteMultipartUploadResult/>'];
                }

                return ['status' => 200, 'headers' => [], 'body' => ''];
            },
        );
    }

    /**
     * @return resource
     */
    private function streamOf(string $data)
    {
        $s = fopen('php://memory', 'r+b');
        self::assertNotFalse($s);
        fwrite($s, $data);
        rewind($s);

        return $s;
    }

    /**
     * @return list<string> "METHOD marker" summaries of recorded calls
     */
    private function summary(): array
    {
        return array_map(static function (array $c): string {
            $q = parse_url($c['url'], PHP_URL_QUERY);

            return $c['method'] . ' ' . (is_string($q) ? $q : '');
        }, $this->calls);
    }

    public function test_a_stream_at_or_below_the_part_size_uses_a_single_put(): void
    {
        $driver = $this->driver(4);

        self::assertTrue($driver->putStream('a/b.txt', $this->streamOf('abcd')));

        self::assertCount(1, $this->calls);
        self::assertSame('PUT', $this->calls[0]['method']);
        self::assertSame('abcd', $this->calls[0]['body']);
    }

    public function test_a_larger_stream_is_uploaded_in_parts_and_completed(): void
    {
        $driver = $this->driver(4);

        self::assertTrue($driver->putStream('big.bin', $this->streamOf('aaaabbbbcc')));

        $summary = $this->summary();
        self::assertSame('POST uploads=', $summary[0]);
        self::assertSame('PUT partNumber=1&uploadId=UP1', $summary[1]);
        self::assertSame('PUT partNumber=2&uploadId=UP1', $summary[2]);
        self::assertSame('PUT partNumber=3&uploadId=UP1', $summary[3]);
        self::assertSame('POST uploadId=UP1', $summary[4]);
        self::assertCount(5, $summary);

        self::assertSame('aaaa', $this->calls[1]['body']);
        self::assertSame('bbbb', $this->calls[2]['body']);
        self::assertSame('cc', $this->calls[3]['body']);
    }

    public function test_complete_request_lists_every_part_with_its_etag_in_order(): void
    {
        $driver = $this->driver(4);
        $driver->putStream('big.bin', $this->streamOf('aaaabbbbcc'));

        $xml = $this->calls[4]['body'];
        self::assertStringContainsString('<CompleteMultipartUpload>', $xml);
        self::assertSame(1, preg_match('#<PartNumber>1</PartNumber><ETag>"etag-1"</ETag>.*<PartNumber>2</PartNumber><ETag>"etag-2"</ETag>.*<PartNumber>3</PartNumber><ETag>"etag-3"</ETag>#s', $xml));
    }

    public function test_every_request_is_sigv4_signed_including_the_query_string(): void
    {
        $driver = $this->driver(4);
        $driver->putStream('big.bin', $this->streamOf('aaaabbbb!'));

        foreach ($this->calls as $call) {
            $auth = implode("\n", $call['headers']);
            self::assertStringContainsString('Authorization: AWS4-HMAC-SHA256 Credential=AKEY/', $auth);
        }
    }

    public function test_a_failed_part_aborts_the_upload_and_returns_false(): void
    {
        $this->overrides['PUT partNumber=2'] = ['status' => 500, 'headers' => [], 'body' => 'boom'];
        $driver = $this->driver(4);

        self::assertFalse($driver->putStream('big.bin', $this->streamOf('aaaabbbbcc')));

        $summary = $this->summary();
        self::assertSame('DELETE uploadId=UP1', end($summary));
        self::assertNotContains('POST uploadId=UP1', $summary);
    }

    public function test_a_transport_exception_aborts_the_upload_and_rethrows(): void
    {
        $calls = 0;
        $driver = new S3Driver('AKEY', 'SECRET', 'us-east-1', 'bucket', multipartPartSize: 4, transport: function (string $method, string $url) use (&$calls): array {
            $calls++;

            if (str_contains($url, 'partNumber=2')) {
                throw new StorageException('network down');
            }

            if ($method === 'POST' && str_contains($url, '?uploads=')) {
                return ['status' => 200, 'headers' => [], 'body' => '<UploadId>UP1</UploadId>'];
            }

            return ['status' => 200, 'headers' => ['etag' => '"e"'], 'body' => ''];
        });

        try {
            $driver->putStream('big.bin', $this->streamOf('aaaabbbbcc'));
            self::fail('expected StorageException');
        } catch (StorageException $e) {
            self::assertSame('network down', $e->getMessage());
        }

        self::assertSame(4, $calls); // initiate, part 1, part 2 (throws), abort
    }

    public function test_a_complete_response_carrying_an_error_body_is_a_failure_and_aborts(): void
    {
        $this->overrides['POST uploadId=UP1'] = ['status' => 200, 'headers' => [], 'body' => '<Error><Code>InternalError</Code></Error>'];
        $driver = $this->driver(4);

        self::assertFalse($driver->putStream('big.bin', $this->streamOf('aaaabbbbcc')));

        $summary = $this->summary();
        self::assertSame('DELETE uploadId=UP1', end($summary));
    }

    public function test_initiate_without_an_upload_id_fails(): void
    {
        $this->overrides['POST uploads='] = ['status' => 200, 'headers' => [], 'body' => '<nothing/>'];
        $driver = $this->driver(4);

        self::assertFalse($driver->putStream('big.bin', $this->streamOf('aaaabbbbcc')));
        self::assertCount(1, $this->calls);
    }

    public function test_object_key_is_percent_encoded_in_multipart_urls(): void
    {
        $driver = $this->driver(4);
        $driver->putStream('my folder/file+x.bin', $this->streamOf('aaaabbbbcc'));

        self::assertStringContainsString('/my%20folder/file%2Bx.bin?uploads=', $this->calls[0]['url']);
    }

    public function test_put_still_uses_a_single_request(): void
    {
        $driver = $this->driver(4);

        self::assertTrue($driver->put('x.txt', 'much larger than the part size'));
        self::assertCount(1, $this->calls);
    }
}
