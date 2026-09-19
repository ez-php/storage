<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\Request;
use EzPhp\Http\ResponseInterface;
use EzPhp\Http\UploadedFile;
use EzPhp\Storage\InMemoryDriver;
use EzPhp\Storage\LocalDriver;
use EzPhp\Storage\StorageException;
use EzPhp\Storage\StorageInterface;
use EzPhp\Storage\StorageResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Storage whose stream is a socket (not seekable, fstat() size 0), like a remote object stream.
 */
final class StorageResponseNonSeekableStorage implements StorageInterface
{
    public function __construct(private readonly string $contents)
    {
    }

    public function put(string $path, string $contents): bool
    {
        return true;
    }

    public function get(string $path): string
    {
        return $this->contents;
    }

    public function delete(string $path): bool
    {
        return true;
    }

    public function exists(string $path): bool
    {
        return true;
    }

    public function url(string $path): string
    {
        return '/' . $path;
    }

    public function putUploadedFile(string $path, UploadedFile $file): bool
    {
        return true;
    }

    public function getStream(string $path): mixed
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new StorageException('no socket pair');
        }

        fwrite($pair[0], $this->contents);
        fclose($pair[0]);

        return $pair[1];
    }

    public function putStream(string $path, mixed $stream): bool
    {
        return true;
    }
}

#[CoversClass(StorageResponse::class)]
#[UsesClass(InMemoryDriver::class)]
#[UsesClass(LocalDriver::class)]
final class StorageResponseTest extends TestCase
{
    private const string CONTENT = '0123456789abcdefghij'; // 20 bytes

    private InMemoryDriver $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new InMemoryDriver();
        $this->storage->put('docs/file.bin', self::CONTENT);
    }

    private function request(?string $range = null): Request
    {
        return new Request(method: 'GET', uri: '/files/docs/file.bin', headers: $range === null ? [] : ['range' => $range]);
    }

    private function body(ResponseInterface $response): string
    {
        $body = '';
        $response->writeBody(static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        return $body;
    }

    public function test_without_a_range_the_whole_file_is_served(): void
    {
        $response = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request(), 'file.bin');

        self::assertSame(200, $response->status());
        self::assertSame(self::CONTENT, $this->body($response));
        self::assertSame('20', $response->headers()['Content-Length']);
        self::assertSame('bytes', $response->headers()['Accept-Ranges']);
        self::assertArrayNotHasKey('Content-Range', $response->headers());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function satisfiableRanges(): array
    {
        return [
            'closed range' => ['bytes=2-5', '2345', 'bytes 2-5/20'],
            'open-ended' => ['bytes=15-', 'fghij', 'bytes 15-19/20'],
            'suffix' => ['bytes=-4', 'ghij', 'bytes 16-19/20'],
            'end past the file is clamped' => ['bytes=18-999', 'ij', 'bytes 18-19/20'],
            'suffix longer than the file is the whole file' => ['bytes=-999', self::CONTENT, 'bytes 0-19/20'],
            'single byte' => ['bytes=0-0', '0', 'bytes 0-0/20'],
        ];
    }

    #[DataProvider('satisfiableRanges')]
    public function test_a_satisfiable_range_yields_206_with_exactly_those_bytes(string $range, string $expectedBody, string $expectedContentRange): void
    {
        $response = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request($range), 'file.bin');

        self::assertSame(206, $response->status());
        self::assertSame($expectedBody, $this->body($response));
        self::assertSame($expectedContentRange, $response->headers()['Content-Range']);
        self::assertSame((string) strlen($expectedBody), $response->headers()['Content-Length']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsatisfiableRanges(): array
    {
        return [
            'start at the end' => ['bytes=20-'],
            'start beyond the end' => ['bytes=100-200'],
            'empty suffix' => ['bytes=-0'],
        ];
    }

    #[DataProvider('unsatisfiableRanges')]
    public function test_an_unsatisfiable_range_yields_416_with_the_size(string $range): void
    {
        $response = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request($range), 'file.bin');

        self::assertSame(416, $response->status());
        self::assertSame('bytes */20', $response->headers()['Content-Range']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ignoredRanges(): array
    {
        return [
            'multiple ranges' => ['bytes=0-1,5-6'],
            'another unit' => ['items=0-1'],
            'first after last' => ['bytes=9-2'],
            'garbage' => ['bytes=abc'],
            'both empty' => ['bytes=-'],
        ];
    }

    #[DataProvider('ignoredRanges')]
    public function test_a_range_that_cannot_be_honoured_serves_the_whole_file(string $range): void
    {
        $response = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request($range), 'file.bin');

        self::assertSame(200, $response->status());
        self::assertSame(self::CONTENT, $this->body($response));
    }

    public function test_a_missing_file_is_a_404(): void
    {
        $response = StorageResponse::serve($this->storage, 'nope.bin', $this->request(), 'nope.bin');

        self::assertSame(404, $response->status());
    }

    public function test_content_type_and_disposition(): void
    {
        $attachment = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request(), 'Rechnung ä.pdf', 'application/pdf');
        $inline = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request(), 'a.pdf', 'application/pdf', inline: true);

        self::assertSame('application/pdf', $attachment->headers()['Content-Type']);
        self::assertStringStartsWith('attachment; filename="Rechnung .pdf"; filename*=UTF-8\'\'Rechnung%20%C3%A4.pdf', $attachment->headers()['Content-Disposition']);
        self::assertStringStartsWith('inline;', $inline->headers()['Content-Disposition']);
    }

    public function test_a_hostile_filename_cannot_break_out_of_the_header(): void
    {
        $response = StorageResponse::serve($this->storage, 'docs/file.bin', $this->request(), "a\"\r\nSet-Cookie: x=1.bin");

        $disposition = $response->headers()['Content-Disposition'];

        self::assertStringNotContainsString("\r", $disposition);
        self::assertStringNotContainsString("\n", $disposition);
        self::assertSame(1, substr_count($disposition, '"') / 2);
    }

    public function test_a_stream_that_cannot_seek_is_served_whole_without_a_content_length(): void
    {
        $storage = new StorageResponseNonSeekableStorage(self::CONTENT);

        $response = StorageResponse::serve($storage, 'f.bin', $this->request('bytes=5-9'), 'f.bin');

        self::assertSame(200, $response->status(), 'a pipe has no trustworthy size, so the Range header is ignored');
        self::assertSame(self::CONTENT, $this->body($response));
        self::assertArrayNotHasKey('Content-Length', $response->headers());
    }

    public function test_x_accel_redirect_points_at_the_internal_location(): void
    {
        $response = StorageResponse::xAccelRedirect('/protected/', 'reports/2026 q1.pdf', 'q1.pdf', 'application/pdf');

        self::assertSame(200, $response->status());
        self::assertSame('/protected/reports/2026%20q1.pdf', $response->headers()['X-Accel-Redirect']);
        self::assertSame('application/pdf', $response->headers()['Content-Type']);
        self::assertStringContainsString('filename="q1.pdf"', $response->headers()['Content-Disposition']);
    }

    public function test_x_accel_redirect_rejects_unsafe_input(): void
    {
        $this->expectException(StorageException::class);

        StorageResponse::xAccelRedirect('/protected', '../etc/passwd', 'x');
    }

    public function test_x_accel_redirect_requires_a_rooted_prefix(): void
    {
        $this->expectException(StorageException::class);

        StorageResponse::xAccelRedirect('protected', 'a.txt', 'a.txt');
    }

    public function test_x_sendfile_uses_the_absolute_path_of_the_local_driver(): void
    {
        $driver = new LocalDriver('/var/www/storage/app/');

        $response = StorageResponse::xSendfile($driver, 'reports/a.pdf', 'a.pdf');

        self::assertSame('/var/www/storage/app/reports/a.pdf', $response->headers()['X-Sendfile']);
        self::assertSame(200, $response->status());
    }

    public function test_x_sendfile_rejects_path_traversal(): void
    {
        $this->expectException(StorageException::class);

        StorageResponse::xSendfile(new LocalDriver('/var/www/storage/app'), '../secret', 'x');
    }

    public function test_local_driver_absolute_path_does_not_require_the_file_to_exist(): void
    {
        self::assertSame('/root/new/file.txt', (new LocalDriver('/root'))->absolutePath('/new/file.txt'));
    }
}
