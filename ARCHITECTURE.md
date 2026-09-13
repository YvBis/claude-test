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

## Связи

```
User 1 ──── * Collection
Collection 1 ──── * CollectionField
Collection 1 ──── * Item
Item * ──── * Tag   (item_tags)
```

## Application Services

| Сервис | Путь | Use Case |
|--------|------|----------|
| `RegistrationService` | `src/Application/User/Service/RegistrationService.php` | Регистрация пользователя |
| `AuthenticationService` | `src/Application/User/Service/AuthenticationService.php` | Аутентификация (login), JWT |
| `TagService` | `src/Application/Tag/Service/TagService.php` | `resolveByNames` — нормализация/дедуп имён, найти-или-создать тег (flush у вызывающего) |
| `ItemService` | `src/Application/Item/Service/ItemService.php` | CRUD айтемов + списки; слоты через `ItemSlotMapper`, теги через `TagService`; create/update в `uow->transactional()`, delete — remove+flush |
| `ItemSlotMapper` | `src/Application/Item/Service/ItemSlotMapper.php` | Коэрсия `{type, slot, value}` → слоты Item: date ISO-8601 (Z/милли/микро, UTC-нормализация), number→float, bool, text; `null` очищает слот |

**Транзакции:** `UnitOfWorkInterface` (`src/Application/Common/Transaction/`) — `flush()` и `transactional(callable): mixed` (граница транзакции на уровне Application). Реализация `DoctrineUnitOfWork` (`src/Infrastructure/Common/Transaction/`) делегирует `EntityManager::wrapInTransaction`. ItemService create/update обёрнуты в `transactional()`; `getOrCreate` (raw upsert) делит DBAL-соединение EM → атомарность тегов+item; вложенные вызовы безопасны (savepoints).

**DTO:**
- `RegisterUserDTO` — name, email, password
- `LoginUserDTO` — email, password
- `LoginResult` — accessToken, tokenType, expiresIn, user
- `CreateItemDTO` / `UpdateItemDTO` — name, tags, slots `array<ItemSlotDTO>`; обновление: теги replace по TagId, слоты частично (`null` очищает)
- `ItemDTO` — id, name, tags `array<TagDTO>`, слоты только заполненные, collection_id, timestamps (ATOM)
- `ItemSlotDTO` — type, slot (1..`SlotLimits::MAX_SLOTS_PER_TYPE`), value
- `TagDTO` — id, name

## Infrastructure

| Компонент | Путь | Описание |
|-----------|------|----------|
| `LoginController` | `src/Infrastructure/Api/Controller/LoginController.php` | POST /api/login |
| `LogoutController` | `src/Infrastructure/Api/Controller/LogoutController.php` | POST /api/logout |
| `RegistrationController` | `src/Infrastructure/Api/Controller/RegistrationController.php` | POST /api/register |
| `CollectionController` | `src/Infrastructure/Api/Controller/CollectionController.php` | CRUD коллекций; `GET /api/collections` с `?owner={uuid}` (чужие коллекции, двоичный UUID через `IDENTITY`) |
| `ItemController` | `src/Infrastructure/Api/Controller/ItemController.php` | CRUD айтемов + списки: `POST /api/collections/{id}/items`, `GET /api/collections/{id}/items` и `GET /api/items` (свои) с фильтрами `?name` (LIKE, ci) и `?tags[]` (AND), `GET/PATCH/DELETE /api/items/{id}`. Доступ владелец+админ (`User::getRole()->isAdmin()`); слоты/теги `\InvalidArgumentException` → 400 |
| `DoctrineUserRepository` | `src/Infrastructure/User/Repository/DoctrineUserRepository.php` | Реализация репозитория User |
| `DoctrineCollectionFieldRepository` | `src/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepository.php` | Реализация репозитория CollectionField |
| `DoctrineTagRepository` | `src/Infrastructure/Tag/Repository/DoctrineTagRepository.php` | Реализация репозитория Tag; `getOrCreate` — атомарный MySQL upsert |
| `DoctrineItemRepository` | `src/Infrastructure/Item/Repository/DoctrineItemRepository.php` | Реализация репозитория Item; все read-методы JOIN FETCH `i.collection -> collection.owner` (final-сущности не проксируются ORM 3); `IDENTITY`-сравнение бинарного UUID |
| `UserProvider` | `src/Infrastructure/Security/UserProvider.php` | Symfony Security user provider |
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
└── AI Code Review — OpenRabbit, summary + inline comments (PR only, non-draft):
    OpenRouter free pool (`openrouter/free`), при падении — Groq fallback (`qwen/qwen3.8-27b`)
```

**Локально:** `docker compose exec app composer ci:all`

## Инфраструктура (Docker)

| Сервис | Порт | Описание |
|--------|------|----------|
| app | 8000 | Symfony приложение |
| db | 3306 | MySQL |
| redis | 6379 | Redis (кэш, очереди) |
| meilisearch | 7700 | Поиск |

## Текущий статус

| Этап | Описание | Статус |
|------|----------|--------|
| 1 | Инфраструктура | ✅ завершён |
| 2 | Пользователь | ✅ завершён |
| 3 | Коллекция | ✅ завершён |
| 4 | Айтем | ✅ завершён (4.1, 4.2, 4.3, 4.4) |
| 5 | Социальное | ⏳ |
| 6 | Поиск | ⏳ |
| 7 | Админ | ⏳ |
| 8 | Тестирование | ⏳ |

---

*Обновлять при архитектурных изменениях.*
