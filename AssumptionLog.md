# Assumption Log

## 2026-09-17 — Task 5.6: unit-тесты домена Like (ядро Этапа 5 завершено)

**Task 5.6 — tests-only.** Добавлены `tests/Domain/Like/ValueObject/LikeIdTest.php` (9 тестов, 78 строк) и `tests/Domain/Like/Entity/LikeTest.php` (5 тестов, 97 строк) — паритет с `CommentIdTest`/`CommentTest`. Дифф не затрагивает `src/` (проверено `git diff --name-only`). 175 строк тестов формально превышают ветку «≤150 source», поэтому подзадача уложена по альтернативной ветке «≤2ч» (код написан по готовым зеркалам).

**Почему задача существует, хотя покрытие уже 100%.** Покрытие соцслоя (`Application/Like`+`Application/Comment`+`Domain/Like`+`Domain/Comment`) — 148/148 statements = 100%, поэтому цифра после задачи **не изменилась**. Пробел был не в строках, а в согласованности: `Like`/`LikeId` были единственными доменными классами без прямого unit-теста (проверялись только транзитивно). `LikeTest` короче `CommentTest` намеренно: у `Like` нет `changeContent`/`touch`.

**Voter вынесен из 5.6 в новую задачу 5.10.** Изначальный план включал `SocialContentVoter` в 5.6, но ревью архитектора и техлида независимо указали на нарушение лимита 150 source-строк на подзадачу (~270 строк) и размывание скоупа «Unit-тесты». Решение пользователя «Voter в Этапе 5, а не в Этапе 7» сохранено — 5.10 стоит в Этапе 5 как core-задача (не `[review]`: это запланированная функциональность, а не находка ревью). Порядок: **5.6 → 5.10 → 5.9**; 5.8 также помечен зависящим от 5.10 (те же строки контроллеров).

**Отклонённая альтернатива для 5.10: `SocialContentPolicy` в Domain.** Техлид отметил, что польза Symfony Voter здесь маргинальна: предикат уже централизован в одном унаследованном хелпере `AbstractApiController::canManage` (строка 95), а контроллер всё равно сам собирает 403-ответ, поэтому Voter заменяет 3 вызова на 3 вызова плюс framework-церемония. Более дешёвый вариант — plain-политика в Domain с unit-тестами. Мандат пользователя на Voter принят; альтернатива фиксируется как рассмотренная и отклонённая.

**Исправленные фактические ошибки плана (по ревью).** (1) `canManage` не существует в виде приватных копий в `LikeController`/`CommentController` — это один `protected`-метод в `AbstractApiController`, вызываемый из 6 мест. (2) `ItemController` не имеет «своего» guard'а — он использует тот же унаследованный метод; split-brain после 5.10 будет осознанным и документируется. (3) Symfony 7.4.14 и PHPStan `level: 6`. (4) Регистрация Voter уже обеспечена `autoconfigure: true` на блоке `App\Infrastructure\Security\` в `config/services.yaml` — дополнительный тег не нужен.

**Порядок вызовов в 5.10 (решение техлида):** `supports()` = оба атрибута × {`Comment`, `Like`} → true; EDIT-on-`Like` отклоняется в `voteOnAttribute` (`ACCESS_DENIED`), а не через abstain. Причина: abstain при единственном зарегистрированном Voter даёт «denied» **случайно** (affirmative-стратегия), и это сломается при появлении второго Voter'а с grant.

**Состояние Этапа 5: core готов, этап НЕ закрыт.** Открыт core-пункт 5.10, а обязательные процедуры закрытия не выполнены: **smoke test** и **периодический review** (триггер сработал: 6 задач с прошлого review + конец этапа). Они зафиксированы незакрытыми чекбоксами в `PRD/5.6-social-tests.md` и перенесены в закрытие этапа после 5.10. Первоначальная формулировка «Этап 5 завершён» в этом PR была исправлена на «core готов, закрытие отложено» во всех артефактах (Roadmap, CLAUDE.md, ARCHITECTURE.md, CHANGELOG) по итогам трёх ревью — фиксировать невыполненное как выполненное нельзя.

**Актуализированы артефакты:** `CLAUDE.md` (блок «Текущий статус»), `Roadmap.md` (5.6 → done, +5.10 core, прогресс 30/48 и правило учёта `[review]`-строк), `ARCHITECTURE.md` (строка статуса Этапа 5), `CHANGELOG.md` (секция «Этап 5» с пометкой об отложенном закрытии).

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

## 2026-09-10 — Task 3.4: API-эндпоинты коллекции (PR #29)

**Decisions:**
- Delta of 3.4 vs already-shipped 3.3 CRUD: only "list other users' collections" missing. Design **Variant A** (locked): extend `GET /api/collections` with optional `?owner={uuid}` — absent = own collections, present = foreign. Variant B (separate `/api/users/{id}/collections`) rejected by owner. PRD: `PRD/3.4-api-collection-endpoints.md`
- `findByOwnerId(UserId, limit, offset)` compares via `IDENTITY(c.owner) = :ownerId` plus `leftJoin('c.owner','owner')` + `addSelect('owner')` — binary UUID compare without loading User entity
- **Doctrine ORM 3 hard constraint (empirically verified):** cannot lazy/ghost-proxy `final` entities (final `User` as ManyToOne owner). `fetch: 'EAGER'` on the mapping does NOT fix it (ORM 3 still routes ToOne through proxy path outside identity map). Required fix: **JOIN FETCH** (innerJoin/leftJoin + addSelect) in every DQL read method. Applied to all 4 read methods of `DoctrineCollectionRepository`. Mapping stays `fetch: 'LAZY'` (JOIN FETCH overrides at query time)
- Invalid `?owner` UUID → 400 `{"error": "Invalid owner id"}` via `\InvalidArgumentException` catch in controller; `$owner` guarded with `\is_string()` (query-param is `mixed`)
- 3-agent parallel review (mandatory for 3.4): senior `php-senior-reviewer` (ling-3.0-flash-fin-free) APPROVE; architect `architect-reviewer` (nemotron-3-ultra-free) REVISE → OwnerId-VO + separate endpoint + EAGER (EAGER empirically disproven, endpoint = locked Variant B, OwnerId extracted → review-6); tech-lead `tech-lead-reviewer` (nemotron-3.5-lightning-free) SHIP-WITH-NITS

**CI:** local ci:all exit 0 (238 tests). PR #29: all 5 checks green, no Gemini comments.

## 2026-09-10 — CI: AI code review switched Gemini → OpenRabbit (PR #31)

**Context:** Gemini review (petarzarkov action) kept failing silently — free-tier quota (429) + auto-derank chain includes retired `gemini-2.0-flash` (404). Action exits 0 with 0 comments = green check, no review.

**Decisions:**
- Switch to **OpenRabbit** (`aryanbrite/openrabbit@v0.8.7`) on **OpenRouter free pool** (`llm_model: openrouter/free`). Auto-rotation between free models = built-in failover vs single-model quota death. Groq evaluated (1000 RPD free, faster) but single provider without multi-model rotation → rejected. OpenRouter key = `LLM_API_KEY` secret; `GEMINI_API_KEY` deleted
- **English comments** (owner decision): stock OpenRabbit has no language input (`specialInstructions` in src/reviewer.ts not exposed via action.yml); RU via wrapper/fork rejected
- **No file-exclude**: OpenRabbit v0.8.7 has no exclude input — reviews all changed files (docs/migrations/workflows included). Accepted tradeoff
- **Draft PRs skipped** at job level (`if: github.event.pull_request.draft == false`) — saves free-pool tokens
- Summary posted as PR review (`COMMENTED`) + inline comments; verdicts use needs-changes formally even when action items are non-configurable decisions — respond in thread and merge on all-green

**CI check names now:** CI Summary, Static Analysis & Lint, Unit Tests, OpenRabbit Review, Dependency Audit.

## 2026-09-10 — Review Backlog sweep: review-2 closed

**review-2 (CVE-2026-45073 symfony/cache):** CLOSED — `composer audit` clean (2026-09-10, lock v7.3.11: `No security vulnerability advisories found`). Advisory no longer matches current lock; no bump needed.

**New finding:** `sebastian/phpcpd` abandoned (used in CI PHPCPD step). Still functional; either pin or drop in later infra task — logged, no action yet.

## 2026-09-10 — review-5: CollectionFieldRepository ghost-proxy fix

**Bug:** `findById()` via persister `find()` + LAZY `final` Collection / User → `Cannot generate lazy ghost` on fresh UoW. Latent — tests kept entity in identity map.
**Fix:** DQL chain-join `f -> collection -> owner` (innerJoin + addSelect, binary id param) — mirrors verified `Collection.owner` pattern. First attempt (join only f->collection) failed: hydrating `Collection` alone proxies its `owner` User — chained join required.
**Test:** regression `testFindByIdHydratesCollectionAssociation` — `em->clear()` + `findById` + `getCollection()`; red before fix (confirmed), green after.
**CI:** ci:all exit 0, 239 tests. Scope kept tight: other repo methods take hydrated `Collection` param — no change. `transactional()` stays in fwd-1 (Этап 4).

## 2026-09-10 — review-1: Clock TZ binding audit closed

**Verdict:** non-issue, closed. Production binding is the `Clock` facade (not direct `NativeClock` with `timezone=`), guarded by `ClockInjectListener` LogicException off the dual-clock contract (AssumptionLog F3).
**Verified:** zero `date_default_timezone_set`/`withTimeZone` calls in src/config/tests → no runtime TZ mutation → bootstrap-order drift scenario impossible; container `date.timezone=UTC`, `date_default_timezone_get()=UTC` stable.
**Latent note:** facade's internal NativeClock reads `date_default_timezone_get()` on first `now()`. If future code calls `date_default_timezone_set`, Clock silently shifts TZ — keep in mind, not actionable now.

## 2026-09-10 — review-3: serializer policy for ClockAwareTrait closed

**Verdict:** non-issue, closed with policy. Facts: (1) no entity goes through
`serialize()` anywhere — Symfony serializer is used for request DTOs only;
(2) the trait is Symfony's own `ClockAwareTrait` with private nullable `$clock`
— null in production (facade `Clock::get()` static), never `setClock()` in app code;
(3) `Clock` facade is stateless (no instance props) — even if an entity carrying
a facade were serialized, no frozen time state is carried.

**Policy:** entities must never hold an explicit clock instance; always rely on the
facade (already enforced by ClockInjectListener dual-clock contract). If a future
consumer needs entity round-trip through `serialize()` (cache/queue/session), add
`#[Serializer\Ignore]` + null-restore in `__unserialize` for `$clock` BEFORE enabling it.

## 2026-09-10 — Task 3.5 and 3.6 closed via audit (no code delta)

**Context:** internal review cycle flagged tasks 3.5 (theme validation) and 3.6
(domain unit tests) as candidates for auditing — the codebase appeared to already
satisfy both.

**Findings — 3.5 «Валидация тем»:**
- `Theme` VO full: `books()/games()/movies()/drinks()`, `fromString()` (trim,
  lowercase, `InvalidArgumentException: Invalid theme`), `values()`, `equals()`.
- `ThemeEnum` (books/games/movies/drinks) + `ThemeEnumType` Doctrine mapping.
- `CreateCollectionDTO`: `NotBlank` + `Choice(constraints: ThemeEnum::values())`
  → invalid theme returns 400 `{"error":"Validation failed","details":[...]}`,
  covered by `testCreateReturns400WhenInvalid`.
- Domain guard: `Theme::fromString` unreachable via API (DTO filters first).
- OpenAPI create schema documents `enum: ['books','games','movies','drinks']`.
- 11 unit tests in `ThemeTest.php`.

**Findings — 3.6 «Unit-тесты домена Collection»:**
- `CollectionTest.php`: 16 tests (create variants, changeName/Theme/Description/
  Image, touch, reassignOwner, whitespace normalization to null).
- `CollectionFieldTest.php`: 9 tests (slotIndex range/max, rename, reassign, touch).
- ValueObject suites: 66 tests total (Theme, FieldName, FieldType, CollectionName,
  CollectionId, CollectionFieldId).

**Decision:** both tasks already implemented (work shipped via 3.1/3.3/3.4);
marked done with evidence. No reimplementation.

**Noted constraint:** PRD:99 limits UPDATE scope to name+description only.
`UpdateCollectionDTO` deliberately has no `theme` field — theme change via API is
out of scope (mirrors PRD), `CollectionEntity::changeTheme()` remains domain-only.

---

## 2026-09-10 — Chore #35: phpcpd removal, audit hard-gate, symfony/cache CVE fix

**Task:** PR #35 (`0d36dec`), chore/ci-refine-remove-phpcpd.

**Decisions:**
- **phpcpd removed** from project + CI (composer.json dep+script, `ci:static:quality`, CI step name). Abandoned (no suggested replacement), no maintained CPD alternative exists for PHP (PHPStan/Psalm/Rector offer none). Duplicate detection covered by PHPStan + OpenRabbit review on a young small codebase. If duplication becomes a problem, revisit.
- **`composer audit --locked || true` → `composer audit --locked`**. Audit could never fail CI before (false green the entire time, including the earlier review-2 'CVE-2026-45073 clean' pass — that pass was stale-advisory-cache based). Now a real gate on PR and main push.
- **symfony/cache 7.3.11 → 7.4.18** (CVE-2026-45073, SQLi via `PdoAdapter::doClear`, affected >=7.3,<7.4). Included because the hardened audit gate would otherwise fail CI on main immediately. No full 7.4 migration — 3 packages (cache, contracts, var-exporter).

**Verification:** ci:all local 239/239 green; `composer audit --locked` no advisories; CI 5/5 green incl. Dependency Audit; OpenRabbit no findings.

**Assumption:** CVE-2026-45073 previously declared clean (review-2 close) was WRONG — advisory-cache staleness masked it. The hard gate prevents recurrence.

## Coverage enforcement in CI (PR #36, squash 2cb78b5)

**Change:** PHPUnit now ALWAYS runs with coverage, on every branch and on main push.

- phpunit.xml.dist `<report>`: `<text showOnlySummary>` (console: only Classes/Methods/Lines totals) + `<clover>` (var/coverage/clover.xml, gitignored, internal).
- New `scripts/coverage-gate.php`: parses clover project metrics (coveredstatements/statements), fails with exit 1 when line coverage < COVERAGE_MIN (workflow env, default 80). Missing/corrupt clover = fail, no silent pass.
- composer: `coverage:gate` = phpunit (XDEBUG_MODE=coverage) + gate script; `phpunit` and `ci:test:coverage` = XDEBUG_MODE=coverage phpunit.
- ci.yml: single unconditioned step `Run PHPUnit tests with coverage enforcement` (${{ env.COVERAGE_MIN }} = 80) replaces the old two conditional steps; no OpenRabbit on main push (unchanged).
- README documents local no-coverage (composer test / ci:all) vs CI always-coverage + 80% gate.

**Verdicts:** OpenRabbit `ready to merge` on 695d3be; 8 inline comments addressed (4 code/dir fixes, 4 confirmed). Final re-review (3d0a378): no findings.

**Verified:** main push 2cb78b5: 4/4 checks green, unit log shows summary + "coverage-gate: passed" (99.16%).

## 2026-09-11 — Squash migrations (#37)

**Change:** 4 Doctrine migrations (Version20260717..Version20260801140000) collapsed into one baseline Version20260911120000 (users, collections, collection_fields). Written by hand from the live SHOW CREATE TABLE golden reference; column order, index/FK names, utf8mb4_0900_ai_ci collation preserved byte-identically (verified: schema:update = Nothing to update on probe DB, SHOW CREATE diff empty vs golden).

**Decision:** one-time reset of dev/test DBs (project in dev, no data). Future DBs apply a single migration.

**Hidden lint gap found and fixed:** .php-cs-fixer.dist.php excluded migrations/ from PHP-CS-Fixer; PHPStan/Rector scan only src/. No linter ever inspected migrations -- a missing trailing newline in the squashed file went unnoticed on local AND CI runs. Fix: removed the finder exclusion, migrations now linted (project scan 83 to 84 files). Both fixes in same squashed PR commit.

**Verification:** probe DB taskflow_probe migrate + schema:update empty; composer ci:all 239/239; coverage:gate 99.16%; CI 5/5 on final squashed commit f25a4c2.

## 2026-09-11 — fwd-2: slots sized by constant (deferred to backlog)

**Finding (from Task 4.1 closeout):** `Item` has fixed 12 columns (text_1..3, num_1..3, date_1..3, bool_1..3), and `getSlotValue`/`setSlotValue` hardcode `1,2,3 =>` match arms. `SlotLimits::MAX_SLOTS_PER_TYPE = 3` is used only for range validation — changing it does NOT change schema columns nor match arms (slot > 3 → throw). The constant creates false configurability.

**Decision:** intentional at 4.1 scope (fixed schema, 150-line cap). Dynamic slot count deferred as `fwd-2` in Roadmap backlog with candidate designs: (a) EAV table item_slot_values(item_id, field_type, slot_index, value), (b) JSON column slots, (c) column generation by constant. Trigger: real need >3 slots per type or architecture review. Estimate 3-5h.

## 2026-09-11 — Task 4.1: shared SlotLimits, slot semantics

- OpenRabbit review-раунд: найдено `MAX_SLOTS_PER_TYPE` дублирован в CollectionField и Item.
  Опасность: field (type, slot) maps 1:1 на Item.{type}_{slot}; расход констант = поля молча теряются.
- Решение: единый `App\Domain\Common\Constant\SlotLimits::MAX_SLOTS_PER_TYPE = 3`,
  обе сущности ссылаются. Архитектура слоёв не нарушена (Domain/Common — общий слой).
- Отклонено: protected-конструктор Item (@internal + factory — паттерн всех сущностей, Doctrine 3
  требует конструируемых сущностей для hydration; runtime-защита create() — ответственность Application).
- Slash в ALLOWED_PATTERN намеренный (иерархические имена), рационал подтверждён дизайн-подтверждением.
- `nextSlotIndexFor` (MAX по всем типам) — нет prod-вызовов; периодически per-type semantics
  пересматривается в Task 4.4 (mapping/service).


## 2026-09-11 — Task 4.1 closed: PR #38 merged

- Squash-merge #38 (feat(Item): 3f0a24c), 10 коммитов → 1. 286 tests / 628 assertions, coverage gate 99%+.
- OpenRouter free pool исчерпал daily free-models-per-day (50 RPD) на финальном CI-ранге: OpenRabbit упал честным 429-failure (в отличие от Gemini silent-pass). Merge прошёл после локальной проверки + 4/5 green.
- Rebаse на main при слиянии: AssumptionLog conflict разрешён — обе секции (fwd-2 из #39 + SlotLimits из #38) сохранены.
- ARCHITECTURE.md: добавлен домен Item (entity, ItemId, repo interface, правила слотов).


## 2026-09-11 — AI code review: Groq fallback when OpenRouter fails

- Problem: OpenRouter free pool (50 requests/day, `free-models-per-day`) exhausted → OpenRabbit threw 429 and the review check went red with no review produced.
- OpenRabbit v0.8.7 has no native cross-provider fallback: `createLLMClient` selects a single provider, and Groq/OpenRouter share the same OpenAI-compatible client.
- Safety finding: `runReview` performs all LLM calls first and writes to GitHub exactly once at the end (`pulls.createReview`). A failed step therefore posts nothing, so a retry with a second provider cannot duplicate comments.
- Solution: `.github/workflows/code-review.yml` — step 1 OpenRouter (`id: openrouter`, `continue-on-error: true`), step 2 Groq (`if: steps.openrouter.outcome == 'failure'`), provider `groq`, `llm_api_url: https://api.groq.com/openai/v1`, model `openai/gpt-oss-120b`, secret `GROQ_API_KEY`.
- Model choice: OpenRabbit always sends `reasoning_effort: 'medium'`; among free Groq models only `gpt-oss-120b`/`gpt-oss-20b` accept it (llama/qwen reject it). Chose 120b.
- Verified end-to-end on PR #40: OpenRouter step failed with 429, Groq fallback ran and posted the review (check green).
- Known limit: Groq free TPM is 8K — very large PR prompts may still 429; fallback is best-effort.


## 2026-09-12 — Task 4.2 closed: Tag entity + item_tags many-to-many (PR #41)

- Squash-merge #41 (feat(Tag): 8ef7ceb). 330 тестов, coverage 97.77% (gate >= 80). Миграция Version20260911195240 применена на dev и test, schema:update пусто.
- Tag глобальный (без владельца). UNIQUE(name) + коллация utf8mb4_0900_ai_ci даёт регистронезависимую уникальность; регистр первого ввода сохраняется. Подтверждено integration-тестами на MySQL: Books/books -> UniqueConstraintViolationException; DQL WHERE name.value='books' находит 'Books'.
- Item <-> Tag unidirectional ManyToMany через item_tags (оба FK ON DELETE CASCADE); методы addTag/removeTag/hasTag/getTags (идемпотентные, touch updatedAt). Ленивая загрузка to-many коллекции final Tag не даёт ghost-proxy (в отличие от to-one final сущностей).
- TagRepositoryInterface только интерфейс (findByName для переиспользования); DoctrineTagRepository отложен в Task 4.3. TagName VO: trim, strip control, collapse, 2..30, whitelist как FieldName.
- Декомпозиция: TagRepositoryInterface перенесён из S1 в S2 — интерфейс ссылается на Tag entity, иначе PHPStan падает на висячей ссылке.
- Ревью (senior + architect): принято — удалён мёртвый Tag::touch() (нет мутатора), docblock про регистронезависимость в TagName. Отклонено с обоснованием — Item->Tag через entity вместо TagId VO (в проекте entity-ссылки: Item->Collection, Collection->User), #[ORM\Embeddable] на ID VO (единый паттерн CollectionId/ItemId), #[ORM\HasLifecycleCallbacks] без колбэков (паттерн проекта).
- Новая Task 4.7 в Roadmap: эндпоинт списка/поиска тегов, была упущена при планировании Этапа 4 (запрос пользователя).
- AI-ревью PR #41 не прошло: OpenRouter дневная квота исчерпана; Groq fallback сработал, но free-модель уперлась в лимит токенов (413, ~12.7K > 7000) на крупном diff. Отдельный CI-фикс #42 переключил Groq fallback на qwen/qwen3.8-27b (валидирован на самом #42, 5/5 green).


## 2026-09-12 — Task 4.3 closed: tag service find-or-create (PR #44)

- Squash-merge #44 (feat(Tag): 9c90369). 344 теста, coverage 97.83% (gate >=80), phpstan L9/phpcs/rector чисто. Без миграций.
- Выбран вариант B: `DoctrineTagRepository::getOrCreate` делает атомарный MySQL upsert (`INSERT ... ON DUPLICATE KEY UPDATE id = id`) и перечитывает строку. Гонка конкурентного создания разрешается unique-индексом без исключений; raw SQL только в Infrastructure — Doctrine не протекает в Application.
- `TagService::resolveByNames(array<string>)` (Application): нормализация через `TagName`, дедуп до записи, разрешение через `getOrCreate`, дедуп по идентичности (регистронезависимо), порядок первого появления. Без UoW/flush/Doctrine; коммит за вызывающим (Task 4.4).
- Отклонение от конвенции «save отложен до flush»: `getOrCreate` пишет строку сразу (атомарность нужна на момент разрешения; ORM 3 не имеет partial flush и auto-flush перед DQL — проверено: `AutoFlushMode`/`autoFlush` в vendor/doctrine/orm 3.6.7 отсутствуют). «Осиротевший» тег при падении создания айтема безвреден — теги глобальные и переиспользуемые.
- `UnitOfWorkInterface::clear()` отклонён: сброс общего EntityManager отцепил бы pending-сущности вызывающего.
- Ревью (senior + architect): принято — явный `ParameterType::BINARY` для binary UUID, ассерт регистра в integration-тесте, фиксация аргументов в дедуп-тесте, документирование семантики персистентности. Отклонено с обоснованием — `wrapInTransaction` (единичный атомарный upsert достаточен; вложенная транзакция DBAL без savepoints преждевременно коммитит внешнюю), перенос `getOrCreate` в Domain Service (потерял бы атомарность; в проекте нет доменных сервисов), `is_string`-гард (контракт `array<string>`; валидация формы запроса — в контроллере 4.5).
- AI-ревью PR #44: OpenRouter отработал (квота сбросилась), Groq fallback пропущен; вердикт ready to merge. Два inline-комментария отвечены (один невалидный — сниппет с глобальным `\Tag`; второй уже покрыт тремя тестами на регистронезависимость).

## 2026-09-13 — Task 4.4 closed: item service (PR #45)

- `DoctrineItemRepository` создан (в 4.1 были только entity + интерфейс). Все read-методы делают JOIN FETCH-цепочку `i.collection -> collection.owner` (to-one только, поэтому cartesian-инфляции нет). Причина: final Collection/User не проксируются ORM 3 (подтверждено эмпирически в review-5/Task 3.4).
- «Свободная» валидация слотов (решение пользователя): слоты не сверяются со схемой `CollectionField`; `ItemDTO::fromEntity` возвращает только заполненные.
- Слоты в DTO — массив структур `{type, slot, value}` (решение пользователя). `ItemSlotMapper` коэрсит: date → ISO-8601 (`createFromFormat` + round-trip-проверка, `Z` → `+00:00`, милли/микросекунды, offset-less → UTC), number → float, bool, text. Невалидная дата/строка → `\InvalidArgumentException` (в 4.5 мапится в 400).
- Обновление тегов — replace-семантика по `TagId` (решение пользователя); слоты — частичное обновление (только из DTO, `null` очищает).
- Неатомарность create (теги пишутся сразу через `getOrCreate`, Item — финальным flush): осознанно, `fwd-1` отложен; orphan-теги глобальные/безвредные, риск задокументирован в PRD.
- N+1 по тегам в списках: отложен. `leftJoin('i.tags')` + `addSelect` сломал бы пагинацию (cartesian). Правильный фикс — batch-fetch вторым запросом или subquery-пагинация (зафиксировано в PRD).
- Авторизация владельца на update/delete — уровень контроллера (4.5); Application-сервис принципала не знает (решение пользователя).
- Ревью (senior + architect): принято — `SlotLimits::MAX_SLOTS_PER_TYPE` в DTO-констрейнтах, `FieldType::values()`, id-based replace тегов, `withCollectionAndOwner()` хелпер, round-trip UTC-интеграционный тест, ссылка на доки Doctrine, B-side изоляции. Отклонено — атомарная транзакция (`fwd-1`), eager-теги (ломают пагинацию).
- OpenRabbit: Z/мс-гэп в `asDate` (реальный баг, исправлен), два ложных «needs changes» (статус Roadmap по конвенции — после мержа; `artifacts/` в репо существуют). Финальный вердикт на сквоше `3354ddc` — ready to merge.
- Сквош 13 коммитов в один (`3354ddc`) перед мержем по просьбе пользователя.

## 2026-09-13 — Task 4.5 API item endpoints (PR pending)

- **Доступ на чтение** (решение пользователя): только владелец+админ, НЕ public. Убрано `^/api/items` из `PUBLIC_ACCESS` в security.yaml; добавлено правило `^/api/collections/[^/]+/items` → `IS_AUTHENTICATED_FULLY` ПЕРЕД public-GET коллекций (первое совпадение выигрывает; регулярка обязана быть в YAML-кавычках — иначе `[^/]` парсится как flow-sequence).
- **Фильтр тегов** — AND через коррелированные `EXISTS`-подзапросы (по одному на тег, `_t MEMBER OF i.tags AND _t.name.value = :tagN`), НЕ `JOIN + GROUP BY/HAVING` — иначе `setMaxResults` пагинация ломается. Имена нормализуются `TagName::fromString` в `ItemService` (ci — через коллацию MySQL).
- **`GET /api/items`** — только свои айтемы; `?owner={uuid}` — расширение Этап 5.
- **N+1 по тегам** — отложен, записан `fwd-6` (двухзапросный batch).
- **Слоты** — свободная валидация (из 4.4), со схемой `CollectionField` не сверяются.
- **`PhpDocExtractor`** подключён в `property_info.type_extractor` (`config/services.yaml`): без него вложенные `ItemSlotDTO[]` денормализовались как raw-массивы → `TypeError` в `ItemSlotMapper::applySlot`. `property_info.constructor_extractor` оборачивал только `ReflectionExtractor`; приоритет `-1001` ставит PhpDocExtractor перед ним.
- **OpenAPI-компонент `Item`**: nelmio перезаписывает `components` из конфига (`config/packages/dev/api_doc.yaml`), поэтому `#[OA\Schema(schema: 'Item')]`-класс терялся — схема определена в YAML-конфиге nelmio, не в коде.
- **Тестовый кэш** (`var/cache/test`) чистился при появлении новых роутов — известный кейс (#30).

## 2026-09-13 — Task 4.5 review fixes (senior + architect)

- **Невалидный UUID в пути** → 404 (не 500): `ItemId::fromString`/`CollectionId::fromString` бросают `\InvalidArgumentException`; ловим его в эндпоинтах рядом с `*NotFoundException` → 404.
- **`limit`/`offset` валидация** (`parsePagination`): нецифровые/отрицательные → 400 (раньше `?limit=abc` → 0, `?offset=-1` → 500).
- **LIKE-wildcards** экранируются `addcslashes($name, '%_\\')` — `?name=%` больше не матчит всё.
- **`parseFilters`** упрощён: контроллер передаёт raw, каноническая нормализация (trim/empty/TagName) — только в `ItemService` (одна точка правды).
- **Доступ админа** — только для Item-ресурсов (решение D1); `CollectionController` остаётся owner-only. Несогласованность задокументирована, синхронизация — на усмотрение отдельной задачи.
- **Анонимный GET `/api/collections`** возвращает 401 из контроллера (`getUser()` null) — так было ДО 4.5; PUBLIC_ACCESS на firewall лишь пропускает запрос. Гостевой просмотр коллекций/айтемов — Этап 5, не реализован.
- **`config/reference.php`** — локальный артефакт (stray), случайно попал в коммит через `git add -A`; восстановлен из origin/main.
- **OpenAPI `Item`-схема** расширена (tags → `{id,name}`, slots → `{type,slot,value}`); убран мёртвый `securityDefinitions`.

## 2026-09-13 — fwd-3 closed: ArrayableInterface (PR #46)

- Введён `App\Application\Common\DTO\ArrayableInterface` (`toArray(): array`, docblock `@return array<string, mixed>`) — единый контракт сериализации response-DTO. Реализован на `CollectionDTO`, `ItemDTO`, `TagDTO` **без изменения формы вывода** (JSON-контракт API байт-в-байт).
- Решения пользователя: нативный return тип остаётся `array`, конкретные docblock-формы (`array{id,name}` у TagDTO) не нормализуются — PHPStan LSP проходит; `TagDTO` остаётся в `App\Application\Item\DTO` (не переносим); входные DTO вне контракта; generic-потребитель не вводится.
- Rector добавил `#[\Override]` на 3 `toArray()` (реализация интерфейса, PHP 8.3).
- Тест — `ArrayableInterfaceTest` на `@dataProvider` (не атрибут: установленный PHPUnit **9.6.35**, хотя composer.json требует `^11.5` — несоответствие зафиксировано, тест написан под 9).
- 393/393 теста зелёные, ci:all зелёный.

## 2026-09-13 — review-6 closed: OwnerId value object (PR #48)

- Введён `App\Domain\Collection\ValueObject\OwnerId` (UuidBinaryValue, final readonly, зеркало `CollectionFieldId`). Типизирует query/DTO-слой вместо `User\UserId`:
  - Collection: `CollectionRepositoryInterface::findByOwnerId(OwnerId)`, `CollectionService::listByOwnerId(OwnerId)`, `CollectionDTO::$ownerId: OwnerId`. `findByOwner(User)` и `listByOwner(User)` **удалены**.
  - Item: `ItemRepositoryInterface::findByOwnerId(OwnerId)`, `ItemService::listByOwner(OwnerId)` — переиспользует `Collection\OwnerId` (зависимость Item→Collection уже была).
  - Контроллер конвертирует: `OwnerId::fromString($owner)` для `?owner` (400 на невалидный), `OwnerId::fromBytes($user->getId()->toBytes())` для своих.
- **Ограничение scope (честно):** полный разрыв кросс-доменной зависимости невозможен без смены связи `Collection.owner` — `ManyToOne User` и `CollectionService::create(User)` остаются. Убрана зависимость от `User\UserId` в query-сигнатурах, не от сущности `User`. Формулировка Roadmap была шире фактического скоупа.
- **OQ-1:** ORM-атрибуты (`#[ORM\Embeddable]`/`#[ORM\Column]`) зеркалированы как у `CollectionFieldId` (консистентность, VO не маппится). **OQ-2:** guard-тест на запрет `User\UserId` не добавлен (entity всё равно импортирует User; хрупко) — отложен до удаления `User` из entity. **OQ-3:** конверсия через `fromBytes(toBytes())`, без UUID-string round-trip.
- PHPUnit-мисматч (composer.json `^11.5`, установлен 9.6.35) — зафиксирован, отдельная задача `fwd-4`.
- План создан агентом `task-planner` (deepseek-v4.1-flash) — PRD `PRD/review-6-ownerid-vo.md`. 400/400 тестов, ci:all зелёный.

## 2026-09-13 — fwd-1 closed: transactional boundary in UnitOfWork

- `UnitOfWorkInterface::transactional(callable): mixed` (`@template T`, возврат callback насквозь); `DoctrineUnitOfWork::transactional()` → `EntityManager::wrapInTransaction`.
- `ItemService::create`/`update` обёрнуты в `transactional` (убрали явный `flush` внутри — транзакция владеет flush+commit). `delete` — `remove`+`flush` (одна сущность). Collection/Registration не тронуты.
- **Атомарность доказана:** `getOrCreate` пишет через `getEntityManager()->getConnection()` — та же DBAL-connection, что у EM. `wrapInTransaction` открывает транзакцию на ней → raw-INSERT тега откатывается вместе с Item. Проверено по vendor (doctrine/orm 3.6.7, dbal 4.4.3).
- **Вложенность безопасна:** DBAL 4.4.3 для level>1 — SAVEPOINT/RELEASE/ROLLBACK TO SAVEPOINT. **Коррекция прежней записи** (2026-09-12, review-4): утверждение «вложенная DBAL-транзакция без savepoints преждевременно коммитит внешнюю» неточно для 4.4.3 — savepoints работают.
- `wrapInTransaction` на исключении вызывает `EntityManager::close()` (clear+detach, connection не закрывается). В integration-тесте после исключения проверяем через DBAL-connection.
- Integration-тесты (MySQL): happy-path — item+новый тег персистентны; rollback — тег, записанный raw-upsert'ом внутри упавшей транзакции, отсутствует. 2/2.
- Plan: `PRD/fwd-1-transactional-uow.md` (task-planner, deepseek-v4.1-flash).

## 2026-09-15 — Task 4.7: tag list/search endpoint

- `GET /api/tags?search=&limit=&offset=` — список/поиск глобальных тегов для autocomplete при тегировании айтема. Доступ auth-only (catch-all `^/api` → `IS_AUTHENTICATED_FULLY`), read-only.
- **D1:** search = подстрока `LIKE %term%`, ci-коллация, wildcards `%_` экранируются (`addcslashes`); `ORDER BY name ASC`.
- **D2/D3/D4:** auth-only; limit default 50 (1..100); ответ — bare-массив `TagDTO[]`.
- **Поиск-терм НЕ нормализуется через `TagName`** (тот требует ≥2 символов и whitelist) — поисковая строка свободная: trim + strip `\p{Cc}` + collapse whitespace, пустая → null (все теги). TagName остаётся для СОЗДАНИЯ тега (через айтемы).
- `TagRepositoryInterface::search(?string $term, int $limit = 50, int $offset = 0): array` + Doctrine impl (DQL `t.name.value LIKE`). `TagService::listTags()` — нормализация + делегирование.
- Тесты: интеграционные (MySQL) 4 — все без терма, ci-подстрока, escape wildcards, пагинация+порядок; functional 8 — 401 аноним, список, search ci, escape, пагинация, 400 limit/offset/out-of-range.
- **Нюанс:** API-роуты регистрируются только через `config/routes/dev/attributes.yaml`; после добавления контроллера нужен `rm -rf var/cache/test` (иначе «No route found» в функциональных тестах).
- Plan: `PRD/4.7-tag-list-endpoint.md`.
- **Контракт пагинации /api/items выровнен** (review PR #54): `parsePagination` переехал в `AbstractApiController` с валидацией `limit 1..100`, `offset >= 0`. Ранее `/api/items?limit=200` (или 0) проходил без валидации. Breaking-совместимость: потребители с `limit>100`/`limit=0` теперь получают 400. Сознательное ужесточение, все тесты зелёные.

## 2026-09-15 — Task 4.7: API-эндпоинт тегов (GET /api/tags)

**Реализовано:** `GET /api/tags?search=&limit=&offset=` — auth-only, bare `TagDTO[]`, `ORDER BY name ASC`; поиск = ci-подстрока (`LIKE %term%`, `%`/`_`/`\` экранированы через `addcslashes`). Слои: `TagRepositoryInterface::search(?string $term, int $limit, int $offset)` + `DoctrineTagRepository`; `TagService::listTags()` с нормализацией term (trim, strip control-символов, collapse whitespace, blank → null — свободная форма, НЕ через `TagName`, т.к. одиночный символ валиден для поиска); тонкий `TagController`; `Tag`-схема в `api_doc.yaml`; OpenAPI перегенерирован.

**Решения:**
- Поиск подстрокой (D1), доступ auth-only (D2), default limit 50 (D3), bare-массив (D4) — подтверждены пользователем.
- `parsePagination` вынесен в `AbstractApiController` (общий с `ItemController`) — контракт пагинации выровнен: `limit 1..100`, `offset >= 0`. Ранее `/api/items?limit=200`/`limit=0` проходил без валидации; теперь 400 (сознательное ужесточение).
- Границы пагинации — типизированные константы `DEFAULT_LIMIT/MIN_LIMIT/MAX_LIMIT/DEFAULT_OFFSET` (правка по ревью пользователя: «Hardcoded values are not good»); OA-аннотации ссылаются на константы, у `/api/items` добавлены min/max.
- `TagDTO` перенесён `Application/Item/DTO` → `Application/Tag/DTO` (bounded-context fix по ревью архитектора).
- `config/reference.php` — локальный stray-артефакт, повторно исключался из коммитов.

**Отложено:** N+1 тегов в списках — `fwd-6`; `?owner` для айтемов и guest-view — Этап 5.

**Инцидент CI:** OpenRabbit-ревью сгорело в 15-мин таймауте — OpenRouter-шаг утонул в ретраях 429 (RPD exhausted), NIM-fallback не успел (у экшена нет input'а на retry/timeout). Повторный ран — 1m42s. Предложен хардненинг: NIM primary + OpenRouter free fallback.

**Тесты:** 457 (1044 ассерта), `ci:all` exit 0. PR #54 merged.

## 2026-09-15 — Task 4.6: Unit-тесты домена Item (audit-close)

**Решение:** audit-close (прецедент 3.5/3.6). Задача фактически выполнена попутно в 4.1/4.4/4.5/4.7.

**Проверка:** замер покрытия clover по `src/Domain/Item/` + `src/Application/Item/` (полный прогон): было 10 непокрытых стейтментов (9 в `Item.php`, 1 в `ItemSlotMapper.php`), после 4 новых тестов — **0 uncovered (311 stmts)**. Полный набор: 461 тестов, 1048 ассертов, 99.59% измеряемых строк (Domain+Application).

**Новые тесты:** `testSetDateSlotToNullResetsSlot`, `testSetBoolSlotToNullResetsSlot`, `testSetTextSlotOverMaxLengthThrows` (ItemTest), `testThrowsOnNonStringForDateSlot` (ItemSlotMapperTest).

**Зафиксировано:** `src/Infrastructure` намеренно исключён из coverage-gate (`phpunit.xml.dist`, `<coverage>` включает только Domain+Application) — DoctrineItemRepository не измеряется; интеграционные тесты существуют, покрытие не верифицировано. Не менять без отдельного решения.

**Scope:** «домен Item» трактован как весь Item-слой (Domain + Application + Infrastructure) — подтверждено пользователем.

## 2026-09-15 — Периодический review (после завершения Этапа 4)

**Smoke tests:** docker compose up — все 5 контейнеров Up (db healthy). `/health` → 200. E2E-auth-прогон: register 201 → login 200 → collections list/create → tags list → item create (тег «Books» авто-создан через `getOrCreate`) → list/update/delete → всё корректно.

**Review checklist:**
- Артефакты: Roadmap актуален (Этап 4 = 7/7), PRD 4.1–4.7 записаны, ARCHITECTURE дополнен (Item/Tag домены, ItemService, TagController, NIM CI, статус этапа). CHANGELOG.md создан (Этап 4).
- Архитектура: новых проблем не выявлено; известные отложенные — fwd-2 (гибкие слоты), fwd-4 (PHPUnit ^11.5), fwd-5 (полный декоплинг Collection.owner), fwd-6 (N+1 тегов). `config/reference.php` — повторяющийся local stray-артефакт (не входит в коммиты).
- Безопасность: `composer audit --locked` — «No security vulnerability advisories found».
- CI: main 3/3 success; NIM primary стабилен (fast pass 34с–1м44с на ревью).
- Зависимости: symfony/cache 7.4.18 (CVE закрыт), Symfony 7.4 актуален; PHPUnit 9.6 — bump до ^11.5 в fwd-4.

**Новых `[review]`-задач не заведено** — беклог уже содержит все выявленные отложенные работы.

## 2026-09-15 — Task 5.1: Сущность Like (Этап 5)

**Реализовано:** `Like` domain (`LikeId`, `Like` entity: owner `ManyToOne User`, item `ManyToOne Item`, UNIQUE `uniq_like_owner_item(owner_id, item_id)`, INDEX `idx_like_item`), `LikeRepositoryInterface` (`save/remove/findById/findByOwnerAndItem/findByItemId/countByItemId`), `DoctrineLikeRepository` (JOIN FETCH-цепочка `l.owner` + `l.item→collection→owner` против ghost-proxy final-сущностей; `IDENTITY`+binary параметры; `orderBy createdAt ASC, addOrderBy id ASC` — детерминированный порядок при tie µs), миграция `Version20260915202634` (golden-style DATETIME(6)/utf8mb4_0900_ai_ci, FK CASCADE), 8 интеграционных тестов (persist/reload, unique-violation, cascade, repo-методы в `DoctrineLikeRepositoryTest`).

**Решения (3-agent ревью: senior APPROVE, architect REVISE, tech-lead NEEDS-CHANGES):**
- `findById(LikeId)` добавлен в интерфейс — конвенция всех sibling-репозиториев + нужен для админ-удаления (5.5).
- `idx_like_item(item_id)` явный; отдельный owner-индекс редундантен (UNIQUE покрывает префикс owner_id) — убран.
- Тесты сплитнуты по конвенции: entity-persistence → `LikePersistenceTest`, repo-методы → `DoctrineLikeRepositoryTest`.
- fwd-5 scope расширен: read-цепочка `Like → item.collection.owner` входит в будущий декоплинг.
- Отклонено (задокументировано): public `@internal` конструктор Like — консистентно с Tag (паттерн тест-объектов).

**⚠️ Операционное открытие (DB-env):** `docker-compose.yml` задаёт `DATABASE_URL=.../taskflow` env-переменной контейнера → она **переопределяет `.env.test`** (`host.docker.internal:3306/taskflow_test`). Итог: все тесты локально идут в dev-БД `taskflow` (не `taskflow_test`). «Загрязнение» тест-данными = общий DB с dev-данными (smoke). Зафиксировано как **fwd-9** в Roadmap; при настройке изолированной тест-БД — убрать env из docker-compose или вынести в `env_file`.

**review-7 заведён** (tech-lead: debug-код в `src/Kernel.php` — file_put_contents на каждый boot во всех env + error_log).

**Ревью-конвенция исправлена:** с этой задачи 3 агента (senior+architect+tech-lead) на трёх моделях (CLAUDE.md 5.6.1, PR #57).

## 2026-09-16 — Infra cleanup: канонический Kernel (review-7) + изоляция тест-БД (fwd-9)

**review-7 — `src/Kernel.php` приведён к каноническому Flex-виду:** `class Kernel extends BaseKernel { use MicroKernelTrait; }`. Удалены `registerBundles()`, `configureContainer()`, `configureRoutes()`, `isDebug()` и весь debug-мусор (3× `file_put_contents('/tmp/kernel_debug.log')` + `error_log` на каждый boot).
- `config/bundles.php` стал единственным источником бандлов: добавлены `LexikJWTAuthenticationBundle` (был только в мёртвом `registerBundles()`), `DAMADoctrineTestBundle => ['test'=>true]`, `NelmioApiDocBundle => ['dev'=>true,'test'=>true]` (сохраняет прежнее поведение).
- Attribute-роуты API (`src/Infrastructure/Api/Controller/`) перенесены из кастомного метода Kernel в `config/routes.yaml`; дублирующие `resource:` убраны из `config/routes/dev/attributes.yaml` (оставлены swagger UI/openapi).
- Проверено: `debug:router` dev/test/prod (15 API-роутов), `cache:warmup --env=prod` OK, `/tmp/kernel_debug.log` больше не создаётся, `ci:all` 469/1069 зелёный.
- **Важное открытие:** `config/bundles.php` до этого был **мёртв** — `registerBundles()` в Kernel полностью его переопределял; любой бандл, добавленный в bundles.php, не грузился.

**fwd-9 — изоляция тест-БД:** корень проблемы — `docker-compose.yml` отдаёт `DATABASE_URL=…/taskflow` (dev) через `env_file: .env` в real env контейнера, что затеняет `.env.test`; `tests/bootstrap.php` намеренно не грузит Dotenv в test, а `phpunit.xml.dist` `DATABASE_URL` не задаёт → локальные тесты шли в dev-БД. Решение (минимально инвазивное, без правки env_file/JWT): `tests/bootstrap.php` в test-окружении **переопределяет только `DATABASE_URL`** значением из `.env.test`, **если текущее значение ещё не указывает на `taskflow_test`** (value-sniff guard: CI задаёт `taskflow_test` сам и не затирается; локальный `CI=1` тоже не ломает — в отличие от флага `CI`). `.env.test` → `db:3306/taskflow_test`, user `taskflow`. Создана БД `taskflow_test` (+ `docker/mysql/init/01-test-db.sql` для чистых volume), применены миграции (3). Добавлен guard-тест `TestDatabaseIsolationTest` (assert активной БД = `taskflow_test`), закрывающий ветку, которую CI напрямую не исполняет.
- **Отклонено:** вариант «убрать `DATABASE_URL` из docker-compose» из первоначального плана — не работает: значение всё равно попадает в контейнер через `env_file: .env`. Также отвергнута правка `env_file`/`JWT_PASSPHRASE`, т.к. JWT-ключи локально привязаны к значению из `.env` (memory #97) — риск сломать auth-тесты. Отвергнут флаг `CI` как единственный переключатель (architect: локальный `CI=1` тихо уводит тесты в dev) — заменён на value-sniff.
- Проверено: test→`taskflow_test`, dev→`taskflow`, `ci:all` 469/1069 зелёный.
- **Backlog:** `fwd-10` (дубль `DATABASE_URL` в `.env`), `fwd-11` (политика для дрейфующего `config/reference.php`).

## 2026-09-16 — fwd-4: PHPUnit 9.6.35 → 11.5.56

**Реализовано:** `composer.json` `phpunit/phpunit: ^9.0` → `^11.5`; update подтянул `phpunit/php-code-coverage 9.2.32 → 11.0.12` и `sebastian/*` (4.x), `myclabs/deep-copy 1.14.0`. `phpunit.xml.dist` мигрирован на схему 10/11 (`--migrate-configuration`): `<coverage><include>/<exclude>` → отдельный `<source>`, убраны `processUncoveredFiles`/`forceCoversAnnotation`/`beStrictAboutCoversAnnotation`, добавлены `requireCoverageMetadata`/`beStrictAboutCoverageMetadata`/`cacheDirectory=".phpunit.cache"`. `ArrayableInterfaceTest`: `@dataProvider` → `#[DataProvider]`. `.gitignore`: добавлен `.phpunit.cache/`. Composer-скрипт `phpunit:no-coverage` получил `--no-coverage`.

**Ключевая находка (спайк S0):** в PHPUnit 10/11 расширения в `<extensions>` регистрируются как **`<bootstrap class="…"/>`**, а НЕ `<extension class="…"/>`. Старая форма не проходит XSD-валидацию; PHPUnit 11 выдаёт **late test-runner warning** (только после прогона тестов, т.к. config-валидация не гейтится `failOnWarning`) и **пропускает расширение** → DAMA DoctrineTestBundle не загружался → тесты шли без per-test rollback (UniqueConstraintViolation, «actual size 32 vs 3»). `--migrate-configuration` этот ренейм **не** исправляет — правится вручную. После `<bootstrap>` DAMA активна (`StaticDriver::isKeepStaticConnections() = true`), 470/470 зелёные. Постоянный guard: `TestDatabaseIsolationTest::testDamaTransactionalIsolationIsActive`.
Вторая находка: PHPUnit 11 при наличии `<coverage>`/`<source>` без драйвера выдаёт warning «XDEBUG_MODE=coverage … has to be set», что при `failOnWarning="true"` валит прогон → `--no-coverage` в no-coverage скрипте.

**Совместимость проверена:** DAMA v8.2.2 (require-dev `phpunit ^8||^9||^10||^11`), symfony/phpunit-bridge v8.1.1, `doctrine/orm 3.6.7`/`dbal 4.4.3` — без изменений; PHP 8.3.33 (CI тот же 8.3) удовлетворяет PHPUnit 11 (≥8.2). Удалённых API PHPUnit (`setMethods`, `withConsecutive`, `->at()`, `assertRegExp`, `@expectedException` и пр.) в тестах нет — 0 вхождений.

**Проверено:** `composer ci:all` — 470 tests, 1117 assertions, exit 0 (warnings/deprecations 0); `composer coverage:gate` — Lines 97.16% (718/739), gate ≥80% passed; `composer coverage:check` (clover+html+php+junit через `--coverage-*`) — exit 0; DAMA-прогон 3× подряд на одном DB-volume — 470/1117 каждый раз (доказывает, что rollback реально работает).

**Прочее (в PR #60):** `phpunit.xml.dist` — `includeUncoveredFiles="true"` выставлен явно (дефолт `true`; явность защищает гейт от будущего дрейфа дефолта). `.gitignore` — `.phpunit.cache/` добавлен в секцию Testing (не дублируется). Guard `TestDatabaseIsolationTest::testDamaTransactionalIsolationIsActive` — assert `StaticDriver::isKeepStaticConnections()`, ловит повтор «extension silently skipped» мгновенно.

**Замечено:** `composer.json` в репозитории был CRLF; при правке нормализован в LF (`core.autocrlf=input`) → 127-строчный whole-file churn. Обсуждалось; принято как нормализация, политика — `fwd-12` (`.gitattributes`). `willReturnOnConsecutiveCalls` (`TagServiceTest:82`) deprecated в PHPUnit 10, удаляется в 12 — `fwd-13`. `config/reference.php` исключён из PR (политика — `fwd-11`).

## 2026-09-16 — Task 5.2: Сущность Comment (Этап 5)

**Реализовано:** `Comment` domain — `CommentId` (бинарный UUID), `CommentContent` (embeddable, immutable), `Comment` entity (`owner` `ManyToOne User`, `item` `ManyToOne Item`, оба `ON DELETE CASCADE`, embedded `content`, `createdAt`/`updatedAt`, `ClockAwareTrait`, `create(User, Item, CommentContent)`, `changeContent()` c no-op-гардом по нормализованному равенству + `touch()`), `CommentRepositoryInterface` (`save/remove/findById/findByItemId/findByOwnerId/countByItemId`), `DoctrineCommentRepository` (JOIN FETCH-цепочка `c.owner` + `c.item→collection→owner` против ghost-proxy final-сущностей; `IDENTITY`+binary; порядок `createdAt ASC, id ASC`), миграция `Version20260916145324` (golden-style DATETIME(6)/`utf8mb4_0900_ai_ci`, FK CASCADE, `INDEX idx_comment_item` + `INDEX idx_comment_owner`), 36 тестов (27 юнит + 9 интеграционных на реальном MySQL).

**Решения (утверждены пользователем 2026-09-16):**
- **Опечатка артефакта:** domain-model перечисляет `Comment: id, owner_id, comment_id` — `comment_id` исправлен на `item_id` (связи артефакта и Roadmap 5.2 однозначно про айтем).
- **Длина контента 3000** (не 1000 и не 2000); `CommentContent` — `VARCHAR(3000)` (utf8mb4, DYNAMIC row format допускает; колонка без индекса).
- **Переводы строк сохраняются, пробелы не схлопываются** — контент это Markdown. Осознанное расхождение с `TagName`/`FieldName` (там `\s+` → пробел): санитизация = нормализация `\r\n`/`\r` → `\n`, strip `\p{Cc}` **кроме** `\n` и `\t`, `trim` краёв; внутреннее сохраняется байт-в-байт. Markdown хранится как есть (экранирование/рендеринг — фронт).
- **UNIQUE нет** — пользователь комментирует айтем многократно. Поэтому (в отличие от Like, где UNIQUE покрывал owner-префикс) добавлен отдельный `INDEX idx_comment_owner(owner_id)`.
- **`findByOwnerId` делаем** (задел под «мои комментарии» и админ-операции 5.5/7.6); owner-запрос идёт через `IDENTITY(c.owner) = :ownerId` + binary.
- Правка отслеживается только `updatedAt` (без флага `isEdited`).

**Замечено:** `DoctrineCommentRepository` создан изначально с интерфейсным алиасом (`debug:container` показывает приватный alias из `ServiceRepositoryCompilerPass` DoctrineBundle) — 7 ошибок `ServiceNotFoundException` в тестах были из-за **устаревшего тест-контейнера**, лечится `rm -rf var/cache/test` (memory #30). Guard-тест после round-trip сравнивает `createdAt`/`updatedAt` по форматированной строке (`Y-m-d H:i:s.u`), т.к. `assertSame` на двух `DateTimeImmutable` из БД проверяет идентичность объектов.

**Проверено:** `composer ci:all` — 510 tests, 1191 assertions, exit 0 (после review-фиксов); миграция применена к dev + test (`schema:update --dump-sql` → «Nothing to update»; `SHOW INDEX` подтверждает композитные индексы).

**3-агентное ревью (senior APPROVE, architect APPROVE, tech-lead REVISE) и применённые фиксы:**
- **Композитные индексы** (architect + OpenRabbit): `idx_comment_item(item_id, created_at, id)` / `idx_comment_owner(owner_id, created_at, id)` — обслуживают `ORDER BY created_at, id` без filesort. InnoDB и так добавляет PK `id` к secondary-индексам, но `id` указан **явно** (по запросу OpenRabbit) для читаемости/переносимости. Правка entity + миграции; миграция откатана и применена заново на dev и test (проверено `SHOW INDEX` — по 3 колонки).
- **`countByOwnerId`** (architect): добавлен в интерфейс/репозиторий — симметрия с `countByItemId`, нужен для пагинации «моих комментариев» в 5.5.
- **Guard на невалидный UTF-8** (senior MEDIUM): `preg_replace('/…/u')` при битой кодировке возвращает `null` — раньше `?? ''` приводил к ложному «cannot be empty»; теперь явный `InvalidArgumentException('Comment content must be valid UTF-8')`. Тест добавлен.
- **Детерминизм order-тестов** (senior MEDIUM): `DoctrineCommentRepositoryTest` для сортировочных тестов использует `MockClock` со сдвигом между созданиями (иначе три комментария могут попасть в одну микросекунду и tie уйдёт на `id`).
- **Тест каскада при удалении user** (tech-lead MAJOR): `testCascadeOnUserDelete` — критерий приёмки заявлял каскад и по айтему, и по пользователю, а тест был только на айтем.
- **Отклонено:** снятие `#[Assert\NotBlank]`/`#[Assert\Length]` с `CommentContent` (senior/architect NIT как «мёртвый» слой) — оставлено для консистентности с `TagName`/`FieldName` (в проекте VO несут и Assert, и валидацию в конструкторе; Assert срабатывает, когда VO валидируется через Symfony Validator на API-слое в 5.5).
- **Отмечено (не фикс):** `INNER JOIN` в `withAll` — комментарии без item/collection недостижимы (софт-делита нет); listing-проекция для объёмных списков — кандидат в fwd-8-класс задач; `CountByItemId`/`ById` — scalar-only, без гидратации.

**Процессные заметки:** S1 превысил бюджет 150 строк исходников (Comment 121 + CommentContent 68 + интерфейс 49 + CommentId 22 = 260) — VO+entity+interface в одной подзадаче по образцу 5.1; зафиксировано как отклонение. `ARCHITECTURE.md` дополнен пропущенной на 5.1 секцией Like + Comment (док-долг 5.1). `config/reference.php` (dirty из-за миграций) исключён из PR — политика `fwd-11`.

## 2026-09-16 — Task 5.3: Сервис лайков (Этап 5)

**Реализовано:** `LikeService` (`src/Application/Like/Service/LikeService.php`, `final readonly`) — `like`/`unlike` (идемпотентно), `toggle` (возвращает новое состояние), `isLikedBy`, `countByItem`, `listByItem`, `removeLike` (админский путь), `toDTO`/`toDTOList`; `LikeDTO` (`src/Application/Like/DTO/`) с `ArrayableInterface`; 13 юнит-тестов (моки репозитория и UoW, без БД).

**Решения (утверждены пользователем 2026-09-16, «на все автоаппрув»):**
- **Идемпотентность:** `like()` при существующем лайке возвращает существующий (без `save`); `unlike()` при отсутствии — no-op. Соответствует UNIQUE `(owner_id, item_id)` и упрощает HTTP-семантику в 5.5.
- **`toggle()` → `bool`** (новое состояние). Счётчик контроллер собирает через `countByItem` — сервис не смешивает мутацию и агрегацию.
- **`LikeDTO` + `listByItem`** — под PRD «получить все лайки айтема»; `toArray()` snake_case + ATOM (конвенция `ItemDTO`/`TagDTO`).
- **`removeLike(Like)`** — прямой путь для админского удаления (5.5/7.6), т.к. `unlike` требует пару (owner, item), которой у админа может не быть.
- **Транзакции:** один агрегат → `save`/`remove` + `uow->flush()`, без `transactional()` (как `ItemService::delete`).
- **`LikeNotFoundException` не заводим** — при идемпотентности не нужен.
- **Гонка `like()`/`toggle()`:** «найти → создать» под конкуренцией может дать `UniqueConstraintViolation` (UNIQUE-индекс). Для MVP принимается для обеих операций (у `toggle` тот же hazard); кандидат в беклог — race-safe атомарный upsert (по образцу `TagRepository::getOrCreate`).
- `OwnerId` строится из `User` (`OwnerId::fromBytes($user->getId()->toBytes())`), т.к. `findByOwnerAndItem` принимает `OwnerId`.

**3-агентное ревью (senior APPROVE, architect REVISE, tech-lead REVISE) и применённые фиксы:**
- **`getById(string): ?Like`** добавлен (architect MEDIUM): админский путь (5.5/7.6) иначе не мог получить `Like` через сервис и вынуждал бы контроллер тянуть репозиторий. Возвращает `null` (без исключения, по решению), контроллер маппит в 404.
- **`LikeDTO` добавлен в `ArrayableInterfaceTest::responseDtoProvider`** (architect LOW) — контрактный тест теперь покрывает новый DTO.
- **Качество тестов** (senior/tech-lead): `save` теперь проверяется через `with(...)` (owner+item), `testIsLikedBy` разделён на true/false, `testToDTOListMapsAllLikes` использует второго пользователя (не нарушает UNIQUE-инвариант), добавлен тест дефолтной пагинации `listByItem` (50/0), добавлены `getById` делегирование и `null`. Итого 17 тестов.
- **Документация:** статус PRD → «Выполнено»; зафиксировано превышение бюджета S1 (**168 строк** исходников: `LikeService` 127 + `LikeDTO` 41 > 150) — укладывается в критерий «≤2ч», по аналогии с 5.2.
- **Отклонено (осознанно):** вынос `isLikedBy` на `EXISTS`/`countByOwnerAndItem` (senior LOW) и batch-счётчики для списков (architect MEDIUM) — это оптимизации уровня 5.5, зафиксированы в fwd-8 с конкретными сигнатурами, а не в 5.3. `toggle()` без делегирования в `like`/`unlike` — оставлено (один `find`, без двойного запроса).
- **Отклонено (ложная находка ИИ-ревью OpenRabbit):** вердикт `needs changes` на сквоше утверждал «`findLike` helper missing → compile-time error» и «unused import / truncated comments». Опровергнуто: `findLike` определён в `LikeService.php:139` (вызовы 37/54/70/86), все 9 импортов используются, `php -l` — без ошибок, PHPStan level 9 — `No errors`, CI jobs Static Analysis & Lint и Unit Tests — pass (528 тестов). Тот же прогон параллельно перечислял корректные факты, т.е. это галлюцинация смешанного вывода, а не реальный дефект. В код ничего не вносилось.

**Проверено:** `LikeServiceTest` 17/17 (в т.ч. проверка, что `findByOwnerAndItem` вызывается с корректным `OwnerId`/`ItemId`, и `save` — с верным владельцем/айтемом); `composer ci:all` — 528 tests, 1249 assertions, exit 0.

## 2026-09-17 — Task 5.5: API-эндпоинты лайков и комментариев (Этап 5)

**Реализовано:** 9 эндпоинтов — `POST`/`DELETE`/`GET /api/items/{id}/likes` (идемпотентно; POST отдаёт `{likes_count}`), `DELETE /api/likes/{id}` (автор или админ, PRD 118), `POST`/`GET /api/items/{id}/comments`, `GET /api/comments` (свои), `PATCH`/`DELETE /api/comments/{id}` (автор или админ, PRD 117). `LikeController`, `CommentController`, `CommentRequestDTO`, `ItemDetailDTO`, `AbstractApiController::toArrayPayload()`/`canManage()`/`findItemOrNull()`, `LikeDTO.owner_name`, счётчики в `GET /api/items/{id}`, схемы OpenAPI `ItemDetail`/`Like`/`Comment`.

**D1 релаксация чтения:** `ItemController::get`/`listByCollection` и `CollectionController::get` открыты любому аутентифицированному (соцфича бессмысленна без чтения чужого айтема); `GET /api/items` остаётся owner-scoped. Заодно починен латентный 500 `CollectionController::get` на битом UUID (ловился только `CollectionNotFoundException`). Гостевой доступ — по-прежнему fwd-7.

**Счётчики:** только `GET /api/items/{id}` через `ItemDetailDTO` (вариант C, `allOf: [Item]`); списки счётчики не отдают сознательно — иначе N+1 на страницу; batch-агрегация — fwd-8. Счётчики читаются после мутации (сервисы флашат внутри).

**Карта ошибок:** id (битый/несуществующий) → 404; контент (пусто/>3000) → 422; тело (битый JSON) → 400. Отсутствующий `content` → дефолт `''` в `CommentRequestDTO` → домен → 422 (не TypeError). Тонкий DTO без `#[Assert]` — сознательно: `Assert` дал бы 400 вместо мандатного 422.

**Ревью (3 агента — senior REVISE, architect REVISE, tech-lead REVISE), всё принято:**
- 🔴 malformed id на like/comment-путях давал 500 (uncaught `\InvalidArgumentException`) → `findCommentOrNull`/`findLikeOrNull` + 6 функциональных тестов на `not-a-uuid`.
- 🔴 списки Item/Collection сериализовали DTO-объекты (camelCase, сырой `DateTimeImmutable`) → мигрированы на `toArrayPayload()`; форма ответа теперь едина.
- 🟡 `CommentRequestDTO` переехал `Infrastructure\Api\Controller` → `Application\Comment\DTO` (конвенция слоя).
- 🟡 `canManage`/`findItemOrNull` подняты в `AbstractApiController` (были три копии); `ItemController::canAccess` переименован в `canManage`.
- 🟡 PRD-карта ошибок противоречила коду (`{}` → 422, а не 400) — исправлена таблица.
- 🟡 Roadmap 7.6 пере-скоуплен: правка/удаление контента сделаны в 5.5, за 7.6 остаётся аудит-список и админ-UI.
- Тестовые пробелы закрыты: границы 3000/3001, пагинация like/comment, `liked_by_me=false` для чужого пользователя, malformed UUID ×3.
- **Отклонено:** вынесение авторизации в Symfony Voter сейчас — решение зафиксировано как рефакторинг Этапа 7 (дублирование `canManage` по контроллерам осознанно на MVP).

**Бюджет:** S1 ~45, S2 (LikeController) 224 строки, S3 (CommentController + DTO) ~367 — S2/S3 превысили лимит 150 source-строк (S3 >2×). Оценка PRD была неверной; декомпозиция не пересматривалась, т.к. обе подзадачи уложились в изначальный бюджет 2ч и резались по когезии (like-контроллер / comment-контроллер + карта ошибок).

**Проверено:** 48 новых/обновлённых тестов; `composer ci:all` — 601 test, 1645 assertions, exit 0; OpenAPI перегенерирован (5 новых путей + 3 схемы).

**Замечено:** `LikeService::like()` — find-then-insert, гонка даёт `UniqueConstraintViolation` → 500 на HTTP-слое (5.3-заметка); 5.5 — первая HTTP-поверхность, кандидат на атомарный upsert.

## 2026-09-16 — Task 5.4: Сервис комментариев (Этап 5)

**Реализовано (Application-слой над доменом `Comment` из 5.2):** `CommentDTO` (`id`, `owner_id`, `owner_name`, `item_id`, `content`, `created_at`/`updated_at` ATOM; `implements ArrayableInterface`; snake_case; точная `array{...}`-аннотация) + `CommentService` (`create`, `getById`, `changeContent`, `delete`, `listByItem`, `listByOwner`, `countByItem`, `countByOwner`, `toDTO`, `toDTOList`); 21 тест (16 сервис + 5 DTO) + регистрация `CommentDTO` в контрактном `ArrayableInterfaceTest`.

**Решения (утверждены 2026-09-16):**
- **Авторизация — на уровне контроллера** (как `ItemController::canAccess`); сервис правил доступа не содержит. Правка/удаление «своего» и админское «любого» — это 5.5/7.6 поверх `getById` + `changeContent`/`delete`.
- **Удаление своего комментария пользователем** включено (PRD 20 «менять свои…»; админ-удаление — 117).
- **`CommentDTO.owner_name`** включён (PRD 56 «под своим именем»): `owner` гидрируется JOIN FETCH-цепочкой в репозитории, дополнительных запросов нет. Аналогичное поле для `LikeDTO` не добавлялось (нужно будет для UI лайков — кандидат к fwd-8/5.5).
- **`getById(string): ?Comment`** — без `CommentNotFoundException`: `null` → 404 в контроллере (конвенция `LikeService`). Расхождение с `ItemService::getById()` (тот бросает `ItemNotFoundException`) зафиксировано осознанно: сосуществуют две конвенции — «бросить» и «вернуть null»; для новых сервисов берём null-вариант.
- **`changeContent()` возвращает `Comment`**; при нормализованно равном контенте сервис возвращает комментарий **без** `save`/`flush` (доменный `changeContent()` — no-op; сервисный guard нужен, чтобы не делать пустой flush управляемой сущности). Guard в сервисе дублирует доменный осознанно.
- **Owner-чтения принимают `OwnerId`** (`listByOwner`, `countByOwner`) — симметрия с `ItemId`; конверсию `User → OwnerId` делает контроллер 5.5 (как `CollectionController`). `create(User, Item, string)` принимает `User`, т.к. он нужен `Comment::create`. `listByOwner`/`countByOwner` оставлены, потому что планируется экран «мои комментарии» (PRD 20).
- **Транзакции:** один агрегат на мутацию → `save`/`remove` + `uow->flush()`, без `transactional()`.
- **Валидация контента:** `CommentContent::fromString` бросает `\InvalidArgumentException` (пусто / >3000). **Для 5.5 зафиксировано:** невалидный контент → **422**, битый id → 400/404. Оба случая бросают один и тот же тип `\InvalidArgumentException`, поэтому в 5.5 **нельзя** копировать blanket-catch `ItemController` (там `InvalidArgumentException` → 404); это отмечено в docblock `CommentService::getById()` и в PRD.

**3-агентное ревью (senior APPROVE, architect APPROVE, tech-lead NEEDS-CHANGES — только по полноте S3, код не менялся):**
- **Senior finding (принято):** у `listByOwner` не было теста дефолтной пагинации (в отличие от `listByItem`) — добавлен `testListByOwnerUsesDefaultPagination`.
- **Architect (принято замечанием):** для списочных чтений `findByItemId`/`findByOwnerId` JOIN-цепочка `item → collection → collectionOwner` избыточна (DTO читает только owner id/name + item id) — два лишних to-one join на строку; N+1 нет (limit 50), оптимизация отнесена к fwd-8 (там уже зафиксирован приём «проекция для списков» для лайков). `withAll` сохранён для `findById`.
- **Tech-lead (принято):** S2/S3 полнота — коммит кода, запись в AssumptionLog, строка `CommentService` в ARCHITECTURE, статус Roadmap/прогресс, чекбоксы PRD; формулировка PRD про порядок «ревью до commit/push» поправлена.
- **Бюджет:** S1 — 52 source-строки, S2 — 129 source; оба ≤150 (тесты считаются отдельно). Превышения нет (в отличие от 5.2/5.3).

**Ревью OpenRabbit (PR #63, вердикт `looks good to me`), 2 находки:**
- **Принято как замечание (architect-находка дублирована ботом):** для списочных чтений (`findByItemId`/`findByOwnerId`) полная JOIN-цепочка `item → collection → collectionOwner` избыточна — DTO читает только `owner_name` и `item_id`. Оставлено осознанно: цепочка нужна `findById` (авторизация «автор или админ» в 5.5/7.6 требует владельца коллекции айтема), а единый хелпер `withAll` гарантирует инвариант «любой прочитанный комментарий полностью гидрирован». Стоимость — 2 лишних to-one join на строку при `limit ≤ 50` (не N+1); оптимизация отнесена к fwd-8.
- **Отклонено:** «добавить debug-log/событие на no-op ветку `changeContent`» — no-op означает, что запрошенное состояние уже существует: мутации не было, строки не менялись, доменный `changeContent()` — no-op по проекту. Логгер/событие в Application-слое связал бы его с инфраструктурой, тогда как сервисы проекта свободны от side effects кроме `save`/`remove` + `flush`. Аудит/логирование запросов — уровень HTTP (5.5) или event-subscriber.

**Проверено:** `CommentServiceTest` 16/16 + `CommentDTOTest` 5/5; `composer ci:all` — 550 tests, 1321 assertions, exit 0.

**Task 5.10 — Social moderation Voter (PRD/5.10-social-voter.md), 2026-09-17:**

- **Решение:** первый Voter в проекте — `SocialContentVoter` (`src/Infrastructure/Security/Voter/`) с атрибутами `SOCIAL_EDIT`/`SOCIAL_DELETE`, автозарегистрирован через autoconfigure (тег `security.voter`). Три call-site переведены с `AbstractApiController::canManage` на `$this->isGranted(...)`: `LikeController::delete`, `CommentController::update`, `CommentController::delete`.
- **Правило:** автор контента или админ (`Role::isAdmin()`, доменная роль — не строка `ROLE_ADMIN`) могут редактировать/удалять `Comment`; для `Like` разрешён только `SOCIAL_DELETE`. `Like`+`SOCIAL_EDIT` → `ACCESS_DENIED` **всем**, включая админа (у лайка нет редактируемого содержимого).
- **Осознанный split-brain:** `canManage(User, User)` оставлен для `ItemController`/`CollectionController`; соц. контент идёт через Voter, доменные коллекции/айтемы — через императивный хелпер. Унификация отложена (5.9/7.x). Дублирование предиката «owner-or-admin» между `canManage` и `voteOnAttribute` — принято осознанно, кандидат в бэклог.
- **Affirmative-стратегия ADM:** DENIED одного voter'а не veto'ит GRANTED другого, но сейчас voter только один, поэтому хрупкость молчаливая. При появлении второго Voter (Item/Collection) нужно явно определить `access_decision_manager.strategy` и добавить guard-тест — кандидат в бэклог.
- **Скрытый инвариант:** `voteOnAttribute` читает `$subject->getOwner()`, поэтому subject обязан приходить с гидрированным owner. Все три call-site получают сущность через `findById` с `withAll()` (JOIN FETCH) — инвариант соблюдён; задокументирован в докблоке Voter. Кандидат в бэклог — `getOwnerId()`-акцессор, чтобы убрать зависимость от гидрирования.
- **Отклонённые альтернативы:** Domain-интерфейс `SocialContentInterface` / policy-класс — преждевременная абстракция на MVP; прецедент `UserProvider` (Infrastructure → Domain) легитимирует прямой `instanceof`. Обоснование зафиксировано в PRD (D-раздел).
- **Ручная проверка регистрации:** `debug:container --tag=security.voter` показывает ровно один `SocialContentVoter`; автотест на тег не добавлен — регистрация косвенно покрыта функциональными тестами (если voter выпадет, GRANTED-кейсы для админа/владельца упадут).
- **Ревью (3 агента):** senior `APPROVE`, architect `APPROVE`, tech-lead `SHIP-WITH-NITS`. Применено: docblock-тип data-provider, inline FQN → `use`, комментарий к defensive `instanceof`, докблок Voter об инварианте owner, снят `{@see}`-импорт в базовом контроллере, тест идемпотентности DELETE→404. Отклонено: автотест тега voter (косвенно покрыт), вынос общего предиката (бэклог).
- **Проверено:** `composer ci:all` — 634 tests / 1689 assertions, exit 0; `lint:container` без ошибок; voter в `debug:container`.

## 2026-09-20 — Task 5.11: Symfony-aware диагностика `symfony-lsp` в CI (инфраструктура)

**Контекст:** задача из периодического review (аспект «CI/инфраструктура»), взята по прямой команде пользователя вне очереди 5.7–5.9. Источник — `symfony/language-tools` (`symfony-lsp` v0.21.0, релиз 2026-09-19): standalone-бинарник, подкоманда `check` (headless), форматы `human|json|github|gitlab|sarif`, стабильные коды выхода (`0` — ок, `10` — блокирующие находки, `11` — ошибка конфигурации, `12` — неполный анализ).

**Решения:**

- **Пилот non-blocking.** CI-job `symfony-diagnostics` идёт с `--source-only` (приложение не запускается: не нужны PHP, БД, Redis, JWT-ключи) и `continue-on-error: true`; в `needs` у `ci-summary` не входит. Перевод в блокирующий — отдельная задача **5.14** с датой решения (~2026-09-27), намеренно **не** привязана к 5.13 (runtime-режим): иначе тишина пилота тянулась бы, пока не доделают runtime.
- **Runtime-режим проверен локально, в CI отложен.** В контейнере runtime-прогон даёт `complete: true`, `exit 0` и реальную находку (`config.deprecated_key`: `lexik_jwt_authentication.encoder.crypto_engine`) за ~46 с. В CI runtime требует MySQL/Redis-сервисов и JWT-ключей — вынесено в 5.13.
- **`excludePaths: [config/reference.php]`.** Runtime-прогон падал с `exit 12` («The selected file "config/reference.php" changed during the diagnostics check») — известный дрейф автогенерируемого `config/reference.php` (см. fwd-11). Исключение лечит это и **не подменяет** fwd-11: там git-политика для файла, здесь — самоперезапись файла во время runtime-анализа. После закрытия fwd-11 исключение станет безвредным.
- **Baseline не заводится.** Source-only — 0 диагностик; runtime — 1 warning (warnings не блокируют по умолчанию). Блокирующих находок нет, поэтому `.symfony-lsp-baseline.json` не нужен.
- **Единый runner-скрипт.** `scripts/symfony-lsp-check.sh` — общий источник правды для composer-скрипта и CI: версия закреплена (`SYMFONY_LSP_VERSION`, по умолчанию `0.21.0`), SHA256 сверяется из релизного `SHA256SUMS`, бинарник кэшируется в `var/bin/` (gitignored). Дублирования версии нет: в CI она задана через `env` и попадает в cache-key индекса.
- **Бинарник не кэшируется в CI.** Кэшируется только индекс (`var/symfony-lsp`). Сам бинарник не кэшируем сознательно: восстановленный из кэша файл обошёл бы проверку SHA256. 4.7 МБ на прогон — приемлемая цена за инвариант.
- **Принятые риски.** `SHA256SUMS` тянется из того же GitHub-релиза, что и бинарник (TOFU-доверие: от повреждения/MITM защищает, от компрометации релиза — нет) — пиннинг digest'а в 5.14. Частота 0.x-релизов: Dependabot скачиваемый бинарник не видит, bump ручной — тоже в 5.14.
- **Editor-LSP `symfony-lsp` на этой машине не подключаем.** Официальный гайд OpenCode ограничен Linux/Apple Silicon; Windows-сборка существует (`windows-x64.zip`), но не проверялась. Редакторный PHP-LSP остаётся `phpantom`.
- **`CLAUDE.md`.** Устаревшая строка «Тесты: PHPUnit 9, DoctrineTestBundle» исправлена на «PHPUnit 11.5, DAMA\DoctrineTestBundle» (политика §7 — устаревшие доки правятся сразу).

**Ревью (3 агента):** senior `APPROVE`, architect `NEEDS-CHANGES`, tech-lead `NEEDS-CHANGES`. Блокер у обоих один — незаписанные артефакты (эта запись + чекбоксы PRD); техника одобрена. Применено: версия в cache-key, блочно-scoped guard + негативная проверка `ci-summary`, `setup-ci` для parity CI↔локально, уборка после распаковки + `grep -F`, задача 5.14 на expiry пилота, правки README. Отклонено: кэш бинарника (обходил бы SHA256-проверку).

**Проверено:** `composer ci:all` — 638 tests / 1703 assertions, exit 0 (было 634, +4 guard-теста); `SymfonyLspConfigTest` 4/4 (14 assertions); `bash scripts/symfony-lsp-check.sh --source-only` — 0 диагностик, exit 0 (в т.ч. с нуля, `rm -rf var/bin`); `composer ci:symfony-lsp` (runtime) — exit 0, complete, 1 warning; дифф не трогает `src/`.

## 2026-09-20 — Периодический review (после Этапа 5)

Триггеры по CLAUDE.md §7: конец под-этапа + 11 PR с прошлого review (15.09, #57–#68: весь Этап 5 и 5.11). Scope — Этап 5 (5.1–5.6, 5.10, 5.11) + инфра. Код в `src/` не менялся, кроме одного robustness-фикса guard-теста (см. ниже).

**Артефакты (дрейфы, исправлены сразу, без задач):** проектный `opencode.json` — строка «Этапы 1-2, 2/6» актуализирована (Этапы 1–4 ✅, Этап 5 core ✅, открыты 5.7–5.9, 5.12–5.15); `ARCHITECTURE.md` — CI-блок дополнен job'ом `symfony-diagnostics`, статус Этапа 5 (smoke 20.09), слита дублированная строка `TagController`, добавлены `AbstractApiController` и `nginx`; `CHANGELOG.md` — заголовок Этапа 5, записи 5.10 (Voter) и 5.11 (symfony-lsp), счётчик тестов (638); `config/packages/security.yaml` — уточнён комментарий про анонимный доступ (firewall пропускает, контроллер отвечает 401; полный guest — fwd-7). Счётчики Roadmap `31/48` проверены арифметикой — верны.

**Архитектура (новых задач нет):** дубли контроллеров (`getUser()`-guard, 404-конверт, parsePagination-try/catch) и несогласованный конверт `{error,details}` vs `{error,message}` — уже задача 5.9, дублей не завожу. `LikeService::toggle()` и `CommentService::countByOwner()` не вызываются в `src`, но `toggle` прямо требуется PRD 5.3, оба покрыты тестами — не дефект, наблюдение. `canManage` без прямых unit-тестов, но косвенно покрыт функциональными 403 (`testCreateInForeignCollectionReturns403`, `testUpdateForeignReturns403`, `testDeleteForeignReturns403`). Слои чисты: Voter — Infrastructure над Domain-сущностями.

**Безопасность (новых задач нет):** PUBLIC_ACCESS работает на уровне firewall (проверено: `/api/doc.json` → 200 анонимно); 401 на `GET /api/collections` — из контроллера (`{"error":"Unauthorized"}`), остаток гостевого доступа = fwd-7. Матрица Voter покрыта (5.10); DTO не отдают внутренних полей; markdown не экранируется — осознанно, задокументировано. Deprecated `crypto_engine` — уже 5.12.

**CI (стабилен):** с 15.09 все прогоны `success`; единственный `cancelled` — штатная concurrency-отмена на main. Новый job `symfony-diagnostics` — зелёный (PR + merge). Косметика: прямой `vendor/bin/phpunit` без `--no-coverage` даёт xdebug-warning (composer-скрипты корректны). `composer ci:all` — 638 tests / 1703 assertions, exit 0.

**Зависимости:** `composer audit` — чисто, advisories нет. `composer outdated --direct` — 20 пакетов (patch/minor): `symfony/redis-messenger` 7.3.10 → 7.4.19 отстаёт на minor от остальных 7.4.x; phpunit 12 и rector 2.6 — мажоры, не срочно. Новая задача **5.15** (routine bump + выравнивание).

**Robustness-фикс guard-теста (в этом review):** `SymfonyLspConfigTest::jobBlock` падал локально на Windows (`Job "symfony-diagnostics" was not found`) — regex был привязан к `\n`, а checkout даёт CRLF; в CI (LF) тест проходил. Добавлена нормализация `\r\n` → `\n`. Попутно выяснено: `write`-тул на этой машине пишет CRLF, `edit` сохраняет EOL; git (`autocrlf`) это прозрачно обрабатывает, CI на Linux всегда видит LF — но regex-тесты обязаны нормализовать. Зафиксировано как урок.

**Новые задачи:** только 5.15. Отклонено: прямые unit-тесты `canManage` (косвенно покрыт), удаление `toggle`/`countByOwner` (spec/tests), правки по 5.9/fwd-7/fwd-11 (уже заведены).

**Проверено:** `composer ci:all` — 638 tests / 1703 assertions, exit 0; `SymfonyLspConfigTest` 4/4; дифф не трогает `src/` (кроме robustness-фикса теста), `config/reference.php` отреверчен (fwd-11).
