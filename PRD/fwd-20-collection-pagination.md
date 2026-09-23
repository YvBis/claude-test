# PRD: fwd-20 — валидация пагинации в `CollectionController::list`

Баг из триажа бэклога (мини-этап 5A, шаг 1). Estimate: 0.5ч.

## Проблема

`CollectionController::list` парсил `limit`/`offset` сырым кастом:
`$limit = (int) ($request->query->get('limit') ?? 50);`

| Запрос | Было | Стало |
|---|---|---|
| `?limit=abc` | `(int)'abc'` = 0 → `LIMIT 0`, **200 с пустым списком** | **400** |
| `?limit=0` | проходил как есть → 200 с пустым списком | **400** (MIN_LIMIT = 1) |
| `?limit=101` | уходил в репозиторий без потолка (101 строка) | **400** (MAX_LIMIT = 100) |
| `?limit=-5` | уходил отрицательным | **400** |
| `?offset=-1` | уходил отрицательным | **400** |

Все остальные списки уже валидировали через `AbstractApiController::parsePagination`:
`TagController:50`, `LikeController:140,180`, `ItemController:75,118`, `CommentController:142,182` — 7 вызовов.
Грепом по `query->get(` подтверждено, что `CollectionController:273-274` был единственным сырым числовым
разбором в `src/`.

## Решение

Заменить касты на общий хелпер в `try/catch` (образец — `ItemController::listOwn:117-128`), **до** разбора
`owner`: приоритет пагинации фиксирован, то есть запрос, неверный и там и там, отвечает причиной
пагинации. Два отдельных `catch` (пагинация и `owner`) сохранены осознанно — у них разные `details`
(`'Invalid limit: …'` против `'Invalid owner id'`), поэтому объединение в один `catch` изменило бы
существующий контракт `owner`-ошибки.

**Форма `400` не выбирается заново**: конверт `{error, message, details}` зафиксирован ещё в 5.9 (D7,
`AbstractApiController::badRequest`), fwd-20 его переиспользует. Единственный открытый вопрос конверта
остаётся у `fwd-19` — форма `401` (Lexik `{code,message}` против guard `{error}`); fwd-20 его не трогает.

OpenAPI-параметры переведены на константы (`self::DEFAULT_LIMIT`, `self::MIN_LIMIT`,
`self::MAX_LIMIT`, `self::DEFAULT_OFFSET`) с `minimum`/`maximum`, описание `400` расширено до
«invalid limit/offset/owner id», `public/api/openapi.json` перегенерирован.

## Критерии приёмки

- [x] `?limit=abc`, `?limit=0`, `?limit=101`, `?limit=-5`, `?offset=-1`, `?offset=abc` → `400` с конвертом `{error, message, details}`.
- [x] `?limit=100` → `200` (граница), `?limit=2&offset=1` → вторая страница.
- [x] `?limit=abc&owner=not-a-uuid` → `400` с причиной пагинации (приоритет закреплён тестом).
- [x] `?owner=not-a-uuid` → `400` как раньше (регрессия покрыта существующим `testListInvalidOwnerReturns400`, код этой ветки не менялся).
- [x] `composer ci:all` зелёный (698/1974, +9 тестов); `openapi.json` перегенерирован; `config/reference.php` не в коммите.
