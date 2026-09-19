# ez-php/storage

File storage abstraction for the ez-php framework.

Provides a unified interface (`put`, `get`, `delete`, `exists`, `url`) over pluggable drivers. Ships with a **LocalDriver** (filesystem), an **S3Driver** (AWS S3 and S3-compatible APIs via cURL + AWS Signature V4), and a **GcsDriver** (Google Cloud Storage via cURL + Bearer access token). Integrates with `UploadedFile` from `ez-php/http`.

---

## Installation

```bash
composer require ez-php/storage
```

---

## Configuration

Add `config/storage.php` to your application:

```php
return [
    'driver' => env('STORAGE_DRIVER', 'local'),

    'local' => [
        'root' => env('STORAGE_ROOT', storage_path('app')),
        'url'  => env('STORAGE_URL', ''),
    ],

    's3' => [
        'key'        => env('AWS_ACCESS_KEY_ID'),
        'secret'     => env('AWS_SECRET_ACCESS_KEY'),
        'region'     => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket'     => env('AWS_BUCKET'),
        'endpoint'   => env('AWS_ENDPOINT'),   // optional: MinIO, R2, etc.
        'url'        => env('AWS_URL'),         // optional: CDN base URL
        'url_expiry' => 3600,
        'multipart_part_size' => 8 * 1024 * 1024, // putStream(): larger streams use multipart upload
    ],

    'gcs' => [
        'bucket'       => env('GCS_BUCKET'),
        'access_token' => env('GCS_ACCESS_TOKEN'), // OAuth2 Bearer token; caller refreshes it
        'url'          => env('GCS_URL'),          // optional: CDN base URL
    ],
];
```

Register the provider in `provider/modules.php`:

```php
\EzPhp\Storage\StorageServiceProvider::class,
```

---

## Usage

### Via static façade

```php
use EzPhp\Storage\Storage;

Storage::put('avatars/user-1.jpg', $imageBytes);
$data = Storage::get('avatars/user-1.jpg');
Storage::exists('avatars/user-1.jpg'); // true
Storage::url('avatars/user-1.jpg');    // public URL or presigned URL
Storage::delete('avatars/user-1.jpg');
```

### Storing an uploaded file

```php
Storage::putUploadedFile('uploads/' . $file->originalName(), $file);
```

### Via dependency injection

```php
use EzPhp\Storage\StorageInterface;

class AvatarController
{
    public function __construct(private readonly StorageInterface $storage) {}

    public function upload(Request $request): Response
    {
        $file = $request->file('avatar');
        $this->storage->putUploadedFile('avatars/' . $file->originalName(), $file);
        // ...
    }
}
```

---

## Serving files over HTTP

`StorageResponse` turns a stored file into a response — with `Range` support so browsers can seek in audio/video and resume downloads:

```php
use EzPhp\Storage\StorageResponse;

// controller: GET /files/{path}
return StorageResponse::serve($storage, $path, $request, filename: 'report.pdf', contentType: 'application/pdf', inline: true);
```

| Request | Response |
|---|---|
| no `Range` | `200`, `Content-Length`, `Accept-Ranges: bytes` |
| `Range: bytes=2-5` / `bytes=15-` / `bytes=-4` | `206` + `Content-Range: bytes 2-5/20` |
| unsatisfiable (`bytes=100-` on a 20-byte file) | `416` + `Content-Range: bytes */20` |
| several ranges, another unit, malformed | `200`, the whole file |
| unknown path | `404` |

`Range` needs a seekable stream (local files, in-memory); for a non-seekable remote stream the whole file is streamed without `Content-Length`. `If-Range` is not evaluated.

**Let the web server send the file** (PHP only checks access):

```php
// nginx:  location /protected/ { internal; alias /var/www/storage/app/; }
return StorageResponse::xAccelRedirect('/protected', $path, 'report.pdf', 'application/pdf');

// Apache mod_xsendfile / lighttpd, LocalDriver only:
return StorageResponse::xSendfile($localDriver, $path, 'report.pdf', 'application/pdf');
```

**Expiring links for the `LocalDriver`** (the S3 driver's `url()` is already presigned):

```php
$signer = new SignedUrl($_ENV['FILES_SECRET'], 'https://app.test/files');   // secret >= 16 bytes
$link   = $signer->make('reports/2026.pdf', ttl: 600);                     // …?expires=…&signature=…

// in the /files/{path} handler
if (!$signer->verifyRequest($path, $request)) {
    return new Response('Forbidden', 403);
}
return StorageResponse::serve($storage, $path, $request, basename($path));
```

The signature is HMAC-SHA256 over the path and the expiry, compared in constant time; changing either invalidates it. A link is valid until it expires (no single-use tracking).

## Drivers

### LocalDriver

Stores files under a configurable root directory. Creates nested directories automatically.

```php
$driver = new LocalDriver('/var/www/storage', 'https://cdn.example.com');
$driver->put('docs/readme.txt', 'Hello');
$driver->url('docs/readme.txt'); // https://cdn.example.com/docs/readme.txt
```

### S3Driver

Uploads and retrieves objects using cURL with AWS Signature Version 4. Works with AWS S3 and any S3-compatible service (MinIO, Cloudflare R2, DigitalOcean Spaces).

- `url()` returns a presigned GET URL (valid for `url_expiry` seconds)
- If a custom `url` (CDN) is configured, `url()` returns `{url}/{path}` instead
- Custom `endpoint` overrides the default `{bucket}.s3.{region}.amazonaws.com` host
- `putStream()` uploads streams larger than `multipart_part_size` (default 8 MiB) with S3 multipart upload, one part in memory at a time; failed uploads are aborted. Parts must be ≥ 5 MiB except the last (S3 rule).

```php
$driver = new S3Driver('key', 'secret', 'eu-west-1', 'my-bucket');
$driver->put('report.pdf', file_get_contents('/tmp/report.pdf'));
$driver->url('report.pdf'); // presigned URL
```

### GcsDriver

Uploads and retrieves objects using cURL against the Google Cloud Storage JSON API,
authenticated with a Bearer access token you supply (e.g. from
`gcloud auth print-access-token`, or a workload-identity/metadata-server token in
production). The driver never mints or refreshes tokens itself.

- `url()` returns a direct `https://storage.googleapis.com/{bucket}/{path}` URL — no
  signed URLs, since that requires a service-account private key rather than a Bearer
  token. Put a CDN or a bucket/object ACL in front for controlled public access.
- If a custom `url` (CDN) is configured, `url()` returns `{url}/{path}` instead.

```php
$driver = new GcsDriver('my-bucket', $accessToken);
$driver->put('report.pdf', file_get_contents('/tmp/report.pdf'));
$driver->url('report.pdf'); // https://storage.googleapis.com/my-bucket/report.pdf
```

### InMemoryDriver (tests only)

Keeps file contents in a PHP array — no filesystem, no network. Use it to test code
that depends on `StorageInterface` without writing real files.

```php
use EzPhp\Storage\InMemoryDriver;

$driver = new InMemoryDriver();
$driver->put('invoices/2026.pdf', $bytes);

$driver->exists('invoices/2026.pdf'); // true
$driver->url('invoices/2026.pdf');    // memory://invoices/2026.pdf
$driver->all();                       // ['invoices/2026.pdf' => $bytes]
$driver->flush();                     // reset between tests
```

Set `STORAGE_DRIVER=memory` to use it via the service provider.

- Rejects `..` path segments exactly like `LocalDriver`, so a traversal bug cannot pass
  against the double and then fail against the real driver.
- Supports the full interface, streams included (`getStream()`/`putStream()` use `php://memory`).
- **Not for production.** Nothing is persisted or shared between processes.

---

## Running Tests

```bash
docker compose exec app composer full
```

S3 integration tests are skipped unless `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_BUCKET` are set in the environment. GCS integration tests are likewise skipped unless `GCS_BUCKET` and `GCS_ACCESS_TOKEN` are set.
