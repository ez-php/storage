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
