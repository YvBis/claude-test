# PRD — review-6: Extract `OwnerId` value object to remove cross-domain dependency

## 1. Описание задачи

Collection-домен с Task 3.1 использует `App\Domain\User\ValueObject\UserId` в качестве типа идентификатора владельца в query-слое:

- `CollectionRepositoryInterface::findByOwnerId(UserId $ownerId, ...)`
- `CollectionService::listByOwnerId(UserId $ownerId, ...)`
- `CollectionService::listByOwner(User $owner, ...)` + `findByOwner(User $owner, ...)`
- `CollectionDTO` — `public UserId $ownerId`

Item-домен повторяет ту же зависимость:

- `ItemRepositoryInterface::findByOwnerId(UserId $ownerId, ...)`
- `ItemService::listByOwner(UserId $ownerId, ...)`

Итог: Application/Collection и Domain/Collection/Repository импортируют VO чужого домена (`User\UserId`), а Item — то же. Источник — architect-review finding на Task 3.4 от 2026-09-10.

Что уже есть:

- Паттерн UUID-VO: `CollectionId`, `CollectionFieldId`, `UserId` — все на трейте `App\Domain\Common\ValueObject\UuidBinaryValue`.
- Прецедент role-based ID VO: `CollectionFieldId` живёт в домене-владельце и используется как тип в репозиториях/сущностях этого домена.
- В `CollectionController::list` уже есть разветвление `?owner` vs `getUser()`; оба пути идут в сервис.

## 2. Ссылки на требования

- `artifacts/domain-model-taskflow-ru.md` — Collection.owner_id, Item наследует владельца через collection.owner.
- `artifacts/technical-requirements-taskflow-ru.md` — Clean Architecture, границы доменов.
- `Roadmap.md` → `review-6` (todo, 2026-09-10, architect-review finding).
- `CLAUDE.md` — слои, VO, UoW, репозитории без flush, Conventional Commits.

## 3. Решения (согласованы с пользователем)

1. **Option A**: новый VO `App\Domain\Collection\ValueObject\OwnerId`, тот же паттерн, что `CollectionId`/`UserId`. Связь сущности **остаётся**: `Collection.owner` — `ManyToOne User`. Меняется только query/service/DTO слой. Без изменений маппинга и миграций.
2. Удалить `findByOwner(User $owner, ...)` из `CollectionRepositoryInterface` и `CollectionService::listByOwner(User)`. Остаётся только `findByOwnerId`.
3. `CollectionDTO::$ownerId`: `UserId` → `OwnerId`.
4. Item-домен переиспользует `Collection\OwnerId`: `ItemRepositoryInterface::findByOwnerId(OwnerId)`, `ItemService::listByOwner(OwnerId)`.
5. `CollectionService::create(..., User $owner)` **остаётся** (связь сущности требует User). Конвертация User→OwnerId — ответственность Infrastructure.
6. Контроллеры (Infrastructure) конвертируют `User` → `OwnerId` и вправе импортировать оба домена.

**OQ-1 (ORM-атрибуты):** зеркалим `CollectionFieldId` (включая `#[ORM\Embeddable]`, `#[ORM\Column]`) — консистентность паттерна, атрибуты инертны, VO не маппится.
**OQ-2 (guard-тест):** не добавляем (entity всё равно импортирует `User`; guard хрупкий) — вернуться на периодическом review.
**OQ-3 (конверсия):** `OwnerId::fromBytes($user->getId()->toBytes())` — без UUID-string round-trip.

**Ограничение scope (честно):** полного устранения кросс-доменной зависимости не будет. `Collection` entity сохраняет `use App\Domain\User\Entity\User` (поле `$owner`, `create()`, `getOwner()`), `CollectionService::create` принимает `User`. `OwnerId` убирает зависимость от `User\UserId` в query/DTO-сигнатурах, но не от сущности `User`.

## 4. Ограничения

- Слои: `OwnerId` только в `Domain/Collection/ValueObject`; сервисы Application зависят от интерфейсов Domain; контроллеры Infrastructure конвертируют.
- Doctrine ORM 3: `IDENTITY(c.owner)` / `IDENTITY(collection.owner)` и сравнение `toBytes(), 'binary'` не меняются. JOIN FETCH-паттерн не трогаем. `final`-сущности / lazy-ghost (review-5) не затрагиваются.
- UoW: flush на Application-уровне, репозитории flush не делают.
- Миграции не требуются — схема не меняется.
- PHPStan level 6: смена типа параметра обязана сопровождаться обновлением всех call-sites и моков в одном коммите.

## 5. Декомпозиция

| # | Подзадача | Описание | Оценка |
|---|-----------|----------|--------|
| ST1 | `OwnerId` VO + unit-тест | `src/Domain/Collection/ValueObject/OwnerId.php` (по образцу `CollectionFieldId`, 22 строки). Тест `tests/Domain/Collection/ValueObject/OwnerIdTest.php` — по образцу `CollectionIdTest`: generate/fromString/fromBytes/equals/toString/wrong-length. Новый символ, callers нет | ≤1 ч |
| ST2 | Decoupling Collection read-path (один коммит) | (1) `CollectionRepositoryInterface` — `UserId`→`OwnerId`, удалить `findByOwner(User)`, ретипизировать `findByOwnerId(OwnerId)`; (2) `DoctrineCollectionRepository` — убрать `User`/`UserId` импорты, удалить `findByOwner()`, ретипизировать `findByOwnerId(OwnerId)`; (3) `CollectionService` — `UserId`→`OwnerId`, удалить `listByOwner(User)`, ретипизировать `listByOwnerId(OwnerId)` (User остаётся для `create`); (4) `CollectionDTO` — `ownerId` тип `OwnerId`, конверсия; (5) `CollectionController::list` — `OwnerId::fromString($owner)` / `OwnerId::fromBytes(...)`, убрать `use UserId`; (6) `CollectionServiceTest` — удалить `testListByOwnerReturnsCollections`, обновить `testListByOwnerIdReturnsCollections` | ≤1.5 ч |
| ST3 | Decoupling Item read-path (один коммит) | (1) `ItemRepositoryInterface` — `UserId`→`Collection\OwnerId`; (2) `DoctrineItemRepository`; (3) `ItemService::listByOwner(OwnerId)`; (4) `ItemServiceTest`, `DoctrineItemRepositoryTest` | ≤1 ч |
| ST4 | Верификация + документация | Полный `composer ci:all`, grep-проверка (`User\ValueObject\UserId` / `findByOwner(User` отсутствуют в затронутых), AssumptionLog, Roadmap `review-6` → done | ≤0.5 ч |

Порядок ST1 → ST2 → ST3 → ST4. ST2 и ST3 независимы, оба требуют ST1.

## 6. Критерии приёмки

- [x] `OwnerId` создан, `UuidBinaryValue`, `final readonly`, приватный конструктор; unit-тест покрывает generate/fromString/fromBytes/equals/toString и оба `InvalidArgumentException`
- [x] `CollectionRepositoryInterface` и `DoctrineCollectionRepository` не импортируют `App\Domain\User\ValueObject\UserId`; `findByOwnerId(OwnerId)` — единственный owner-query
- [x] `findByOwner(User)` удалён из интерфейса, doctrine-репозитория и `CollectionService`
- [x] `CollectionService::listByOwnerId(OwnerId)` работает; `create(..., User $owner)` не изменён
- [x] `CollectionDTO::$ownerId` типа `OwnerId`; JSON `owner_id` не изменился
- [x] `CollectionController::list` конвертирует через `OwnerId::fromString` (400 на невалидный UUID) и `OwnerId::fromBytes`; `use UserId` удалён
- [x] Item-домен использует `Collection\OwnerId`; `use UserId` в нём отсутствует
- [x] Тесты обновлены: `CollectionServiceTest`, `ItemServiceTest`, `DoctrineItemRepositoryTest`
- [x] `composer ci:all` зелёный (400/400, phpstan L6, phpcs, rector, coverage)
- [x] Функциональный `CollectionControllerTest` проходит без правок
- [x] Миграции не созданы, схема БД не изменена
- [ ] Запись в `AssumptionLog.md` — сделана; `review-6` в `Roadmap.md` → done — сделано; чекбоксы актуальны — ожидает CI (PR)

## 7. Библиотеки

Новых зависимостей нет. PHP 8.3, Symfony 7.4, doctrine/orm 3.6, ramsey/uuid, PHPUnit 9.6, PHPStan L6, php-cs-fixer, rector.

## 8. Зависимости

- **Требует:** Task 3.1/3.4 (`CollectionId`, `CollectionFieldId`, `findByOwnerId`/`listByOwnerId`).
- **Разблокирует:** дальнейший декаплинг Item/будущих доменов (Like/Comment owner_id) по тому же паттерну role-based ID VO.

## 9. Заметки

- Полное удаление кросс-доменной зависимости невозможно без смены связи `Collection.owner` (решение 1 запрещает). Формулировка Roadmap шире фактического scope — отражено в AssumptionLog.
- `DoctrineCollectionRepositoryTest` отсутствует; owner-фильтрация Collection покрыта функционально (`CollectionControllerTest`).
- **Deferred:** guard-тест на запрет `User\UserId` в query-слое — только если убрать `User` из Collection entity (отдельная задача).
- `fwd-1` не затрагивается.
- Коммиты: `refactor(Collection): review-6 — <описание>` + trailer.

## 10. Out of Scope

- Изменение связи `Collection.owner` (`ManyToOne User` остаётся) и миграции.
- Удаление `User` из `Collection` entity, `create`, `getOwner()`.
- Маппинг/embedding `OwnerId` куда-либо.
- `CollectionFieldId` — уже существует, не трогаем.
- VOs для Like/Comment (`owner_id`) — будущие этапы.
- `fwd-1` — transactional boundary UoW.