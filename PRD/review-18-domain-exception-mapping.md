# review-18 — доменный `InvalidArgumentException` в write-пути коллекций

`CollectionController::create` и `::update` строили value objects вне `try`, поэтому
доменный `InvalidArgumentException` доходил до `kernel.exception`, где
`ApiExceptionSubscriber` не маппит его ни в один `HttpException`, и клиент получал
голый 500 вместо 422.

## Что было не так

| Метод | Строка | Проблема |
|---|---|---|
| `create` | `:92` | `try` покрывал только `deserializeAndValidate`; `$collectionService->create(...)` — вне |
| `update` | `:328` | `getById` обёрнут (`:316-320`), `$collectionService->update(...)` — вне |

Правильно было бы и наоборот: чтение по id уже отдаёт 404 через
`catch (CollectionNotFoundException|\InvalidArgumentException)`, а вот запись
(построение `CollectionName`/`Theme` из DTO) — нет.

## Решения

### D1. Второй узкий `try`, а не расширение существующего

Оба технических ревьювера нашли это независимо. Расширить `try` до `:92` нельзя по
двум причинам:

1. `OwnerId::fromBytes(...)` стоит в той же строке, а `UuidBinaryValue::fromBytes`
   бросает `InvalidArgumentException` на битых байтах (`:21-23`) — битая строка
   пользователя обязана быть 500, а не 422.
2. Сериализатор тоже бросает `InvalidArgumentException`-семейство из `deserialize`
   (`AbstractApiController:51` — `MissingConstructorArgumentsException`,
   `ExtraAttributesException`): расширение покрыло бы и их.

Поэтому — дословное зеркало `ItemController:248-252`: узкий `try` вокруг вызова
сервиса, `OwnerId` вычисляется до него.

### D2. Слой не трогаем

Отдельный `InvalidCollectionDataException` рассматривался и отвергнут: это
исключение ради одной пары вызовов, второй потребитель не появится, а
`ItemController` уже живёт по тому же правилу (домен бросает `InvalidArgumentException`,
контроллер маппит). Ловить `\InvalidArgumentException` широко здесь безопасно:
внутри `CollectionService::create/update` его бросают только `CollectionName::fromString`
и `Theme::fromString`; после `persist/flush` он же может прийти из Doctrine
(`ORMInvalidArgumentException extends \InvalidArgumentException`), но там он
недостижим для этих двух маппингов, и это принято как паритет с `ItemController`.

### D3. `delete` не трогаем

`CollectionService::delete` не строит ни одного VO: `findIds…`, `remove`, `flush`,
индексация. Доменного throw там нет, а `ORMInvalidArgumentException` из remove/flush
обязан оставаться 500.

### D4. Тесты — постоянные, без временных фикстур

Живого HTTP-триггера сегодня нет: DTO зеркалит домен полностью (`Length` в единицах
кодовых точек — `Length::COUNT_CODEPOINTS` по умолчанию, то есть тот же `mb_strlen`,
что у `CollectionName`; тема — `Choice` по `Theme::values()`). Поэтому дефект —
латентный класс отказа, а не воспроизводимый баг.

Тесты сделаны по готовому рецепту из `CollectionControllerTest` (500-тест):
`CollectionService` — `final`, поэтому сбой инжектится слоем ниже, моком
`CollectionRepositoryInterface::save`, бросающим `InvalidArgumentException`; через
реальный стек контроллер → подписчик проверяется конверт. Существующий тест на
`RuntimeException → 500` остаётся и продолжает доказывать, что не всё подряд стало 422.

## Красное доказательство

Убран `catch` в `create` → тот же тест красный (`expected 422, got 500`). После
возврата — зелёный. Снято и откатано, дерево чистое.

## Приёмка

- [x] узкие `try/catch` в `create` и `update`, `OwnerId` вне блока;
- [x] два постоянных теста: POST и PATCH → 422 с `{error, message, details}`;
- [x] тест на `RuntimeException → 500` по-прежнему зелёный;
- [x] `openapi:fresh` — спека не дрейфует (422 был задокументирован на обоих эндпоинтах, `:68` и `:294`);
- [x] `ci:all` 1141 тест / 7492 ассерта;
- [x] `ARCHITECTURE.md` — строка коллекций больше не утверждает 500.

## Что осталось

Класс дефекта закрыт для коллекций. Остальные write-пути проверены ранее:
айтемы маппят 422 (`ItemController:249,321`), комментарии и лайки строят VO только
из значений, валидируемых DTO. Новый write-эндпоинт с VO-построением обязан
повторить этот `try` — правило записано в AssumptionLog.
