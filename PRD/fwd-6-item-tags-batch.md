# fwd-6 — устранение N+1 по тегам в списках айтемов

Статус: в работе, 2026-10-01.

## Проблема

`ItemDTO::fromEntity` (`ItemDTO.php:30-41`) читает `$item->getTags()` на каждый
item. `Item.tags` — `ManyToMany(fetch: 'LAZY')` (`Item.php:100-104`), а
репозиторий джойнит только collection+owner (`withCollectionAndOwner`,
`DoctrineItemRepository.php:90-96`). Каждый `getTags()` — отдельный SELECT.

Путь: `ItemController.php:80,130` → `ItemService::toDTOList` (`:128-134`) →
`ItemDTO::fromEntity` (`:37`).

Граница: `AbstractApiController::MAX_LIMIT = 100`, не 50. Худший случай — 1
запрос страницы плюс 100 запросов тегов.

## Решение

Двухзапросный batch в `DoctrineItemRepository`, интерфейс не меняется.

1. Запрос 1 — без изменений: страница айтемов с collection+owner, фильтры,
   пагинация, сортировка fwd-27.
2. `initializeTags()` — приватный хелпер после получения страницы:
   `SELECT i, t FROM Item i LEFT JOIN i.tags t WHERE i.id IN (:ids)` по id
   страницы, `ArrayParameterType::BINARY`. Сущности возвращаются из identity map
   и Doctrine инициализирует их существующие коллекции на месте
   (`ObjectHydrator::84` ставит `hints['fetched']` до `continue` на ManyToMany,
   `initRelatedCollection:197-211` инициализирует имеющуюся коллекцию, дубли
   схлопываются в `identifierMap:495`, `UnitOfWork:2549` отдаёт тот же инстанс).
   Порядок и содержимое `$items` не меняются.
3. Вызывается из `findByCollectionId`, `findByOwnerId` и `findById`. Пустая
   страница — ранний возврат (`IN ()` невалиден); `findById` при `null` хелпер
   не зовёт.

Почему так, а не иначе:

- `leftJoin('i.tags')->addSelect` на списочном запросе — cartesian-инфляция
  ломает пагинацию (решение 4.5, перепроверять нечего).
- `fetch: EAGER` на маппинге — глобальное изменение, затрагивает записи, и
  to-many всё равно ходит отдельными запросами.
- `EXTRA_LAZY` не убирает N+1 при итерации; `HINT_FORCE_PARTIAL_LOAD` ломает
  гидратацию collection/owner; `HINT_REFRESH` переинициализирует коллекции, но
  затирает managed-состояние скаляров; кэш второго уровня не настроен.
- Batch на стороне сервиса потребовал бы кросс-агрегатного метода и ручной
  раскладки тегов по айтемам; в репозитории это делает identity map.

### Почему LEFT JOIN, а не INNER

С INNER айтем без тегов не даёт строки, остаётся неинициализированным, и
следующий `getTags()` снова идёт в базу — N+1 возвращается ровно для них. LEFT
JOIN доводит коллекцию до initialized-empty без запроса.

### Неатомарность — принято, не чинится

Два statement'а не обёрнуты в транзакцию, поэтому коммит изменения тегов между
ними даёт снимок, взятый по двум точкам. Для списочного чтения приемлемо, тот же
компромисс уже есть у самого запроса страницы. Записано в лог.

## Тест

Красный тест — **рефлексия, без конфига**: после фикстур, `flush()` и
`$em->clear()` вызывается `findByCollectionId`, и до касания `getTags()`
ассертится `PersistentCollection::isInitialized() === true` на каждом айтеме
(рефлексия `Item::$tags`). Отдельный кейс — айтем без тегов, должен вернуться
initialized-empty. То же для `findByOwnerId` и `findById`.

`$em->clear()` обязателен: без него запрос возвращает те же инстансы, что создали
фикстуры, с тегами, уже заполненными `addTag()`, и N+1 не воспроизводится.

Почему не счётчик запросов: `DebugStack` удалён в DBAL 4, а логирование в тестах
выключено (`bootstrap.php` ставит `APP_DEBUG=0`, бандл вешает
`doctrine.dbal.debug_middleware` только на profiling-соединения). Вариант с
`profiling: true` под `when@test` потребовал бы конфига; владелец выбрал
рефлексию. Счётчик сессионных statement'ов (`SHOW SESSION STATUS`) под DAMA не
работает — не двигается даже на голом `SELECT 1`, проверено пробником, пробник
удалён.

## Смежные вещи, найденные по ходу

- `ItemRepositoryInterface:14-19` — докблок оторван от методов (два подряд).
  Расширен текстом про теги и про константные два statement'а; теперь формулировка
  верна и для `findById`, который тоже переведён на batch.
- `findById` меняет форму гидрации для PATCH (`ItemController:315`) и DELETE
  (`:361`). Dirty-checking безопасен: ветка инициализации делает
  `setDirty(false)` и снимает снапшот (`initRelatedCollection:203`,
  `hydrateAllData:152`), поэтому `replaceTags()` даёт тот же набор add/remove и
  лишнего `touch()` не будет. У DELETE опора на `onDelete: CASCADE` в БД, а не на
  содержимое коллекции.
- Призрачной стены fwd-16 здесь нет: теги приходят fetch-join'ем, `createEntity`
  создаёт настоящие объекты, прокси не генерируются.

## Критерии приёмки

- [x] Красные тесты падают до правки по нужной причине
- [x] `findByCollectionId`, `findByOwnerId`, `findById` — теги инициализированы
- [x] Айтем без тегов — initialized-empty
- [x] Существующие тесты зелёные без правок (поведение не сдвинуто)
- [ ] `ci:all` зелёный
- [ ] Три ревьюера (шаг 6.1)
