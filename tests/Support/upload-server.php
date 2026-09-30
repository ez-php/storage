<?php

declare(strict_types=1);

/**
 * Router for the `php -S` loopback server started by LocalDriverTest.
 *
 * Stores the multipart field `file` through LocalDriver::putUploadedFile() under
 * the root in UPLOAD_TEST_ROOT — a real HTTP upload, which is the only way
 * move_uploaded_file() accepts a file.
 */

use EzPhp\Http\UploadedFile;
use EzPhp\Storage\LocalDriver;

foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        break;
    }
}

/** @var array{name: string, type: string, size: int, tmp_name: string, error: int} $upload */
$upload = $_FILES['file'];
$file = new UploadedFile($upload['name'], $upload['type'], $upload['size'], $upload['tmp_name'], $upload['error']);
$driver = new LocalDriver((string) getenv('UPLOAD_TEST_ROOT'), 'https://cdn.example.com');

try {
    echo $driver->putUploadedFile('uploads/nested/stored.txt', $file) ? 'stored' : 'not stored';
} catch (Throwable $e) {
    http_response_code(500);
    echo $e::class . ': ' . $e->getMessage();
}
