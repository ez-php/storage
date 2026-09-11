<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\UploadedFile;

/**
 * Class LocalDriver
 *
 * Stores files on the local filesystem within a configurable root directory.
 * Nested directories are created automatically on write.
 *
 * @package EzPhp\Storage
 */
final class LocalDriver implements StorageInterface
{
    /**
     * @param string $root    Absolute path to the storage root directory.
     * @param string $baseUrl Public base URL used when generating file URLs.
     */
    public function __construct(
        private readonly string $root,
        private readonly string $baseUrl = '',
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->fullPath($path);

        $this->ensureDirectory(dirname($fullPath));

        return file_put_contents($fullPath, $contents) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $path): string
    {
        $fullPath = $this->fullPath($path);

        if (!file_exists($fullPath)) {
            throw new StorageException("File not found: {$path}");
        }

        $contents = file_get_contents($fullPath);

        if ($contents === false) {
            throw new StorageException("Failed to read file: {$path}");
        }

        return $contents;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);

        if (!file_exists($fullPath)) {
            return false;
        }

        return unlink($fullPath);
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    /**
     * {@inheritdoc}
     */
    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * {@inheritdoc}
     */
    public function getStream(string $path): mixed
    {
        $fullPath = $this->fullPath($path);

        if (!file_exists($fullPath)) {
            throw new StorageException("File not found: {$path}");
        }

        $stream = fopen($fullPath, 'rb');

        if ($stream === false) {
            throw new StorageException("Failed to open stream for: {$path}");
        }

        return $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function putStream(string $path, mixed $stream): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('putStream() requires a valid resource.');
        }

        $fullPath = $this->fullPath($path);

        $this->ensureDirectory(dirname($fullPath));

        $dest = fopen($fullPath, 'wb');

        if ($dest === false) {
            throw new StorageException("Failed to open destination for writing: {$path}");
        }

        $copied = stream_copy_to_stream($stream, $dest);
        fclose($dest);

        if ($copied === false) {
            throw new StorageException("Failed to write stream to: {$path}");
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function putUploadedFile(string $path, UploadedFile $file): bool
    {
        if (!$file->isValid()) {
            throw new StorageException("Invalid uploaded file: error code {$file->error()}");
        }

        $fullPath = $this->fullPath($path);

        $this->ensureDirectory(dirname($fullPath));

        $file->moveTo($fullPath);

        return true;
    }

    /**
     * Resolve a storage-relative path to an absolute filesystem path.
     *
     * @param string $path Relative path.
     *
     * @throws StorageException If the path contains a `.` or `..` segment
     *                          that could escape the storage root.
     *
     * @return string Absolute path within the storage root.
     */
    private function fullPath(string $path): string
    {
        return rtrim($this->root, '/') . '/' . $this->assertSafeRelativePath($path);
    }

    /**
     * Reject a path containing `.` or `..` segments, which could otherwise
     * be used to escape the storage root (e.g. `../../etc/passwd`).
     *
     * Checked segment-by-segment rather than via `realpath()` so this also
     * works for paths that do not exist yet (e.g. `put()` of a new file).
     *
     * @param string $path Relative path as supplied by the caller.
     *
     * @throws StorageException If the path contains a `.` or `..` segment.
     *
     * @return string The path, unmodified, once validated.
     */
    private function assertSafeRelativePath(string $path): string
    {
        $trimmed = ltrim($path, '/');

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new StorageException("Invalid path: {$path}");
            }
        }

        return $trimmed;
    }

    /**
     * Create a directory and all intermediate directories if they do not exist.
     *
     * @param string $dir Absolute directory path.
     *
     * @throws StorageException If the directory cannot be created.
     *
     * @return void
     */
    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new StorageException("Failed to create directory: {$dir}");
        }
    }
}
