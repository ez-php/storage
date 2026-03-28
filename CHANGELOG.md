# Changelog

All notable changes to `ez-php/storage` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.2.0] — 2026-03-28

### Fixed
- Removed deprecated `curl_close()` call in `S3Driver`; the function has been a no-op since PHP 8.0 and is deprecated in PHP 8.5

---

## [v1.1.0] — 2026-03-28

### Added
- `StorageInterface` — unified contract: `put()`, `get()`, `delete()`, `exists()`, `url()`, `putUploadedFile()`
- `LocalDriver` — stores files under a configurable root directory; creates nested directories automatically; `url()` returns an optional CDN base URL prefix
- `S3Driver` — uploads and retrieves objects via cURL with AWS Signature Version 4; compatible with AWS S3, MinIO, Cloudflare R2, and DigitalOcean Spaces; `url()` returns a presigned GET URL (configurable expiry) or a CDN URL when configured
- `Storage` — static façade wrapping `StorageInterface`
- `StorageServiceProvider` — registers `StorageInterface` and `Storage` façade based on `config/storage.php`; supports `local` and `s3` drivers
- Integration with `UploadedFile` from `ez-php/http` via `putUploadedFile()`
- S3 integration tests skipped automatically when AWS credentials are not present in the environment
