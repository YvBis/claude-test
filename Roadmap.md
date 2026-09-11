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
| review-6 | [review] Extract `OwnerId`/`CollectionFieldId`-style value objects to remove cross-domain dependency: Collection domain currently hard-depends on `User/UserId` (pre-existing since 3.1). Proposed: `OwnerId` VO inside Collection domain + repo finds by it. >150 lines — separate refactor, not scope of 3.4. | todo | From 2026-09-10: architect-reviewer finding on Task 3.4 (Variant A vs OwnerId-VO); design locked as `?owner` param, VO extraction deferred |

| fwd-1 | [future] Extend UnitOfWorkInterface with transactional boundary (`transactional(callable)` / wrapInTransaction) for atomic multi-entity operations (item + dynamic fields). Deferred from review-4: no consumer yet. Take during Этап 4 design. | todo | From 2026-09-10: architect review finding on review-4 |

| fwd-2 | [future] Заменить фиксированные 12 колонок Item (match-arms 1,2,3 + SlotLimits::MAX_SLOTS_PER_TYPE) на произвольное количество слотов на тип. Сейчас константа создаёт ложную конфигурируемость: изменение значения не меняет ни колонки схемы, ни match-ветки (slot > 3 → throw). Кандидаты: (a) EAV-таблица item_slot_values(item_id, field_type, slot_index, value), (b) JSON-колонка slots, (c) генерация колонок по константе. Затронет: Item mapping, репозитории, миграцию, CollectionField cap, nextSlotIndexFor (per-type), сервис маппинга 4.4. Триггер: реальная потребность >3 слотов на тип или архитектурное ревью. Estimate: 3-5ч | todo | From 2026-09-11: выявлено при закрытии 4.1 — константа не управляет схемой |

## Этап 4: Доменная модель — Айтем

### Управление айтемами с динамическими полями

| Задача | Описание | Статус |
|--------|----------|--------|
| 4.1 | [x] Сущность Item с name, collection_id и слотами динамических полей | done |
| 4.2 | [ ] Сущность Tag и связующая таблица many-to-many | todo |
| 4.3 | [ ] Сервис тегов — создание и переиспользование существующих | todo |
| 4.4 | [ ] Сервис айтемов — создание, редактирование, список с логикой динамических полей | todo |
| 4.5 | [ ] API-эндпоинты айтемов (CRUD, список айтемов коллекции, список своих) | todo |
| 4.6 | [ ] Unit-тесты для домена Item | todo |
| 4.7 | [ ] API-эндпоинт тегов — список/поиск существующих тегов (выбор из уже существующих при проставлении айтему) | todo |

## Этап 5: Социальные функции

### Лайки и комментарии

| Задача | Описание | Статус |
|--------|----------|--------|
| 5.1 | [ ] Сущность Like с уникальным ограничением (user_id, item_id) | todo |
| 5.2 | [ ] Сущность Comment с owner_id, item_id | todo |
| 5.3 | [ ] Сервис лайков — добавление, удаление, переключение | todo |
| 5.4 | [ ] Сервис комментариев — создание, редактирование, удаление | todo |
| 5.5 | [ ] API-эндпоинты лайков и комментариев | todo |
| 5.6 | [ ] Unit-тесты для социальных функций | todo |

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
| 7.6 | [ ] Редактирование/удаление лайков и комментариев админом | todo |
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
- **Этап 4 (Айтем)**: 1/7 задач выполнено
- **Этап 5 (Социальное)**: 0/6 задач выполнено
- **Этап 6 (Поиск)**: 0/4 задач выполнено
- **Этап 7 (Админ)**: 0/7 задач выполнено
- **Этап 8 (Тестирование)**: 0/6 задач выполнено

**Итого**: 17/46 задач выполнено

---

## Архитектурные заметки

- **Стек**: Symfony 7, PHP 8.3, MySQL/PostgreSQL, Redis, Meilisearch
- **Архитектура**: Clean Architecture — слои Domain, Application, Infrastructure
- **API**: REST с документацией OpenAPI
- **Auth**: Symfony Security с JWT или сессионной аутентификацией
- **БД**: Doctrine ORM с миграциями
- **Очереди**: Symfony Messenger с Redis transport
- **CI**: GitHub Actions со всеми инструментами качества