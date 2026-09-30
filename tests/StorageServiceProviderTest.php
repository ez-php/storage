<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\GcsDriver;
use EzPhp\Storage\InMemoryDriver;
use EzPhp\Storage\LocalDriver;
use EzPhp\Storage\S3Driver;
use EzPhp\Storage\Storage;
use EzPhp\Storage\StorageInterface;
use EzPhp\Storage\StorageServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\FakeConfig;
use Tests\Support\FakeContainer;

/**
 * Smoke test: StorageServiceProvider registers its binding in a minimal
 * container context without error.
 *
 * @uses \Tests\Support\FakeConfig
 * @uses \Tests\Support\FakeContainer
 */
#[CoversClass(StorageServiceProvider::class)]
#[UsesClass(LocalDriver::class)]
#[UsesClass(S3Driver::class)]
#[UsesClass(GcsDriver::class)]
#[UsesClass(InMemoryDriver::class)]
#[UsesClass(Storage::class)]
final class StorageServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Storage::resetInstance();
        parent::tearDown();
    }

    public function test_register_binds_storage_and_defaults_to_local_driver(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new StorageServiceProvider($container);

        $provider->register();

        $this->assertTrue($container->wasBound(StorageInterface::class));

        $storage = $container->make(StorageInterface::class);
        $this->assertInstanceOf(StorageInterface::class, $storage);
        $this->assertInstanceOf(LocalDriver::class, $storage);
    }

    public function test_resolving_storage_sets_the_facade(): void
    {
        $container = new FakeContainer(new FakeConfig([]));
        $provider = new StorageServiceProvider($container);

        $provider->register();
        $resolved = $container->make(StorageInterface::class);

        // The binding factory wires the Storage facade as a side effect.
        $this->assertSame($resolved, Storage::getInstance());
    }

    /**
     * @return array<string, array{array<string, mixed>, class-string<StorageInterface>}>
     */
    public static function drivers(): array
    {
        return [
            'local' => [['storage.driver' => 'local', 'storage.local.root' => '/tmp'], LocalDriver::class],
            's3' => [[
                'storage.driver' => 's3',
                'storage.s3.key' => 'key',
                'storage.s3.secret' => 'secret',
                'storage.s3.bucket' => 'bucket',
                'storage.s3.endpoint' => 'http://minio:9000',
                'storage.s3.multipart_part_size' => 0, // invalid → falls back to the 8 MiB default
            ], S3Driver::class],
            'gcs' => [['storage.driver' => 'gcs', 'storage.gcs.bucket' => 'bucket', 'storage.gcs.access_token' => 'token'], GcsDriver::class],
            'memory' => [['storage.driver' => 'memory'], InMemoryDriver::class],
            'unknown falls back to local' => [['storage.driver' => 'ftp'], LocalDriver::class],
            'non-string falls back to local' => [['storage.driver' => 42], LocalDriver::class],
        ];
    }

    /**
     * @param array<string, mixed>               $config
     * @param class-string<StorageInterface> $expected
     */
    #[DataProvider('drivers')]
    public function test_each_configured_driver_resolves_to_its_class(array $config, string $expected): void
    {
        $container = new FakeContainer(new FakeConfig($config));
        (new StorageServiceProvider($container))->register();

        $this->assertInstanceOf($expected, $container->make(StorageInterface::class));
    }

    public function test_local_driver_receives_root_and_url_from_config(): void
    {
        $root = sys_get_temp_dir() . '/ez-storage-provider-' . bin2hex(random_bytes(4));
        $container = new FakeContainer(new FakeConfig([
            'storage.local.root' => $root,
            'storage.local.url' => 'https://files.example.com',
        ]));
        (new StorageServiceProvider($container))->register();

        $storage = $container->make(StorageInterface::class);

        $this->assertInstanceOf(LocalDriver::class, $storage);
        $this->assertSame($root . '/a.txt', $storage->absolutePath('a.txt'));
        $this->assertSame('https://files.example.com/a.txt', $storage->url('a.txt'));
    }
}
