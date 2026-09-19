<?php

declare(strict_types=1);

namespace EzPhp\Storage;

use EzPhp\Http\HeaderValidator;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;
use EzPhp\Http\StreamedResponse;

/**
 * Class StorageResponse
 *
 * Turns a stored file into an HTTP response, in three ways:
 *
 *  - {@see serve()} streams it from any `StorageInterface`, with `Range` support
 *    (`206 Partial Content`, `416` for unsatisfiable ranges) so browsers can seek in
 *    audio/video and resume downloads;
 *  - {@see xAccelRedirect()} / {@see xSendfile()} hand the transfer to the web server
 *    (nginx / Apache, lighttpd) for files on the local disk — PHP only checks access;
 *  - combine either with {@see SignedUrl} to protect a route with expiring links.
 *
 * Range handling follows RFC 9110 §14 for a single range (`bytes=a-b`, `bytes=a-`,
 * `bytes=-n`). Several ranges in one header are answered with the whole file, which
 * the RFC allows. `If-Range` is not evaluated.
 *
 * @package EzPhp\Storage
 */
final class StorageResponse
{
    private const int CHUNK_SIZE = 8192;

    /**
     * Stream a stored file, honouring a `Range` request header.
     *
     * @param StorageInterface $storage
     * @param string           $path        Path inside the storage.
     * @param RequestInterface $request     Read for the `Range` header.
     * @param string           $filename    Suggested file name for `Content-Disposition`.
     * @param string           $contentType MIME type of the file.
     * @param bool             $inline      `inline` (show in the browser) instead of `attachment`.
     *
     * @return ResponseInterface 200, 206, 404, or 416.
     */
    public static function serve(
        StorageInterface $storage,
        string $path,
        RequestInterface $request,
        string $filename,
        string $contentType = 'application/octet-stream',
        bool $inline = false,
    ): ResponseInterface {
        if (!$storage->exists($path)) {
            return new Response('Not Found', 404);
        }

        $stream = $storage->getStream($path);

        if (!is_resource($stream)) {
            return new Response('Not Found', 404);
        }

        // Pipes and sockets report a size of 0 and cannot be sliced: only a seekable stream has a trustworthy size.
        $stat = fstat($stream);
        $size = $stat !== false && stream_get_meta_data($stream)['seekable'] ? $stat['size'] : null;

        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => self::contentDisposition($filename, $inline),
            'Accept-Ranges' => 'bytes',
        ];

        // Without a known size there is nothing to slice: serve the whole file (chunked, no Content-Length).
        if ($size === null) {
            return new StreamedResponse(self::chunks($stream, 0, null), 200, $headers);
        }

        $range = self::parseRange($request->header('range'), $size);

        if ($range === false) {
            fclose($stream);

            return (new Response('', 416))->withHeader('Content-Range', 'bytes */' . $size);
        }

        if ($range === null) {
            $headers['Content-Length'] = (string) $size;

            return new StreamedResponse(self::chunks($stream, 0, $size), 200, $headers);
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";

        return new StreamedResponse(self::chunks($stream, $start, $length), 206, $headers);
    }

    /**
     * Let nginx serve the file: an empty response carrying `X-Accel-Redirect` that points
     * at an `internal;` location mapped to the storage directory.
     *
     * nginx:  `location /protected/ { internal; alias /var/www/storage/app/; }`
     * PHP:    `StorageResponse::xAccelRedirect('/protected', 'reports/2026.pdf', 'report.pdf')`
     *
     * @param string $internalPrefix URI prefix of the internal location, starting with `/`.
     * @param string $path           Path inside the storage; `.`/`..` segments are rejected.
     * @param string $filename       Suggested file name.
     * @param string $contentType    MIME type (nginx otherwise derives it from the extension).
     * @param bool   $inline
     *
     * @throws StorageException When the prefix or path is unsafe.
     *
     * @return ResponseInterface
     */
    public static function xAccelRedirect(
        string $internalPrefix,
        string $path,
        string $filename,
        string $contentType = 'application/octet-stream',
        bool $inline = false,
    ): ResponseInterface {
        if ($internalPrefix === '' || $internalPrefix[0] !== '/') {
            throw new StorageException('The X-Accel-Redirect prefix must start with "/".');
        }

        $encoded = implode('/', array_map(rawurlencode(...), explode('/', self::assertSafePath($path))));

        return (new Response('', 200))
            ->withHeader('X-Accel-Redirect', rtrim($internalPrefix, '/') . '/' . $encoded)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', self::contentDisposition($filename, $inline));
    }

    /**
     * Let Apache (`mod_xsendfile`) or lighttpd serve a file of a `LocalDriver`: an empty
     * response carrying `X-Sendfile` with the absolute path.
     *
     * @param LocalDriver $driver
     * @param string      $path        Path inside the storage; `.`/`..` segments are rejected.
     * @param string      $filename    Suggested file name.
     * @param string      $contentType
     * @param bool        $inline
     *
     * @throws StorageException When the path is unsafe.
     *
     * @return ResponseInterface
     */
    public static function xSendfile(
        LocalDriver $driver,
        string $path,
        string $filename,
        string $contentType = 'application/octet-stream',
        bool $inline = false,
    ): ResponseInterface {
        return (new Response('', 200))
            ->withHeader('X-Sendfile', $driver->absolutePath($path))
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', self::contentDisposition($filename, $inline));
    }

    /**
     * Parse a `Range` header value for a resource of `$size` bytes.
     *
     * @param mixed $header Raw header value, or null.
     * @param int   $size
     *
     * @return array{0: int, 1: int}|false|null [start, end] inclusive; false when the range cannot be
     *                                          satisfied (answer 416); null when there is no usable
     *                                          `Range` header (answer with the whole file).
     */
    private static function parseRange(mixed $header, int $size): array|false|null
    {
        if (!is_string($header) || preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m) !== 1) {
            // Absent, another unit, or several ranges ("bytes=0-1,5-6"): ignore and send everything.
            return null;
        }

        [, $first, $last] = $m;

        if ($first === '' && $last === '') {
            return null;
        }

        if ($first === '') {
            // Suffix range: the last N bytes.
            $suffix = (int) $last;

            if ($suffix === 0 || $size === 0) {
                return false;
            }

            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $first;

        if ($start >= $size) {
            return false;
        }

        $end = $last === '' ? $size - 1 : min((int) $last, $size - 1);

        if ($end < $start) {
            return null; // Syntactically invalid (first > last): RFC 9110 says ignore the header.
        }

        return [$start, $end];
    }

    /**
     * Chunk generator over a stream; closes it when done, failed, or abandoned.
     *
     * @param resource $stream
     * @param int      $start  Byte offset to begin at.
     * @param int|null $length Bytes to send; null = until the end.
     *
     * @return \Closure(): \Generator<int, string>
     */
    private static function chunks(mixed $stream, int $start, ?int $length): \Closure
    {
        return static function () use ($stream, $start, $length): \Generator {
            try {
                if ($start > 0) {
                    $meta = stream_get_meta_data($stream);

                    if ($meta['seekable'] && fseek($stream, $start) === 0) {
                        $skipped = $start;
                    } else {
                        $skipped = 0;

                        while ($skipped < $start && !feof($stream)) {
                            $read = fread($stream, max(1, min(self::CHUNK_SIZE, $start - $skipped)));

                            if ($read === false || $read === '') {
                                break;
                            }

                            $skipped += strlen($read);
                        }
                    }
                }

                $remaining = $length;

                while (!feof($stream) && ($remaining === null || $remaining > 0)) {
                    $want = $remaining === null ? self::CHUNK_SIZE : min(self::CHUNK_SIZE, $remaining);
                    $chunk = fread($stream, max(1, $want));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    if ($remaining !== null) {
                        $remaining -= strlen($chunk);
                    }

                    yield $chunk;
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        };
    }

    /**
     * RFC 6266 Content-Disposition with an ASCII fallback and a UTF-8 `filename*`; the fallback
     * drops `"`, `\` and non-printable characters, `filename*` is percent-encoded, so neither can
     * carry CR/LF or break out of the quoted string.
     *
     * @param string $filename
     * @param bool   $inline
     *
     * @return string
     */
    private static function contentDisposition(string $filename, bool $inline): string
    {
        $fallback = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/', '', $filename);
        $value = sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', $fallback, rawurlencode($filename));

        HeaderValidator::assertValid('Content-Disposition', $value);

        return $value;
    }

    /**
     * @param string $path
     *
     * @throws StorageException
     *
     * @return string The path without a leading slash.
     */
    private static function assertSafePath(string $path): string
    {
        $trimmed = ltrim($path, '/');

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new StorageException("Invalid path: {$path}");
            }
        }

        return $trimmed;
    }
}
