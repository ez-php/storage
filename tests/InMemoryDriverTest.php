<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\UploadedFile;
use EzPhp\Storage\InMemoryDriver;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class InMemoryDriverTest
 *
 * Mirrors LocalDriverTest's contract surface so driver parity is visible.
 * Nothing touches the filesystem.
 *
 * @package Tests
 */
#[CoversClass(InMemoryDriver::class)]
final class InMemoryDriverTest extends TestCase
{
    private InMemoryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = new InMemoryDriver();
    }

    // ── put / get ─────────────────────────────────────────────────────────────

    public function testPutAndGet(): void
    {
        $this->driver->put('file.txt', 'hello');

        $this->assertSame('hello', $this->driver->get('file.txt'));
    }

    public function testPutOverwritesExistingFile(): void
    {
        $this->driver->put('file.txt', 'first');
        $this->driver->put('file.txt', 'second');

        $this->assertSame('second', $this->driver->get('file.txt'));
    }

    public function testPutHandlesNestedPaths(): void
    {
        $this->driver->put('a/b/c/file.txt', 'nested');

        $this->assertSame('nested', $this->driver->get('a/b/c/file.txt'));
    }

    public function testPutReturnsTrue(): void
    {
        $this->assertTrue($this->driver->put('file.txt', 'x'));
    }

    public function testGetThrowsForMissingFile(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->get('missing.txt');
    }

    public function testEmptyContentsRoundTrip(): void
    {
        $this->driver->put('empty.txt', '');

        $this->assertTrue($this->driver->exists('empty.txt'));
        $this->assertSame('', $this->driver->get('empty.txt'));
    }

    public function testBinaryContentsRoundTrip(): void
    {
        $binary = random_bytes(256);
        $this->driver->put('blob.bin', $binary);

        $this->assertSame($binary, $this->driver->get('blob.bin'));
    }

    // ── exists / delete ───────────────────────────────────────────────────────

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->driver->exists('nope.txt'));
    }

    public function testExistsReturnsTrueAfterPut(): void
    {
        $this->driver->put('file.txt', 'x');

        $this->assertTrue($this->driver->exists('file.txt'));
    }

    public function testDeleteReturnsTrueAndRemovesFile(): void
    {
        $this->driver->put('file.txt', 'x');

        $this->assertTrue($this->driver->delete('file.txt'));
        $this->assertFalse($this->driver->exists('file.txt'));
    }

    public function testDeleteReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse($this->driver->delete('nope.txt'));
    }

    // ── path normalisation ────────────────────────────────────────────────────

    public function testLeadingSlashIsNormalised(): void
    {
        $this->driver->put('/file.txt', 'x');

        $this->assertSame('x', $this->driver->get('file.txt'));
        $this->assertTrue($this->driver->exists('/file.txt'));
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->put('../escape.txt', 'x');
    }

    public function testPathTraversalIsRejectedOnRead(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->get('a/../../escape.txt');
    }

    // ── url ───────────────────────────────────────────────────────────────────

    public function testUrlReturnsAnInMemoryScheme(): void
    {
        $this->driver->put('file.txt', 'x');

        $this->assertSame('memory://file.txt', $this->driver->url('file.txt'));
    }

    // ── streams ───────────────────────────────────────────────────────────────

    public function testGetStreamReturnsReadableResource(): void
    {
        $this->driver->put('file.txt', 'streamed');

        $stream = $this->driver->getStream('file.txt');

        $this->assertIsResource($stream);
        $this->assertSame('streamed', stream_get_contents($stream));

        fclose($stream);
    }

    public function testGetStreamThrowsForMissingFile(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->getStream('missing.txt');
    }

    public function testPutStreamStoresContents(): void
    {
        $source = fopen('php://memory', 'r+');
        self::assertIsResource($source);
        fwrite($source, 'from stream');
        rewind($source);

        $this->assertTrue($this->driver->putStream('file.txt', $source));
        $this->assertSame('from stream', $this->driver->get('file.txt'));

        fclose($source);
    }

    // putStream()'s non-resource guard is deliberately not tested: the parameter
    // is documented `@param resource`, so passing a string fails PHPStan level 9
    // before the guard can run. LocalDriver's identical guard is untested for the
    // same reason.

    public function testStreamRoundTrip(): void
    {
        $source = fopen('php://memory', 'r+');
        self::assertIsResource($source);
        fwrite($source, 'round trip');
        rewind($source);

        $this->driver->putStream('a.txt', $source);
        fclose($source);

        $out = $this->driver->getStream('a.txt');
        $this->assertSame('round trip', stream_get_contents($out));
        fclose($out);
    }

    // ── uploads ───────────────────────────────────────────────────────────────

    public function testPutUploadedFileThrowsForInvalidUpload(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->putUploadedFile(
            'file.txt',
            new UploadedFile('test.txt', 'text/plain', 0, '', UPLOAD_ERR_NO_FILE),
        );
    }

    // ── test-support helpers ──────────────────────────────────────────────────

    public function testAllReturnsEveryStoredPath(): void
    {
        $this->driver->put('a.txt', '1');
        $this->driver->put('b/c.txt', '2');

        $this->assertSame(['a.txt', 'b/c.txt'], array_keys($this->driver->all()));
    }

    public function testFlushEmptiesTheStore(): void
    {
        $this->driver->put('a.txt', '1');
        $this->driver->flush();

        $this->assertSame([], $this->driver->all());
        $this->assertFalse($this->driver->exists('a.txt'));
    }

    public function testStoreIsPerInstance(): void
    {
        $this->driver->put('a.txt', '1');

        $this->assertFalse((new InMemoryDriver())->exists('a.txt'));
    }
}
