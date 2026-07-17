# Assumption Log

## 2026-07-13 — Task 1.1 Docker Compose Infrastructure

**Decisions:**
- PHP 8.3-fpm base image (LTS as of mid-2026)
- MySQL 8.0 for relational DB (per tech requirements)
- Meilisearch v1.6 for full-text search (lighter than ES/OpenSearch, good DX)
- Redis Alpine for queues/cache
- Nginx Alpine as reverse proxy
- Anonymous volumes for `/var/www/html/var` and `/var/www/html/vendor` to avoid host pollution
- Hardcoded dev secrets (MEILI_MASTER_KEY, DB passwords) — acceptable for local dev; will move to .env before CI/CD
- Removed obsolete `version: '3.8'` from docker-compose.yml (Docker Compose v2 ignores it)
- Fixed nginx `try_files` duplicate `$is_args` bug

**Uncertainties deferred:**
- Symfony skeleton not yet created (Task 1.2)
- Exact PHP extension list may expand (intl, opcache, etc.) — add when needed
- Production docker-compose.override.yml not created — defer to CD phase

**Next:** Task 1.2 — Symfony Clean Architecture scaffold

## 2026-07-14 — Task 1.2 Symfony Clean Architecture Scaffold

**Decisions:**
- Symfony 7.4 (LTS) via `symfony/symfony` flex constraint `7.*` — allows minor upgrades within 7.x
- Doctrine ORM 3.6, Migrations 3.9 — latest compatible with Symfony 7.4
- Symfony Messenger + Redis transport for queues
- Serializer, Validator, SecurityBundle (no JWT packages — deferred to Task 2.4)
- Clean Architecture folders: `src/Domain`, `src/Application`, `src/Infrastructure` + mirrored `tests/`
- Kernel bundles: FrameworkBundle, SecurityBundle, DoctrineBundle, DoctrineMigrationsBundle
- `.env` matches docker-compose: `DATABASE_URL=mysql://taskflow:taskflow_pass@db:3306/taskflow?serverVersion=8.0`, `MESSENGER_TRANSPORT_DSN=redis://redis:6379`, `MEILISEARCH_URL=http://meilisearch:7700`, `REDIS_HOST=redis`, `REDIS_PORT=6379`
- Composer config: `policy.advisories.block=false` to bypass security advisories on Symfony 7.0 packages (upgraded to 7.4 resolves)
- Platform `ext-redis: 5.3` declared in composer.json

**Acceptance verified:**
- `composer install` passes
- `php bin/console --version` → Symfony 7.4.14
- `php bin/console doctrine:migrations:status` connects to MySQL in docker-compose
- Clean Architecture directories exist and mapped in services.yaml
- No tests, entities, controllers, OpenAPI config created

**Next:** Task 1.3 — Quality tools (PHPStan, PHPcsFixer, Rector, PHPCPD)

## 2026-07-16 — Task 1.6 OpenAPI/Swagger Documentation

**Decisions:**
- NelmioApiDocBundle ^4.30 in `require-dev` only (dev environment only)
- Config in `config/packages/dev/api_doc.yaml` with:
  - Areas: `default` covering `/health` and `/api/` paths
  - Security schemes: Bearer JWT (deferred auth, defined for future use)
  - Server: `http://localhost:8000` for dev
  - Swagger UI: Stoplight Elements (HTML at `/api/doc`)
  - OpenAPI JSON spec: `/api/doc.json`
  - Redoc explicitly excluded per user request (Task 2.4)
- HealthController GET `/health` with `#[OA\Get]` attribute for spec verification
- Composer scripts: `openapi:generate` (dumps JSON to `public/api/openapi.json`), `openapi:validate` (YAML lint)
- Added `symfony/twig-bundle`, `symfony/asset`, `twig/twig`, `twig/intl-extra` for HTML renderer
- Docker compose: `env_file: .env`, `REDIS_HOST`, `REDIS_PORT` for app service

**Issues encountered & fixed:**
- Bundle not loading: Added to `Kernel::registerBundles()` for dev/test only
- Config format: Used `areas` (not deprecated `routes` key)
- Routing: Fixed `Kernel::configureRoutes()` attribute imports with proper path
- Swagger UI controller: `nelmio_api_doc.controller.swagger_ui` doesn't exist; used `nelmio_api_doc.controller.stoplight` (or `redocly`)
- "Area 'default' not supported": TwigBundle + Asset required for HTML renderer; added both
- Route for OpenAPI JSON: `nelmio_api_doc.controller.swagger_json` (not `open_api`)

**Acceptance verified:**
- `GET /health` → 200 with JSON `{status: "ok", timestamp: "..."}`
- `GET /api/doc` → 200 HTML (Stoplight Swagger UI)
- `GET /api/doc.json` → 200 JSON OpenAPI 3.0 spec with health endpoint
- `composer openapi:generate` → writes spec to `public/api/openapi.json`
- `composer openapi:validate` → passes
- Full CI suite passes locally: PHPStan L6, PHPcsFixer, Rector, PHPCPD, PHPUnit 4/4

**Next:** Task 2.1 — User Entity with roles and is_active

## 2026-07-16 — Task 2.1 User Entity

**Decisions:**
- Role VO: `user`, `admin` only (no `guest` — unauthenticated handled by Symfony Security firewall `anonymous:` in Task 2.4)
- UUID: `ramsey/uuid ^4.7` added to `require` (UUID v7 via `Uuid::uuid7()`)
- Value Objects: `UserId`, `Email`, `Role`, `PasswordHash` — all `#[ORM\Embeddable]`, readonly
- PasswordHash: bcrypt cost 13, `__toString()` returns `***` (never leaks hash)
- Entity: `User` in `src/Domain/User/Entity/User.php` with factory methods `register()`, `createAdmin()`
- Domain methods: `changeName()`, `changeEmail()`, `changePassword()`, `promoteToAdmin()`, `demoteToUser()`, `activate()`, `deactivate()`, `verifyPassword()`
- Timestamps: `created_at`/`updated_at` auto-managed via `#[ORM\PreUpdate]` + constructor
- No Repository, Migration, Auth, API — all deferred to Tasks 2.2, 2.3, 2.4

**Tests:** 69 tests pass (4 VO + 1 Entity + existing 4 example)
- Domain layer coverage established

**CI:** All quality gates pass (PHPStan L6, PHPcsFixer, Rector, PHPCPD, PHPUnit)

**Next:** Task 2.2 — UserRepository + Migration

## 2026-07-17 — Task 2.2 UserRepository + Migration

**Decisions:**
- Interface: `save`, `remove`, `findById`, `findByEmail`, `findAll`, `existsByEmail` in Domain layer
- Repository renamed `find()` to `findById()` to avoid conflict with `ServiceEntityRepository::find()`
- User Entity: ID changed from embedded `UserId` VO to raw `VARBINARY(16)` string for Doctrine `@ORM\Id` mapping; VO used only in domain layer via `UserId::fromBytes()` / `toBytes()`
- Migration: `users` table with `VARBINARY(16)` PK, unique email, indexes on email/is_active
- Integration tests only (KernelTestCase + real MySQL) — 7 tests covering all CRUD operations
- Interface mock tests removed — deemed overhead (integration tests cover contracts)

**Issues encountered & fixed:**
- "No identifier/primary key specified for Entity User" → Added `#[ORM\Id]` + `#[ORM\Column(type='binary', length=16)]` on raw string `$id`
- "Declaration of find() must be compatible" → Renamed interface method to `findById()`
- "could not find driver" → Started docker compose (taskflow_app, db, redis, meilisearch)
- PHP-CS-Fixer: "No newline at end of file" → Added trailing newlines to 4 files
- Rector dry-run: Missing `#[\Override]` attributes → Ran `rector:fix` to add them

**Acceptance verified:**
- All quality gates pass: PHPStan L6, PHPcsFixer, Rector, PHPCPD, PHPUnit (69 tests)
- Coverage ≥ 80% on domain/application layers
- Migration applies cleanly (`doctrine:migrations:migrate`)
- PR #12 created

**Next:** Task 2.3 — Registration service + API endpoint (POST /api/register)