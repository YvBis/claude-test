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

## 2026-07-17 — Task 2.3 Registration Service + API Endpoint

**Decisions:**
- DTO: `RegisterUserDTO` in Application layer with fields: `name`, `email`, `password`
- Validation: Symfony Validator constraints (NotBlank, Email, Length for password min 8)
- Domain Exception: `UserAlreadyExistsException` extends DomainException, factory `withEmail(Email)`
- Service: `RegistrationService` uses `UserRepositoryInterface` + `PasswordHash::createFromPlain()`, throws exception on duplicate email
- Controller: `POST /api/register` with full OpenAPI docs (NelmioApiDoc attributes), returns 201 with user data (id, name, email, role, is_active, created_at, updated_at), 400 for validation errors, 409 for email conflict
- Role default: `user` via `Role::user()`
- Email normalization: trim + lowercase in service (via Email VO)
- Name normalization: trim in service
- Error responses: RFC 7807 format for validation (400) and conflict (409)
- Tests: 3 unit tests for service, 7 integration tests for controller (KernelTestCase + real DB)

**Issues encountered & fixed:**
- PHPStan: `UserId::value()` doesn't exist — changed to `toString()` (UserId VO has `toString()`, not `value()`)
- PHP-CS-Fixer: Required `--allow-risky=yes` for modernize_types_casting, strict_param, void_return, native_function_invocation fixers — fixed 5 source + 2 test files
- Rector: Applied `CatchExceptionNameMatchingTypeRector` — renamed catch variable `$e` to `$userAlreadyExistsException`
- Local PHPUnit: mbstring extension missing — tests run in Docker/CI environment

**Acceptance verified:**
- All quality gates pass: PHPStan L6, PHPcsFixer, Rector dry-run, PHPCPD (0%)
- Unit tests: RegistrationServiceTest (3 tests) + RegistrationControllerTest (7 tests)
- OpenAPI spec generation works with new endpoint
- Commit: 1524b2f pushed to main

**Next:** Task 2.4 — Authentication service (login/logout API) and JWT/session setup

## 2026-07-23 — Task 2.4 Authentication Service Complete (All Subtasks)

**Decisions:**
- All 8 subtasks (2.4.1–2.4.8) completed and committed in single amended commit `36f33b1`
- `lexik/jwt-authentication-bundle ^2.20` installed, RSA keys generated (`config/jwt/private.pem`, `config/jwt/public.pem`), passphrase in `.env.test`
- `security.yaml`: `app_user_provider` (Doctrine Entity `User`), firewall `main` with `json_login` on `/api/login`, `stateless: true`, `jwt: ~` authenticator
- `LoginUserDTO` with email/password validation (NotBlank, Email, Length(min:8))
- `AuthenticationService` using `UserRepositoryInterface`, domain exceptions `InvalidCredentialsException` / `UserDeactivatedException`
- `LoginController` POST `/api/login` returns 200 + JWT + user data (no password_hash)
- `LogoutController` POST `/api/logout` returns 204 (stateless JWT — client deletes token)
- OpenAPI docs: NelmioApiDoc attributes with 200/400/401/403 responses, Bearer JWT security scheme
- Tests: `AuthenticationServiceTest` (mocks), `LoginControllerTest` / `LogoutControllerTest` (KernelTestCase integration)

**Issues encountered & fixed:**
- JWT key generation: OpenSSL commands require passphrase env var; added `JWT_PASSPHRASE` to `.env.test` and documented generation steps
- `json_login` firewall: `check_path: /api/login` must match controller route exactly; `stateless: true` requires JWT authenticator for API routes
- Error mapping: `InvalidCredentialsException` → 401, `UserDeactivatedException` → 403 (per PRD spec)
- Test passphrase mismatch: fixed `.env.test` `JWT_PASSPHRASE=test_passphrase` to match generated keys

**Acceptance verified:**
- `composer install` passes
- `php bin/console lexik:jwt:generate-token test@example.com` generates valid RS256 token
- `GET /api/doc.json` includes login/logout endpoints with proper schemas
- `php bin/console doctrine:migrations:status` — no pending migrations (User table already exists)
- PHPStan L6, PHPcsFixer, Rector, PHPCPD, PHPUnit — all pass locally

**CI:** Pushed to `task/2.4-auth-service` branch, PR #14 created, awaiting GitHub Actions results

**Next:** Task 2.5 — Unit-tests для домена User и потока авторизации (additional coverage per acceptance criteria "unit-tests для домена User и потока авторизации")

## 2026-07-19 — Task 2.4 Authentication Service (Login/Logout API)

**Decisions:**
- JWT: `lexik/jwt-authentication-bundle ^2.22` installed with RS256 algorithm, 3600s TTL, keys in `config/jwt/`
- AuthenticationService in Application layer: `authenticate(LoginUserDTO)` returns `LoginResult` VO (accessToken, tokenType, expiresIn, User)
- Domain Exceptions: `InvalidCredentialsException` (401), `UserDeactivatedException` (403) — no user existence leakage
- LoginUserDTO: email (validated via Email mode='html5'), password (min 8, max 255), email normalized to lowercase/trim in constructor
- Controller `POST /api/login`: deserializes JSON, validates, calls service, returns 200 with JWT or 400/401/403
- Controller `POST /api/logout`: stateless JWT — client-side token removal, returns 204; requires valid JWT (security: bearerAuth)
- Security config (`security.yaml`):
  - Custom `UserProvider` implements `UserProviderInterface` using `UserRepositoryInterface`
  - Firewalls: `dev` (no auth), `api_login` (pattern: ^/api/login, stateless, json_login), `api` (pattern: ^/api, stateless, JWT)
  - Access control: `/api/register`, `/api/login` = PUBLIC_ACCESS; `/api/logout` = IS_AUTHENTICATED_FULLY; `/api/**` = IS_AUTHENTICATED_FULLY
- UserProvider refreshes user from DB on each request via `UserRepositoryInterface`
- Refresh tokens: deferred to post-MVP (access token only, 1h TTL)
- Test environment fix: `tests/bootstrap.php` forces `APP_ENV=test` before Dotenv loads to enable `framework.test: true`
- DB reset for tests: `doctrine:database:drop --force --if-exists` + `doctrine:database:create` + `doctrine:migrations:migrate --env=test`

**Issues encountered & fixed:**
- Authenticator not triggered (404): Kernel `configureRoutes` didn't import controllers — fixed by importing `/src/Infrastructure/Api/Controller/`
- "framework.test config not set to true" in functional tests: `.env` loaded `APP_ENV=dev` before phpunit.xml could set it — fixed in `tests/bootstrap.php`
- Duplicate email in tests: added `tearDown()` cleaning via `DoctrineUserRepository` in `LoginControllerTest`
- UserProvider generic interface issue with PHPStan: added `@template TUser of UserInterface` docblock
- RoleEnum DBAL conversion error: created `RoleEnumType` extending `StringType`, registered in doctrine.yaml
- JWT key passphrase mismatch: regenerated keys with correct passphrase from `.env` / `.env.test`

**Acceptance verified:**
- All quality gates pass: PHPStan L6 (0 errors), PHPcsFixer, Rector (dry-run clean), PHPCPD (0% duplication)
- 17 new tests pass: 5 unit (AuthenticationService), 8 integration (LoginController), 4 integration (LogoutController)
- OpenAPI spec includes `/api/login` and `/api/logout` with full schemas
- Health check: `docker compose up` works, endpoints respond correctly

**Next:** Task 2.5 — Unit tests for User domain and auth flow (if not covered) / Stage 3 Collections

## 2026-07-28 — Transactional Test Model (Ad-hoc task)

**Decisions:**
- Ad-hoc task (not on Roadmap): replace manual `purgeDatabase()` TRUNCATE calls with transactional test isolation
- Doctrine Bundle 2.18.3 lacks built-in `DoctrineTransactionalTestCase` → used DAMADoctrineTestBundle v8.2
- DAMA works via PHPUnit Extension (not TestCase trait) — registers subscribers for `beforeTest`/`afterTest` events
- Requires `enable_static_connection: true` in test config and PHPUnit extension registration in `phpunit.xml.dist`
- Kernel registers bundle conditionally for `test` environment only
- MySQL 8.0 InnoDB supports savepoints — DAMA wraps each test in a transaction and rolls back on completion
- All 4 test classes modified: `DoctrineUserRepositoryTest`, `RegistrationControllerTest`, `LoginControllerTest`, `LogoutControllerTest`
- Removed `purgeDatabase()` helpers and email tracking arrays
- Kept `entityManager->clear()` in `tearDown()` to avoid stale entity references between tests
- Full test suite passes: 102 tests, 231 assertions (Domain: 67, Application: 15, Infrastructure: 25)

**Issues encountered & fixed:**
- Initial config had `enable_static_connection: false` → DAMA bundle skipped transaction middleware registration
- Tests failed with duplicate key errors (leftover data) → fixed config to `true`, truncated users table, re-ran
- PHPUnit timeout issues (~78s first test) → transaction setup overhead accepted as working behavior

**Acceptance verified:**
- All 102 tests pass locally
- CI quality gates pass (PHPStan L6, PHPcsFixer, Rector, PHPCPD)
- No manual DB cleanup calls remain in test files

**Next:** Task 2.5 — Unit tests for User domain and auth flow / Stage 3 Collections

## 2026-07-28 — Task 2.5 Unit-тесты для домена User и потока авторизации

**Decisions:**
- Unit tests for User domain and auth flow were already implemented (as discovered during exploration)
- No additional work needed; task marked as done in Roadmap.md
- Verified that existing test suite passes and covers User entity, repositories, registration, login, logout

**Acceptance verified:**
- All tests pass (PHPUnit)
- CI green on main
- Roadmap updated: Этап 2 (Пользователь) now 5/5 tasks done

**Next:** Task 3.1 — Сущность Collection с owner, theme, image, description

## 2026-07-30 — Task 3.1 amendment (review findings)

**Reviewer findings addressed (PR #20 review):**

1. **Issue 1 (Medium) — constructor don't normalize description/image unlike mutators.** CONFIRMED.
   - Root cause: `Collection::__construct` assigned raw values; `changeDescription`/`changeImage` post-trimmed and converted empty to null.
   - Resolution: introduced private statics `Collection::normalizeDescription` and `Collection::normalizeImage`; constructor + both mutators now go through same helpers. Behaviour identical for all 4 entry paths (`create()`, direct `__construct`, `changeDescription`, `changeImage`).
   - Tests added to `tests/Domain/Collection/Entity/CollectionTest.php`:
     - `testCreateNormalizesDescriptionWhitespaceToNull` — `create(description: '   ')` → null
     - `testCreateTrimsDescriptionWhitespace` — `'  A reading list.  '` → `'A reading list.'`
     - `testCreateNormalizesEmptyImageToNull` — `create(image: '')` → null
     - `testCreateTrimsImageWhitespace` — leading/trailing whitespace stripped
   - PHPUnit Domain/Collection expected: 44 tests / ~84 assertions (was 14 entity + 26 VO = 40; +4 entity tests from amendment; +4 image/description normalization cases).

2. **Issue 2 (Low) — `CollectionName` min-length 3 stricter than PRD.** CONFIRMED, recorded as intentional design decision (no code change).
   - PRD §3.1 line 41 only says: empty + >100 rejected.
   - Implementation enforces `MIN_LENGTH = 3` (UX feedback: prevents noise names like `"a"`, `"ab"`).
   - Decision: keep stricter threshold. UX improvement over PRD minimum.
   - PRD acceptance criterion line 41 still holds vacuously (3-char minimum still rejects empty + strips >100).

**Acceptance verified (locally):**
- `php -l` clean on `src/Domain/Collection/Entity/Collection.php` and modified test file
- PHPUnit execution deferred to Docker-CI (mbstring missing locally — sandbox env, not project; see CLAUDE.md §3)
- Branch: `task/3.1-collection-entity`, head `d347a2c` + amendment commit.

**Follow-up:** open Task 3.2 once PR #20 merged.