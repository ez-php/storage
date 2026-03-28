<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\UploadedFile;

/**
 * Class Storage
 *
 * Static façade for the active StorageInterface instance.
 * Wired by StorageServiceProvider via setInstance().
 *
 * @package EzPhp\Storage
 */
final class Storage
{
    private static ?StorageInterface $instance = null;

    /**
     * Wire the active storage instance (called by StorageServiceProvider).
     *
     * @param StorageInterface $storage
     *
     * @return void
     */
    public static function setInstance(StorageInterface $storage): void
    {
        self::$instance = $storage;
    }

    /**
     * Return the active storage instance.
     *
     * @throws StorageException If no instance has been set.
     *
     * @return StorageInterface
     */
    public static function getInstance(): StorageInterface
    {
        if (self::$instance === null) {
            throw new StorageException(
                'Storage instance not set. Did you register StorageServiceProvider?'
            );
        }

        return self::$instance;
    }

    /**
     * Reset the active instance (primarily for testing).
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * @param string $path
     * @param string $contents
     *
     * @return bool
     */
    public static function put(string $path, string $contents): bool
    {
        return self::getInstance()->put($path, $contents);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public static function get(string $path): string
    {
        return self::getInstance()->get($path);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public static function delete(string $path): bool
    {
        return self::getInstance()->delete($path);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public static function exists(string $path): bool
    {
        return self::getInstance()->exists($path);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public static function url(string $path): string
    {
        return self::getInstance()->url($path);
    }

    /**
     * @param string       $path
     * @param UploadedFile $file
     *
     * @return bool
     */
    public static function putUploadedFile(string $path, UploadedFile $file): bool
    {
        return self::getInstance()->putUploadedFile($path, $file);
    }
}
