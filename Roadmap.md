# TaskFlow - План работ

## Этап 1: Базовая инфраструктура

### Настройка проекта и CI/CD

| Задача | Описание | Статус |
|--------|----------|--------|
| 1.1 | [x] Docker Compose — приложение, веб-сервер, БД, Redis, поисковый движок | done |
| 1.2 | [x] Каркас Symfony с архитектурой Clean Architecture | done |
| 1.3 | [x] Инструменты качества: PHPStan, PHPcsFixer, Rector, PHPCPD | done |
| 1.4 | [x] PHPUnit с отчётами покрытия кода | done |
| 1.5 | [x] CI-пайплайн GitHub Actions (lint, test, static analysis, audit) | done |
| 1.6 | [x] Документация OpenAPI (Swagger/Redoc) | done |

## Этап 2: Доменная модель — Пользователь

### Сущность User и аутентификация

| Задача | Описание | Статус |
|--------|----------|--------|
| 2.1 | [x] Сущность User с полями role (user/admin) и is_active | done |
| 2.2 | [x] Repository для User с CRUD-операциями | done |
| 2.3 | [x] Сервис регистрации и API-эндпоинт | done |
| 2.4 | [x] Сервис аутентификации (login/logout) | done |
| 2.5 | [x] Unit-тесты для домена User и потока авторизации | done |

## Этап 3: Доменная модель — Коллекция

### Базовая функциональность коллекций

| Задача | Описание | Статус |
|--------|----------|--------|
| 3.1 | [x] Сущность Collection с owner, theme, image, description | done |
| 3.2 | [x] Сущность CollectionField с типами (text/number/date/bool) и slot_index | done |
| 3.3 | [x] Сервис коллекции — создание, редактирование, список коллекций пользователя | done |
| 3.4 | [x] API-эндпоинты коллекции (CRUD, список всех, список своих) | done |
| 3.5 | [x] Валидация тем (Books/Games/Movies/Drinks) | done | Closed 2026-09-10 after audit: implemented via 3.1/3.3/3.4 — Theme VO (factories/fromString/values/equals + InvalidArgumentException), ThemeEnum, CreateCollectionDTO NotBlank+Choice → 400 Validation failed, OpenAPI enum, 11 ThemeTest + controller testCreateReturns400WhenInvalid |
| 3.6 | [x] Unit-тесты для домена Collection | done | Closed 2026-09-10 after audit: CollectionTest (16), CollectionFieldTest (9), ValueObject suites (66 tests total) — entity invariants, changeTheme/changeName/touch, slotIndex range/max, whitespace normalization |

## Review Backlog

| Задача | Описание | Статус |  Why |
|--------|----------|--------|------|
| review-1 | [review] Audit Symfony Clock production binding for explicit `timezone=` arg on `NativeClock`; verify container init order against PHP `date_default_timezone_set` | done | From session 2026-07-31: deferred from Clock refactor (could surface TZ drift in rare container-bootstrap reordering). Closed 2026-09-10: binding is facade `Clock` not NativeClock; no `date_default_timezone_set`/`withTimeZone` calls in src/config/tests; container `date.timezone=UTC` stable |
| review-2 | [review] `composer audit` warning: pre-existing symfony/cache `CVE-2026-45073` (medium SQL injection). Bump `symfony/cache` to mitigated version | done | From session 2026-07-31: surfaced during `composer require symfony/clock`. Closed 2026-09-10: composer audit clean (lock v7.3.11, no advisories) |
| review-3 | [review] Decide serializer policy for ClockAwareTrait's `$clock` field on User/Collection entities. Options: `#[Serializer\Ignore]` exclusion, custom `__serialize`/`__unserialize` that null the field, or `ClockAwareTrait`-free alternate ("pure PSR-20"). Trigger when any consumer (cache adapter, queued command, session storage) needs to round-trip an entity through `serialize()` — current default would carry a frozen `MockClock` into production | done | From session 2026-07-31: surfaced via external-AI code review on PR #21 (informational severity). Closed 2026-09-10: non-issue — no entity serialize() consumer (serializer used for DTOs only), trait is Symfony's own with null `$clock` in prod, `Clock` facade is stateless so no frozen-time carry. Policy recorded in AssumptionLog |
| review-4 | [review] Move UoW/flush out of repositories into Application layer. All 3 Doctrine repos call `flush()` inside `save()`/`remove()`; services rely on it. Pattern blocks atomic multi-entity transactions (e.g. collection + fields). Remove `flush()` from repos, inject `EntityManagerInterface` into services (or `#[AsTransactional]`), flush after operation. Estimate 2-3h. | done | From 2026-09-10: Gemini AI code review on PR #25 (pervasive cross-cutting pattern confirmed by investigation) |
| review-5 | [review] Doctrine ORM 3 ghost-proxy latent bug in `DoctrineCollectionFieldRepository` — `CollectionField.collection` is a LAZY `final` Collection association; any future read path that doesn't JOIN FETCH will throw `Cannot generate lazy ghost: class "Collection" is final`. Fix along `Collection.owner` pattern (JOIN FETCH in all DQL read methods). | done | From 2026-09-10: surfaced by php-senior-reviewer during Task 3.4 review (pre-existing, unrelated to 3.4 diff). Fixed in `findById`: chain JOIN field→collection→owner; regression test with `em->clear()` |
| review-6 | [review] Extract `OwnerId`/`CollectionFieldId`-style value objects to remove cross-domain dependency: Collection domain currently hard-depends on `User/UserId` (pre-existing since 3.1). Proposed: `OwnerId` VO inside Collection domain + repo finds by it. >150 lines — separate refactor, not scope of 3.4. | done | From 2026-09-10: architect-reviewer finding on Task 3.4 (Variant A vs OwnerId-VO); design locked as `?owner` param, VO extraction deferred. Closed 2026-09-13 (PR #48): `Collection\OwnerId` VO, query/DTO-слой (Collection + Item) на `OwnerId` вместо `User\UserId`; `findByOwner(User)` удалён; entity-связь `Collection.owner` осталась (полный разрыв — отдельная задача) |
| review-7 | [review] Удалить debug-код из `src/Kernel.php`: `file_put_contents('/tmp/kernel_debug.log', ...)` (строки 42/47/49 — пишет на диск при каждом boot во ВСЕХ env, включая prod) и `error_log("[KERNEL DEBUG] ...")` (строка 65). Остаток старой отладки (JWT-инцидент). Estimate: 0.5ч | done | From 2026-09-15: tech-lead-reviewer finding on Task 5.1. Closed 2026-09-16 (PR #59): Kernel приведён к каноническому Flex-виду (`use MicroKernelTrait`), `config/bundles.php` стал источником бандлов (+Lexik, +DAMA(test), Nelmio dev+test); attribute-роуты API перенесены в `config/routes.yaml`; debug-код и `isDebug()` удалены. Проверено: dev/test/prod роуты, prod warmup, `/tmp/kernel_debug.log` не пишется |

| fwd-1 | [future] Extend UnitOfWorkInterface with transactional boundary (`transactional(callable)` / wrapInTransaction) for atomic multi-entity operations (item + dynamic fields). Deferred from review-4: no consumer yet. Take during Этап 4 design. | done (2026-09-13, PR #49) | From 2026-09-10: architect review finding on review-4 |

| fwd-2 | [future] Заменить фиксированные 12 колонок Item (match-arms 1,2,3 + SlotLimits::MAX_SLOTS_PER_TYPE) на произвольное количество слотов на тип. Сейчас константа создаёт ложную конфигурируемость: изменение значения не меняет ни колонки схемы, ни match-ветки (slot > 3 → throw). Кандидаты: (a) EAV-таблица item_slot_values(item_id, field_type, slot_index, value), (b) JSON-колонка slots, (c) генерация колонок по константе. Затронет: Item mapping, репозитории, миграцию, CollectionField cap, nextSlotIndexFor (per-type), сервис маппинга 4.4. Триггер: реальная потребность >3 слотов на тип или архитектурное ревью. Estimate: 3-5ч | todo | From 2026-09-11: выявлено при закрытии 4.1 — константа не управляет схемой |

| fwd-3 | [future] Единый контракт сериализации response-DTO: интерфейс `ArrayableInterface` (`toArray(): array`) в `src/Application/Common/DTO/`; реализован на `CollectionDTO`, `ItemDTO`, `TagDTO` (PR #46). Будущие response-DTO (4.5, 5.x) реализуют тот же контракт | done | From 2026-09-12: предложено пользователем при обсуждении ItemDTO (Task 4.4). Closed 2026-09-13 (PR #46) |

| fwd-4 | [future] Бамп PHPUnit до ^11.5. Сейчас `composer.json:41` = `^9.0` (установлен 9.6.35). Миграция: bump + `phpunit.xml.dist` схема (10/11), `@dataProvider` → `#[DataProvider]`, `#[\Override]`/deprecated-ассерты. Затронет: composer.json/lock, phpunit.xml.dist, тесты. Estimate: 1-2ч | done | From 2026-09-13: выявлено при fwd-3. Closed 2026-09-16 (PR #60): PHPUnit 9.6.35 → **11.5.56** (php-code-coverage 9.2.32 → 11.0.12). Реальная находка: в PHPUnit 10/11 расширения в `phpunit.xml` регистрируются как `<bootstrap class="…"/>`, а не `<extension/>` — старая форма не проходит XSD-валидацию, PHPUnit 11 выдаёт **late test-runner warning** (после прогона) и пропускает расширение → **DAMA-extension не грузился**, тесты не изолировались (UniqueConstraintViolation, «actual size 32 vs 3»). Исправлено; схема мигрирована (`<source>` вместо `<coverage><include>`, `includeUncoveredFiles="true"`, `cacheDirectory=".phpunit.cache"`); `@dataProvider` → атрибут; `phpunit:no-coverage` получил `--no-coverage` (PHPUnit 11 иначе warning при отсутствии драйвера + `failOnWarning`). Добавлен guard-тест `TestDatabaseIsolationTest::testDamaTransactionalIsolationIsActive`. Проверено: `ci:all` 470/1117, coverage gate 97.16%, `coverage:check` ок. Устаревшее описание («требует ^11.5») поправлено |

| fwd-6 | [future] Устранить N+1 по тегам в списках айтемов (`ItemDTO::fromEntity` → `getTags()` на каждый item). Правильный фикс — двухзапросный batch (сначала страница id, потом теги через `WHERE item_id IN (...)`), т.к. `leftJoin('i.tags')->addSelect` + `setMaxResults` даёт cartesian-инфляцию и ломает пагинацию. Отложено из 4.5 (D4): для MVP `limit<=50` терпимо. Estimate: 1-2ч | todo | From 2026-09-13: решение 4.5 — отложить N+1 |

| fwd-7 | [future] Публичный/гостевой доступ к айтемам и коллекциям (PRD story 37: «гость видит чужие коллекции и айтемы»). Сейчас (Этап 4, D1) чтение = только владелец+админ; Этап 5 (D1) открыл чтение аутентифицированным. Остаток: anonymous-доступ — ослабление security.yaml (`^/api/items`, `^/api/collections` GET → PUBLIC_ACCESS), конверт 401→200 для анонима, guest-view тесты. Затронет: security.yaml, ItemController/CollectionController (опциональный getUser), functional-тесты 4.5. Estimate: 1-2ч | todo | From 2026-09-15: решение Этапа 5 D1 — только аутентифицированные, гостевой доступ отложен |

| fwd-8 | [future] Устранить потенциальный N+1 счётчиков в item-DTO при обогащении `likes_count`/`comments_count`/`liked_by_me` (Этап 5, D6). Если агрегация на каждый item списка даст N+1 — batch-подсчёт (`GROUP BY` по item_id для страницы) по прецеденту fwd-6. Конкретные сигнатуры (под 5.5, зафиксировано architect-ревью 5.3): репозиторий — `countByItemIds(array<ItemId>): array<string,int>` (`GROUP BY IDENTITY(...)`) и `likedItemIdsBy(OwnerId, array<ItemId>): array<string>` (`EXISTS`/`IN`); сервис — `LikeService::countByItems(array<ItemId>)` / `likedItemIdsBy(...)` (аналогично Comment). Тот же приём для `listByItem` (перегидратация 4-join на список — кандидат на проекцию). Estimate: 1-2ч | todo | From 2026-09-15: риск Этапа 5 — счётчики в списках. 2026-09-16: уточнено на ревью 5.3 |

| fwd-9 | [future] Изолировать тест-БД от dev. Сейчас `docker-compose.yml` задаёт `DATABASE_URL=.../taskflow` env-переменной контейнера → она переопределяет `.env.test` (`taskflow_test` на host.docker.internal): все тесты локально идут в dev-БД `taskflow`. Открытое при 5.1 (smoke-данные «загрязнили» тестовый прогон). Кандидаты: (a) убрать env из docker-compose → `env_file` с dev-значением, чтобы `.env.test` работал для тестов; (b) отдельный сервис `db_test` в compose; (c) CI уже изолирован (свой MySQL-сервис). Estimate: 1-2ч | done | From 2026-09-15: открыто при 5.1 — тесты ходят в dev `taskflow`. Closed 2026-09-16 (PR #59): `tests/bootstrap.php` в test-окружении переопределяет `DATABASE_URL` из `.env.test`, если текущее значение ещё не указывает на `taskflow_test` (value-sniff guard — CI сам задаёт `taskflow_test` и не трогается); создана БД `taskflow_test` (+`docker/mysql/init/01-test-db.sql` для чистых установок); миграции применены; добавлен `TestDatabaseIsolationTest`. Проверено: test→`taskflow_test`, dev→`taskflow`, CI не затронут |

| fwd-10 | [future] Единый источник `DATABASE_URL` в `.env`: файл содержит дубль-ключ (uncommented recipe-блок `DATABASE_URL="postgresql://..."` — last-wins), что ломает «single source of truth» и сбивает хостовой `bin/console`. Запрошено architect-review на PR #59. Кандидат: удалить постгресовый recipe-дубль, оставить один `DATABASE_URL`. Estimate: 0.25ч | todo | From 2026-09-16: architect-reviewer finding на PR #59 |

| fwd-11 | [future] Политика для `config/reference.php` (tracked, авто-генерируемый): он регулярно дрейфует при изменении бандлов/конфигов и вручную ревертится в каждом PR. Решить: (a) убрать из git + `.gitignore`, (b) добавить CI-шаг regenerate+diff-check. Estimate: 0.5ч | todo | From 2026-09-16: ревью PR #59 — артефакт систематически dirty |

| fwd-12 | [future] `.gitattributes` / политика переводов строк. `composer.json` был CRLF в репозитории, при правке нормализован в LF → 127-строчный whole-file churn (PR #60). Добавить `.gitattributes` (`* text=auto`, `*.php text eol=lf`, `composer.json text eol=lf`) и `git add --renormalize .` отдельным PR. Estimate: 0.5ч | todo | From 2026-09-16: tech-lead-review на PR #60 — нет `.gitattributes` |

| fwd-13 | [future] Guard `phpunit.xml.dist` от дрейфа схемы: XSD-lint (`xmllint --schema vendor/phpunit/phpunit/phpunit.xsd phpunit.xml.dist`) или `--migrate-configuration` no-diff-check в `ci:all`. Плюс задел под PHPUnit 12: `willReturnOnConsecutiveCalls` (deprecated в 10, удаляется в 12; есть в `TagServiceTest:82`) → `willReturnCallback`; doc-comment метаданные уже конвертированы. Estimate: 0.5-1ч | todo | From 2026-09-16: architect/tech-lead review PR #60 — silent extension skip класс багов |

| fwd-5 | [future] Полный разрыв cross-domain зависимости: заменить `Collection.owner` (`ManyToOne User`) и `CollectionService::create(User)` на OwnerId-представление (маппинг/embeddable или колонка owner_id + загрузка User по требованию). Итог review-6 (2026-09-13): query/DTO-слой уже на `OwnerId`, но entity-связь и create остались — полное устранение требует смены Doctrine-маппинга, миграцию и пересмотр CollectionDTO/контроллеров. С Этапа 5 (5.1) в scope также read-цепочка `Like` → `item.collection.owner` (JOIN FETCH в `DoctrineLikeRepository::withAll`). Estimate: 3-5ч | todo | From 2026-09-13: OpenRabbit review PR #48 — track full cross-domain decoupling as future PR. 2026-09-15: scope+Like |

| fwd-14 | [future] Единый предикат «owner-or-admin»: после 5.10 одна и та же логика живёт дважды — `AbstractApiController::canManage(User, User)` (item/collection) и `SocialContentVoter::voteOnAttribute` (social). Вынести в общий сервис/интерфейс (Application или Domain), чтобы смена правила не расходилась по копиям. Стык с 5.9/7.x. Estimate: 0.5-1ч | todo | From 2026-09-17: architect-review PR 5.10 — дублирование предиката |

| fwd-15 | [future] Явно определить `access_decision_manager.strategy` и закрепить guard-тестом, что `Like`+`SOCIAL_EDIT` остаётся denied при появлении второго Voter (сейчас affirmative-стратегия работает молча, т.к. voter один). Триггер: первый Voter для Item/Collection. Estimate: 0.5ч | todo | From 2026-09-17: architect-review PR 5.10 — молчаливое допущение о стратегии ADM |

| fwd-16 | [future] Убрать скрытый инвариант Voter'а: `SocialContentVoter::voteOnAttribute` читает `$subject->getOwner()`, значит subject обязан приходить с гидрированным owner (зависит от `withAll()`-JOIN в репозитории — в сигнатуре не видно). Добавить `getOwnerId(): OwnerId`-акцессор (читает FK без гидрирования объекта) и сравнивать по нему; при 5.9/7.x рассмотреть `OwnableInterface`. Estimate: 1ч | todo | From 2026-09-17: architect-review PR 5.10 — скрытая зависимость от eager-hydration |

| fwd-17 | [future] `AccessDeniedException` → JSON listener: убрать ручные 403-блоки из контроллеров (`LikeController`/`CommentController` + будущие), чтобы `isGranted` бросал, а конверт `{error, message}` строился один раз. Осознанный долг 5.10 (D11 PRD). Стык с 5.9. Estimate: 1ч | todo | From 2026-09-17: architect-review PR 5.10 — ручной JSON-403 сохранён |

## Этап 4: Доменная модель — Айтем

### Управление айтемами с динамическими полями

| Задача | Описание | Статус |
|--------|----------|--------|
| 4.1 | [x] Сущность Item с name, collection_id и слотами динамических полей | done |
| 4.2 | [x] Сущность Tag и связующая таблица many-to-many | done |
| 4.3 | [x] Сервис тегов — создание и переиспользование существующих | done |
| 4.4 | [x] Сервис айтемов — создание, редактирование, список с логикой динамических полей | done |
| 4.5 | [x] API-эндпоинты айтемов (CRUD, список айтемов коллекции, список своих; фильтры по имени и тегам) | done (PRD/4.5-item-api-endpoints.md) |
| 4.6 | [x] Unit-тесты для домена Item | done (audit-close, PRD/4.6-item-domain-tests.md) |
| 4.7 | [x] API-эндпоинт тегов — список/поиск существующих тегов (выбор из уже существующих при проставлении айтему) | done (PRD/4.7-tag-list-endpoint.md) |

## Этап 5: Социальные функции

### Лайки и комментарии

| Задача | Описание | Статус |
|--------|----------|--------|
| 5.1 | [x] Сущность Like с уникальным ограничением (user_id, item_id) | done (PRD/5.1-like-entity.md) |
| 5.2 | [x] Сущность Comment с owner_id, item_id | done (PRD/5.2-comment-entity.md) |
| 5.3 | [x] Сервис лайков — добавление, удаление, переключение | done (PRD/5.3-like-service.md) |
| 5.4 | [x] Сервис комментариев — создание, редактирование, удаление | done (PRD/5.4-comment-service.md) |
| 5.5 | [x] API-эндпоинты лайков и комментариев | done (PRD/5.5-social-api.md) |
| 5.6 | [x] Unit-тесты домена Like (tests-only) | done (PRD/5.6-social-tests.md) |
| 5.10 | [x] Social moderation Voter: `SocialContentVoter` (атрибуты EDIT/DELETE, admin-OR-owner) для `Like`/`Comment`; перевод `LikeController::delete`, `CommentController::update`/`delete` с `canManage` на голосование; ручной JSON-403 сохраняется (нет `AccessDeniedException`→JSON listener); unit-тест матрицы {автор, чужой, чужой-админ, гость} × {EDIT, DELETE} × {Comment, Like}; `lint:container` + `debug:container --tag=security.voter` в проверках. Depends: 5.6. Estimate: 3ч (ревизия: +тесты) | done (PRD/5.10-social-voter.md) |
| 5.7 | [review] API-эндпоинт «мои лайки» (`GET /api/likes`) — симметрия с `GET /api/comments`; 5.5 отдала только «мои комментарии» (PRD 20). | todo |
| 5.8 | [review] `DELETE /api/items/{id}/comments/{commentId}` — вложенный путь для админ-контекста; 5.5 отдала `DELETE /api/comments/{id}`. Зависит от 5.10 (та же область `canManage` в контроллерах). | todo |
| 5.9 | [review] Выделить повторяющуюся обработку исключений в `AbstractApiController`: каждый контроллер вручную собирает `JsonResponse` с `{error, message}` для 400/401/403/404/422 (`ItemController`, `CollectionController`, `LikeController`, `CommentController`, `TagController`), плюс `@var User` / `if (!$user instanceof User)` повторяется в каждом действии. Нужны хелперы вида `errorResponse(int $status, string $error, string $message)`, `unauthorized()`, `notFound()`, `forbidden()`, `badRequest()`, `unprocessable()` и `currentUser(): User` (с единым 401). Также рассмотреть единый `try/catch` для `\InvalidArgumentException` (id → 404, контент → 422) и serializer-исключений (400), чтобы карта ошибок 5.5 задавалась один раз. После 5.10 сужается: `canManage` у `ItemController` остаётся (осознанный split-brain). Зависит от 5.10. Estimate: 1-2ч | todo |

## Этап 6: Полнотекстовый поиск

### Функциональность поиска

| Задача | Описание | Статус |
|--------|----------|--------|
| 6.1 | [ ] Реализация полнотекстового поиска (Meilisearch/OpenSearch/Elasticsearch) | todo |
| 6.2 | [ ] Сервис поиска айтемов по имени, тегам, имени коллекции | todo |
| 6.3 | [ ] API-эндпоинт поиска | todo |
| 6.4 | [ ] Конфигурация индекса и начальные данные | todo |

## Этап 7: Админ-панель

### Административные эндпоинты

| Задача | Описание | Статус |
|--------|----------|--------|
| 7.1 | [ ] API списка пользователей с的所有 полями | todo |
| 7.2 | [ ] Управление пользователями — блок/разблок/удаление | todo |
| 7.3 | [ ] API создания пользователя админом | todo |
| 7.4 | [ ] API повышения до администратора | todo |
| 7.5 | [ ] Переопределение админа на коллекциях/айтемах (как владелец) | todo |
| 7.6 | [ ] Админ-модерация контента: аудит-список лайков/комментариев и админ-UI (правка/удаление самого контента реализованы в 5.5 — автор или админ) | todo |
| 7.7 | [ ] Unit-тесты для админ-эндпоинтов | todo |

## Этап 8: Тестирование и полировка

### Финальные проверки

| Задача | Описание | Статус |
|--------|----------|--------|
| 8.1 | [ ] E2E-тесты — регистрация, вход, создание коллекции, создание айтема | todo |
| 8.2 | [ ] Smoke tests — docker compose up и проверка URL | todo |
| 8.3 | [ ] Аудит покрытия — 80% на слоях domain/application | todo |
| 8.4 | [ ] Мутационное тестирование с Infection | todo |
| 8.5 | [ ] Проверка полноты документации OpenAPI | todo |
| 8.6 | [ ] Обзор безопасности — аутентификация, авторизация, валидация входных данных | todo |

---

## Прогресс

- **Этап 1 (Инфраструктура)**: 6/6 задач выполнено
- **Этап 2 (Пользователь)**: 5/5 задач выполнено
- **Этап 3 (Коллекция)**: 6/6 задач выполнено
- **Этап 4 (Айтем)**: 7/7 задач выполнено
- **Этап 5 (Социальное)**: 7/7 core задач выполнено (5.1–5.6, 5.10); этап не закрыт: smoke test и периодический review. Review-бэклог 5.7–5.9 открыт
- **Этап 6 (Поиск)**: 0/4 задач выполнено
- **Этап 7 (Админ)**: 0/7 задач выполнено
- **Этап 8 (Тестирование)**: 0/6 задач выполнено

**Итого**: 31/48 задач выполнено

> Правило счёта: в знаменатель этапа входят только строки таблицы этапа (`[ ]`/`[x]`). Строки `[review]` и `[future]` исключаются из счётчиков этапа и ведутся отдельной строкой бэклога. `Итого` — арифметическая сумма строк этапов, пересчитывается при каждом изменении, никогда не инкрементируется от прошлого значения.

---

## Архитектурные заметки

- **Стек**: Symfony 7, PHP 8.3, MySQL/PostgreSQL, Redis, Meilisearch
- **Архитектура**: Clean Architecture — слои Domain, Application, Infrastructure
- **API**: REST с документацией OpenAPI
- **Auth**: Symfony Security с JWT или сессионной аутентификацией
- **БД**: Doctrine ORM с миграциями
- **Очереди**: Symfony Messenger с Redis transport
- **CI**: GitHub Actions со всеми инструментами качества