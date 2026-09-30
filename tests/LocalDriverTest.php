<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\LocalDriver;
use EzPhp\Storage\StorageException;

/**
 * Class LocalDriverTest
 *
 * @package Tests
 * @covers \EzPhp\Storage\LocalDriver
 */
final class LocalDriverTest extends TestCase
{
    private string $tmpDir;

    private LocalDriver $driver;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ez-php-storage-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
        $this->driver = new LocalDriver($this->tmpDir, 'https://cdn.example.com');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testPutAndGet(): void
    {
        $this->assertTrue($this->driver->put('hello.txt', 'Hello, World!'));
        $this->assertSame('Hello, World!', $this->driver->get('hello.txt'));
    }

    public function testPutCreatesNestedDirectories(): void
    {
        $this->assertTrue($this->driver->put('a/b/c/deep.txt', 'deep'));
        $this->assertSame('deep', $this->driver->get('a/b/c/deep.txt'));
    }

    public function testPutOverwritesExistingFile(): void
    {
        $this->driver->put('overwrite.txt', 'first');
        $this->driver->put('overwrite.txt', 'second');
        $this->assertSame('second', $this->driver->get('overwrite.txt'));
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->driver->exists('missing.txt'));
    }

    public function testExistsReturnsTrueAfterPut(): void
    {
        $this->driver->put('present.txt', '');
        $this->assertTrue($this->driver->exists('present.txt'));
    }

    public function testDeleteReturnsTrueAndRemovesFile(): void
    {
        $this->driver->put('todelete.txt', 'bye');
        $this->assertTrue($this->driver->delete('todelete.txt'));
        $this->assertFalse($this->driver->exists('todelete.txt'));
    }

    public function testDeleteReturnsFalseForNonExistentFile(): void
    {
        $this->assertFalse($this->driver->delete('nonexistent.txt'));
    }

    public function testGetRejectsPathTraversal(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->get('../../etc/passwd');
    }

    public function testPutRejectsPathTraversal(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->put('../escape.txt', 'nope');
    }

    public function testPutRejectsNulByteWithStorageException(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->put("avatar.png\0.php", 'nope');
    }

    public function testGetRejectsNulByteWithStorageException(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->get("dir/\0file.txt");
    }

    public function testExistsRejectsNulByteWithStorageException(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->exists("a\0b");
    }

    public function testPutRejectsPathTraversalInNestedSegment(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->put('a/../../escape.txt', 'nope');
    }

    public function testExistsRejectsPathTraversal(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->exists('../outside.txt');
    }

    public function testDeleteRejectsPathTraversal(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->delete('../outside.txt');
    }

    public function testPathTraversalCannotEscapeStorageRoot(): void
    {
        // Even if the exception guard were ever removed, confirm intent:
        // a traversal attempt must not be able to read a file the parent
        // directory of the storage root, proving containment end-to-end.
        $outsideFile = dirname($this->tmpDir) . '/outside-' . uniqid('', true) . '.txt';
        file_put_contents($outsideFile, 'secret');

        try {
            $this->expectException(StorageException::class);
            $this->driver->get('../' . basename($outsideFile));
        } finally {
            @unlink($outsideFile);
        }
    }

    public function testUrlAppendsPathToBaseUrl(): void
    {
        $this->assertSame(
            'https://cdn.example.com/images/photo.jpg',
            $this->driver->url('images/photo.jpg')
        );
    }

    public function testUrlHandlesLeadingSlashInPath(): void
    {
        $this->assertSame(
            'https://cdn.example.com/file.txt',
            $this->driver->url('/file.txt')
        );
    }

    public function testUrlHandlesTrailingSlashOnBaseUrl(): void
    {
        $driver = new LocalDriver($this->tmpDir, 'https://cdn.example.com/');
        $this->assertSame(
            'https://cdn.example.com/file.txt',
            $driver->url('file.txt')
        );
    }

    public function testGetThrowsForMissingFile(): void
    {
        $this->expectException(StorageException::class);
        $this->driver->get('nonexistent.txt');
    }

    public function testPutUploadedFileThrowsForInvalidUpload(): void
    {
        $file = new \EzPhp\Http\UploadedFile(
            'test.txt',
            'text/plain',
            0,
            '',
            \UPLOAD_ERR_NO_FILE,
        );

        $this->expectException(StorageException::class);
        $this->driver->putUploadedFile('test.txt', $file);
    }

    public function testGetStreamReturnsReadableResource(): void
    {
        $this->driver->put('stream/read.txt', 'streamed contents');

        $stream = $this->driver->getStream('stream/read.txt');

        $this->assertSame('streamed contents', stream_get_contents($stream));
        fclose($stream);
    }

    public function testGetStreamThrowsForMissingFile(): void
    {
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('File not found: missing.txt');

        $this->driver->getStream('missing.txt');
    }

    public function testGetStreamRejectsPathTraversal(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->getStream('../outside.txt');
    }

    public function testPutStreamCopiesResourceIntoNestedDirectory(): void
    {
        $source = fopen('php://memory', 'w+b');
        $this->assertIsResource($source);
        fwrite($source, 'from a stream');
        rewind($source);

        $this->assertTrue($this->driver->putStream('a/b/c.txt', $source));
        fclose($source);

        $this->assertSame('from a stream', $this->driver->get('a/b/c.txt'));
    }

    public function testPutStreamRejectsAClosedResource(): void
    {
        // A closed stream still has PHPStan's `resource` type but fails is_resource(),
        // which is the guard's real job (a string would already fail static analysis).
        $source = fopen('php://memory', 'rb');
        $this->assertIsResource($source);
        fclose($source);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('putStream() requires a valid resource.');

        $this->driver->putStream('x.txt', $source);
    }

    public function testAbsolutePathResolvesInsideRoot(): void
    {
        $this->assertSame($this->tmpDir . '/docs/report.pdf', $this->driver->absolutePath('docs/report.pdf'));
    }

    public function testAbsolutePathRejectsParentSegments(): void
    {
        $this->expectException(StorageException::class);

        $this->driver->absolutePath('docs/../../etc/passwd');
    }

    /**
     * move_uploaded_file() only accepts files from a real HTTP upload, so this
     * posts a multipart request to a `php -S` loopback server whose router
     * (Support/upload-server.php) calls putUploadedFile().
     */
    public function testPutUploadedFileStoresARealUpload(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl is required to post the upload.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $server = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/Support/upload-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['UPLOAD_TEST_ROOT' => $this->tmpDir],
        );
        $this->assertIsResource($server);

        try {
            [$host, $port] = explode(':', $address);
            $deadline = microtime(true) + 5;

            while (($probe = @fsockopen($host, (int) $port, $errno, $errstr, 0.2)) === false && microtime(true) < $deadline) {
                usleep(50_000);
            }

            $this->assertNotFalse($probe, 'The loopback server did not start.');
            fclose($probe);

            $upload = $this->tmpDir . '/client-upload.txt';
            file_put_contents($upload, 'uploaded bytes');

            $ch = curl_init('http://' . $address . '/');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['file' => new \CURLFile($upload, 'text/plain', 'client-upload.txt')],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $body = curl_exec($ch);

            $this->assertSame('stored', $body);
            $this->assertSame('uploaded bytes', $this->driver->get('uploads/nested/stored.txt'));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir
     *
     * @return void
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
