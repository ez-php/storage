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
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
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

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^1.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| **next free** | **3311** | **6383** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

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
  S3Driver.php                — S3-compatible driver via cURL + AWS Signature V4
  Storage.php                 — static façade; wired by StorageServiceProvider
  StorageServiceProvider.php  — reads config/storage.php, binds StorageInterface
tests/
  TestCase.php                — extends PHPUnit\Framework\TestCase (no framework dependency)
  LocalDriverTest.php         — unit tests for LocalDriver using a temp directory
  S3DriverTest.php            — integration tests; skipped without AWS credentials
  StorageTest.php             — tests for the Storage static façade
```

## Key classes and responsibilities

**`StorageInterface`** — unified contract with six methods:
- `put(path, contents): bool` — write file
- `get(path): string` — read file; throws StorageException if missing
- `delete(path): bool` — remove file; returns false if not found
- `exists(path): bool` — check presence
- `url(path): string` — public or presigned URL
- `putUploadedFile(path, UploadedFile): bool` — store an HTTP upload

**`LocalDriver`** — PHP filesystem implementation. `fullPath()` resolves relative paths against the configured root. `ensureDirectory()` creates parent directories with `mkdir($dir, 0755, true)`.

**`S3Driver`** — cURL-based S3 client. Signs requests with AWS Signature V4 (Authorization header for API calls, query-parameter signature for presigned URLs). `host()` derives the virtual-hosted-style AWS hostname or uses the configured custom endpoint. No external dependencies — only `ext-curl` and PHP hash functions.

**`Storage`** — static façade backed by a `?StorageInterface $instance`. `setInstance()` is called in `StorageServiceProvider::register()`. `resetInstance()` is used in tests.

**`StorageServiceProvider`** — reads `storage.driver` from config; instantiates and binds `LocalDriver` or `S3Driver` accordingly.

## Design decisions and constraints

- **No external dependencies** — only `ez-php/contracts`, `ez-php/http`, and `ext-curl`. The S3 signing is implemented in pure PHP to stay dependency-free.
- **`putUploadedFile` on S3Driver** — since `UploadedFile::moveTo()` uses `move_uploaded_file()` (HTTP-upload-only), the S3Driver moves the file to a system temp path first, reads the contents, uploads to S3, then deletes the temp file.
- **Virtual-hosted-style S3 URLs** — the bucket is part of the hostname (`bucket.s3.region.amazonaws.com`), not the path. Custom endpoint support enables MinIO and Cloudflare R2 compatibility.
- **Presigned URLs** — `S3Driver::url()` generates presigned GET URLs signed with `UNSIGNED-PAYLOAD`. Expiry defaults to 3600 seconds and is configurable. If a CDN `url` is set in config, direct CDN URLs are returned instead.
- **No streaming API** — `put()` and `get()` operate on strings. Streaming support belongs in a future iteration if the need arises (YAGNI).

## Testing approach

- **No external services required** — LocalDriver tests use PHP's `sys_get_temp_dir()` and clean up after themselves.
- **S3 integration tests are skipped** by default (`markTestSkipped` when credentials are absent). Run them by setting `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, and `AWS_BUCKET`.
- `StorageTest` tests the static façade isolation using `resetInstance()` in setUp/tearDown.
- No framework application context is needed — `TestCase` extends plain `PHPUnit\Framework\TestCase`.

## What does NOT belong in this module

- Stream/chunked upload support — add as a separate method or module if needed
- Image resizing or file processing — belongs in a dedicated media module
- Database-backed file metadata — belongs in the ORM module
- Serving files via HTTP (X-Accel-Redirect, range requests) — belongs in the framework HTTP layer
- File validation (MIME type, size limits) — belongs in `ez-php/validation`
