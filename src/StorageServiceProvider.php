<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\ServiceProvider;

/**
 * Class StorageServiceProvider
 *
 * Reads the storage configuration and binds the active StorageInterface
 * driver to the container. Also wires the Storage static façade.
 *
 * Supported drivers: local (default), s3.
 *
 * @package EzPhp\Storage
 */
final class StorageServiceProvider extends ServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register(): void
    {
        $this->app->bind(StorageInterface::class, function (ContainerInterface $app): StorageInterface {
            $config = $app->make(ConfigInterface::class);
            $driver = $config->get('storage.driver', 'local');
            $driver = is_string($driver) ? $driver : 'local';

            $storage = match ($driver) {
                's3' => $this->makeS3($config),
                default => $this->makeLocal($config),
            };

            Storage::setInstance($storage);

            return $storage;
        });
    }

    /**
     * Create a LocalDriver from config.
     *
     * @param ConfigInterface $config
     *
     * @return LocalDriver
     */
    private function makeLocal(ConfigInterface $config): LocalDriver
    {
        $root = $config->get('storage.local.root', '');
        $url = $config->get('storage.local.url', '');

        return new LocalDriver(
            is_string($root) ? $root : '',
            is_string($url) ? $url : '',
        );
    }

    /**
     * Create an S3Driver from config.
     *
     * @param ConfigInterface $config
     *
     * @return S3Driver
     */
    private function makeS3(ConfigInterface $config): S3Driver
    {
        $key = $config->get('storage.s3.key', '');
        $secret = $config->get('storage.s3.secret', '');
        $region = $config->get('storage.s3.region', 'us-east-1');
        $bucket = $config->get('storage.s3.bucket', '');
        $endpoint = $config->get('storage.s3.endpoint');
        $url = $config->get('storage.s3.url');
        $expiry = $config->get('storage.s3.url_expiry', 3600);

        return new S3Driver(
            is_string($key) ? $key : '',
            is_string($secret) ? $secret : '',
            is_string($region) ? $region : 'us-east-1',
            is_string($bucket) ? $bucket : '',
            is_string($endpoint) ? $endpoint : null,
            is_string($url) ? $url : null,
            is_int($expiry) ? $expiry : 3600,
        );
    }
}
