# Architecture — TaskFlow

## Обзор

TaskFlow — REST API для управления личными коллекциями. Clean Architecture на Symfony 7.

## Слои

```
┌─────────────────────────────────────────────┐
│              Infrastructure                  │
│  Controllers │ Repositories │ Doctrine │ etc │
├─────────────────────────────────────────────┤
│              Application                     │
│  Services (use cases) │ DTO │ Exceptions    │
├─────────────────────────────────────────────┤
│              Domain                          │
│  Entities │ Value Objects │ Repo interfaces  │
└─────────────────────────────────────────────┘
```

**Правило:** Зависимости идут только внутрь. Domain не знает ни о Application, ни о Infrastructure.

## Доменные сущности

### User (`src/Domain/User/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `User` | `Entity/User.php` | Сущность пользователя (id, name, email, passwordHash, role, isActive) |
| `UserId` | `ValueObject/UserId.php` | Бинарный UUID |
| `Email` | `ValueObject/Email.php` | Value Object email с валидацией |
| `PasswordHash` | `ValueObject/PasswordHash.php` | Хеш пароля (bcrypt) |
| `Role` | `ValueObject/Role.php` | Роль (user/admin) через RoleEnum |
| `UserRepositoryInterface` | `Repository/UserRepositoryInterface.php` | Интерфейс репозитория |

**Бизнес-правила:**
- Уникальность email
- Роли: `user`, `admin`
- Блокировка через `isActive`
- Пароль верифицируется через `verifyPassword()`

### Collection (`src/Domain/Collection/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Collection` | `Entity/Collection.php` | Коллекция (id, owner, name, theme, description, image) |
| `CollectionField` | `Entity/CollectionField.php` | Динамическое поле (id, collection, name, type, slotIndex) |
| `CollectionId` | `ValueObject/CollectionId.php` | Бинарный UUID |
| `CollectionName` | `ValueObject/CollectionName.php` | Название коллекции |
| `Theme` | `ValueObject/Theme.php` | Тема (Books, Games, Movies, Drinks) |
| `FieldType` | `ValueObject/FieldType.php` | Тип поля (text, number, date, bool) |
| `FieldName` | `ValueObject/FieldName.php` | Название поля |
| `OwnerId` | `ValueObject/OwnerId.php` | ID владельца (бинарный UUID); типизирует query/DTO-слой вместо `User\UserId` (review-6) |
| `CollectionFieldRepositoryInterface` | `Repository/CollectionFieldRepositoryInterface.php` | Интерфейс репозитория |

**Бизнес-правила:**
- Каждая коллекция принадлежит одному пользователю (owner)
- Максимум 100 полей на коллекцию (`MAX_FIELDS_PER_COLLECTION`)
- Поля имеют порядок через `slotIndex`; максимум 3 поля на тип via `SlotLimits::MAX_SLOTS_PER_TYPE`
- UNIQUE `(collection_id, field_type, slot_index)` — per-type слот-уникальность
- Темы: Books, Games, Movies, Drinks

### Item (`src/Domain/Item/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Item` | `Entity/Item.php` | Айтем (id, collection, name, 12 typed slots: text/num/date/bool × 1-3) |
| `ItemId` | `ValueObject/ItemId.php` | Бинарный UUID |
| `ItemNotFoundException` | `Exception/ItemNotFoundException.php` | `withId(ItemId)` — паттерн CollectionNotFoundException |
| `ItemRepositoryInterface` | `Repository/ItemRepositoryInterface.php` | Интерфейс репозитория (`save`, `remove`, `findById`, `findByCollectionId`, `findByOwnerId`)

**Бизнес-правила:**
- Слоты типизированы и фиксированы: `Item.{type}_{slot}` для (type, slot 1..3)
- Поле CollectionField (type, slot) maps 1:1 на слот Item
- Имя айтема — простая строка, санитизируется (trim, control-символы, whitelist)
- Лимит слотов общий: `SlotLimits::MAX_SLOTS_PER_TYPE` (Domain/Common)

### Tag (`src/Domain/Tag/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Tag` | `Entity/Tag.php` | Глобальный тег (id, name, createdAt, updatedAt); имя иммутабельное |
| `TagId` | `ValueObject/TagId.php` | Бинарный UUID |
| `TagName` | `ValueObject/TagName.php` | Имя тега (нормализация, длина 2..30) |
| `TagRepositoryInterface` | `Repository/TagRepositoryInterface.php` | Интерфейс репозитория (`save`, `remove`, `findById`, `findByName`, `getOrCreate`) |

**Бизнес-правила:**
- Теги глобальные (без владельца), переиспользуются между пользователями
- Уникальность `name` регистронезависима (UNIQUE + коллация `utf8mb4_0900_ai_ci`); хранится регистр первого ввода
- `TagName`: trim, strip control-символов, collapse whitespace, длина 2..30, whitelist как у `FieldName`
- Связь с Item — many-to-many через `item_tags` (оба FK `ON DELETE CASCADE`)

### Like (`src/Domain/Like/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Like` | `Entity/Like.php` | Отметка «нравится» (id, owner, item, createdAt); owner/item — `ManyToOne` с `ON DELETE CASCADE` |
| `LikeId` | `ValueObject/LikeId.php` | Бинарный UUID |
| `LikeRepositoryInterface` | `Repository/LikeRepositoryInterface.php` | Интерфейс репозитория (`save`, `remove`, `findById`, `findByOwnerAndItem`, `findByItemId`, `countByItemId`) |

**Бизнес-правила:**
- Один лайк на пару (пользователь, айтем): UNIQUE `uniq_like_owner_item(owner_id, item_id)`; двойной лайк → `UniqueConstraintViolation`
- `idx_like_item(item_id)` — списки лайков айтема; отдельный owner-индекс не нужен (UNIQUE покрывает префикс `owner_id`)
- Удаление айтема/пользователя каскадно удаляет лайки

### Comment (`src/Domain/Comment/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Comment` | `Entity/Comment.php` | Комментарий (id, owner, item, content, createdAt, updatedAt); owner/item — `ManyToOne` с `ON DELETE CASCADE` |
| `CommentId` | `ValueObject/CommentId.php` | Бинарный UUID |
| `CommentContent` | `ValueObject/CommentContent.php` | Markdown-текст (длина 1..3000; нормализация `\r\n`→`\n`, strip control-символов кроме `\n`/`\t`, trim краёв; внутренние пробелы/переносы сохраняются) |
| `CommentRepositoryInterface` | `Repository/CommentRepositoryInterface.php` | Интерфейс репозитория (`save`, `remove`, `findById`, `findByItemId`, `findByOwnerId`, `countByItemId`, `countByOwnerId`) |

**Бизнес-правила:**
- Один пользователь может комментировать айтем многократно — **UNIQUE нет**
- Индексы композитные под сортировку: `idx_comment_item(item_id, created_at, id)` и `idx_comment_owner(owner_id, created_at, id)` → индекс обслуживает `ORDER BY created_at, id` без filesort (`id` указан явно; InnoDB добавляет PK и так)
- Правка `changeContent()` + `touch()`: `updatedAt` меняется, no-op при нормализованно равном контенте
- Удаление айтема/пользователя каскадно удаляет комментарии
- Контент хранится как есть (markdown); рендеринг/экранирование — забота фронтенда

## Связи

```
User 1 ──── * Collection
Collection 1 ──── * CollectionField
Collection 1 ──── * Item
Item * ──── * Tag   (item_tags)
User 1 ──── * Like
Item 1 ──── * Like   (UNIQUE owner_id + item_id)
User 1 ──── * Comment
Item 1 ──── * Comment
```

## Application Services

| Сервис | Путь | Use Case |
|--------|------|----------|
| `RegistrationService` | `src/Application/User/Service/RegistrationService.php` | Регистрация пользователя |
| `AuthenticationService` | `src/Application/User/Service/AuthenticationService.php` | Аутентификация (login), JWT |
| `TagService` | `src/Application/Tag/Service/TagService.php` | `resolveByNames` — нормализация/дедуп имён, найти-или-создать тег (flush у вызывающего); `listTags` — список/поиск по подстроке (ci) |
| `ItemService` | `src/Application/Item/Service/ItemService.php` | CRUD айтемов + списки; слоты через `ItemSlotMapper`, теги через `TagService`; create/update в `uow->transactional()`, delete — remove+flush |
| `ItemSlotMapper` | `src/Application/Item/Service/ItemSlotMapper.php` | Коэрсия `{type, slot, value}` → слоты Item: date ISO-8601 (Z/милли/микро, UTC-нормализация), number→float, bool, text; `null` очищает слот |
| `LikeService` | `src/Application/Like/Service/LikeService.php` | Лайки: `like`/`unlike` (идемпотентно), `toggle` (возвращает новое состояние), `isLikedBy`, `countByItem`, `listByItem`, `removeLike` (админский путь), `toDTO`/`toDTOList`; мутации — `save`/`remove` + `uow->flush()` |
| `CommentService` | `src/Application/Comment/Service/CommentService.php` | Комментарии: `create`, `getById` (`?Comment` → 404), `changeContent` (no-op без flush), `delete`, `listByItem`/`listByOwner` (пагинация), `countByItem`/`countByOwner`, `toDTO`/`toDTOList`; авторизация — на контроллере (5.5) |

**Транзакции:** `UnitOfWorkInterface` (`src/Application/Common/Transaction/`) — `flush()` и `transactional(callable): mixed` (граница транзакции на уровне Application). Реализация `DoctrineUnitOfWork` (`src/Infrastructure/Common/Transaction/`) делегирует `EntityManager::wrapInTransaction`. ItemService create/update обёрнуты в `transactional()`; `getOrCreate` (raw upsert) делит DBAL-соединение EM → атомарность тегов+item; вложенные вызовы безопасны (savepoints).

**DTO:**
- `RegisterUserDTO` — name, email, password
- `LoginUserDTO` — email, password
- `LoginResult` — accessToken, tokenType, expiresIn, user
- `CreateItemDTO` / `UpdateItemDTO` — name, tags, slots `array<ItemSlotDTO>`; обновление: теги replace по TagId, слоты частично (`null` очищает)
- `ItemDTO` — id, name, tags `array<TagDTO>`, слоты только заполненные, collection_id, timestamps (ATOM)
- `ItemSlotDTO` — type, slot (1..`SlotLimits::MAX_SLOTS_PER_TYPE`), value
- `TagDTO` — id, name
- `LikeDTO` — id, owner_id, owner_name, item_id, created_at (ATOM)
- `CommentDTO` — id, owner_id, owner_name, item_id, content, created_at, updated_at (ATOM)
- `CommentRequestDTO` — content (Application/Comment/DTO; тонкий, валидация в `CommentContent` → 422) 
- `ItemDetailDTO` — `ItemDTO::toArray()` + likes_count, comments_count, liked_by_me (только `GET /api/items/{id}`)

## Infrastructure

| Компонент | Путь | Описание |
|-----------|------|----------|
| `LoginController` | `src/Infrastructure/Api/Controller/LoginController.php` | POST /api/login |
| `AbstractApiController` | `src/Infrastructure/Api/Controller/AbstractApiController.php` | Базовый класс API-контроллеров: `deserializeAndValidate`, `createValidationErrorResponse` (тонкий делегат `unprocessable('Validation failed', …)` — литерал централизован в нём, форма байт-в-байт равна прямому вызову), `toArrayPayload`, `findItemOrNull`, `parsePagination`, хелперы конвертов ошибок (`errorResponse`, `unauthorized`, `notFound`, `forbidden`, `badRequest`, `unprocessable`, `conflict`), `canManage` + `denyUnlessCanManage` (бросает `AccessDeniedException`; owner-or-admin для Item; соцконтент — через Voter + `denyAccessUnlessGranted`) |
| `LogoutController` | `src/Infrastructure/Api/Controller/LogoutController.php` | POST /api/logout |
| `RegistrationController` | `src/Infrastructure/Api/Controller/RegistrationController.php` | POST /api/register |
| `CollectionController` | `src/Infrastructure/Api/Controller/CollectionController.php` | CRUD коллекций; `GET /api/collections` с `?owner={uuid}` (чужие коллекции, двоичный UUID через `IDENTITY`); `GET /api/collections/{id}` — любой аутентифицированный (D1); non-scalar `?owner` (`?owner[]=x`) returns 400 with the API envelope (fwd-25); `ApiExceptionSubscriber` мини-этапа 5A не перемапливает 400 на query-параметрах |
| `ItemController` | `src/Infrastructure/Api/Controller/ItemController.php` | CRUD айтемов + списки: `POST /api/collections/{id}/items`, `GET /api/collections/{id}/items` и `GET /api/items` (свои) с фильтрами `?name` (LIKE, ci) и `?tags[]` (AND), `GET/PATCH/DELETE /api/items/{id}`. Чтение — любой аутентифицированный (D1 Этапа 5); запись — владелец+админ; слоты/теги `\InvalidArgumentException` → 400. `GET /api/items/{id}` отдаёт `ItemDetailDTO` (+`likes_count`/`comments_count`/`liked_by_me`). Карта ошибок (5.9, единая через хелперы): нет токена → 401, id/не найдено → 404, не автор/не админ → 403, битый JSON или `limit`/`offset` → 400, семантически невалидный контент (слот/пустой PATCH) → 422. Не-скалярный query-параметр (`?name[]=x`, `?tags=x`, `?limit[]=1`) отдаёт 400 нашим конвертом — чтение идёт через сырой массив `$request->query->all()`, потому что `InputBag::get()` бросает фреймворковый `BadRequestException` (fwd-25); `ApiExceptionSubscriber` мини-этапа 5A не перемапливает 400 на query-параметрах |
| `LikeController` | `src/Infrastructure/Api/Controller/LikeController.php` | Лайки: `POST`/`DELETE /api/items/{id}/likes` (идемпотентно, `{likes_count}`), `GET /api/items/{id}/likes` (пагинация), `GET /api/likes` (свои, пагинация), `DELETE /api/likes/{id}` — автор или админ (PRD 118) |
| `CommentController` | `src/Infrastructure/Api/Controller/CommentController.php` | Комментарии: `POST`/`GET /api/items/{id}/comments`, `GET /api/comments` (свои), `PATCH`/`DELETE /api/comments/{id}` и вложенный `DELETE /api/items/{itemId}/comments/{id}` — автор или админ (PRD 117). Карта ошибок: id → 404, контент → 422, тело → 400 (`CommentRequestDTO` в Application) |
| `DoctrineUserRepository` | `src/Infrastructure/User/Repository/DoctrineUserRepository.php` | Реализация репозитория User |
| `DoctrineCollectionFieldRepository` | `src/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepository.php` | Реализация репозитория CollectionField |
| `DoctrineTagRepository` | `src/Infrastructure/Tag/Repository/DoctrineTagRepository.php` | Реализация репозитория Tag; `getOrCreate` — атомарный MySQL upsert; `search` — подстрока `LIKE` (ci, wildcards-escaped), `ORDER BY name` |
| `TagController` | `src/Infrastructure/Api/Controller/TagController.php` | `GET /api/tags` — список/поиск тегов (?search ci-подстрока, ?limit, ?offset); auth-only, bare `TagDTO[]`; non-scalar `?search` returns 400 with the API envelope (fwd-25); `ApiExceptionSubscriber` мини-этапа 5A не перемапливает 400 на query-параметрах |
| `DoctrineItemRepository` | `src/Infrastructure/Item/Repository/DoctrineItemRepository.php` | Реализация репозитория Item; все read-методы JOIN FETCH `i.collection -> collection.owner` (final-сущности не проксируются ORM 3); `IDENTITY`-сравнение бинарного UUID |
| `UserProvider` | `src/Infrastructure/Security/UserProvider.php` | Symfony Security user provider |
| `SocialContentVoter` | `src/Infrastructure/Security/Voter/SocialContentVoter.php` | Модерация соц. контента: `SOCIAL_EDIT`/`SOCIAL_DELETE` для `Comment`/`Like`, автор или админ; `Like`+`SOCIAL_EDIT` запрещён всем. Регистрируется autoconfigure (тег `security.voter`). Требует гидрированный `owner` (репозитории JOIN FETCH). Отказ конвертируется `ApiExceptionSubscriber` в 403-конверт (ручных JSON-403 в контроллерах нет; `LoginController:125` «аккаунт деактивирован» — бизнес-факт, остаётся ручным) |
| `JwtAuthenticationFailureSubscriber` | `src/Infrastructure/Api/EventSubscriber/JwtAuthenticationFailureSubscriber.php` | Единый 401-конверт для трёх путей отказа Lexik (`JWT_NOT_FOUND`/`JWT_INVALID`/`JWT_EXPIRED`): `{error: 'Unauthorized', message: <текст Lexik>}`, заголовок `WWW-Authenticate: Bearer` сохраняется. Два пути из трёх не доходят до `kernel.exception` (аутентификатор возвращает готовый `Response`), поэтому это единственная точка покрытия всех трёх |
| `ApiExceptionSubscriber` | `src/Infrastructure/Api/EventSubscriber/ApiExceptionSubscriber.php` | Единый конверт для `/api`-ошибок на `kernel.exception`, приоритет **-10** (после security-`ExceptionListener` (1) и `logKernelException` (0), до рендера страницы ошибки (-128); ответ останавливает распространение через `setResponse()`). `AccessDeniedHttpException` → 403-конверт (generic, без `getMessage()`); прочий `HttpExceptionInterface` и уже отвеченные события — no-op; остальное → голый 500-конверт без `message` и без своего лога (логирует фреймворк). Только пути `^/api`, кроме `/api/doc*` (документация сохраняет фреймворковое поведение) |
| `ClockInjectListener` | `src/Infrastructure/Doctrine/Listener/ClockInjectListener.php` | Автоинъекция Clock в сущности |
| `MonologBundle` | `config/packages/monolog.yaml` | Логирование (5.32): каналы `app`/`deprecation`/`request`, обработчики по окружениям (dev — файл + stderr, prod — JSON в stderr, test — null); необработанные исключения приходят в `request` |

**Контракт ошибок API (мини-этап 5A):**
- Канонический конверт — `{error, message?, details?}`. `401` всегда с `message` (текст Lexik или guard), `403` — generic `{error: 'Forbidden', message: 'Forbidden'}` без деталей исключения, `500` — голый `{error: 'Internal Server Error'}` без `message`.
- **`error` — всегда HTTP reason phrase статуса** (D10, PR-3): `400 Bad Request`, `401 Unauthorized`, `403 Forbidden`, `404 Not Found`, `409 Conflict`, `422 Unprocessable Entity`. Единственный исторический выброс (`'Validation failed'` в `error`) закрыт: 422 всегда `{error: 'Unprocessable Entity', message: <string>, details?: string[]}`, а `'Validation failed'` живёт в `message` DTO-валидационного пути (`createValidationErrorResponse()` — тонкий делегат `unprocessable('Validation failed', $errors)`, литерал централизован в нём, форма байт-в-байт равна прямому вызову).
- **`details` — человекочитаемый диагноз, не машинный enum** (D11, PR-3): формат `string[]`, стабильны структура и триггер (422/400), содержимое — английские авторские фразы. Запрещено в `details`: debug-лексика (`get_debug_type()`), неограниченное эхо пользовательского ввода (закрыты 8 + 1 мест в `Item`/`ItemSlotMapper`), дефолтные формулировки Symfony Validator (все `Assert\Length` в Application DTO несут `maxMessage`; доменные `Assert\*`-атрибуты инертны — валидатор вызывается только на DTO в `deserializeAndValidate`, поэтому их дефолты никогда не попадают в ответы). Разрешены как ограниченная диагностика: счётчики (`got %d chars`) и отклонённое enum-значение (`Invalid theme: {{ value }}` в `CreateCollectionDTO:23` — осознанное исключение: значение короткое и говорит клиенту, что именно отвергнуто).
- Приоритеты на `kernel.exception`: security-`ExceptionListener` (1) → `logKernelException` (0) → `ApiExceptionSubscriber` (-10) → `ErrorListener::onKernelException` (-128). Свой listener ставить только в интервал (-128, 1): раньше security — увидит исходный `AccessDeniedException`, позже — уже переписанный `AccessDeniedHttpException`.
- `setResponse()` останавливает распространение (`RequestEvent`), `setThrowable()` — нет. Поэтому уже отвеченные события (`getResponse() !== null`) и `HttpExceptionInterface` — no-op, иначе 500-конверт перекроет легитимный 401/403.
- `500` логирует только фреймворк (`logKernelException`, `['exception' => $throwable]`) — в канал `request`, не `app`. Свой лог в subscriber запрещён (двойная запись). Локальные `catch (\Throwable)` с ручным 500 запрещены — исключение обязано дойти до `kernel.exception`.

**Логирование (5.32).** `symfony/monolog-bundle` ^3.11, `logger` → `monolog.logger` (канал `app`). Объявлены каналы `app`, `deprecation` и `request`; **необработанные исключения фреймворк пишет в `request`** (его `ErrorListener` получает логгер этого канала, а не `app`), поэтому ассерты на 500-лог в тестах вешаются на `monolog.logger.request`, а сервиса `monolog.logger.app` не существует. Обработчики по окружениям: `dev` — `main` в `var/log/dev.log` (debug) плюс оперативное зеркало в `php://stderr` (info, без каналов `event`/`deprecation`/`console`), `prod` — буферизованный handler с JSON-форматтером в stderr, `test` — `type: null`: suite не пишет логи, тесты прикрепляют `TestHandler`. Логи не коммитятся (`.gitignore` перекрывает `var/log` правилом ниже), а сам `var/log` — анонимный volume (`docker-compose.yml:11`), поэтому читать логи нужно из контейнера. 4xx-конверты наших контроллеров (`400`/`404`) строятся напрямую и до `kernel.exception` не доходят; `403` доходит и логируется. Свой `LoggerInterface` в контроллеры не инжектится — решение fwd-21 остаётся в силе (monolog дал autowiring, но потребности нет).

**Doctrine Types:**
- `RoleEnumType` — маппинг RoleEnum
- `ThemeEnumType` — маппинг ThemeEnum
- `FieldTypeEnumType` — маппинг FieldTypeEnum

**Сериализатор/OpenAPI:**
- `PhpDocExtractor` добавлен в цепочку `property_info.type_extractor` (`config/services.yaml`) — вложенные DTO-массивы (`CreateItemDTO::slots` как `ItemSlotDTO[]`) денормализуются из `@param`-докблоков.
- Схема `Item` для OpenAPI определена в `config/packages/dev/api_doc.yaml` (nelmio перезаписывает `components` из конфига — swagger-php-компоненты теряются; поэтому не в `#[OA\Schema]`).

## CI/CD Pipeline

```
GitHub Actions
├── PHP CS Fixer (lint)
├── PHPStan (static analysis)
├── Rector (dry-run)
├── PHPUnit (tests + coverage gate ≥ 80%, каждая ветка и main)
├── Composer Audit (security, hard gate)
├── Symfony Diagnostics (`symfony-lsp check`) — blocking gate (5.14): один runtime-прогон с `--environment=test` (source-only снят в 5.17 — parity-зонд показал, что он не даёт диагностик; повторный зонд на бампе 0.23.0 в 5.26 дал тот же результат, дрейфа диагностик между 0.21.0 и 0.23.0 ноль), вердикт из JSON-артефакта выносит `scripts/symfony-lsp-gate.php` (`complete` + `runtime.state` + `blocking`, не exit-код и не второй прогон), в `needs:` у `ci-summary`, GitHub-аннотации (задачи 5.13/5.17/5.26). Дрейф версии бинарника (Dependabot скачиваемый бинарник не видит) отслеживает отдельный advisory-workflow `dependency-drift` (еженедельно + вручную): `scripts/check-tool-version-drift.sh` сверяет оба якоря пина с новым релизом апстрима, только `::warning::`, в гейт не входит (5.14 PR-B)
├── Required status checks on `main` (5.18, перенесено на ruleset в 5.27): ruleset `main-protection` (id 18929859) — `OpenRabbit Review` + `CI Summary` (summary агрегирует четыре блокирующих job'а — `symfony-diagnostics` в нём с 5.14), bypass владельцем только на PR (`pull_request`; прямой push в `main` невозможен) — мерж без зелёного ревью технически невозможен; новый job надо вручную добавить в `needs:` (таблицу `ci-summary` строит сам из `toJSON(needs)`, второй правки больше нет)
├── Deprecations (`--display-deprecations` в composer-скрипте `phpunit`, то есть в том же прогоне, что и coverage-гейт): депрекейшен **роняет** прогон через `failOnDeprecation="true"` в `phpunit.xml.dist` (с 5.25), а не только печатается — bridge-обработчик не регистрируется начиная с PHPUnit 10 (`bootstrap.php:16-18`), поэтому никакая bridge-переменная в проекте ничего не переключает. Дополнительно шаг грепает `triggered N deprecation` → `::warning::` в summary: аннотация advisory, блокировку обеспечивает атрибут. Переменная `SYMFONY_DEPRECATIONS_HELPER` не используется (удалена в fwd-29, `PRD/fwd-29-remove-bridge-env-var.md`); охраняется гардом `DeprecationConfigTest::testPhpunitConfigDoesNotCarryTheInertBridgeEnvVar` от повторного появления. Второй, coverage-less прогон suite удалён в 5.30: он стоил те же 269 с, что и прогон с покрытием (272 с), и сигнала не давал. Формат строки зондирует отдельный шаг 5.31 (`scripts/deprecation-format-probe.sh`: паттерн извлекается из grep'а шага, две фикстуры `tests/Fixtures/Deprecation/` — триггер/чистая; в suite не кладём: дочерний прогон 5.8 с; детали — `PRD/5.31-deprecation-format-probe.md`)
└── AI Code Review — OpenRabbit, summary + inline comments (PR only, non-draft; таймаут 35 мин: худший путь 12 + 15 + 2 + 3 = 32, запас ~2.5 мин; пошаговые границы есть и у обоих API-шагов — 5.29; `cancel-in-progress: true` — ревьюится только последний head, `cancelled` = вердикта нет):
    OpenRouter free pool (`openrouter/free`, секрет `LLM_API_KEY`) — основной, фолбэк — NVIDIA NIM (`openai/gpt-oss-20b`, https://integrate.api.nvidia.com/v1, секрет `NVIDIA_API_KEY`). Оба провайдера — **один и тот же экшен** `aryanbrite/openrabbit@v0.8.7`, меняются только ключ, эндпоинт и модель, поэтому режим отказа «экшен молча ничего не публикует» наследуется фолбэком; спасает не другой код, а другой провайдер и ключ.
    Шаг `Check whether the primary provider published a verdict` (5.28) — probe-режим того же скрипта: пишет `published|missing|unverified` в `$GITHUB_OUTPUT` и комментариев не пишет; фолбэк запускается по `!= 'published'` (fail-open: `missing`, `unverified`, незаписанный output), потому что экшен умеет выйти с кодом 0, ничего не опубликовав (PR #84, #87) — до 5.28 условие смотрело на код выхода, и NIM не запускался ни разу
    Финальный шаг `Verify a verdict was published for this head` (5.19A) — advisory: проверяет, что review бота существует на текущем head SHA, иначе sticky-комментарий + `::warning::`; job не роняет, потому что `### Verdict` не контракт с третьей стороной, а required-чек, упавший на дрейфе формулировки, не позеленеет никогда (детекция `cancelled`/`timed_out` изнутри job'а невозможна, и не нужна: такие прогоны роняют required-чек `OpenRabbit Review` — 5.19B закрыта как устаревшая 2026-09-25)
    Наблюдаемая структура затрат — только в PRD/5.20-review-timing-measurement.md (канон, 30 прогонов 2026-09-25…27): обычный job — десятки секунд, хвост даёт фолбэк NIM, run-level максимум — очередь раннера. Повторный замер: `bash scripts/review-timing-report.sh <run-id>`
```

**Локально:** `docker compose exec app composer ci:all`

**Переводы строк (5.21).** `.gitattributes` объявляет `* text=auto eol=lf`: атрибут сильнее локального `core.autocrlf`, поэтому индекс, рабочая копия на любой ОС и CI видят одни и те же LF-байты — иначе php-cs-fixer помечал whole-file диффы, регекс-тесты на `\n` падали только на Windows, а `*.sh` ломался на `set -euo pipefail\r`. `.editorconfig` дублирует это как подсказку редакторам, `line_ending` в php-cs-fixer — второй эшелон для файлов, попавших мимо git. Ручная нормализация перед `ci:all` больше не требуется.

## Инфраструктура (Docker)

| Сервис | Порт | Описание |
|--------|------|----------|
| nginx | 8000 (host) | Веб-сервер, проксирует в app |
| app | — (внутренний) | Symfony приложение (PHP) |
| db | 3306 | MySQL |
| redis | 6379 | Redis (кэш, очереди) |
| meilisearch | 7700 | Поиск |

## Текущий статус

| Этап | Описание | Статус |
|------|----------|--------|
| 1 | Инфраструктура | ✅ завершён |
| 2 | Пользователь | ✅ завершён |
| 3 | Коллекция | ✅ завершён |
| 4 | Айтем | ✅ завершён (4.1–4.7) |
| 5 | Социальное | 🔄 core готов (5.1–5.6, 5.10); 5.11 закрыта; smoke test выполнен 20.09; этап не закрыт: периодический review — в работе |
| 6 | Поиск | ⏳ |
| 7 | Админ | ⏳ |
| 8 | Тестирование | ⏳ |

---

*Обновлять при архитектурных изменениях.*
