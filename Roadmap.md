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

| fwd-11 | [future] Политика для `config/reference.php` (tracked, авто-генерируемый): он регулярно дрейфует при изменении бандлов/конфигов и вручную ревертится в каждом PR. Решить: (a) убрать из git + `.gitignore`, (b) добавить CI-шаг regenerate+diff-check. Estimate: 0.5ч | todo | From 2026-09-16: ревью PR #59 — артефакт систематически dirty. 2026-09-21 (5.12): выяснена природа — файл пишет `PhpConfigReferenceDumpPass` (регистрируется `FrameworkBundle` только при `kernel.debug`) и это **дамп схемы** конфигов бандлов, а не значения приложения: удаление нашего ключа `encoder.crypto_engine` его не изменило (строка живёт в схеме Lexik). Плюс содержимое env-зависимо через `.kernel.bundles_definition` (DAMA только test, Nelmio dev+test), поэтому «regen+diff-check» имеет смысл лишь с фиксацией одного канонического env; ловить deprecated-ключи конфигов этот файл не может — для этого нужен runtime-режим checker'а (5.13) |

| fwd-12 | [future] `.gitattributes` / политика переводов строк. `composer.json` был CRLF в репозитории, при правке нормализован в LF → 127-строчный whole-file churn (PR #60). Добавить `.gitattributes` (`* text=auto`, `*.php text eol=lf`, `composer.json text eol=lf`) и `git add --renormalize .` отдельным PR. Estimate: 0.5ч | merged into 5.21 | From 2026-09-16: tech-lead-review на PR #60 — нет `.gitattributes`. 2026-09-23: слито в 5.21 — та же тема, две строки не нужны |

| fwd-13 | [future] Guard `phpunit.xml.dist` от дрейфа схемы: XSD-lint (`xmllint --schema vendor/phpunit/phpunit/phpunit.xsd phpunit.xml.dist`) или `--migrate-configuration` no-diff-check в `ci:all`. Плюс задел под PHPUnit 12: `willReturnOnConsecutiveCalls` (deprecated в 10, удаляется в 12; есть в `TagServiceTest:82`) → `willReturnCallback`; doc-comment метаданные уже конвертированы. Estimate: 0.5-1ч | merged into 5.25 | From 2026-09-16: architect/tech-lead review PR #60 — silent extension skip класс багов. 2026-09-23: слито в 5.25 — XSD-guard и миграция ассертов идут как предпосылка мажорного перехода |

| fwd-5 | [future] Полный разрыв cross-domain зависимости: заменить `Collection.owner` (`ManyToOne User`) и `CollectionService::create(User)` на OwnerId-представление (маппинг/embeddable или колонка owner_id + загрузка User по требованию). Итог review-6 (2026-09-13): query/DTO-слой уже на `OwnerId`, но entity-связь и create остались — полное устранение требует смены Doctrine-маппинга, миграцию и пересмотр CollectionDTO/контроллеров. С Этапа 5 (5.1) в scope также read-цепочка `Like` → `item.collection.owner` (JOIN FETCH в `DoctrineLikeRepository::withAll`). Estimate: 3-5ч | todo | From 2026-09-13: OpenRabbit review PR #48 — track full cross-domain decoupling as future PR. 2026-09-15: scope+Like |

| fwd-14 | [future] Единый предикат «owner-or-admin»: после 5.10 одна и та же логика живёт дважды — `AbstractApiController::canManage(User, User)` (item/collection) и `SocialContentVoter::voteOnAttribute` (social). Вынести в общий сервис/интерфейс (Application или Domain), чтобы смена правила не расходилась по копиям. Стык с 5.9/7.x. Estimate: 0.5-1ч | todo | From 2026-09-17: architect-review PR 5.10 — дублирование предиката |

| fwd-15 | [future] Явно определить `access_decision_manager.strategy` и закрепить guard-тестом, что `Like`+`SOCIAL_EDIT` остаётся denied при появлении второго Voter (сейчас affirmative-стратегия работает молча, т.к. voter один). Триггер: первый Voter для Item/Collection. Estimate: 0.5ч | todo | From 2026-09-17: architect-review PR 5.10 — молчаливое допущение о стратегии ADM |

| fwd-16 | [future] Убрать скрытый инвариант Voter'а: `SocialContentVoter::voteOnAttribute` читает `$subject->getOwner()`, значит subject обязан приходить с гидрированным owner (зависит от `withAll()`-JOIN в репозитории — в сигнатуре не видно). Добавить `getOwnerId(): OwnerId`-акцессор (читает FK без гидрирования объекта) и сравнивать по нему; при 5.9/7.x рассмотреть `OwnableInterface`. Estimate: 1ч | todo | From 2026-09-17: architect-review PR 5.10 — скрытая зависимость от eager-hydration |

### Мини-этап 5A: Контракт ошибок и наблюдаемость API

Восемь пунктов ниже (`fwd-17`…`fwd-24`) — один связный мини-этап, а не разрозненный долг; PRD: `PRD/mini-stage-5A-error-contract.md`. Порядок работ: `fwd-20` → `fwd-19` → `fwd-17` → `fwd-21` → `fwd-22` → `fwd-24` → `fwd-23` → `fwd-18` (`fwd-17`/`fwd-19`/`fwd-21` делят один `kernel.exception`-listener). Три пункта помечены `[bug]` — это не долг, а дефекты контракта.

| fwd-17 | [future] `AccessDeniedException` → JSON listener: убрать ручные 403-блоки из контроллеров (`LikeController`/`CommentController` + будущие), чтобы `isGranted` бросал, а конверт `{error, message}` строился один раз. Осознанный долг 5.10 (D11 PRD). Стык с 5.9. Estimate: 1ч | todo | From 2026-09-17: architect-review PR 5.10 — ручной JSON-403 сохранён |

| fwd-18 | [future] Дублирование DELETE-поверхности у комментариев: `DELETE /api/comments/{id}` и `DELETE /api/items/{itemId}/comments/{id}` делают одно и то же (тот же Voter, тот же `CommentService::delete`); различие только в проверке консистентности пути. Оба shipped до первого релиза, внешних клиентов нет → решить политику: объявить вложенный путь каноническим, а плоский пометить deprecated и удалить в следующем мажоре (либо, наоборот, оставить плоский и снять вложенный). Стык с fwd-17/5.9 (тот же контроллер). Estimate: 0.5ч + решение | todo | From 2026-09-21: architect-review PR 5.8 — две URL на одну операцию |
| fwd-19 | [bug] Единый 401-конверт: неаутентифицированный запрос к защищённому пути отклоняется jwt-entry-point'ом Lexik и отвечает его телом {"code":401,"message":"JWT Token not found"}, тогда как guard в AbstractApiController отдаёт {"error":"Unauthorized"}. Свести к одному конверту через кастомный entry point / failure handler; заодно поправить примеры 401 в OpenAPI (они уже описывают желаемую форму `error` + `message`, ответ Lexik приводится к ним). Зависит от 5.9. **Влияние:** клиент получает два разных тела на один и тот же статус в зависимости от того, кто отклонил запрос. Estimate: 0.5ч | todo | From 5.9 (PRD/5.9-error-helpers.md:64): 401-конверт — это security-слой, а не хелперы, поэтому вынесен из 5.9 |
| fwd-20 | [bug] CollectionController::list парсит limit/offset сырым (int) вместо parsePagination: ?limit=abc молча даёт LIMIT 0 (пустой список) вместо 400, ?limit=0 тоже проходит. Остальные списки (Item/Like/Comment/Tag) уже валидируют через parsePagination. Привести list() к общему хелперу + тест на ?limit=abc → 400. Родственно fwd-19 (гигиена ошибок коллекций). **Влияние:** опечатка в пагинации выглядит для клиента как «данных нет», а не как ошибка запроса. Estimate: 0.5ч | done | From 2026-09-21: senior-review 5.9 — единственный неохраняемый край на тронутом маршруте. 2026-09-23: закрыто (PRD/fwd-20-collection-pagination.md) — сырой каст заменён на `parsePagination` в `try/catch` до разбора `owner`, OA-параметры переведены на константы, `public/api/openapi.json` перегенерирован, +9 тестов; `ci:all` 698/1974 |
| fwd-21 | [bug] Логировать пойманные `\Throwable`: 3 сайта в `CollectionController` (create/update/delete) возвращают 500 без следа — исключение поймано, `$throwable` не используется, в лог ничего не уходит. В приложении нет логгер-плумбинга: monolog не подключён, в `src/` ноль использований `LoggerInterface`, а как аргумент контроллера `LoggerInterface` не резолвится вовсе (проверено: `ArgumentResolver` → «requires the "$logger" argument that could not be resolved»), поэтому «просто добавить лог-вызов» не выходит. Решать вместе с глобальной обработкой исключений (fwd-17/fwd-19): `kernel.exception`-listener логирует `error`-уровнем с `['exception' => $e]`, а тело 500 остаётся без `message` — внутренности клиенту не светим. **Важно:** три сайта `catch (\Throwable)` в `CollectionController::create/update/delete` ловят исключение локально и до listener'а не доходят — при реализации fwd-21 их тоже нужно покрыть (либо снять локальные catch'и, либо логировать на месте); иначе 500 в этих трёх местах останутся невидимыми. **Влияние:** сбой на этих трёх маршрутах невозможно диагностировать по логам. Estimate: 0.5ч + решение | todo | From 2026-09-22: openrabbit-review PR 5.9 — 500 без диагностики; architect-review 5.9 — «listener не увидит пойманное» |
| fwd-22 | [future] Единый словарь лейбла `error` для 422: сейчас у одного статуса два лейбла — `'Validation failed'` (DTO-валидация через `createValidationErrorResponse`, `details` = сообщения полей) и `'Unprocessable Entity'` (семантика контента через `unprocessable()`). Выбрать одно: либо HTTP-причина для всех, либо доменные категории; либо оставить split, но зафиксировать в ARCHITECTURE.md. Затрагивает OpenAPI-примеры и тесты. Estimate: 0.5ч + решение | todo | From 2026-09-22: architect-review 5.9 — два словаря на один статус |
| fwd-23 | [future] Общие компоненты схемы ошибок в OpenAPI: вместо ~30 inline-блоков `OA\JsonContent` для 400/401/403/404/409/422 в 8 контроллерах завести `$ref` на `#/components/schemas/Error` (+ `ErrorDetails` для `details`). Дублирование уже мешает: 422 у комментариев были без схемы, пока у коллекций она есть. Estimate: 1ч | todo | From 2026-09-22: architect-review 5.9 — дублирование схем |
| fwd-24 | [future] Контент `details` при ошибках ввода: сейчас туда идёт `$exception->getMessage()` (best-effort, текст может меняться с рефакторингом исключений), а часть сообщений содержит внутреннюю лексику (`get_debug_type($value)` → «got int»). Решить: оставить best-effort и зафиксировать в ARCHITECTURE.md, либо выводить стабильные фразы на уровне call-site (`'Invalid limit'`, `'Invalid slot value'`). Родственно fwd-21 (логи). Estimate: 0.5ч + решение | todo | From 2026-09-22: senior-review 5.9 — `details` не стабилен |

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
| 5.7 | [review] API-эндпоинт «мои лайки» (`GET /api/likes`) — симметрия с `GET /api/comments`; 5.5 отдала только «мои комментарии» (PRD 20). | done (PRD/5.7-own-likes-endpoint.md) |
| 5.8 | [review] `DELETE /api/items/{id}/comments/{commentId}` — вложенный путь для админ-контекста; 5.5 отдала `DELETE /api/comments/{id}`. Зависит от 5.10. Реализовано как автор-или-админ (D1 в PRD 5.8), не admin-only. | done (PRD/5.8-nested-comment-delete.md) |
| 5.9 | [review] Выделить повторяющуюся обработку исключений в `AbstractApiController`: каждый контроллер вручную собирает `JsonResponse` с `{error, message}` для 400/401/403/404/422 (`ItemController`, `CollectionController`, `LikeController`, `CommentController`, `TagController`), плюс `@var User` / `if (!$user instanceof User)` повторяется в каждом действии. Нужны хелперы вида `errorResponse(int $status, string $error, string $message)`, `unauthorized()`, `notFound()`, `forbidden()`, `badRequest()`, `unprocessable()` и сужение пользователя `instanceof`-guard'ом на месте вызова (обёртка над `getUser()` не нужна — он уже возвращает `?UserInterface`). Также рассмотреть единый `try/catch` для `\InvalidArgumentException` (id → 404, контент → 422) и serializer-исключений (400), чтобы карта ошибок 5.5 задавалась один раз. После 5.10 сужается: `canManage` у `ItemController` остаётся (осознанный split-brain). Зависит от 5.10. Estimate: 1-2ч | done (PRD/5.9-error-helpers.md) |
| 5.11 | [review] CI: Symfony-aware диагностика `symfony-lsp check` (symfony/language-tools v0.21.0) — `scripts/symfony-lsp-check.sh` (закреплённая версия + SHA256), composer-скрипт `ci:symfony-lsp`, пилотный non-blocking CI-job `symfony-diagnostics` (`--source-only --format=github`), `.symfony-lsp.json` с `excludePaths: [config/reference.php]` (фикс exit 12), guard-тест. | done (PRD/5.11-symfony-lsp-check.md) |
| 5.12 | [review] Убрать устаревший ключ `lexik_jwt_authentication.encoder.crypto_engine` (`config/packages/lexik_jwt_authentication.yaml:8`) — найдено `symfony-lsp check` (warning `config.deprecated_key`). Проверено runtime-режимом: 0 diagnostics. | done (PRD/5.12-drop-deprecated-crypto-engine.md) |
| 5.13 | [review] Перевести `symfony-lsp` в CI на runtime-режим (маршруты, DI-контейнер, Doctrine-метаданные) + надёжность скачивания. Итог: job делает **два прогона** (source baseline + runtime) с явным `--environment=test`; сервисы MySQL/Redis **не понадобились** (test-env поднимается без БД/кэша/транспорта — проверено с недоступными DSN), APCu тоже не нужен (checker не инстанцирует `cache.app`); `--environment=test` обязателен, т.к. checker по умолчанию берёт `dev` и не читает `.env`, а в `dev` кэш — Redis. Флейк `504` вылечен retry с backoff и таймаутами + кэшем архива в `var/symfony-lsp/bin` с обязательной SHA256-проверкой на кэш-хите. Пилот остаётся non-blocking. | done (PRD/5.13-symfony-lsp-runtime.md; CI подтверждён: run #35739005201) |
| 5.14 | [review] Сделать CI-job `symfony-diagnostics` блокирующим: убрать `continue-on-error`, добавить в `needs` у `ci-summary` — решение по итогам недели пилота (~2026-09-27, не дожидаясь 5.13). Заодно: закрепить ожидаемый SHA256 бинарника константой в репо (сейчас `SHA256SUMS` тянется из того же релиза — TOFU-доверие) и завести регулярную проверку версии `symfony-lsp` (0.x-релизы частые, Dependabot скачиваемый бинарник не видит). Перед флипом учесть слепые зоны `--environment=test` (PRD 5.13): Redis-адаптер `cache.app`, `when@test`-ветки, `framework.test`, отсутствие сверки Doctrine-маппинга со схемой — решить, нужен ли дополнительный прогон в `dev` с Redis-сервисом. | todo |
| 5.15 | [review] Выровнять минорные версии Symfony: `symfony/redis-messenger` 7.3.10 → 7.4.x (остальное уже на 7.4.x) + батч patch-бампов по списку `composer outdated --direct` от 2026-09-20; после — `composer ci:all`. Мажоры (phpunit 12, rector 2.6) не трогать. Итог: `redis-messenger` 7.3.10 → **7.4.19**, 14 Symfony-компонентов → 7.4.17–7.4.19 (не всё до 7.4.19: `config`/`dependency-injection`/`event-dispatcher`/`type-info` остались на 7.4.17), `phpunit-bridge` 8.1.6, `ramsey/uuid` 4.9.4, `php-cs-fixer` 3.95.27; `composer.json` не менялся — только `composer.lock`. Флаг `-w` подтянул и транзитивные (`routing`/`string`/`type-info`, `polyfill-*`, `masterminds/html5`) вместе с **мажором `brick/math` 0.18.0 → 1.0.0** (расширенный констрейнт `ramsey/uuid` 4.9.4). Миноры Doctrine/Twig/DAMA — 5.23, слепые депрекейшены в CI — 5.24, мажоры и ревизия неиспользуемых зависимостей — 5.25. Слабый прогон депрекейшенов чист (штатно они заглушены `disabled=1`). Проверки: `ci:all` 689/1931, `coverage:gate` 97.74%, runtime `symfony-lsp` — 0 diagnostics, smoke 25/25. | done (PRD/5.15-symfony-version-alignment.md) |
| 5.16 | [review] **Code Review: потерянные вердикты** (перенесено из 5.14 по architect-review 5.13 — не смешивать несвязанные гейты в одном флипе). Итог: таймаут job'а `OpenRabbit Review` 15 → 30 мин (реальная потеря вердикта — таймаут, а не отмена); `cancel-in-progress: true` оставлен осознанно и объяснён в workflow (ревьюится только последний head: у отложенного прогона `github.sha` зафиксирован на момент триггера, плюс пул OpenRouter free ограничен 50 запросами в сутки); «`cancelled`/`timed out` = вердикта нет» внесено правилом в `CLAUDE.md` 6.1; политика закрыта guard-тестом `CodeReviewConfigTest`. | done |
| 5.17 | [future] **Parity-probe: runtime ⊇ source-only?** Два прогона checker'а в CI держатся «навсегда», пока не доказано, что runtime-анализ является надмножеством статического (architect-review 5.13). Собрать фикстуру с заведомо статическими находками (неиспользуемый аргумент сервиса, мёртвый `#[Route]`, нероутабельный контроллер) и показать, что runtime-прогон сообщает тот же набор; при N=2 подряд зелёных прогонах — снять `--source-only` из job'а (в скрипте флаг оставлен). **Итог:** зонд (PRD/5.17-parity-probe.md) инжектировал 6 boot-safe дефектов (route/template/service/parameter/config); runtime дважды (N=2, второй — с холодным индексом) нашёл 4 (`config.deprecated_key`, `parameter.not_found`, `template.not_found`, `route.not_found`), `--source-only` — **0** (исправный прогон, `complete: true`); ни одной source-only-only находки. `--source-only` снят из CI, job делает один runtime-прогон, guard-тест переписан. P1 (`route.missing_parameters`) и P3 (`service.not_found` на абстрактном сервисе) не диагностируются ни одним режимом; причина не разведена (слепое пятно инструмента или артефакт фикстуры/ожидания) — целевая проверка в 5.26. | done (PRD/5.17-parity-probe.md) |
| 5.18 | [future] **Branch protection: ревью как обязательный чек.** «Вердикт есть» сейчас не проверяется механически: job ревью не входит в `ci-summary`, а правило в `CLAUDE.md` 6.1 — процедурное. Добавить `Code Review / OpenRabbit Review` в required status checks для `main` (это настройка GitHub, не код). | todo | From 2026-09-22: architect-review 5.16 |
| 5.19 | [future] **Механическая детекция `cancelled`/`timed out`.** Шаг, который падает или ставит sticky-комментарий в PR, когда итог ревью ∉ {success}, чтобы «вердикта нет» нельзя было принять за успех; заодно рассмотреть триггер `reopened` (сейчас close→reopen без новых коммитов не запускает ревью). Из senior-review 5.16: (а) зафиксировать словарь исходов — сейчас посменный таймаут помечается `failure` (фолбэк срабатывает), но GitHub это не контрактует, и `cancelled` у основного шага молча пропустил бы фолбэк; (б) «вердикт опубликован» ≠ «job зелёный»: экшен может выйти с кодом 0, ничего не опубликовав (например, на fork-PR без секретов). | todo | From 2026-09-22: architect-review 5.16 |
| 5.20 | [future] **Производительность ревью.** Таймаут 30 мин — запас, а не лечение: free-tier LLM на большом PR может не уложиться. Сузить вход (diff-only или лимит файлов), вернуть таймаут к ~10 мин и логировать длительность прогонов. | todo | From 2026-09-22: architect-review 5.16 |
| 5.21 | [future] **`.gitattributes`: полная нормализация.** `* text=auto eol=lf` + бинарные исключения (`*.png binary` и т.п.) + однократный `git add --renormalize .` — закрывает класс CRLF (php-cs-fixer-фантомы, fwd-11) целиком, а не только `*.sh`. Сюда же слит **fwd-12**: `composer.json` был CRLF в репозитории (правка дала 127-строчный whole-file churn в PR #60) — политика `* text=auto`, `*.php text eol=lf`, `composer.json text eol=lf`. | todo | From 2026-09-22: architect-review 5.16; 2026-09-23: +fwd-12 |
| 5.22 | [future] **Закрепить входы AI-ревью в guard-тесте.** Усиление, а не дыры (сейчас тест падает громко, а не пропускает): (а) шаги берутся позиционно (`assertCount(3, $steps)`, `$steps[1]`/`$steps[2]`) — при добавлении setup-шага тест ломается по счётчику, а не по смыслу; искать шаги по `id` (у основного `id: openrouter`) или `name`, счёт ослабить до `>= 2`; (б) `llm_model` и `llm_api_url` не закрепляются вовсе — при этом free-пул OpenRouter осознанно ротирует модели между собой (AssumptionLog:592), поэтому закреплять надо контракт, а не литерал: у основного — allowlist известных free-моделей (`openrouter/free`), у фолбэка — литерал (`llm_provider: groq` + `openai/gpt-oss-20b`), иначе молчаливая эскалация на платный тир пройдёт; «тир» из YAML не выводится, поэтому только allowlist; (в) `assertGreaterThanOrEqual(30, timeout-minutes)` допускает дрейф бюджета вверх при неизменных пошаговых 12/15 — закрепить `assertSame`/коридор, сохранив «сумма шагов < бюджет»; (г) прямой доступ к `timeout-minutes`/`if` без `assertArrayHasKey` — косметика: без него PHP 8 даёт `Warning: Undefined array key` + null и тест всё равно падает, но через assert, а не сразу. Estimate: 0.5ч | todo | From 2026-09-23: senior-review 5.16 (прогон на muse-spark-1.3-contributor); уточнено architect-review и повторным senior-review — тихого зазора нет, только хрупкость |
| 5.23 | [future] **Миноры Doctrine/Twig/DAMA.** Отложено из 5.15: `doctrine/orm` 3.6.7 → 3.7.2, `doctrine/doctrine-bundle` 2.18.3 → 2.19.1, `doctrine/doctrine-migrations-bundle` 3.7.0 → 3.7.1, `dama/doctrine-test-bundle` 8.2.2 → 8.6.0, `twig/twig` 3.28.0 → 3.29.0, `twig/intl-extra` 3.26.0 → 3.29.0. Причина выделения (senior-review 5.15): риск изменения поведения выше, чем у патча, и в 5.15 они не проверялись бы отдельным прогоном. Проверять тем же набором: `ci:all` + `coverage:gate` + слабый прогон депрекейшенов + smoke; по ORM-минору решить отдельно (возможны новые депрекейшены). Estimate: 1ч | todo | From 2026-09-23: senior-review 5.15 (muse-spark-1.3-contributor) |
| 5.24 | [future] **Слабые депрекейшены в CI.** В 5.15 депрекейшены проверялись разово (`SYMFONY_DEPRECATIONS_HELPER=weak php bin/phpunit`), потому что `phpunit.xml.dist:54` штатно держит `disabled=1`. Иначе каждый следующий бамп снова слепой. Добавить отдельный non-blocking шаг/job с `SYMFONY_DEPRECATIONS_HELPER=weak` (warn-only, без падения), чтобы новые депрекейшены были видны до мажорного перехода. | todo | From 2026-09-23: architect-review 5.15 |
| 5.25 | [future] **Мажорный свип + ревизия неиспользуемых зависимостей.** Мажоры отложены не «навсегда»: `phpunit` 11.5 → 12.5, `rector` 1.2.10 (пин) → 2.6, `phpstan` 1.12 → 2.2 + 3 расширения, `lexik/jwt` 2.21 → 3.2, `nelmio/api-doc` 4.38 → 5.12 — lockfile rot копится. Сюда же слит **fwd-13** как предпосылка: XSD-guard `phpunit.xml.dist` (XSD-lint или `--migrate-configuration` no-diff-check в `ci:all`) + миграция `willReturnOnConsecutiveCalls` → `willReturnCallback` (`TagServiceTest:82`; deprecated в 10, удаляется в 12). Заодно решить судьбу `symfony/redis-messenger` + `ext-redis` + `MESSENGER_TRANSPORT_DSN`: в приложении Messenger не задействован (нет `dispatch()`/`AsMessageHandler`, routing закомментирован), в 5.15 он бампнут как заявленная будущая возможность — либо задействовать, либо убрать. | todo | From 2026-09-23: architect-review 5.15; 2026-09-23: +fwd-13 |
| 5.26 | [future] **Повтор parity-зонда на бампе checker'а.** Утверждение «runtime ⊇ source-only» измерено на symfony-lsp 0.21.x и одной фикстуре из шести классов дефектов (5.17) — новая версия может добавить коды, которые статика находит без runtime-индекса. Плюс остались неразведёнными P1 (`route.missing_parameters`) и P3 (`service.not_found` на абстрактном сервисе): нужны целевые фикстуры (генерация ссылки через `path()`/`generate()` для P1; не-абстрактный неиспользуемый сервис для P3) — либо подтвердить слепое пятно, либо снять подозрение. Триггер: бамп `SYMFONY_LSP_VERSION`. Протокол тот же: оба режима, `--format=json`, N=2, холодный индекс, затем ревизия guard-теста. Estimate: 1ч | todo | From 2026-09-23: senior/architect-review 5.17 |

**Порядок работ (этап 5, текущий):** `fwd-20` → `fwd-10` → `5.24` → `5.18` → `5.14` (после недели пилота ~27.09) → `5.22` → `5.23` → `5.19` → `5.20` → `5.21` → `5.25` → `5.26`. Далее — **мини-этап 5A** (контракт ошибок и наблюдаемость API: 8 пунктов `fwd-17`…`fwd-24`, раздел в бэклоге и `PRD/mini-stage-5A-error-contract.md`). Этап 6 (поиск) стартует только после закрытия бэклога этапа 5 и мини-этапа 5A.

## Этап 6: Полнотекстовый поиск

> Старт — **после закрытия бэклога этапа 5 (5.14, 5.18–5.26) и мини-этапа 5A** (контракт ошибок и наблюдаемость API).

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
- **Этап 5 (Социальное)**: 7/7 core задач выполнено (5.1–5.6, 5.10); периодический review выполнен (PR #69), smoke test выполнен 20.09; этап не закрыт. Review-бэклог 5.13–5.15 открыт (5.7–5.12 закрыты)
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