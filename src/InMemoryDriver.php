<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\UploadedFile;

/**
 * Class InMemoryDriver
 *
 * Keeps file contents in a PHP array for the lifetime of the process. Exists so
 * that code depending on {@see StorageInterface} can be tested without touching
 * the filesystem or reaching S3 — the same role `ArrayDriver` plays in
 * `ez-php/cache`, `ez-php/broadcast`, `ez-php/feature-flags` and
 * `ez-php/rate-limiter`.
 *
 * Named `InMemoryDriver` rather than `ArrayDriver` because the array is an
 * implementation detail: what it models is a filesystem, not a key-value map.
 *
 * **Not for production.** Nothing is persisted and nothing is shared between
 * processes or requests.
 *
 * @package EzPhp\Storage
 */
final class InMemoryDriver implements StorageInterface
{
    /**
     * Stored file contents, keyed by normalised path.
     *
     * @var array<string, string>
     */
    private array $files = [];

    /**
     * @param string $path
     * @param string $contents
     *
     * @throws StorageException On path traversal.
     *
     * @return bool Always true.
     */
    public function put(string $path, string $contents): bool
    {
        $this->files[$this->normalise($path)] = $contents;

        return true;
    }

    /**
     * @param string $path
     *
     * @throws StorageException If the file does not exist, or on path traversal.
     *
     * @return string
     */
    public function get(string $path): string
    {
        $key = $this->normalise($path);

        if (!array_key_exists($key, $this->files)) {
            throw new StorageException("File not found: {$path}");
        }

        return $this->files[$key];
    }

    /**
     * @param string $path
     *
     * @throws StorageException On path traversal.
     *
     * @return bool True when a file was removed, false when it did not exist.
     */
    public function delete(string $path): bool
    {
        $key = $this->normalise($path);

        if (!array_key_exists($key, $this->files)) {
            return false;
        }

        unset($this->files[$key]);

        return true;
    }

    /**
     * @param string $path
     *
     * @throws StorageException On path traversal.
     *
     * @return bool
     */
    public function exists(string $path): bool
    {
        return array_key_exists($this->normalise($path), $this->files);
    }

    /**
     * Return a `memory://` pseudo-URL for the path.
     *
     * Nothing serves these files over HTTP, so there is no real URL to return.
     * A distinctive scheme makes it obvious in test output that the in-memory
     * driver is in use, rather than silently returning a plausible-looking path.
     *
     * @param string $path
     *
     * @throws StorageException On path traversal.
     *
     * @return string
     */
    public function url(string $path): string
    {
        return 'memory://' . $this->normalise($path);
    }

    /**
     * Store an uploaded file.
     *
     * Mirrors `S3Driver`: `UploadedFile::moveTo()` wraps `move_uploaded_file()`,
     * which only succeeds for a genuine HTTP upload and must target a real path,
     * so the upload is moved to a temp file, read, and then discarded.
     *
     * @param string       $path
     * @param UploadedFile $file
     *
     * @throws StorageException If the upload is invalid or cannot be read.
     *
     * @return bool
     */
    public function putUploadedFile(string $path, UploadedFile $file): bool
    {
        if (!$file->isValid()) {
            throw new StorageException("Invalid uploaded file: error code {$file->error()}");
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'memupload_');

        if ($tmpPath === false) {
            throw new StorageException('Failed to create temporary file for in-memory upload.');
        }

        try {
            $file->moveTo($tmpPath);

            $contents = file_get_contents($tmpPath);

            if ($contents === false) {
                throw new StorageException("Failed to read uploaded file: {$file->originalName()}");
            }

            return $this->put($path, $contents);
        } finally {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    /**
     * Open a readable in-memory stream over the stored contents.
     *
     * @param string $path
     *
     * @throws StorageException If the file does not exist or the stream cannot be opened.
     *
     * @return resource
     */
    public function getStream(string $path): mixed
    {
        $contents = $this->get($path);

        $stream = fopen('php://memory', 'r+b');

        if ($stream === false) {
            throw new StorageException("Failed to open stream for: {$path}");
        }

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param string   $path
     * @param resource $stream
     *
     * @throws StorageException If $stream is not a resource or cannot be read.
     *
     * @return bool
     */
    public function putStream(string $path, mixed $stream): bool
    {
        if (!is_resource($stream)) {
            throw new StorageException('putStream() requires a valid resource.');
        }

        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw new StorageException("Failed to read stream for: {$path}");
        }

        return $this->put($path, $contents);
    }

    /**
     * Return every stored file, keyed by normalised path.
     *
     * Test-support only — lets a test assert what was written without going
     * through `get()` for each path.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->files;
    }

    /**
     * Discard all stored files.
     *
     * Test-support only — useful in `tearDown()` when the driver is shared.
     *
     * @return void
     */
    public function flush(): void
    {
        $this->files = [];
    }

    /**
     * Normalise a path to its storage key and reject traversal.
     *
     * There is no real root directory to escape here, but `..` is still rejected:
     * a test passing a traversing path must behave like `LocalDriver`, which
     * throws. Silently accepting it would let a traversal bug pass its tests and
     * only surface against the real driver in production.
     *
     * @param string $path
     *
     * @throws StorageException When the path contains a `..` segment.
     *
     * @return string
     */
    private function normalise(string $path): string
    {
        $trimmed = ltrim($path, '/');

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '..') {
                throw new StorageException("Path traversal is not allowed: {$path}");
            }
        }

        return $trimmed;
    }
}
