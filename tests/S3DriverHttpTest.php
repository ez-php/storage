<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\UploadedFile;
use EzPhp\Storage\S3Driver;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\Support\StorageLoopbackServer;

/**
 * S3Driver's own cURL layer against a plain-HTTP loopback server
 * (tests/Support/storage-api-server.php) — an explicit `http://` endpoint, as
 * used for a local MinIO. No credentials, no network.
 *
 * @package Tests
 */
#[CoversClass(S3Driver::class)]
#[UsesClass(\EzPhp\Storage\S3Signer::class)]
final class S3DriverHttpTest extends TestCase
{
    private static string $endpoint = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$endpoint = StorageLoopbackServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        StorageLoopbackServer::stop();
        parent::tearDownAfterClass();
    }

    private function driver(?string $endpoint = null): S3Driver
    {
        return new S3Driver('AKEY', 'SECRET', 'us-east-1', 'bucket', $endpoint ?? self::$endpoint);
    }

    public function test_put_sends_a_signed_request_over_plain_http(): void
    {
        $this->assertTrue($this->driver()->put('dir/file.txt', 'hello'));
    }

    public function test_get_returns_the_body_and_signs_the_request(): void
    {
        $echo = json_decode($this->driver()->get('dir/file.txt'), true);

        $this->assertIsArray($echo);
        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/dir/file.txt', $echo['uri']);
        $this->assertIsString($echo['authorization']);
        $this->assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKEY/', $echo['authorization']);
    }

    public function test_get_maps_404_and_other_errors_to_exceptions(): void
    {
        try {
            $this->driver()->get('missing.txt');
            $this->fail('404 must throw');
        } catch (StorageException $e) {
            $this->assertSame('File not found: missing.txt', $e->getMessage());
        }

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Failed to read file: broken.txt (HTTP 500)');

        $this->driver()->get('broken.txt');
    }

    public function test_get_stream_returns_a_rewound_memory_stream(): void
    {
        $stream = $this->driver()->getStream('dir/file.txt');

        $this->assertStringContainsString('"uri":"\/dir\/file.txt"', (string) stream_get_contents($stream));
    }

    public function test_get_stream_maps_404_and_other_errors_to_exceptions(): void
    {
        try {
            $this->driver()->getStream('missing.txt');
            $this->fail('404 must throw');
        } catch (StorageException $e) {
            $this->assertSame('File not found: missing.txt', $e->getMessage());
        }

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('(HTTP 500)');

        $this->driver()->getStream('broken.txt');
    }

    public function test_exists_and_delete_map_status_codes(): void
    {
        $this->assertTrue($this->driver()->exists('dir/file.txt'));
        $this->assertFalse($this->driver()->exists('missing.txt'));
        $this->assertTrue($this->driver()->delete('dir/file.txt'));
        $this->assertFalse($this->driver()->delete('broken.txt'));
    }

    public function test_presigned_url_keeps_an_explicit_http_scheme_and_defaults_to_https(): void
    {
        $this->assertStringStartsWith(self::$endpoint . '/a.txt?', $this->driver()->url('a.txt'));
        $this->assertStringStartsWith('https://minio.example.com/a.txt?', $this->driver('minio.example.com')->url('a.txt'));
        $this->assertStringStartsWith('https://minio.example.com/a.txt?', $this->driver('https://minio.example.com')->url('a.txt'));
    }

    public function test_a_connection_failure_is_a_storage_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('cURL error for GET a.txt');

        $this->driver('http://127.0.0.1:' . StorageLoopbackServer::freePort())->get('a.txt');
    }

    public function test_put_stream_requires_a_resource(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('putStream() requires a valid resource.');

        $closed = fopen('php://memory', 'r');
        self::assertIsResource($closed);
        fclose($closed); // still typed as a resource, but is_resource() is false now

        $this->driver()->putStream('a.txt', $closed);
    }

    public function test_put_uploaded_file_rejects_a_failed_upload(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Invalid uploaded file: error code ' . UPLOAD_ERR_PARTIAL);

        $this->driver()->putUploadedFile('a.txt', new UploadedFile('a.txt', 'text/plain', 1, '/tmp/none', UPLOAD_ERR_PARTIAL));
    }

    public function test_put_uploaded_file_removes_its_temp_file_when_the_move_fails(): void
    {
        $before = glob(sys_get_temp_dir() . '/s3upload_*') ?: [];
        $source = (string) tempnam(sys_get_temp_dir(), 'not-uploaded');

        try {
            // Not an HTTP upload, so move_uploaded_file() refuses it.
            $this->driver()->putUploadedFile('a.txt', new UploadedFile('a.txt', 'text/plain', 1, $source, UPLOAD_ERR_OK));
            $this->fail('the move must fail outside an HTTP upload');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to move uploaded file', $e->getMessage());
        } finally {
            unlink($source);
        }

        $this->assertSame($before, glob(sys_get_temp_dir() . '/s3upload_*') ?: []);
    }
}
