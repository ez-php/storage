# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks every
  `CLAUDE.md` copy as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

Pass `--extensions` to merge PHP extension install blocks (apt packages plus `docker-php-ext-install`/`pecl` lines) directly into `docker/app/Dockerfile`, instead of hand-editing it afterward — supported extensions: `bcmath`, `gmp`, `gd`, `imagick`:

```
vendor/bin/docker-init --extensions=gmp,bcmath
vendor/bin/docker-init --extensions=gd,imagick
```

When run from a module directory inside this monorepo, any requested extension not already present is also merged into the shared root `docker/app/Dockerfile` — the container `composer full` at the root actually runs against, distinct from the module's own standalone image.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/` (application template) | 3308 | 6383 (`REDIS_PORT`) | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| `ez-php/event-store` | 3311 | — | — |
| **next free** | **3312** | **6384** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project and the `ez-php/` application template are the two exceptions, since both have no host/container split and use `REDIS_PORT` for both (the template's other in-container Redis settings — `CACHE_REDIS_PORT`, `QUEUE_REDIS_PORT`, `RATE_LIMITER_REDIS_PORT` — stay fixed at `6379` regardless, same as every other module).

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/storage

## Source structure

```
src/
  StorageInterface.php        — put/get/delete/exists/url/putUploadedFile contract
  StorageException.php        — thrown on read, write, delete, or upload failure
  LocalDriver.php             — filesystem driver; auto-creates nested directories
  S3Driver.php                — S3-compatible driver via cURL: object operations, multipart upload, HTTP layer
  S3Signer.php                — AWS Signature V4 (request Authorization header, presigned query string, path encoding); pure, no I/O
  StorageResponse.php         — serve(): stream a stored file with Range/206/416; xAccelRedirect()/xSendfile(): hand the transfer to nginx/Apache
  SignedUrl.php               — HMAC-signed, expiring links for files behind your own route (LocalDriver has no native presigning)
  GcsDriver.php                — Google Cloud Storage driver via cURL + Bearer access token
  InMemoryDriver.php          — in-process driver for tests; no filesystem, no network
  Storage.php                 — static façade; wired by StorageServiceProvider
  StorageServiceProvider.php  — reads config/storage.php, binds StorageInterface
tests/
  TestCase.php                — extends PHPUnit\Framework\TestCase (no framework dependency)
  LocalDriverTest.php         — unit tests for LocalDriver using a temp directory
  S3DriverTest.php            — integration tests; skipped without AWS credentials
  S3DriverMultipartTest.php   — multipart putStream() against an injected fake S3 transport; no network
  S3SignerTest.php            — SigV4 against the worked examples from the AWS documentation (Authorization header + presigned URL)
  StorageResponseTest.php     — Range parsing (closed/open/suffix/clamped/unsatisfiable/ignored), 206/416/404, headers, X-Accel-Redirect, X-Sendfile
  SignedUrlTest.php           — signing, expiry boundary, tampering with path/expiry/secret, malformed parts, verifyRequest()
  GcsDriverTest.php           — integration tests; skipped without GCS credentials
  InMemoryDriverTest.php      — mirrors LocalDriverTest's contract surface; no infrastructure
  StorageTest.php             — tests for the Storage static façade
```

## Key classes and responsibilities

**`StorageInterface`** — unified contract with eight methods:
- `put(path, contents): bool` — write file
- `get(path): string` — read file; throws StorageException if missing
- `delete(path): bool` — remove file; returns false if not found
- `exists(path): bool` — check presence
- `url(path): string` — public or presigned URL
- `putUploadedFile(path, UploadedFile): bool` — store an HTTP upload
- `getStream(path): resource` — open a readable stream
- `putStream(path, resource): bool` — write from a stream

**`LocalDriver`** — PHP filesystem implementation. `fullPath()` resolves relative paths against the configured root. `ensureDirectory()` creates parent directories with `mkdir($dir, 0755, true)`.

**`S3Driver`** — cURL-based S3 client (`putStream()` uses S3 multipart upload above `multipartPartSize`; the HTTP layer is replaceable via an optional `transport` closure). Delegates all signing to `S3Signer`.

**`S3Signer`** — AWS Signature V4: `signRequest()` (Authorization header for API calls), `presignQuery()` (query-parameter signature for presigned URLs), `encodePath()`. Takes the timestamp as an argument and does no I/O, so it is tested against fixed AWS vectors.

**`StorageResponse`** — static helpers turning a stored file into an HTTP response. `serve($storage, $path, $request, $filename, …)` streams via `StreamedResponse` and answers a single `Range` (`bytes=a-b`, `a-`, `-n`) with `206` + `Content-Range`, an unsatisfiable one with `416` + `Content-Range: bytes */size`, and ignores multi-range/other-unit/inverted headers by sending the whole file. `xAccelRedirect($prefix, $path, …)` and `xSendfile($localDriver, $path, …)` return an empty response with the web-server hand-off header.

**`SignedUrl`** — `make($path, $ttl)` builds `base/path?expires=…&signature=…` (HMAC-SHA256 over `path\nexpires`); `verify()`/`verifyRequest()` check expiry and signature in constant time. `host()` derives the virtual-hosted-style AWS hostname or uses the configured custom endpoint. No external dependencies — only `ext-curl` and PHP hash functions.

**`GcsDriver`** — cURL-based Google Cloud Storage client, using the GCS JSON API (`storage.googleapis.com/storage/v1/...` for metadata/read/delete, `storage.googleapis.com/upload/storage/v1/...` for writes). Authenticates with a caller-supplied OAuth2 Bearer access token — the driver never mints or refreshes tokens itself.

**`Storage`** — static façade backed by a `?StorageInterface $instance`. `setInstance()` is called in `StorageServiceProvider::register()`. `resetInstance()` is used in tests.

**`StorageServiceProvider`** — reads `storage.driver` from config; instantiates and binds `LocalDriver`, `S3Driver`, or `GcsDriver` accordingly.

## Design decisions and constraints

- **No external dependencies** — only `ez-php/contracts`, `ez-php/http`, and `ext-curl`. The S3 signing is implemented in pure PHP to stay dependency-free.
- **`putUploadedFile` on S3Driver** — since `UploadedFile::moveTo()` uses `move_uploaded_file()` (HTTP-upload-only), the S3Driver moves the file to a system temp path first, reads the contents, uploads to S3, then deletes the temp file.
- **Virtual-hosted-style S3 URLs** — the bucket is part of the hostname (`bucket.s3.region.amazonaws.com`), not the path. Custom endpoint support enables MinIO and Cloudflare R2 compatibility.
- **Presigned URLs** — `S3Driver::url()` generates presigned GET URLs signed with `UNSIGNED-PAYLOAD`. Expiry defaults to 3600 seconds and is configurable. If a CDN `url` is set in config, direct CDN URLs are returned instead.
- **`putStream()` uses multipart upload above `multipartPartSize` (default 8 MiB).** The stream is read one part at a time, so the object is never held in memory. A stream that fits in one part (peeked by reading a second chunk) still uses a single PutObject. Flow: `POST ?uploads` → `PUT ?partNumber&uploadId` per part (ETag from the response header) → `POST ?uploadId` with the part list. Any failure — non-2xx part, an `<Error>` body on a 200 Complete response, or an exception — triggers a best-effort `DELETE ?uploadId` so orphaned parts don't accrue charges; non-2xx returns `false` like `put()`, transport exceptions propagate. S3 requires parts ≥ 5 MiB (except the last); the driver does not enforce it, so tiny part sizes are for tests only. The query string is part of the SigV4 canonical request (sorted, RFC 3986-encoded).
- **`S3Driver` gained an injectable HTTP `transport`** (optional last constructor argument, default cURL). It exists so the multipart protocol is unit-testable against a fake S3 instead of only against live credentials; `S3DriverTest` (live) is unchanged. `GcsDriver` still has no such seam. `put()`/`get()`/`delete()`/`exists()` are unchanged. `putUploadedFile()` still reads the file fully via `put()`.
- **`GcsDriver` does not generate signed URLs** — unlike `S3Driver`, `GcsDriver::url()` returns a direct `storage.googleapis.com/{bucket}/{path}` URL (or a configured CDN `url` prefix), never a signed/expiring one. A true GCS V4 signed URL requires a service-account private key to sign with, which is a materially different (and heavier) auth story than the Bearer-token access-token approach used for every other request — out of scope here; put a CDN or a bucket/object ACL in front instead. This is a deliberate, documented limitation, not an oversight.
- **Streaming is supported alongside the string API** — `put()`/`get()` operate on strings; `getStream()`/`putStream()` avoid materialising large files. `LocalDriver` uses `stream_copy_to_stream()`, `S3Driver` pipes the object body into a memory stream, `InMemoryDriver` wraps `php://memory`.
- **`InMemoryDriver` is named for what it models, not how it is built** — The sibling test drivers in `cache`, `broadcast`, `feature-flags` and `rate-limiter` are called `ArrayDriver`, but those modules genuinely store key-value pairs. Storage models a filesystem; the backing array is an implementation detail, so the name says "in memory".
- **`InMemoryDriver` rejects `..` even though it has no root to escape** — A test double must fail the same way the real driver does. If it silently accepted a traversing path, a traversal bug would pass its tests and only surface against `LocalDriver` in production.
- **`InMemoryDriver::putUploadedFile()` uses the temp-file dance** — `UploadedFile::moveTo()` wraps `move_uploaded_file()`, which requires a genuine HTTP upload and a real destination path, so the file is moved to a temp path, read, and discarded. This mirrors `S3Driver`, which has the same constraint.
- **Signing is split out of `S3Driver` into `S3Signer`.** `S3Driver` stays the object-storage adapter (paths, multipart, HTTP, hostname); everything that is pure SigV4 math lives in `S3Signer`, which is the part that must match AWS byte for byte and is therefore verified against the published examples. `S3Driver`'s constructor and public API are unchanged.
- **HTTP serving lives here, on top of `StorageInterface`, not in `ez-php/http`.** `StorageResponse` needs the storage abstraction (`exists`/`getStream`) and `ez-php/http`'s `StreamedResponse`/`Response`, both already dependencies of this module; `ez-php/http` stays storage-agnostic. It is a set of static helpers (no state), not a service, so a controller can call it directly.
- **Range support requires a seekable stream.** The size comes from `fstat()`, which is only trustworthy for a seekable stream — pipes and sockets (an object stream from a remote driver) report 0. For those, `serve()` ignores `Range` and streams the whole file without `Content-Length` instead of guessing. `If-Range` is not evaluated, and multi-range requests are answered with the whole file, which RFC 9110 permits.
- **Two ways to protect a file route:** either verify a `SignedUrl` before calling `StorageResponse::serve()`, or let the web server do the transfer (`xAccelRedirect`/`xSendfile`) after your own access check. `LocalDriver::absolutePath()` (new, additive) exists for the latter. `S3Driver` needs neither — `url()` is already a presigned URL.
- **`SignedUrl` requires a secret of at least 16 bytes and has no single-use tracking.** A link is valid until its expiry; revoke by changing the secret or by shortening the TTL. The clock is injectable (`Closure`) only so tests do not need `sleep()`.

## Testing approach

- **No external services required** — LocalDriver tests use PHP's `sys_get_temp_dir()` and clean up after themselves.
- **S3 integration tests are skipped** by default (`markTestSkipped` when credentials are absent). Run them by setting `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_BUCKET`.
- **Multipart tests use a fake transport** injected into `S3Driver`, asserting the request sequence (initiate/parts/complete/abort), part bodies, the ETag part list, SigV4 headers on every call, key encoding and every failure path.
- **GCS integration tests are skipped** the same way, unless `GCS_BUCKET` and `GCS_ACCESS_TOKEN` are set. Like `S3Driver`, `GcsDriver` has no mockable HTTP seam, so its behavior is only verified against a real bucket.
- `StorageTest` tests the static façade isolation using `resetInstance()` in setUp/tearDown.
- No framework application context is needed — `TestCase` extends plain `PHPUnit\Framework\TestCase`.

## What does NOT belong in this module

- Resumable/multipart upload for GCS, and parallel or resumable-after-crash part uploads for S3 — S3 parts are uploaded sequentially and a crashed upload is aborted, not resumed
- Minting or refreshing GCS OAuth2 access tokens — `GcsDriver` only sends the token it is constructed with; obtaining one is the application's responsibility
- Image resizing or file processing — belongs in a dedicated media module
- Database-backed file metadata — belongs in the ORM module
- Routing, authorization and access rules for file downloads — the application's controller decides *who* may fetch a file; this module only builds the response (`StorageResponse`) and the expiring link (`SignedUrl`)
- Conditional requests (`If-Range`, `ETag`/`If-None-Match`), multi-range (`multipart/byteranges`) responses and a CDN/cache layer — application or reverse-proxy concern
- File validation (MIME type, size limits) — belongs in `ez-php/validation`
