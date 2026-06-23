<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Storage\LocalDriver;
use EzPhp\Storage\Storage;
use EzPhp\Storage\StorageInterface;
use EzPhp\Storage\StorageServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
