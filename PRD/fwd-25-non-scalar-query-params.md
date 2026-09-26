# PRD: fwd-25 — own-envelope для не-скалярных query-параметров

## Статус

Реализовано 2026-09-26. Senior-review: четыре прохода (первый — NEEDS-CHANGES: S1 нереализуем,
потому что `get()` бросает раньше проверки; второй — NEEDS-CHANGES: `owner` вне `try`,
scope-пробел по `limit[]`/`tags`, неопределённый порядок; третий — NEEDS-CHANGES: `is_scalar`
хуже `is_string` по `ctype_digit`-депрекейшену, слияние `try` ломает пинованное тело,
бросок `TagController` вне `try`; четвёртый — APPROVE).

## Исходная посылка и почему она не воспроизводится

Roadmap описывал дефект так: `GET /api/collections?owner[]=x` не проходит проверку `is_string`
в `CollectionController::list`, управление падает в ветку `else`, клиент получает **свои**
коллекции вместо чужих, статус 200.

Эмпирика (живой `symfony/http-foundation` v7.4.19, `Request::create()` в контейнере приложения)
опровергла это: `InputBag::get()` (`vendor/symfony/http-foundation/InputBag.php:44-46`) **бросает**
`BadRequestException` («Input value … contains a non-scalar value») раньше, чем приложение успевает
увидеть массив. `BadRequestException implements RequestExceptionInterface`, а
`HttpKernel.php:82-83` конвертирует любой такой эксепшен в `BadRequestHttpException` → **400**.

Проверено end-to-end (регистрированный пользователь, валидный JWT, `APP_ENV=dev`):

| Запрос | Статус до | Тело до |
|---|---|---|
| `?owner[]=x` на `/api/collections` | 400 | фреймворковая HTML-страница (`text/html`) |
| `?name[]=x` на `/api/items` | 400 | та же HTML-страница |
| `?search[]=x` на `/api/tags` | 400 | та же HTML-страница |
| `?limit[]=1` / `?offset[]=1` везде | 400 | та же HTML-страница |
| `?tags=x`, `?tags=a&tags=b` на `/api/items` | 400 | та же HTML-страница (`Unexpected value for parameter "tags"`) |

Настоящий дефект — не подмена данных, а **чужой конверт**: JSON API отдавал `text/html`
с внутренним текстом фреймворка. Тот же класс, который мини-этап 5A чинит для 401/403/500
(fwd-17/19/21). Отдельно: `?name[]=a&limit=abc` на `/api/items` отдавал ошибку `name`, а не
пагинации, потому что `parseFilters` вызывался до `parsePagination` — приоритет
«структурные → доменные» (запинован `CollectionControllerTest:318`) был нарушен на практике.

## Решение

Выбран объём `owner` + `name` + `search` разом (решение пользователя): правка одинаковая,
политика PRD 5A третьего поведения не оставляет. Отступление от буквы PRD 5A («в этом мини-этапе
закрывается только owner») зафиксировано здесь же и в решении 8 того PRD.

Центральный приём: **читать сырой массив `$request->query->all()` вместо `get()`/`all($key)`**,
потому что оба последних бросают `BadRequestException extends UnexpectedValueException`,
который существующий `catch (\InvalidArgumentException)` не видит. Собственный бросок обязан
быть именно `\InvalidArgumentException`, иначе конверт снова чужой.

- `CollectionController::list` — `$owner = $request->query->all()['owner'] ?? null`; проверка
  типа **внутри** существующего внутреннего `try`, чтобы `Invalid owner id` осталась точной
  деталью для обоих случаев (тип и невалидный UUID) — этого требует пинованный тест
  `testListInvalidOwnerReturns400`. `OwnerId::fromString(string)` при массиве дал бы `TypeError`,
  поэтому сначала `is_string`, потом VO. Старая связка `null !== $owner && \is_string($owner)`
  удалена — это она и создавала иллюзию, будто массив куда-то «падает в else».
- `TagController::list` — существующий `try` расширен: `parsePagination` первым, затем чтение
  `search` из `all()` и проверка; бросок `'Invalid search: must be a string'`.
- `AbstractApiController::parsePagination` — чтение из `all()`; предикат
  `!\is_string($v) && !\is_int($v)` → throw; далее `ctype_digit((string) $v)`, потому что вызов
  `ctype_digit` на int/bool/float даёт deprecation (проверено; 5.24 его теперь видит) и `false`.
  `bool`/`float` отсекаются до вызова. Тексты сообщений, обход `'' !== $v` и range-проверки
  не тронуты. Ветки `!\is_string` недостижимы по HTTP, но достижимы программно —
  оставлены как defence-in-depth.
- `ItemController::parseFilters` — `name` из `all()`, не-строка → `'Invalid name: must be a string'`;
  `tags` как `$all['tags'] ?? []`, не-массив → throw, вложенный элемент-массив → throw
  (`'Invalid tags: must be an array of strings'`) вместо молчаливого отбрасывания, которое
  расширяло бы выборку. Вызов перенесён внутрь существующего `try` **после** `parsePagination`
  в обоих эндпоинтах (`listByCollection`, `listOwn`); других вызывающих нет. Нигде не
  использовать `InputBag::get()` и `ParameterBag::all($key)` для этих ключей.

Порядок ошибок: `?owner[]=x&limit=abc`, `?name[]=x&limit=abc`, `?search[]=x&limit=abc` отвечают
пагинационной причиной. `?owner=` (пустая строка) — это 400 `Invalid owner id` через
`OwnerId::fromString`; пустые `?name=`/`?search=` нормализуются в `null` («список всего»),
это задокументированное поведение, не тронуто. `?tags=a&tags=b` — last-wins-скаляр `b`,
значит 400; рабочая мульти-форма — только `?tags[]=a&tags[]=b`.

Инты из тестового клиента безопасны: конструктор `BrowserKit\Request` приводит значения к
строке (`array_walk_recursive`, `vendor/symfony/browser-kit/Request.php:36-38`), и
`HttpKernelBrowser::filterRequest:142` строит `Request::create` уже из строк.
(`TagControllerTest::testListPagination` с `['limit' => 2]` это подтверждает.)

## Тесты

17 новых (88 в трёх классах, зелёные): точное тело для `?owner[]=x`; приоритеты
`owner/name/search + limit`; `?owner[]=x&owner=y`; `?limit[]=1`, `?offset[]=1`; `?name[]=x`
(оба эндпоинта items); `?search[]=x`; `?tags=x`; `?tags=a&tags=b`; вложенный `tags[0][x]`;
пустой `?owner=`; регрессии — валидный uuid, `name/search` со строкой, `tags[]=a&tags[]=b`
(END-вариант с AND-семантикой), существующий int-тест пагинации.

OpenAPI: 400 за неверный **тип**, а не только значение (`CollectionController:262`,
`TagController:37`, `ItemController:51/:103`, `CommentController`, `LikeController`),
`tags` описан как `tags[]`; `public/api/openapi.json` перегенерирован.
Smoke: 12 новых точек с проверкой `Content-Type: application/json` — 38/38.
PHPStan чист. `composer ci:all` — см. CI прогона PR.

## Граница

400 на неверный тип query-параметра отдаёт **наш** конверт. Ветки `!\is_string`/`!\is_int`
в `parsePagination` недостижимы по HTTP и оставлены как defence-in-depth. Будущий listener
мини-этапа 5A не должен перемапливать 400 на query-параметрах (у них свои `details`).

Заведомо вне объёма (известные соседи, не регрессии): тело запроса десериализуется через
`Serializer::deserialize()` из сырого `$request->getContent()`, и битый JSON даёт
`NotEncodableValueException` → 500 фреймворковой страницей — отдельный класс чужого конверта,
кандидат для решений 4/5 мини-этапа 5A, здесь не трогается.

Общий хелпер вида `queryValue(Request, string $key): mixed` осознанно не вводится: он был бы
тонкой обёрткой над `$request->query->all()[$key] ?? null` и всё равно оставил бы каждому месту
свою проверку типа и своё сообщение — экономия нулевая, а правило проекта запрещает такие
обёртки. Правило «читать через `all()`, проверять тип, бросать `\InvalidArgumentException`
внутри существующего `try`» живёт в комментариях четырёх мест, в этом PRD и в ARCHITECTURE.
