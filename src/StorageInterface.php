<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\UploadedFile;

/**
 * Interface StorageInterface
 *
 * Unified contract for file storage drivers.
 * Provides put, get, delete, exists, and url operations
 * along with UploadedFile integration.
 *
 * @package EzPhp\Storage
 */
interface StorageInterface
{
    /**
     * Write contents to a file at the given path.
     *
     * @param string $path     Relative path within the storage.
     * @param string $contents File contents to write.
     *
     * @throws StorageException If the file cannot be written.
     *
     * @return bool True on success.
     */
    public function put(string $path, string $contents): bool;

    /**
     * Read and return the contents of a file.
     *
     * @param string $path Relative path within the storage.
     *
     * @throws StorageException If the file does not exist or cannot be read.
     *
     * @return string File contents.
     */
    public function get(string $path): string;

    /**
     * Delete a file at the given path.
     *
     * @param string $path Relative path within the storage.
     *
     * @return bool True on success, false if the file did not exist.
     */
    public function delete(string $path): bool;

    /**
     * Determine whether a file exists at the given path.
     *
     * @param string $path Relative path within the storage.
     *
     * @return bool True if the file exists.
     */
    public function exists(string $path): bool;

    /**
     * Return a publicly accessible URL for the given path.
     *
     * For the LocalDriver this is a base URL + path.
     * For the S3Driver this is a presigned GET URL (or a custom CDN URL if configured).
     *
     * @param string $path Relative path within the storage.
     *
     * @return string Public URL.
     */
    public function url(string $path): string;

    /**
     * Store a validated uploaded file at the given path.
     *
     * @param string       $path File path where the upload will be stored.
     * @param UploadedFile $file Valid uploaded file (isValid() must return true).
     *
     * @throws StorageException If the upload is invalid or cannot be stored.
     *
     * @return bool True on success.
     */
    public function putUploadedFile(string $path, UploadedFile $file): bool;
}
