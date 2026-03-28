# ez-php/storage

File storage abstraction for the ez-php framework.

Provides a unified interface (`put`, `get`, `delete`, `exists`, `url`) over pluggable drivers. Ships with a **LocalDriver** (filesystem) and an **S3Driver** (AWS S3 and S3-compatible APIs via cURL + AWS Signature V4). Integrates with `UploadedFile` from `ez-php/http`.

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

```php
$driver = new S3Driver('key', 'secret', 'eu-west-1', 'my-bucket');
$driver->put('report.pdf', file_get_contents('/tmp/report.pdf'));
$driver->url('report.pdf'); // presigned URL
```

---

## Running Tests

```bash
docker compose exec app composer full
```

S3 integration tests are skipped unless `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_BUCKET` are set in the environment.
