# PRD: Task fwd-3 — Единый контракт сериализации response-DTO (`ArrayableInterface`)

## Описание задачи
`toArray(): array` дублируется в трёх response-DTO без общего контракта: `CollectionDTO`, `ItemDTO`, `TagDTO`. Ввести интерфейс `ArrayableInterface` в Application, реализовать на существующих трёх DTO **без изменения поведения и формы вывода** (JSON-контракт API сохраняется). Generic-потребителей сейчас нет — это упреждающий контракт для 4.5 и 5.x.

## Ссылки на требования
- `artifacts/technical-requirements-taskflow-ru.md` — строгая типизация, границы слоёв.
- `artifacts/prd-taskflow-ru.md` — user stories айтемов 48–54 (response-DTO в JSON).
- `Roadmap.md`, строка `fwd-3`.

## Решения (согласованы с пользователем)
1. **Интерфейс:** `App\Application\Common\DTO\ArrayableInterface` (`toArray(): array`, docblock `@return array<string, mixed>`), новый подкаталог `Common/DTO/`.
2. **Реализуют:** ровно `CollectionDTO`, `ItemDTO`, `TagDTO`. Тела `toArray()` не меняются.
3. **Нативный return тип остаётся `array`**; конкретные docblock-формы (`array{id: string, name: string}` у `TagDTO`) сохраняются — PHPStan LSP проходит (конкретная форма — подтип `array<string, mixed>`).
4. **Входные DTO вне контракта** (`Create*`/`Update*`/`ItemSlotDTO`/`RegisterUserDTO`/`LoginUserDTO`).
5. **Generic-потребитель не вводится.**
6. **`TagDTO` остаётся в `App\Application\Item\DTO`** (не переносим — смена namespace отдельная задача).

## Ограничения
- Интерфейс только в Application; Domain/Infrastructure не трогаются; Doctrine не импортируется.
- Без миграций. `UnitOfWorkInterface` не расширяем.
- Вывод `toArray()` всех трёх DTO и ключи/порядок обязаны остаться идентичными.
- Все существующие тесты зелёные, `composer ci:all` зелёный.

## Декомпозиция (≤2ч / ≤150 строк)

| # | Подзадача | Описание | Estimate |
|---|-----------|----------|----------|
| fwd-3.1 | Интерфейс + реализации | `ArrayableInterface` + `implements` на `CollectionDTO`/`ItemDTO`/`TagDTO` | 0.5ч |
| fwd-3.2 | Контрактный тест + проверки | `ArrayableInterfaceTest` (data provider на 3 класса), `ci:all` | 0.25ч |

**Итого**: ~0.75ч, ~15 строк продового кода + тест.

## Критерии приёмки
- [x] `ArrayableInterface` создан (`toArray(): array`, docblock `@return array<string, mixed>`)
- [x] `CollectionDTO` / `ItemDTO` / `TagDTO` реализуют интерфейс, форма `toArray()` не изменилась
- [x] Входные DTO интерфейс не реализуют
- [x] Контрактный тест на 3 класса зелёный
- [x] `composer ci:all` зелёный (393 теста, phpstan/php-cs-fixer/rector чисты)
- [ ] CI (PR) зелёный — ожидает push и прогона GitHub Actions
- [ ] Запись в `AssumptionLog.md`; статус `fwd-3` в `Roadmap.md` → done — после мержа

## Библиотеки
| Пакет | Версия | Где |
|-------|--------|-----|
| `php` | `>=8.3` | интерфейс, return types |
| `phpstan/phpstan` | `^1.12` | анализ level 6 |
| `phpunit/phpunit` | `^11.5` | контрактный тест (attributes) |

## Зависимости
- **Требует:** 3.x (`CollectionDTO`), 4.4 (`ItemDTO`, `TagDTO`).
- **Разблокирует:** 4.5 (API айтемов), 5.x response-DTO — будут реализовывать тот же контракт.

## Заметки
- Риск минимальный — аддитивные изменения.
- `TagDTO` живёт в `App\Application\Item\DTO` — перенос не в этой задаче.
- Контрактный тест дешёвый (без сборки сущностей); shape покрыт существующими тестами DTO.

## Out of Scope
- Перенос `TagDTO`; реализация на входных DTO; generic-потребитель; изменение сигнатур/форматов; нормализация docblock-форм.