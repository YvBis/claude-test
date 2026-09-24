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
| `AbstractApiController` | `src/Infrastructure/Api/Controller/AbstractApiController.php` | Базовый класс API-контроллеров: `deserializeAndValidate`, `createValidationErrorResponse`, `toArrayPayload`, `findItemOrNull`, `parsePagination`, хелперы конвертов ошибок (`errorResponse`, `unauthorized`, `notFound`, `forbidden`, `badRequest`, `unprocessable`, `conflict`, `internalError`), `canManage` (owner-or-admin для Item/Collection; соцконтент — через Voter) |
| `LogoutController` | `src/Infrastructure/Api/Controller/LogoutController.php` | POST /api/logout |
| `RegistrationController` | `src/Infrastructure/Api/Controller/RegistrationController.php` | POST /api/register |
| `CollectionController` | `src/Infrastructure/Api/Controller/CollectionController.php` | CRUD коллекций; `GET /api/collections` с `?owner={uuid}` (чужие коллекции, двоичный UUID через `IDENTITY`); `GET /api/collections/{id}` — любой аутентифицированный (D1) |
| `ItemController` | `src/Infrastructure/Api/Controller/ItemController.php` | CRUD айтемов + списки: `POST /api/collections/{id}/items`, `GET /api/collections/{id}/items` и `GET /api/items` (свои) с фильтрами `?name` (LIKE, ci) и `?tags[]` (AND), `GET/PATCH/DELETE /api/items/{id}`. Чтение — любой аутентифицированный (D1 Этапа 5); запись — владелец+админ; слоты/теги `\InvalidArgumentException` → 400. `GET /api/items/{id}` отдаёт `ItemDetailDTO` (+`likes_count`/`comments_count`/`liked_by_me`). Карта ошибок (5.9, единая через хелперы): нет токена → 401, id/не найдено → 404, не автор/не админ → 403, битый JSON или `limit`/`offset` → 400, семантически невалидный контент (слот/пустой PATCH) → 422 |
| `LikeController` | `src/Infrastructure/Api/Controller/LikeController.php` | Лайки: `POST`/`DELETE /api/items/{id}/likes` (идемпотентно, `{likes_count}`), `GET /api/items/{id}/likes` (пагинация), `GET /api/likes` (свои, пагинация), `DELETE /api/likes/{id}` — автор или админ (PRD 118) |
| `CommentController` | `src/Infrastructure/Api/Controller/CommentController.php` | Комментарии: `POST`/`GET /api/items/{id}/comments`, `GET /api/comments` (свои), `PATCH`/`DELETE /api/comments/{id}` и вложенный `DELETE /api/items/{itemId}/comments/{id}` — автор или админ (PRD 117). Карта ошибок: id → 404, контент → 422, тело → 400 (`CommentRequestDTO` в Application) |
| `DoctrineUserRepository` | `src/Infrastructure/User/Repository/DoctrineUserRepository.php` | Реализация репозитория User |
| `DoctrineCollectionFieldRepository` | `src/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepository.php` | Реализация репозитория CollectionField |
| `DoctrineTagRepository` | `src/Infrastructure/Tag/Repository/DoctrineTagRepository.php` | Реализация репозитория Tag; `getOrCreate` — атомарный MySQL upsert; `search` — подстрока `LIKE` (ci, wildcards-escaped), `ORDER BY name` |
| `TagController` | `src/Infrastructure/Api/Controller/TagController.php` | `GET /api/tags` — список/поиск тегов (?search ci-подстрока, ?limit, ?offset); auth-only, bare `TagDTO[]` |
| `DoctrineItemRepository` | `src/Infrastructure/Item/Repository/DoctrineItemRepository.php` | Реализация репозитория Item; все read-методы JOIN FETCH `i.collection -> collection.owner` (final-сущности не проксируются ORM 3); `IDENTITY`-сравнение бинарного UUID |
| `UserProvider` | `src/Infrastructure/Security/UserProvider.php` | Symfony Security user provider |
| `SocialContentVoter` | `src/Infrastructure/Security/Voter/SocialContentVoter.php` | Модерация соц. контента: `SOCIAL_EDIT`/`SOCIAL_DELETE` для `Comment`/`Like`, автор или админ; `Like`+`SOCIAL_EDIT` запрещён всем. Регистрируется autoconfigure (тег `security.voter`). Требует гидрированный `owner` (репозитории JOIN FETCH). Ручной JSON-403 в контроллерах сохранён |
| `ClockInjectListener` | `src/Infrastructure/Doctrine/Listener/ClockInjectListener.php` | Автоинъекция Clock в сущности |

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
├── Symfony Diagnostics (`symfony-lsp check`) — пилот, non-blocking: один runtime-прогон с `--environment=test` (source-only снят в 5.17 — parity-зонд показал, что он не даёт диагностик), GitHub-аннотации, мерж не блокирует (задачи 5.13/5.17; блокирующим делает 5.14)
├── Required status checks on `main` (5.18): `OpenRabbit Review` + `CI Summary` (summary агрегирует три блокирующих job'а; `symfony-diagnostics` вне него осознанно — 5.14), `enforce_admins: true` — мерж без зелёного ревью технически невозможен; новый job надо вручную добавить в `needs:` и `STATUS`, иначе он вне гейта
├── Deprecations (`--display-deprecations` в composer-скрипте `phpunit`, то есть в том же прогоне, что и coverage-гейт) — advisory: нативный механизм PHPUnit 11 (bridge-обработчик на 11 не регистрируется), шаг грепает `triggered N deprecation` → `::warning::`, мерж не блокирует; жёсткий гейт `--fail-on-deprecation` — задача 5.25. Второй, coverage-less прогон suite удалён в 5.30: он стоил те же 269 с, что и прогон с покрытием (272 с), и сигнала не давал
└── AI Code Review — OpenRabbit, summary + inline comments (PR only, non-draft; таймаут 35 мин: худший путь 12 + 15 + 2 + 3 = 32, запас ~2.5 мин; пошаговые границы есть и у обоих API-шагов — 5.29; `cancel-in-progress: true` — ревьюится только последний head, `cancelled` = вердикта нет):
    OpenRouter free pool (`openrouter/free`, секрет `LLM_API_KEY`) — основной, фолбэк — NVIDIA NIM (`openai/gpt-oss-20b`, https://integrate.api.nvidia.com/v1, секрет `NVIDIA_API_KEY`). Оба провайдера — **один и тот же экшен** `aryanbrite/openrabbit@v0.8.7`, меняются только ключ, эндпоинт и модель, поэтому режим отказа «экшен молча ничего не публикует» наследуется фолбэком; спасает не другой код, а другой провайдер и ключ.
    Шаг `Check whether the primary provider published a verdict` (5.28) — probe-режим того же скрипта: пишет `published|missing|unverified` в `$GITHUB_OUTPUT` и комментариев не пишет; фолбэк запускается по `!= 'published'` (fail-open: `missing`, `unverified`, незаписанный output), потому что экшен умеет выйти с кодом 0, ничего не опубликовав (PR #84, #87) — до 5.28 условие смотрело на код выхода, и NIM не запускался ни разу
    Финальный шаг `Verify a verdict was published for this head` (5.19A) — advisory: проверяет, что review бота существует на текущем head SHA, иначе sticky-комментарий + `::warning::`; job не роняет, потому что `### Verdict` не контракт с третьей стороной, а required-чек, упавший на дрейфе формулировки, не позеленеет никогда (детекция `cancelled`/`timed_out` изнутри job'а невозможна — 5.19B)
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
