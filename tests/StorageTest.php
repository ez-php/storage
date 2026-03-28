<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\LocalDriver;
use EzPhp\Storage\Storage;
use EzPhp\Storage\StorageException;

/**
 * Class StorageTest
 *
 * @package Tests
 * @covers \EzPhp\Storage\Storage
 */
final class StorageTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        Storage::resetInstance();
        $this->tmpDir = sys_get_temp_dir() . '/ez-php-storage-facade-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        Storage::resetInstance();

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testGetInstanceThrowsWithoutInstance(): void
    {
        $this->expectException(StorageException::class);
        Storage::getInstance();
    }

    public function testSetAndGetInstance(): void
    {
        $driver = new LocalDriver($this->tmpDir);
        Storage::setInstance($driver);

        $this->assertSame($driver, Storage::getInstance());
    }

    public function testResetInstanceClearsInstance(): void
    {
        Storage::setInstance(new LocalDriver($this->tmpDir));
        Storage::resetInstance();

        $this->expectException(StorageException::class);
        Storage::getInstance();
    }

    public function testFacadeDelegatesToDriver(): void
    {
        Storage::setInstance(new LocalDriver($this->tmpDir, 'https://cdn.test'));

        Storage::put('test.txt', 'facade content');

        $this->assertTrue(Storage::exists('test.txt'));
        $this->assertSame('facade content', Storage::get('test.txt'));
        $this->assertSame('https://cdn.test/test.txt', Storage::url('test.txt'));
        $this->assertTrue(Storage::delete('test.txt'));
        $this->assertFalse(Storage::exists('test.txt'));
    }
}
