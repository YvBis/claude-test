# PRD: Task fwd-1 — Транзакционная граница UnitOfWork

## Описание задачи

`UnitOfWorkInterface` имеет только `flush(): void`; `DoctrineUnitOfWork` делегирует в `EntityManagerInterface::flush()`. `ItemService::create`/`update` **не атомарны**: `TagService::resolveByNames` → `DoctrineTagRepository::getOrCreate` пишет теги немедленно (raw upsert), Item — финальным `flush()`. При падении финального flush свежесозданные теги остаются закоммиченными (orphan, задокументировано в PRD 4.4/AssumptionLog).

Нужно: `transactional(callable): mixed` в интерфейсе, реализация через ORM `wrapInTransaction`, обернуть `create`/`update`.

**Ключевая проверка (выполнена):** `getOrCreate` пишет через `getEntityManager()->getConnection()` — тот же DBAL-`Connection`, что у EM. `wrapInTransaction` открывает транзакцию на этой connection → raw-INSERT тега откатывается вместе с Item. Атомарность закрыта. Вложенность безопасна (DBAL 4.4.3 использует SAVEPOINT).

## Ссылки на требования
- `Roadmap.md` — fwd-1; review-4 (flush вынесен из репозиториев).
- `AssumptionLog.md` — review-4, неатомарность create.
- `PRD/4.4-item-service.md`, `PRD/4.3-tag-service.md`.

## Решения (согласованы с пользователем)
1. **Сигнатура:** `transactional(callable $callback): mixed` с `@template T` / `@param callable(): T` / `@return T`. Возврат callback проходит насквозь (create/update возвращают `Item`).
2. **Реализация:** `DoctrineUnitOfWork::transactional()` → `$this->entityManager->wrapInTransaction($callback)`. `flush()` сохраняется (Collection/Registration).
3. **Применение:** `ItemService::create` и `update` (оба пишут теги). `delete` — `remove`+`flush` (одна сущность). Collection/Registration не трогаем.
4. **Flush внутри callback убрать** — `transactional()` владеет flush+commit; callback только `save()` и возвращает сущность.
5. **EM close-on-failure** (wrapInTransaction на исключении делает `close()`: clear + detach) — приемлемо; в HTTP исключение завершает обработку.
6. Вложенность: DBAL 4.4.3 — savepoints, безопасно (в т.ч. DAMA).

## Ограничения
- Application не импортирует Doctrine; ORM только в Infrastructure.
- Без миграций.
- PHPUnit 9.6 (тесты на аннотациях, не атрибутах).
- `composer ci:all` зелёный (phpstan L9, phpcs, rector, phpunit).

## Декомпозиция

| # | Подзадача | Описание | Estimate |
|---|-----------|----------|----------|
| fwd-1.1 | Интерфейс + реализация + unit-тест | `transactional(callable): mixed` + `@template T`; `DoctrineUnitOfWork::transactional()` → wrapInTransaction; `DoctrineUnitOfWorkTest` (делегирует 1 раз, passthrough результата, проброс исключения) | 0.5ч |
| fwd-1.2 | ItemService wiring + тесты | create/update в `transactional(fn(): Item => ...)`, убрать явный flush; ItemServiceTest: mock UoW исполняет callback | 1ч |
| fwd-1.3 | Integration-тест атомарности + доки | MySQL: в транзакции создать новый тег → исключение → тег отсутствует; happy-path — item+tag персистентны. AssumptionLog, PRD 4.4 заметка, Roadmap | 1ч |

**Итого:** ~2.5ч, ~30 строк продового кода + тесты.

## Критерии приёмки
- [x] `UnitOfWorkInterface::transactional(callable): mixed` с `@template T`
- [x] `DoctrineUnitOfWork::transactional()` делегирует в `wrapInTransaction`
- [x] `flush()` сохранён, существующие consumer'ы не сломаны
- [x] `DoctrineUnitOfWorkTest` зелёный (3/3)
- [x] `ItemService::create`/`update` внутри `transactional`, без явного flush внутри callback
- [x] `ItemServiceTest` обновлён (mock UoW исполняет callback), 14/14
- [x] Integration-тест доказывает откат raw-тега при исключении (2/2)
- [x] `delete` остаётся `remove`+`flush`; Collection/Registration без изменений
- [ ] `composer ci:all` зелёный — прогон после финализации
- [x] AssumptionLog записан; Roadmap fwd-1 → done после мержа
- [ ] CI (PR) зелёный — ожидает push и прогона GitHub Actions

## Библиотеки
| Пакет | Версия | Где |
|-------|--------|-----|
| `php` | `>=8.3` | generics-docblock, `#[\Override]` |
| `doctrine/orm` | `3.6.x` | `wrapInTransaction` |
| `doctrine/dbal` | `4.x` | savepoints |
| `dama/doctrine-test-bundle` | `^8.2` | изоляция тестов |
| `phpunit/phpunit` | `9.6.x` | тесты |
| `phpstan/phpstan` | `^1.12` | L9 + `@template` |

## Зависимости
- **Требует:** review-4, 4.4, 4.3 (done).
- **Разблокирует:** Этап 5 (атомарное collection + fields), multi-entity use-case'ы 4.5+.

## Заметки
- Атомарность закрыта только для операций внутри `transactional`; других путей записи тегов сейчас нет.
- `wrapInTransaction` на ошибке делает EM `close()`; в integration-тесте после исключения проверять через raw-connection/свежий EM.
- Уточнение AssumptionLog (2026-09-12): в DBAL 4.4.3 вложенные транзакции — SAVEPOINT, безопасно (прежняя формулировка неточна).
- fwd-2/fwd-4/fwd-5 не затрагиваются.

## Out of Scope
- Collection/Registration service изменения; delete в транзакции; локи/retry; компенсирующая чистка orphan-тегов; `#[AsTransactional]`; миграции.