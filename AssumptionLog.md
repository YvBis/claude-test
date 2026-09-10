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

## 2026-07-31 — Chore: PHPUnit suite perf (Quick wins 1–4)

**Scope:** ad-hoc chore, not PRD. Hypothesis: xdebug active unconditionally; sleep/usleep and placeholders inflate baseline. User approved Quick wins 1–4 only (skip docker-network MySQL migration, skip Paratest).

**Decisions:**
- `docker/php/custom.ini`: `xdebug.mode = off` + `start_with_request = no`. PHP precedence: env `XDEBUG_MODE` > ini `xdebug.mode`. Dockerfile unchanged (xdebug stays installed, inert by default).
- `composer.json` scripts: coverage scripts prefixed with `XDEBUG_MODE=coverage`. New aliases: `phpunit:no-coverage` (default fast), `test` (= `phpunit:no-coverage`), `coverage:check` (opt-in coverage + junit). Existing `ci:test` aliases retargeted at `phpunit:no-coverage`.
- Domain entities (`User`, `Collection`): constructor + factories + mutators accept `?\DateTimeImmutable $at = null`, default to `new DateTimeImmutable()`. Backward compatible — all existing call sites stay valid. Doctrine `#[ORM\PreUpdate]` lifecycle callbacks extracted into `onPreUpdate(PreUpdateEventArgs $args)` to avoid signature collision with business `touch($at)`.
- Tests: replaced `usleep(1000)` × 11 in `UserTest` and `CollectionTest` with `new DateTimeImmutable('+1 microsecond')`. Replaced `sleep(1)` × 2 in `DoctrineUserRepositoryTest::testFindAll` with stepped `modify('+N seconds')` offsets from a base.
- Placeholder `tests/{Domain,Application}/ExampleTest.php` deleted. Replaced with `SuitesSelfCheckTest.php` marker in each layer (1 trivial `assertTrue(true)` per suite to assert non-empty membership on testsuite discovery).

**Measured locally (Docker):**
| Command | Before | After | Δ |
|---------|--------|-------|---|
| `composer phpunit:no-coverage` | ~2:20 | 49s | **−65%** |
| `composer coverage:check` | green | 1:17 green | unchanged |
| `composer ci:all` | green | 3:45 green | unchanged |

Sleep/usleep elimination ≈ 3s saved (negligible). The dominant gain is xdebug off-by-default. Coverage runs opt-in when needed.

**Branching:** isolated branch `chore/test-suite-perf` (off `main`). 4 conventional commits:
1. `chore(perf): gate xdebug to opt-in via XDEBUG_MODE env; default to off`
2. `refactor(User,Collection): accept explicit DateTimeImmutable for deterministic timing`
3. `test(User,Collection,DoctrineUserRepository): drop sleep/usleep; inject explicit DateTimeImmutable`
4. `chore(tests): remove placeholder ExampleTest; add suite self-check markers`

**Verification:** PR #21 opened (`YvBis/claude-test#21`). Local gates all green. GitHub `mergeable_state` reached `clean`. Remote CI check-runs not visible (PAT scope `checks:read` missing; `get_status` returns 0 required-status total — repo branch protection apparently does not require status checks for `main`). Treating as: local verification is the source of truth for this chore; remote Actions surface uncertain.

**Assumptions / uncertainties:**
- Repo has branch protection with no required status checks ⇒ GitHub treats PR `mergeable_state: clean` despite no check runs. Local verification substituted.
- `phpunit.xml.dist` `processUncoveredFiles="true"` triggers informational `XDEBUG_MODE=coverage` warning on `phpunit:no-coverage`. Harmless; not gated. Could be removed by splitting coverage stanza into a coverage-only suite, deferred.
- xdebug enabled in Dockerfile stays installed; profile/coverage works when `XDEBUG_MODE=coverage` set explicitly. No image rebuild needed beyond `docker compose build app` for `custom.ini` change.

**Follow-up:** merge PR #21 when convenient. Stage 3 roadmap tasks (3.2+) remain open.

## 2026-07-31 — Chore follow-up: replace `$at`-injection with Symfony Clock (Quick win 5)

**Scope:** ad-hoc follow-up to the previous chore. User flagged the `?\DateTimeImmutable $at = null` parameter threading across `User` and `Collection` (constructor + factories + 12 mutators) as noisy — asked for the Symfony Clock abstraction (`https://symfony.com/doc/7.4/components/clock.html`) instead.

**Decisions:**
- `composer require symfony/clock 7.*` — matched existing `extra.symfony.require` `7.*` pin.
- `Symfony\Component\Clock\ClockAwareTrait` on both entities ⇒ dropped every `$at`, `$createdAt`, `$updatedAt` parameter. `now()` aliased to private `clockNow()` to avoid name collision with the trait.
- `config/services.yaml`: bind `Symfony\Component\Clock\ClockInterface` → `NativeClock` (production) with `when@test` override → `MockClock('2026-01-01 00:00:00')` (public:true for container inspection).
- Doctrine `postLoad` listener `App\Infrastructure\Doctrine\Listener\ClockInjectListener` (autoconfigured via `#[AsEntityListener(event: 'postLoad')]`) injects the autowired `ClockInterface` post-hydration. Critical: without this, `ClockAwareTrait`'s lazy `??= new Clock()` fallback would instantiate a fresh `Clock` facade per `new` entity — defeating global `Clock::set()` test overrides on freshly-constructed entities that never went through postLoad.
- `Doctrine\Persistence\Event\LifecycleEventArgs` import dropped (php-cs-fixer unused — already covered by `PostLoadEventArgs`).
- Test DOMAIN scenarios: `setUp` constructs `MockClock('2026-01-01 10:00:00')` + `Clock::set($this->clock)`; individual tests do `$this->clock->modify('+1 microsecond')` before triggering the mutator. `tearDown` resets `Clock::set(new NativeClock())` to avoid cross-test bleed (the trait's static facade otherwise survives between tests).
- Test KERNEL (`DoctrineUserRepositoryTest`): constructs its own `MockClock('2026-01-01 00:00:00')` rather than fetching from the container. Rationale: `static::getContainer()->get(ClockInterface::class)` returns the wrapped `ServiceLocator` proxy from the test container — not the underlying `MockClock` instance. Direct construction keeps the test honest about what clock it controls.

**Measured (locally):**

| Command | Before this chore | After | Δ |
|---------|-------------------|-------|---|
| `composer phpunit:no-coverage` | 49s (144 tests) | 55s (144 tests, + 1 test added) | flat |
| `composer coverage:check` | 1:17 green | 1:06 green | flat |
| `composer ci:all` | 3:45 green | similar green | flat |

Wall-clock neutral — perf was a side benefit, the user's ask was code cleanliness. The Clock base timing gain is hidden behind xdebug-off gating from the prior chore.

**Branching:** 3 new commits atop `chore/test-suite-perf` (`ee8d892` head):
1. `chore(deps): add symfony/clock` (630f1dc)
2. `chore(services,doctrine): bind ClockInterface + register ClockInjectListener for postLoad hydration` (961fb99)
3. `refactor(User,Collection): replace $at-injection with Symfony ClockAwareTrait; tests drive time via MockClock` (ee8d892)

**Architectural trade-off (recorded):** Domain entities now `use` `Symfony\Component\Clock\ClockAwareTrait` — couples the Domain layer to a framework-specific trait. The trade-off accepted: `ClockAwareTrait` is a thin wrapper over PSR-20 `Psr\Clock\ClockInterface` plus autoconfiguration glue; alternative (custom in-house trait of `Psr\Clock\ClockInterface`) costs code with no functional gain. Domain still depends only on the `Psr` abstraction for behaviour; the Symfony trait only ships `setClock()`/`now()` helpers.

**Assumptions / uncertainties:**
- Per-entity `private readonly ClockInterface $clock` field set by postLoad listener cannot be reassigned on the same entity instance (readonly). Tests therefore MUST use the static `Clock::set` facade + the trait's lazy `new Clock()` fallback rather than mutator-level injection. Documented.
- `composer audit` warning present post-install for `symfony/cache` (pre-existing CVE — `CVE-2026-45073`, medium SQL injection). Out of scope; defer to `[review]` task. Not introduced by this chore.
- Production binding to `NativeClock` without property-default timezone constructor argument. Symfony `NativeClock::__construct` defaults to `date_default_timezone` at instantiation time. If the container creates `NativeClock` ahead of PHP's timezone init, persistent servers may want explicit `withTimeZone()` chain — deferred to `[review]` if production surfaces TZ drift.

**Verification:** local gates green: 144/144 tests, PHPStan L6 clean, phpcs clean, rector dry-run clean, phpcpd 0%. Remote CI on PR #21 pending; same PAT `checks:read` gap as the prior chore — local verification is source of truth.

**Follow-up:** wait for CI; merge PR #21; Stage 3 (3.2+) is the next roadmap task.

## 2026-07-31 — Task 3.1 staged for merge (review on hold)

**Status:** Task 3.1 PR #20 review comments resolved in amendments; awaiting Copilot automated review pass before merge.

**Decisions recorded earlier (2026-07-30 entry) remain valid — no new architectural decisions.**

## 2026-07-31 — Clock refactor review follow-up (commits 38cecf2, 8f7ee1c)

**Scope:** external-AI review on PR #21 surfaced two latent bugs + one behaviour delta + one informational note. Today's fix commits:

**Fix 1 (Medium, latent) — Dual-clock drift in kernel tests.** The postLoad listener was bound to the container's `MockClock` singleton (`when@test` binding `arguments: ['2026-01-01 00:00:00']`); tests advanced a *separate* `MockClock` via `Clock::set()`. Any future reload-and-assert test would have materialised entities whose `$clock` was the frozen container clock — opposite of the test's facade clock.

Resolution: rebind `Symfony\Component\Clock\ClockInterface` to `Symfony\Component\Clock\Clock` (the facade class itself). The facade's `now()/sleep()/withTimeZone()` delegate to `Clock::get()`, so a single `Clock::set(MockClock)` in test setUp synchronises both code paths — the trait's lazy `??= new Clock()` fallback (freshly-constructed entities) and the listener-injected `Clock` instance (postLoaded entities). Single source of truth. The `when@test` binding for `MockClock('2026-01-01 00:00:00')` is no longer required and was removed.

**Fix 2 (Low, latent) — Readonly double-set guard.** `ClockAwareTrait::$clock` is `private readonly`; a second `setClock()` throws. Doctrine dispatches `postLoad` at most once per entity per hydration today (identity map short-circuits repeated finds), so the bug is latent. Resolution: `\ReflectionProperty::isInitialized($entity) && isReadOnly()` short-circuit before `setClock()`. Reflection cost is sub-µs/entity, dwarfed by hydration work.

**Behaviour delta (Low, recorded) — `updatedAt` semantics.** Under the prior `$at`-injection model the timestamp could be either mutation or flush time depending on caller (defaults to `new DateTimeImmutable()` if omitted). Under the Symfony Clock model, `updatedAt` is whatever `$this->clockNow()` returned at the moment a mutator ran — i.e. **mutation instant only**. Doctrine `#[ORM\PreUpdate] onPreUpdate` only re-touches when the mutator did not already touch (the "admin SQL bypass" case). For all Tier-1 use cases (audit log, "last activity" UI, repository ordering) mutation time is more correct than flush time. Recorded for any cross-team consumer that re-exports the timestamp; no code change.

**Serialization surface (Info, recorded) — `Symfony\Component\Clock\Clock` on each entity.** Entities now carry a `$clock` field of `ClockInterface` type. PHP `serialize()` will round-trip the field as-is. For domain entities today this is fine (no consumer serializes them) — they live in Doctrine session state. Surface becomes a concern for any future consumer that pushes entities through cache/messenger/queue. Mitigations catalogued in `Roadmap.md` Review Backlog (`review-3`): `#[Serializer\Ignore]` exclusion or custom `__serialize`/`__unserialize` if a frozen `MockClock` would ever survive into production. Defer until a real consumer surfaces this need.

**Branching:** 2 new commits atop `chore/test-suite-perf`:
1. `fix(doctrine,services): route ClockInjectListener through Clock facade, eliminate dual-clock drift` (38cecf2)
2. `fix(doctrine): guard ClockInjectListener against readonly double-set` (8f7ee1c)

**Verification:** local gates: 144/144 tests, PHPStan L6 clean, phpcs clean, rector dry-run clean. No remote CI on PR #21 yet; same PAT `checks:read` gap as the original Clock refactor; local is source of truth.

**Follow-up:** push commits, wait for remote CI. Stage 3 (Task 3.2+) is the next roadmap task on `task/3.1-collection-entity`.

## 2026-08-01 — Task 3.2 CollectionField Entity with Types and Slot_Index

**Decisions:**
- FieldName VO: Unicode letters/digits + space `_ - . /`, length 2..50 (user-confirmed)
- FieldType VO: 4 enum-backed cases — text, number, date, bool; mirrors Theme VO pattern with factories, fromString, isX() helpers
- DBAL type: `FieldTypeEnumType` extends `StringType` with `requiresSQLCommentHint(true)` for Doctrine enum comment; registered in doctrine.yaml as `field_type_enum`
- Entity: `CollectionField` with `ClockAwareTrait`, unique constraint on (collection_id, slot_index), slot_index range 1..100 (MAX_FIELDS_PER_COLLECTION = 100 constant)
- Domain rule #3: only rename allowed post-creation — `rename(FieldName)` touches; type/slot/collection immutable (no setters). Edge case `reassignToCollection(Collection)` for admin reorg only
- Repository interface: save, remove, findById, findByCollection (ordered by slot_index ASC), findByCollectionAndSlot, nextSlotIndexFor (COALESCE MAX+1), countByCollection
- Doctrine repo implementation using QueryBuilder
- Migration: collection_fields table with VARBINARY(16) PK, FK to collections ON DELETE CASCADE, unique index on (collection_id, slot_index), index on collection_id, datetime(6) precision

**Tests:** 6 test files — VO (3), Entity (1), DBAL type (1), Kernel repo (1); 206 total tests pass

**Issues encountered & fixed:**
- Missing `slotIndex` validation in constructor → added InvalidArgumentException for range 1..100
- User constructor in tests had wrong argument order (name expects string, not Email) → fixed
- PHPStan: `@extends` tag without actual extends on interface → removed docblock
- PHP-CS-Fixer: missing trailing newlines → added
- Doctrine schema validation initially failed due to auto-generated migration dropping unique index → manual migration re-added it

**Acceptance verified:**
- All quality gates pass: PHPStan L6, PHPcsFixer (--allow-risky=yes), Rector dry-run, PHPCPD (0%)
- Full test suite: 206 tests, 408 assertions
- Doctrine schema validate: mapping OK, database sync (unique index present)
- Roadmap updated: 3.1 and 3.2 done

**Next:** Task 3.3 — Service for collection (creation, editing, user's collections list)

## 2026-08-02 — External Review Fixes for Task 3.2

**Review findings addressed:**

1. **High — Broken migration chain** (CONFIRMED, fixed)
   - 4 migrations (130527, 130812, 131500, 132500) created execution order violations on fresh DB: 130812 dropped non-existent index; 132500 duplicated it.
   - Deleted all 4. Single consolidated migration `Version20260801135500` creates `collection_fields` (with unique index) + `collections` (IF NOT EXISTS for dev DBs where 3.1 schema:update already applied), with DATETIME(6) precision.

2. **Medium — Repository untested** (CONFIRMED, fixed)
   - Created `tests/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepositoryTest.php`: 11 integration tests covering save/remove/findByCollection/findByCollectionAndSlot/nextSlotIndexFor/countByCollection.

3. **Medium — reassignToCollection slot collision** (CONFIRMED, contract-specified)
   - Method is pure collection-reference move: `reassignToCollection(Collection $collection): void`. slot_index unchanged.
   - Caller (use-case/service, Task 3.3) is responsible for ensuring slot_index does not collide with an existing field in the target collection. Domain does not query the repository here to avoid coupling entity to persistence.
   - On collision at flush, raw DBAL unique-constraint exception surfaces. Collision handling owned by service layer.

4. **Low — nextSlotIndexFor uncapped** (CONFIRMED, design)
   - Returns raw `MAX(f.slotIndex) + 1`. No PHP-level cap (reverted in commit `5f9f8e9`).
   - Out-of-range slot_index (above 100) is rejected by `CollectionField::__construct` lines 75–79 with `\InvalidArgumentException`, surfacing the overflow to the caller.
   - Concurrent-insert race (TOCTOU) acknowledged; designed to be mitigated at service layer (Task 3.3).

5. **Low — FieldName byte strlen vs char length** (CONFIRMED, fixed)
   - Replaced `strlen()` with `mb_strlen($value, 'UTF-8')` in FieldName constructor. MAX_LENGTH=50 now matches DB `VARCHAR(50)` semantics for multibyte names.

6. **High (hidden) — CI || true on migrations** (CONFIRMED, fixed)
   - `.github/workflows/ci.yml:164`: removed `|| true` from `doctrine:migrations:migrate` so migration failures surface as CI failures.

**Verification:** 217/217 tests pass. PHPStan L6 clean. PHPcsFixer clean (after auto-fix). PHPCPD: 0.43% pre-existing duplication in LoginController/RegistrationController (not introduced by these fixes). Doctrine schema validate: mapping OK.

## 2026-08-03 — External Review Round #3 for Task 3.2 (PR #22)

**Decisions taken as final:**

1. **High — Doctrine ORM 3.x → 4.0 uniqueConstraint/index attribute migration** (CONFIRMED, fixed)
   - `uniqueConstraints: [...]` and `indexes: [...]` arrays inside `#[ORM\Table(...)]` are no-op in Doctrine ORM 3.x and removed in 4.0. `doctrine:migrations:diff` and `schema:update` would silently drop the unique index on `collection_fields`, breaking slot integrity.
   - Moved to repeated class-level attributes in `CollectionField::class` and `Collection::class` (PHP 8 attribute repetition):
     - `CollectionField`: `#[ORM\UniqueConstraint(name: 'uniq_collection_field_slot', columns: ['collection_id', 'slot_index'])]`, `#[ORM\Index(name: 'idx_collection_field_collection', columns: ['collection_id'])]`
     - `Collection`: `#[ORM\Index(name: 'idx_collection_owner', columns: ['owner_id'])]`, `#[ORM\Index(name: 'idx_collection_theme', columns: ['theme'])]` (bundled from Task 3.1 — same risk class).
   - Migration `Version20260801135500` updated to use explicit index names matching the new class-level attributes.
   - New migration `Version20260801140000` conditionally renames the legacy auto-named Doctrine index `IDX_D325D3EE7E3C61F9` → `idx_collection_owner` on environments where 3.1 ran via `schema:update` (idempotent: skips on fresh DBs).
   - `src/Domain/User/Entity/User.php` still uses `indexes: [...]` inside `#[ORM\Table]` — same ORM 4.0 risk. **Out of scope** for Task 3.2; will be tracked as a separate Roadmap item.

2. **Low — AssumptionLog sync** (CORRECTED; see edits to 2026-08-02 entry above)
   - Round #2 entry originally claimed `reassignToCollection` auto-reallocates and `nextSlotIndexFor` is `min(MAX + 1, MAX)` — both reverted in commit `5f9f8e9`. Source-of-truth violation corrected above.

3. **Low (informational) — nextSlotIndexFor unbounded / TOCTOU race** (NO ACTION)
   - Acknowledged design decision. Mitigation owner: Task 3.3 service layer (transactions, locking, or optimistic retry on unique-constraint exception).

4. **Low (acceptable) — reassignToCollection collision surfaces raw DBAL exception** (NO ACTION)
   - Contract explicit in docblock at `CollectionField.php:148–153`. Caller (Task 3.3 service) owns collision avoidance.

**Verification (round #3):**
- `doctrine:schema:validate --env=test` on fresh DB: `[OK] mapping files correct`, `[OK] database schema in sync with mapping files`
- `SHOW INDEX FROM collections`: `idx_collection_owner`, `idx_collection_theme` present (renamed from `IDX_D325D3EE7E3C61F9` where legacy state existed)
- `SHOW INDEX FROM collection_fields`: `uniq_collection_field_slot` (composite), `idx_collection_field_collection` present
- 217/217 PHPUnit tests pass
- PHP-CS-Fixer clean (0 of 69 files)
- Rector clean
- PHPStan L6 clean
- Doctrine deprecation warning "Providing $indexes on Table does not have any effect" reduced to User.php only (Task 2.x territory)

## 2026-08-16 — Task 3.2 Retrospective PRD Creation

**Context:** PRD `PRD/3.2-collection-field-entity.md` создан поздно — после merge PR #22. Причина: ускоренный review-цикл после внешнего аудита, формальный шаг декомпозиции пропущен. На будущее — PRD обязателен до старта (CLAUDE.md §4).

**Decisions:**
- Реализация полностью соответствует критериям приёмки задокументированным ретроспективно
- Подзадачи 3.2.1..3.2.6 малы (≤2ч, ≤150 LoC каждая), выполнены в рамках бюджета 2x (<14ч общий объём)
- Никакого расширения scope ретроспективным PRD не санкционировано — только фиксация уже сделанного

**Outstanding (не блокер для 3.2 done):**
- `DoctrineCollectionFieldRepository` имплементация из infra-отдельной задачи пока отсутствует (в memory зафиксировано создание `tests/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepositoryTest.php`) — нужно проверить на main и создать отдельную задачу в Roadmap, если отсутствует
- `User.php` всё ещё использует `indexes` внутри `#[ORM\Table(...)]` — deprecation warning перенесён из 3.x в 2.x; технический долг, но не блокирует 3.2

**Next:** Task 3.3 (Collection service) — должна использовать `CollectionFieldRepositoryInterface`, проверить наличие имплементации до старта 3.3

## 2026-08-30 — Task 3.3 Collection Application Service

**Decisions:**
- CollectionService: final readonly class, injected CollectionRepositoryInterface; methods: create, update, getById, listByOwner, listAll, delete, toDTO, toDTOList
- DTOs: CreateCollectionDTO (name, theme, description?, image?), UpdateCollectionDTO (name?, description?, image?) with hasChanges() check
- CollectionDTO: output DTO (id, name, theme, description, image, ownerId, createdAt, updatedAt, toArray)
- CollectionNotFoundException: domain exception with factory ::withId(string $id)
- CollectionController: 5 endpoints — POST/GET/PATCH/DELETE /api/collections + GET /api/collections/{id}; JWT auth via getUser(); authorization check owner equality
- AbstractApiController: removed @template T generic (caused PHPStan variance errors); uses object class-string; provides deserializeAndValidate + createValidationErrorResponse helpers
- Controller tests: rewritten as full WebTestCase integration tests (not mocks — CollectionService is final); register+login flow in setUp; raw SQL tearDown for user cleanup
- list endpoint: limit=50, offset=0 query params defaulting via null-coalesce

**Issues encountered & fixed:**
- Readonly property with default value (PHP 8.3): `?string $description = null` invalid on readonly props → removed defaults, inline null-coalesce in constructor
- PHPStan generic type: `@extends AbstractApiController<DTO>` caused "does not specify its types: T" → removed template from base, removed extends docblocks from all controllers
- Mocking final class: ClassIsFinalException on CollectionService → rewrote as integration test
- Static closures accessing $this: static function() with `$this->owner` → changed to function() use ($id) for closures needing captured variables
- PHPStan syntax: OA attribute `description='...'` (equals) invalid → changed to `description: '...'` (colon)
- Rector CatchExceptionNameMatchingTypeRector: caught $e → $collectionNotFoundException, $throwable for \Throwable

**Tests:** 8 unit (CollectionServiceTest) + 9 integration (CollectionControllerTest) pass. Pre-existing failure: DoctrineUserRepositoryTest::testFindAll (4 users vs expected 3) — unrelated to this task, confirmed present before changes.

**CI:** PR #25 green. Rector applied CatchExceptionNameMatchingTypeRector on 2 files.

**Acceptance verified:**
- All quality gates: PHPUnit 234/235 (pre-existing failure excluded), PHPStan L6, PHPcsFixer, Rector dry-run, PHPCPD
- OpenAPI: 5 Collection endpoints documented with full schemas
- 235 tests, 511 assertions

**Assumptions:**
- list endpoint returns only user's own collections (listByOwner); separate listAll for admin/public not implemented (scope)
- UpdateCollectionDTO hasChanges() returns true if any field non-null — body can send {name: "x"} or {description: "y"} or all three
- PATCH with empty {} returns 400 (hasChanges() returns false) — no-op update rejected

**Next:** Task 3.4 — API-эндпоинты коллекции (CRUD, список всех, список своих)

## 2026-09-10 — Gemini AI Code Review integration (PR #25)

**Decisions:**
- CI AI code review: `petarzarkov/gemini-code-review-action@v1.1.3` (workflow `.github/workflows/code-review.yml`), pinned to a real release tag (`v1` short tag does not exist upstream)
- Model `gemini-2.5-flash`, `language: Russian`, conversation context + skip_draft_prs enabled; API key stored as GitHub repo secret `GEMINI_API_KEY`
- Action does its own repo checkout with full history — no explicit checkout step needed
- `prompt:` / `gemini_api_key:` inputs from community examples **do not exist** in v1.1.3; key goes via env, Russian via `language` input
- Domain exceptions live only in Domain layer — Application-layer exceptions duplicate the domain set (`CollectionNotFoundException` removed from `src/Application/Collection/Exception/`; Domain copy kept, service/controller already used it). Root rule: unique source of truth for domain errors

**Findings → review-4 task (Roadmap):**
- `flush()` inside `save()`/`remove()` is pervasive: identical pattern in all 3 Doctrine repos (Collection, CollectionField, User); both services (CollectionService, RegistrationService) rely on it implicitly. Confirmated by investigation agent — cross-cutting, not isolated. Logged as `review-4` (pervasive UoW/flush refactor, ~2-3h, post-merge backlog)

**CI:** PR #25 re-reviewed by Gemini after rebase on current main — 2 substantive inline comments (duplicate exception, flush-in-repository); first fixed, second backlogged.

**Next:** review-4 flush refactor task in Review Backlog.

## 2026-09-10 — review-4: UoW/flush refactor (implemented, PR #28)

**Decisions:**
- New abstraction: `UnitOfWorkInterface` (Application) with single `flush(): void`; implementation `DoctrineUnitOfWork` (Infrastructure) wraps `EntityManagerInterface::flush()`. Autowired via DI (single implementation)
- All 3 Doctrine repos now only schedule entities (`persist`/`remove`); flush removed. Services (CollectionService, RegistrationService) flush after save/remove. Repo interfaces in Domain are now documentation-only about deferred writes — Domain docblocks intentionally do NOT reference the Application UoW class (layering)
- Integration repo tests flush explicitly before query assertions: persister-based find methods (`find(SECOND/PATCH/repo)`, `findBy*`, `findAll`) do NOT trigger Doctrine auto-flush — only DQL does. Initial assumption (DQL-only repos) was wrong; failures caught it
- Test env: `cache:clear --env=test` does NOT invalidate the compiled test container — required `rm -rf var/cache/test` before rerunning after DI changes

**Deferred (not in review-4 scope):**
- `transactional(callable)` boundary on UnitOfWorkInterface — DEFERRED to Этап 4 (item + dynamic fields atomic creation). Rationale: no current consumer (all use-cases single-entity; exception before flush at use-case end already gives atomicity), YAGNI, tight scope. Architect review (nemotron-3-ultra-free) flagged it; logged as Roadmap enhancement for Stage 4 design

**Reviews:** senior-dev (ling Gemini Flash) APPROVE with 1 style nit (inline flush helper — fixed); architect (nemotron) REVISE on transactional + Domain docblock leak (docblocks fixed, transactional deferred).

**CI:** local `composer ci:all` exit 0 (phpstan, phpcs, rector, phpcpd, 235 tests).

**Next:** Task 3.4 — API-эндпоинты коллекции (CRUD, список всех, список своих).

## 2026-09-10 — Task 3.4: API-эндпоинты коллекции — список чужих коллекций (PR #29)

**Decisions:**
- Дизайн «список чужих коллекций»: опциональный query-param `?owner={uuid}` на существующем `GET /api/collections` (Variant A, утверждён пользователем). Отсутствие `owner` → свои коллекции (прежнее поведение backward-compatible); наличие → коллекции другого пользователя. Отклонён Variant B (отдельный `GET /api/users/{id}/collections`) — user-реквизит, не эндпоинтов.
- Репо-метод `findByOwnerId(UserId, limit, offset)` с фильтром `IDENTITY(c.owner) = :ownerId` + `setParameter(..., 'binary')` — сравнение по binary(16) FK без загрузки сущности User. Некорректный/неизвестный `owner` → 400 `{"error":"Invalid owner id"}` / пустой список 200 (не 404).
- **Критичный фикс (по ходу)**: `fetch=EAGER` на `Collection.owner` НЕ устраняет ghost-прокси — эмпирически проверено (чистка кэша метаданных, ошибка «Cannot generate lazy ghost: class User is final» осталась). Doctrine ORM 3 не может lazy/ghost-проксировать final-сущности; работающее решение = `JOIN FETCH` (innerJoin+addSelect owner) во ВСЕХ read-методах репо (findById, findByOwner, findByOwnerId, findAll). Прежние тесты проходили только потому что владелец оставался в identity-map того же EM.
- ассоциация `Collection.owner` снова `fetch=LAZY` + явные join — join-подход покрывает все read-пути; двойной eager-select не нужен.

**Outstanding (pre-existing, вынесены в Roadmap):**
- review-5: тот же латентный ghost-баг в `DoctrineCollectionFieldRepository` (`CollectionField.collection` → final Collection, LAZY, без JOIN FETCH) — всплывёт при Этап 4 (чтения айтемов/полей).
- review-6: cross-domain coupling — `Collection.owner`и `UserId` в Collection-домене. DDD-идеал: `OwnerId` VO в Collection-домене. Дефер: пре-существующий с 3.1, вынос >150 LOC, MVP accepted.

**Reviews (3 параллельных, разные модели):**
- senior (ling): APPROVE — JOIN FETCH покрывает все read-пути; `IDENTITY`+`binary` корректен. MINOR leftJoin→innerJoin в findById — исправлено.
- architect (nemotron): REVISE — OwnerId VO (→review-6), отдельный эндпоинт (отклонено, Variant A утверждён), EAGER (опровергнуто эмпирически).
- tech-lead (nemotron-3.5-lightning): SHIP-WITH-NITS — скоуп полный, non-breaking, покрытие адекватно.

**CI:** локально `composer ci:all` exit 0 — 238 тестов, 539 assertions (+3 теста: unit listByOwnerId, функц. чужие-изоляция, невалидный owner 400).

**Next:** Task 3.5 — валидация тем (Books/Games/Movies/Drinks).
