<?php

declare(strict_types=1);

/**
 * Router for the `php -S` loopback server behind StorageLoopbackServer — a
 * stand-in object-storage API for the S3/GCS drivers' cURL layer (plain HTTP).
 *
 * - a URI containing `missing` answers 404, one containing `broken` answers 500;
 * - HEAD answers 200 with no body;
 * - every other request answers 200 with a JSON echo of method, URI, the
 *   Authorization header and the body, plus an `X-Storage-Test` header.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri = is_string($requestUri) ? $requestUri : '/';

if (str_contains($uri, 'missing')) {
    http_response_code(404);

    return;
}

if (str_contains($uri, 'broken')) {
    http_response_code(500);
    echo 'internal error';

    return;
}

header('X-Storage-Test: yes');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    return;
}

header('Content-Type: application/json');

echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $uri,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
    'body' => file_get_contents('php://input'),
], JSON_THROW_ON_ERROR);
