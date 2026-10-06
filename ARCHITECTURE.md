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

**Асимметрия ответов по состоянию аккаунта (fwd-31, исключение из политики D7).** На логине неактивный аккаунт отвечает **403** со стабильным `User account is deactivated` — вы предъявили настоящие учётные данные, значит заслужили объяснение. Для уже выданного bearer-токена неактивный или удалённый аккаунт отвечает **401** `User not found`: файрвол `api` stateless, поэтому `UserProvider::loadUserByIdentifier()` перечитывает пользователя на каждом запросе, и заблокированный должен выглядеть как отсутствующий — иначе 401 стал бы оракулом перечисления аккаунтов для держателя токена. Проверка стоит в обоих методах провайдера, но боевой путь — именно `loadUserByIdentifier` (`refreshUser` зовут только сессионные файрволы, которых в проекте нет). Это «отзыв при следующем чтении», а не мгновенный: in-flight запросы не прерываются.

### Collection (`src/Domain/Collection/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Collection` | `Entity/Collection.php` | Коллекция (id, ownerId, name, theme, description, image) |
| `CollectionField` | `Entity/CollectionField.php` | Динамическое поле (id, collection, name, type, slotIndex) |
| `CollectionId` | `ValueObject/CollectionId.php` | Бинарный UUID |
| `CollectionName` | `ValueObject/CollectionName.php` | Название коллекции |
| `Theme` | `ValueObject/Theme.php` | Тема (Books, Games, Movies, Drinks) |
| `FieldType` | `ValueObject/FieldType.php` | Тип поля (text, number, date, bool) |
| `FieldName` | `ValueObject/FieldName.php` | Название поля |
| `OwnerId` | `Common/ValueObject/OwnerId.php` | ID владельца (бинарный UUID); лежит в `Domain\Common`, а не в `Domain\Collection`, потому что им пользуются `Collection`, `Like`, `Comment` и `Item` — иначе fwd-5 убрал бы одну междоменную связь и создал бы пять (review-6, fwd-5) |
| `CollectionFieldRepositoryInterface` | `Repository/CollectionFieldRepositoryInterface.php` | Интерфейс репозитория |

**Владение (fwd-5).** `Collection.owner` — не ассоциация, а колонка `owner_id BINARY(16)`
с аксессором `getOwnerId(): OwnerId`. FK на `users(id)` с `ON DELETE CASCADE` сохранён
в схеме, но объявлен вручную в базовой миграции: ORM о нём не знает, поэтому
`doctrine:schema:update` его снёс бы. Домен `Collection` больше не зависит от `User`.

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
| `Like` | `Entity/Like.php` | Отметка «нравится» (id, ownerId, item, createdAt); **owner** — колонка `owner_id` (FK `ON DELETE CASCADE` объявлен вручную в миграции, ORM-ассоциации нет), **item** — `ManyToOne` с `ON DELETE CASCADE` |
| `LikeId` | `ValueObject/LikeId.php` | Бинарный UUID |
| `LikeRepositoryInterface` | `Repository/LikeRepositoryInterface.php` | Интерфейс репозитория (`save`, `remove`, `findById`, `findByOwnerAndItem`, `findByItemId`, `countByItemId`) |

**Бизнес-правила:**
- Один лайк на пару (пользователь, айтем): UNIQUE `uniq_like_owner_item(owner_id, item_id)`; двойной лайк → `UniqueConstraintViolation`
- `idx_like_item(item_id)` — списки лайков айтема; отдельный owner-индекс не нужен (UNIQUE покрывает префикс `owner_id`)
- Удаление айтема/пользователя каскадно удаляет лайки

### Comment (`src/Domain/Comment/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Comment` | `Entity/Comment.php` | Комментарий (id, ownerId, item, content, createdAt, updatedAt); **owner** — колонка `owner_id` (FK `ON DELETE CASCADE` объявлен вручную в миграции, ORM-ассоциации нет), **item** — `ManyToOne` с `ON DELETE CASCADE` |
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

**Про три ребра от `User` (fwd-5b).** `Collection`/`Like`/`Comment` хранят владельца
колонкой `owner_id BINARY(16)`, а не ORM-ассоциацией: сущности отдают
`getOwnerId(): OwnerId`, а FK на `users(id) ON DELETE CASCADE` объявлен вручную в
миграции, потому что ORM о нём не знает. Поэтому эти три ребра — та же доменная связь,
выраженная колонкой, а не `ManyToOne`: на диаграмме они нарисованы одной нотацией с
ассоциациями ради читаемости. Остальные рёбра
(`Collection → CollectionField/Item`, `Item → Tag/Like/Comment`) — настоящие ассоциации
Doctrine. Подробности: «Владение (fwd-5)» в разделе `Collection` и «Доменные сущности»
для `Like`/`Comment`.

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

`owner_name` в `LikeDTO` и `CommentDTO` — `?string` (fwd-5): после перехода владения
на колонку имя владельца резолвится отдельным батчем
(`UserRepositoryInterface::findNamesByIds()`), и отсутствие строки представимо
впервые. FK на `users(id)` гарантирует, что на реальных данных `null` не бывает,
так что ни один ответ не меняется.

## Авторизация

Авторизация в проекте **не централизована**, и это важно понимать перед чтением
любой строки про «права». Механизмов три, и стратегия из третьего относится
только к одному из них.

| Механизм | Где живёт | Что покрывает |
|----------|-----------|------------|
| Voter через `AccessDecisionManager` | `SocialContentVoter` | `SOCIAL_EDIT` / `SOCIAL_DELETE` для `Comment` и `Like` — решение по атрибуту над загруженной сущностью |
| `canManage` / `denyUnlessCanManage` | `AbstractApiController` | owner-or-admin для **всех** мутаций айтемов и коллекций — делегат к `OwnerAccess::isOwnerOrAdmin()` (fwd-14 → fwd-5); сравнение сырых id, **вне** ADM |
| `access_control` через `AccessDecisionManager` | `config/packages/security.yaml` | гейт по URL+method — `IS_AUTHENTICATED_FULLY` / `PUBLIC_ACCESS` / `IS_AUTHENTICATED_REMEMBERED`; никакой бизнес-логики |

**Все мутации коллекций и айтимов — owner-or-admin через один предикат**
(`OwnerAccess::isOwnerOrAdmin()`, fwd-5; до него был `User::isOwnerOrAdminOf()`,
fwd-14). Предикат живёт в Infrastructure, а не в `Domain\User`, потому что
сравнивает залогиненного пользователя с сырым `OwnerId`, а сущности больше не
носят загруженного владельца — перенести его в домен значило бы снова
инвертировать зависимость. **Исключений нет** (fwd-33 закрыл последнее):
`PATCH /api/collections/{id}` был owner-only инлайном, хотя
`artifacts/prd-taskflow-ru.md:114` уже требовал «Администратор может
модифицировать коллекцию любого пользователя», и после fwd-32 админ мог удалить
чужую коллекцию, но не переименовать её. Новый код не должен писать
owner-сравнение инлайн — путь через `denyUnlessCanManage()`.

**Три механизма — три разных вопроса, а не три способа сделать одно и то же.**
Voter отвечает «как этот атрибут правится для этой сущности», `denyUnlessCanManage` —
«владелец ли ты (или админ)», `access_control` — «кому вообще открыт этот URL».
Поэтому стратегия ADM на owner-or-admin не влияет: тот идёт мимо неё, и смешивать
пути значило бы вернуть `OwnerId` в `Domain\User` — инверсию, которую убрал fwd-5.
Эндпоинты «свой-при-создании» (`POST` коллекции, лайка, комментарий) отдельной
проверки не требуют: владелец там тождественен вызывающему по построению.


**Стратегия `access_decision_manager` — `unanimous`, задана явно (fwd-15).**
Без явной строки бандл молча берёт `AffirmativeStrategy`
(`AccessDecisionManager.php:49`), а при affirmative первый же GRANT другого
вотера перебивает DENY — то есть будущий вотер молча открыл бы редактирование
лайка. `unanimous` возвращает `false` на первом DENY
(`UnanimousStrategy.php:37`), поэтому такой DENY переживает чужой GRANT.
Отвергнутый abstain при этом **не** блокирует (блокирует только DENY), так
что вотер для другого домена может спокойно abstain'ить на социальных
атрибутах. `allow_if_all_abstain` намеренно не задан: дефолт `false` означает,
что «все воздержались» = «запрещено».

Стратегия **не** покрывает owner-or-admin проверки
(`OwnerAccess::isOwnerOrAdmin()`) — они идут мимо ADM. На фоне fwd-33 это больше
не расхождение, а разные механизмы: ADM отвечает за решения вида «атрибут/роль», а
владелец-или-админ — сравнение сырых id, и смешивать их в одном пути значило бы
тащить `OwnerId` в `Domain\User` обратно.

## Infrastructure

| Компонент | Путь | Описание |
|-----------|------|----------|
| `LoginController` | `src/Infrastructure/Api/Controller/LoginController.php` | POST /api/login |
| `AbstractApiController` | `src/Infrastructure/Api/Controller/AbstractApiController.php` | Базовый класс API-контроллеров: `deserializeAndValidate`, `createValidationErrorResponse` (тонкий делегат `unprocessable('Validation failed', …)` — литерал централизован в нём, форма байт-в-байт равна прямому вызову), `toArrayPayload`, `findItemOrNull`, `parsePagination`, хелперы конвертов ошибок (`errorResponse`, `unauthorized`, `notFound`, `forbidden`, `badRequest`, `unprocessable`, `conflict`), `canManage` + `denyUnlessCanManage` (бросает `AccessDeniedException`; owner-or-admin для айтемов **и коллекций**; соцконтент — через Voter + `denyAccessUnlessGranted`) |
| `LogoutController` | `src/Infrastructure/Api/Controller/LogoutController.php` | POST /api/logout |
| `RegistrationController` | `src/Infrastructure/Api/Controller/RegistrationController.php` | POST /api/register |
| `CollectionController` | `src/Infrastructure/Api/Controller/CollectionController.php` | CRUD коллекций; **все мутации — owner-or-admin через `denyUnlessCanManage`** (fwd-33 закрыл последнее исключение, `PATCH` больше не owner-only); `GET /api/collections` с `?owner={uuid}` (чужие коллекции, двоичный UUID через `IDENTITY`); `GET /api/collections/{id}` — любой аутентифицированный (D1); non-scalar `?owner` (`?owner[]=x`) returns 400 with the API envelope (fwd-25); `ApiExceptionSubscriber` мини-этапа 5A не перемапливает 400 на query-параметрах |
| `ItemController` | `src/Infrastructure/Api/Controller/ItemController.php` | CRUD айтемов + списки: `POST /api/collections/{id}/items`, `GET /api/collections/{id}/items` и `GET /api/items` (свои) с фильтрами `?name` (LIKE, ci) и `?tags[]` (AND), `GET/PATCH/DELETE /api/items/{id}`. Чтение — любой аутентифицированный (D1 Этапа 5); запись — владелец+админ; слоты/теги `\InvalidArgumentException` → 400. `GET /api/items/{id}` отдаёт `ItemDetailDTO` (+`likes_count`/`comments_count`/`liked_by_me`). Карта ошибок (5.9, единая через хелперы): нет токена → 401, id/не найдено → 404, не автор/не админ → 403, битый JSON или `limit`/`offset` → 400, семантически невалидный контент (слот/пустой PATCH) → 422. Не-скалярный query-параметр (`?name[]=x`, `?tags=x`, `?limit[]=1`) отдаёт 400 нашим конвертом — чтение идёт через сырой массив `$request->query->all()`, потому что `InputBag::get()` бросает фреймворковый `BadRequestException` (fwd-25); `ApiExceptionSubscriber` мини-этапа 5A не перемапливает 400 на query-параметрах |
| `LikeController` | `src/Infrastructure/Api/Controller/LikeController.php` | Лайки: `POST`/`DELETE /api/items/{id}/likes` (идемпотентно, `{likes_count}`), `GET /api/items/{id}/likes` (пагинация), `GET /api/likes` (свои, пагинация), `DELETE /api/likes/{id}` — автор или админ (PRD 118) |
| `CommentController` | `src/Infrastructure/Api/Controller/CommentController.php` | Комментарии: `POST`/`GET /api/items/{id}/comments`, `GET /api/comments` (свои), `PATCH`/`DELETE /api/comments/{id}` и вложенный `DELETE /api/items/{itemId}/comments/{id}` — автор или админ (PRD 117). Карта ошибок: id → 404, контент → 422, тело → 400 (`CommentRequestDTO` в Application) |
| `DoctrineUserRepository` | `src/Infrastructure/User/Repository/DoctrineUserRepository.php` | Реализация репозитория User; `findNamesByIds()` — батч-выборка отображаемых имён по списку id (fwd-5), один запрос на страницу лайков/комментариев вместо одного на строку |
| `OwnerAccess` | `src/Infrastructure/Security/OwnerAccess.php` | Единственный предикат owner-or-admin (fwd-5, заменил `User::isOwnerOrAdminOf()`). Сравнивает id побайтно, admin-шорткат — здесь; пользуются `AbstractApiController` и `SocialContentVoter` |
| `DoctrineCollectionFieldRepository` | `src/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepository.php` | Реализация репозитория CollectionField |
| `DoctrineTagRepository` | `src/Infrastructure/Tag/Repository/DoctrineTagRepository.php` | Реализация репозитория Tag; `getOrCreate` — атомарный MySQL upsert; `search` — подстрока `LIKE` (ci, wildcards-escaped), `ORDER BY name` |
| `TagController` | `src/Infrastructure/Api/Controller/TagController.php` | `GET /api/tags` — список/поиск тегов (?search ci-подстрока, ?limit, ?offset); auth-only, bare `TagDTO[]`; non-scalar `?search` returns 400 with the API envelope (fwd-25); `ApiExceptionSubscriber` мини-этапа 5A не перемапливает 400 на query-параметрах |
| `DoctrineItemRepository` | `src/Infrastructure/Item/Repository/DoctrineItemRepository.php` | Реализация репозитория Item; все read-методы JOIN FETCH `i.collection` (final-сущности не проксируются ORM 3) — owner с fwd-5 не джойнится, это колонка; фильтр по владельцу — `collection.ownerId` с бинарным биндингом; теги (to-many LAZY) **не** джойнятся — `leftJoin` раздувает строки и ломает пагинацию, вместо этого `initializeTags()` делает второй запрос по id страницы (fwd-6): чтение стоит не более двух запросов вместо одного на айтем |
| `UserProvider` | `src/Infrastructure/Security/UserProvider.php` | Symfony Security user provider |
| `SocialContentVoter` | `src/Infrastructure/Security/Voter/SocialContentVoter.php` | Модерация соц. контента: `SOCIAL_EDIT`/`SOCIAL_DELETE` для `Comment`/`Like`, автор или админ; `Like`+`SOCIAL_EDIT` запрещён всем. Регистрируется autoconfigure (тег `security.voter`). Требует лишь `getOwnerId()` на субъекте — гидрировать владельца не нужно (до fwd-5 репозитории JOIN FETCH'или `owner`). Отказ конвертируется `ApiExceptionSubscriber` в 403-конверт (ручных JSON-403 в контроллерах нет; `LoginController:125` «аккаунт деактивирован» — бизнес-факт, остаётся ручным) |
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
    **Probe идёт с `if: always()`, и это не косметика.** Изначально он был загейчен на `steps.openrouter.outcome == 'success'` — «упавший основной публиковать нечего, проба лишь сожжёт ретраи», — и на этом допущении держалась вся схема фолбэка. На PR #132 замерено: основной провайдер **успешен** за 8 мин и публикует вердикт, а проба всё равно `skipped` (нулевая длительность). Раз `$GITHUB_OUTPUT` не записан, fail-open условие истинно по пустому значению → фолбэк NIM запускается без причины, висит 15 мин и роняет required-чек `OpenRabbit Review`. Воспроизведено на двух прогонах подряд, то есть не флук. Теперь условие фолбэка — только вывод пробы (`steps.primary_verdict.outputs.verdict != 'published'`), первый дизъюнкт по `outcome` удалён: источник истины один, и два источника снова могут разойтись. Гард `CodeReviewConfigTest` пинит `always()` с записанным наблюдением, чтобы правка не вернулась под видом «оптимизации»
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

## Поиск (Meilisearch)

Движок, клиент и отвергнутые кандидаты зафиксированы в `ADR/0001-search-engine.md`; ниже —
только то, что реально есть в коде на 6.1. **Индексации и поисковых эндпоинтов ещё нет** —
это 6.2–6.6; здесь только инфраструктурная база.

**Клиент** (`config/packages/meilisearch.yaml`) — сервис `Meilisearch\Client` с PSR-18
(`Symfony\Component\HttpClient\Psr18Client`) и PSR-17 (`Nyholm\Psr7\Factory\Psr17Factory`),
переданными **явно**. При `null` конструктор клиента выполняет discovery
(`Psr18ClientDiscovery::find()`), а он может бросить при построении сервиса — то есть сломался
бы контейнер, а не первый поиск. `MeilisearchClientConfigTest` фиксирует и явные аргументы, и
отсутствие любого boot-вызова: гейт `symfony-diagnostics` собирает контейнер в test-окружении
без внешних сервисов, и `isHealthy()` в конструкторе его бы уронил.

Сервис остаётся приватным во всех окружениях. До 6.2 его никто не потребляет, а
неиспользуемый приватный сервис вырезается из контейнера — и тест на проводку не смог бы до него
дотянуться. Решается публичным **алиасом** под отдельным id в `when@test`
(`test.meilisearch_client`): алиас помечает цель как связанную, поэтому сервис выживает, но сам
остаётся приватным в проде. В 6.2, когда появится адаптер-потребитель, блок удаляется.

Алиас обязан быть под **другим** id: переопределение того же id в `when@test` заменяет
определение, а не сливает его, и аргументы конструктора молча теряются (проверено — падает с
`ArgumentCountError`, `0 passed`).

`Symfony\Component\HttpClient\Psr18Client` получает `$responseFactory` явно: файлы в
`config/packages/` не наследуют `_defaults` из `services.yaml`, поэтому сервис без аргументов не
автоварится и его конструктор подбирает PSR-17 фабрики сам. Фолбэк работает и сегодня
(`nyholm/psr7` — прямая зависимость), но опираться на порядок этой цепочки нельзя: она не
контракт.

`config/packages/http_discovery.yaml` — **не наша проводка**: это Symfony Flex-рецепт для
`php-http/discovery` (тянется клиентом), записанный в `symfony.lock`. Он регистрирует алиасы
для `Psr\Http\Message\*Interface`, и наш клиент их не использует: factories переданы явно.
Файл коммитится ради одинакового состояния локально и в CI. Стоит учесть: `php-http/discovery`
объявлен в lock как `composer-plugin` с `plugin-optional: true` и **не** входит в
`allow-plugins` проекта, поэтому на чистой установке Flex может его и не применить — фактически
паритет держится именно на закоммиченном файле, а не на повторной генерации.

**Адрес в тестах — один файл на два сетевых пространства.** Внутри Docker движок адресуется по
имени сервиса (`http://meilisearch:7700`, приходит из `.env` через `env_file`), а на раннере
(CI или PHPUnit с хостовым PHP) — `127.0.0.1:7700`. Поэтому в `phpunit.xml.dist` лежит
`<env>` **без** `force`: PHPUnit подставляет значение только когда оно не задано, так что
докеровское выигрывает локально, а хостовое подставляется в CI. `force="true"` здесь сломал бы
CI — имя сервиса на раннере не резолвится. `tests/bootstrap.php` и `ci.yml` не трогаются:
механизм `DATABASE_URL` не нужен, потому что в отличие от базы адрес из `env_file` в тестах
корректен.

**Ловушка локальной разработки:** после добавления нового `config/packages/*.yaml` мало
`cache:clear --env=test` — он пересобирает debug-контейнер, а PHPUnit поднимает контейнер с
`APP_DEBUG=0`, и устаревший non-debug контейнер продолжает использоваться, не отслеживая
появившийся файл. Лечится `rm -rf var/cache/test` **внутри контейнера**: `var/cache` на хосте
не тот (анонимный volume на `/var/www/html/var`), так что проверять его на хосте бессмысленно —
файлы там недельной давности.

## Текущий статус

| Этап | Описание | Статус |
|------|----------|--------|
| 1 | Инфраструктура | ✅ завершён |
| 2 | Пользователь | ✅ завершён |
| 3 | Коллекция | ✅ завершён |
| 4 | Айтем | ✅ завершён (4.1–4.7) |
| 5 | Социальное | ✅ завершён (весь бэклог и мини-этап 5A закрыты; гейт messenger снят в 6.0) |
| 6 | Поиск | 🔄 идёт — 6.0 (ADR-0001, гейт messenger) и 6.1 (клиент) закрыты; индексации и эндпоинтов ещё нет |
| 7 | Админ | ⏳ |
| 8 | Тестирование | ⏳ |

---

*Обновлять при архитектурных изменениях.*
