# PRD: fwd-27 — детерминированная сортировка списков (+ решение fwd-28, правки 5.20/5.26)

## Зачем

Разведка 5.23 показала: утверждение «все запросы сортируют `(createdAt, id)`» неверно.
Только Like и Comment сортируют кортежем `(createdAt, id)`; Item, Collection и Tag —
по `createdAt`/`name` без id-тай-брейкера. Строки с равными ключами могут
перетасовываться между страницами (`?limit`/`?offset`) — молчаливый дефект пагинации.

Senior-review плана (2026-09-27, локальный агент) нашёл два дополнения:
тай-брейкер без покрывающего индекса даст filesort, а пол `doctrine/orm: ^3.2`
в `composer.json:13` — ложный (код уже использует `\SortDirection`, появившийся в ORM 3.7).
Оба проверены по репозиторию и upstream-коду и включены в объём.

## Объём

### fwd-27: код + 2 миграции + тесты

Направление тай-брейкера обязано следовать за основной сортировкой (иначе ASC-индекс
не обслуживает смешанное направление ни вперёд, ни назад):

| Репозиторий | Строки | Сортировка | Tie-breaker | Миграция |
|---|---|---|---|---|
| `DoctrineItemRepository::findByCollectionId` | `:60` | `createdAt ASC` | `i.id ASC` | `idx_item_collection_list(collection_id, created_at, id)` |
| `DoctrineItemRepository::findByOwnerId` | `:76` | `createdAt ASC` | `i.id ASC` | — (фильтр join'ом по `collection.owner`, покрывающего индекса нет; filesort принять и зафиксировать) |
| `DoctrineCollectionRepository::findByOwnerId` | `:56` | `createdAt DESC` | `c.id DESC` | `idx_collection_owner_list(owner_id, created_at, id)` (DESC-сторона обслуживается обратным сканированием ASC-индекса) |
| `DoctrineCollectionRepository::findAll` | `:69` | `createdAt DESC` | `c.id DESC` | тот же индекс (полное сканирование индекса в порядке, без filesort) |
| `DoctrineTagRepository::search` | `:93` | `name ASC` | `t.id ASC` | не нужна: `uniq_tag_name(name)` обслуживает `(name, id)`, InnoDB дописывает PK |

Плюс `composer.json:13` пол `doctrine/orm: ^3.2` → `^3.7` (одна строка;
установлено 3.7.2, ущерб от лживого пола мал, но ограничение обязано быть правдой) +
`composer update --lock` + `composer validate` + `composer audit`.

Стиль миграций — по прецеденту `Version20260920201821.php` (raw SQL
`CREATE INDEX … ON …` + `DROP INDEX` в `down()`); атрибуты `#[ORM\Index]`
на сущностях + `schema:validate` до зелёного.

### Тесты (red → green, по прецеденту `DoctrineCommentRepositoryTest`)

- `DoctrineItemRepositoryTest`: `MockClock` без `sleep` → 4 айтема с равным `createdAt`;
  ассерт — порядок равен id-ascending (НЕ порядку вставки: PK случайные, так и надо);
  три+ строки через границу страницы (limit/offset), запрос повторить дважды.
- Новый `tests/Infrastructure/Collection/Repository/DoctrineCollectionRepositoryTest.php`
  (репозиторного теста коллекций нет): то же для `findByOwnerId` с направлением DESC.
- `DoctrineTagRepositoryTest`: пин порядка `search` по имени (тай-брейкер там
  по построению недостижим — имена уникальны; зафиксировать текстом).
- Негативная проба: снять один `addOrderBy` → тест обязан упасть.

### fwd-28: решение (доки)

Пользователь 2026-09-27: **`symfony.lock` оставить игнорируемым**.
Строка получает `done` со следствием: дрейф рецептов не виден;
`config/packages/monolog.yaml` считаем своим файлом; перед следующим
`composer recipes:update` сверять руками.

### Правки формулировок на уровне очереди (в этом же PR)

- `5.20`: цель «таймаут к ~10 мин» заменена измерением (замер 2026-09-27:
  максимум 13.2 мин за 30 прогонов `gh run list --workflow "Code Review"`,
  ноль таймаутов — десятка резала бы живые зелёные прогоны; запрос приложен в логе).
  Рабочий объём 5.20: запись длительности + фиксация «вход сузить нечем в v0.8.7».
- `5.26`: триггер помечен сработавшим (доступно v0.23.0 при `SYMFONY_LSP_VERSION: 0.21.0`).
- `fwd-18`: вместо «либо то, либо это» — решение пользователя 2026-09-27:
  вложенный путь канонический, плоский `deprecated: true`, без runtime-заголовка.
  Статус остаётся `todo`.

## Вне объёма

Денормализация owner в items (убрать filesort на пути «айтемы по владельцу»);
направленные DESC-индексы через raw SQL (не нужны при следовании направления);
`fwd-18`/`fwd-23` (следующие PR); решение по cron для проверки версий LSP (5.14).

## Критерии приёмки

- [x] `addOrderBy` во всех 5 местах с направлением, следующим за сортировкой
- [x] 2 миграции + `schema:validate` чист; CI-миграции на test-БД проходят
- [x] Тесты детерминизма зелёные + негативная проба красная
- [x] `composer.json` пол `^3.7`, lock обновлён, `validate` + `audit` чисты
- [x] Строки `fwd-27`/`fwd-28` → `done`, `5.20`/`5.26`/`fwd-18` переформулированы
- [x] Self-review + 3 ревьюера (senior SHIP-WITH-NITS, архитектор SHIP-WITH-NITS, техлид APPROVE — разобрано) → сквош → push → CI зелёный; мерж за пользователем
