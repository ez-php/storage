<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Starts and stops the `php -S` loopback server (storage-api-server.php) that the
 * S3/GCS HTTP tests point the drivers' endpoint at — no network, no credentials.
 *
 * @package Tests\Support
 */
final class StorageLoopbackServer
{
    /** @var resource|null */
    private static $process = null;

    private static int $port = 0;

    /**
     * Start the server (once per test class) and return its base URL.
     *
     * @return string e.g. `http://127.0.0.1:41234`
     */
    public static function start(): string
    {
        if (self::$process === null) {
            self::$port = self::freePort();

            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/storage-api-server.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Could not start the storage loopback server.');
            }

            self::$process = $process;
            self::awaitConnections();
        }

        return 'http://127.0.0.1:' . self::$port;
    }

    /**
     * Stop the server if it is running.
     *
     * @return void
     */
    public static function stop(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        self::$process = null;
    }

    /**
     * A port nothing listens on (reserved and released again).
     *
     * @return int
     */
    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port: ' . $errstr);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return void
     */
    private static function awaitConnections(): void
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(50_000);
        }

        self::stop();

        throw new RuntimeException('The storage loopback server did not accept connections within 5 seconds.');
    }
}
