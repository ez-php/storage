<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\UploadedFile;
use EzPhp\Storage\GcsDriver;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Tests\Support\StorageLoopbackServer;

/**
 * GcsDriver's own cURL layer against a plain-HTTP loopback server
 * (tests/Support/storage-api-server.php), reached through the `endpoint`
 * override an emulator such as fake-gcs-server uses. No credentials, no network.
 *
 * @package Tests
 */
#[CoversClass(GcsDriver::class)]
final class GcsDriverHttpTest extends TestCase
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

    private function driver(?string $endpoint = null): GcsDriver
    {
        return new GcsDriver('my-bucket', 'token-123', endpoint: $endpoint ?? self::$endpoint);
    }

    public function test_put_posts_the_media_to_the_upload_api(): void
    {
        $this->assertTrue($this->driver()->put('dir/a.txt', 'hello'));
    }

    public function test_get_reads_through_the_json_api_with_the_bearer_token(): void
    {
        $echo = json_decode($this->driver()->get('dir/a.txt'), true);

        $this->assertIsArray($echo);
        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/storage/v1/b/my-bucket/o/dir%2Fa.txt?alt=media', $echo['uri']);
        $this->assertSame('Bearer token-123', $echo['authorization']);
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
        $this->expectExceptionMessage('Failed to read file: broken.txt');

        $this->driver()->get('broken.txt');
    }

    public function test_exists_delete_and_get_stream(): void
    {
        $this->assertTrue($this->driver()->exists('a.txt'));
        $this->assertFalse($this->driver()->exists('missing.txt'));
        $this->assertTrue($this->driver()->delete('a.txt'));
        $this->assertFalse($this->driver()->delete('broken.txt'));

        $stream = $this->driver()->getStream('a.txt');
        $this->assertStringContainsString('alt=media', (string) stream_get_contents($stream));
    }

    public function test_the_endpoint_is_also_the_base_of_public_urls(): void
    {
        $this->assertSame(self::$endpoint . '/my-bucket/dir/a.txt', $this->driver()->url('dir/a.txt'));
        $this->assertSame(self::$endpoint . '/my-bucket/a.txt', $this->driver(self::$endpoint . '/')->url('a.txt'));
        $this->assertSame('https://storage.googleapis.com/my-bucket/a.txt', (new GcsDriver('my-bucket', 't'))->url('a.txt'));
    }

    public function test_a_connection_failure_is_a_storage_exception(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('cURL error for GET');

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
        $this->expectExceptionMessage('Invalid uploaded file: error code ' . UPLOAD_ERR_NO_FILE);

        $this->driver()->putUploadedFile('a.txt', new UploadedFile('a.txt', 'text/plain', 0, '', UPLOAD_ERR_NO_FILE));
    }

    public function test_put_uploaded_file_removes_its_temp_file_when_the_move_fails(): void
    {
        $before = glob(sys_get_temp_dir() . '/gcs_upload_*') ?: [];
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

        $this->assertSame($before, glob(sys_get_temp_dir() . '/gcs_upload_*') ?: []);
    }
}
