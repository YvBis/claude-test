# review-17 — Многобайтовые длины: parity с контрактом

**Статус:** done
**Задача Roadmap:** review-17 — свип класса «длина меряется байтами там, где контракт меряет code points»

## Проблема

Домен мерил длину через `\strlen` (байты), а парные `Assert\Length` в DTO и OA `maxLength`
меряют **Unicode code points** (вентдор: `Length.php:59` — `COUNT_CODEPOINTS`, `LengthValidator.php:56`
— `mb_strlen($value, $constraint->charset)` с charset UTF-8 по умолчанию). На ASCII разницы нет,
на не-ASCII — расхождение в разы.

**Достижимый путь (найден periodic review этапа 8):**

```
POST /api/collections {name: <60 кириллических символов>}
  CreateCollectionDTO: Assert\Length(max: 100) → 60 code points → проходит
  CollectionName::fromString → \strlen = 120 байт > 100 → InvalidArgumentException
  CollectionController::create:92 → вызов вне try (try покрывает только десериализацию 84-90)
  ApiExceptionSubscriber:54,63 → маппит только AccessDenied/HttpExceptionInterface
  → 500 {"error":"Internal Server Error"} вместо 201
```

Тот же дефект на `PATCH /api/collections/{id}` (контроллер `:328` → `CollectionService:66`).
Латентный брат — `User::changeName` (`User.php:155`): вызовов в `src/` нет, но границы заданы
тем же способом.

## Скоуп

**Чинится:** `CollectionName.php:28,32` (min и max — оба на байтах), `User.php:155`,
докблоки `CollectionName.php:13,16` («bytes» → «code points»).

**Не чинится (с причинами, записанными здесь):**
- `UuidBinaryValue.php:21` — `16 !== \strlen($bytes)` — законная байтовая проверка бинарного UUID
  (16 байт — это контракт типа), не текстовый ввод.
- `PasswordHash.php:53` — минимум пароля на байтах против code points в DTO: та же латентность,
  но тема принадлежит `review-15` (bcrypt/72 байта), где обе стороны решаются вместе; здесь
  фиксируется факт и передаётся (строкой в Roadmap и причиной в allowlist).
- Графемы (`grapheme_strlen`) — **сознательно нет**: Symfony `Length` считает code points,
  цель — parity с контрактом, а не «улучшение». Графемы разошлись бы на ZWJ/комбинирующихся
  последовательностях.
- **`Item.php:310` — явный charset у `mb_strlen`** (ноль поведения: дефолт
  `mb_internal_encoding` — UTF-8). Планировалось выровнять в этом PR, но замер показал цену:
  строка попадает в diff-скоуп мутационного гейта, а её covering-set — самый широкий в домене,
  из-за чего локально пять мутантов стабильно упираются в 60-секундный таймаут (полный
  infection-suite в coverage-режиме здесь идёт 74 с, то есть дольше самого таймаута).
  Выравнивание отложено как не относящееся к дефекту; `Item`-тесты при этом остаются — они
  пиннят code-point семантику текстового слота.

## Декомпозиция

Задача **атомарная**: один сабтаск (фикс 3 строк + тесты), оценка 2ч (поднята с 1ч — тестов
стало больше по ревью). Дробить не на что: разделение «домен» и «тесты» дало бы красный коммит.

## Тесты (Red → Green)

**Домен `CollectionNameTest`** (мутационный гейт видит только `tests/Domain` + `tests/Application`):
- `fromString('аб')` бросает «at least» — **Red сейчас** (2 code points, 4 байта проходят `strlen >= 3`);
- `fromString('абв')` принят (pin min-границы);
- 60 кириллических принято, значение round-trip (max-сторона);
- 100 code points эмодзи U+1F600 принято (astral, 400 байт);
- ZWJ-последовательность >100 code points отвергнута — механический анти-графемный страж.

**Домен `UserTest`:** 100 кириллических принято, 101 отвергнуто `changeName` (без нового trim'а).

**Домен `ItemTest`** (контрактные пины, не мутационные: строка `Item.php:310` из диффа убрана):
ровно `MAX_TEXT_SLOT_LENGTH` ASCII принято, 600 кириллических принято (600 code points против
1200 байт), превышение на кириллице сообщает **code-point** счётчик (`got 1001 chars`).

**HTTP `CollectionControllerTest`:**
- POST 60 кириллиц → **201** (регрессия: сейчас 500) + assert имени в ответе (round-trip utf8mb4);
- POST 100 эмодзи → 201 (4-байтовые code points);
- POST 101 кириллиц → 422 «Collection name cannot exceed 100 characters»;
- PATCH 60 кириллиц → 200 (тот же дефект через `CollectionService:66`);
- POST 101 ASCII → 422 (поведение pinned; ASCII-границы уже покрыты VO-тестами, дублировать
  100-символьный ASCII-кейс на HTTP-уровне не нужно).

**Guard свипа:** `MultibyteLengthGuardTest` сканирует **`src`** (не только `src/Domain`:
контракт живёт в Application DTO и Infrastructure OA) на `strlen(` в любой записи — с
квалификатором, без него и в верхнем регистре; `mb_strlen` не матчится. Allowlist —
`UuidBinaryValue.php` + `PasswordHash.php`, каждая запись с причиной. Три негативных зонда:
новый сайт, второй сайт внутри allowlisted-файла, протухшая запись allowlist.

## Доказательства

- Pre-fix `grep -rn '\bstrlen\s*(' src` → 5 вызовов в 4 файлах (2 дефектных, 1 латентный, 2 законных), 0 в Application/Infrastructure.
- Post-fix тот же греп → 2 вызова (`UuidBinaryValue:21`, `PasswordHash:53`) + латентный ушёл.
- `composer openapi:generate` → спек-файл **байт-идентичен** (значения и сообщения не менялись).
- `ValidationConsistencyTest` → таблица не меняется (значения те же).
- Мутанты MBString (`mb_strlen` → `strlen`) на обеих тронутых строках `CollectionName:28,32` и на
  `User:156` убиваются тестами из мутационного suite (`tests/Domain` + `tests/Application`);
  HTTP-кейсы вне suite и служат контрактным доказательством, а не мутационным.
- `composer coverage:gate` — доменное изменение гейт-релевантно, `ci:all` его не включает.
- Diff-scoped мутационный прогон локально (`--git-diff-lines --git-diff-base=origin/main`,
  тот же гейт, что в CI): **10 мутантов, все убиты, MSI 100%, exit 0**. Промежуточный замер с
  выровненным `Item.php:310` дал 5 таймаутов на этом covering-set (60 с) — причина откладывания
  выравнивания, см. «Не чинится».

## Критерии приёмки

- [x] Pre/post-грепы записаны; post показывает ровно 2 законных вызова
- [x] Все тесты матрицы зелёные; min-сторона (`'аб'`) имеет Red→Green
- [x] `ci:all` и `composer coverage:gate` зелёные
- [x] OpenAPI-файл байт-идентичен
- [x] Guard свипа есть: `src` целиком, матчер ловит все записи `strlen(`, три негативных зонда
- [x] AssumptionLog: факт `PasswordHash:53` передан в review-15 (и строкой Roadmap, и allowlist-причиной); записано, что review-17 заменяет решение 8.6 «document only»
- [x] Roadmap: `review-17` → `done (PRD/review-17-multibyte-lengths.md)`, счётчики не меняются (8/8, 55/55); открыт `review-18` (enabler 500-класса), решение по единице длины закреплено в `ADR/0003-text-length-unit.md`
- [x] Один коммит `task/review-17-multibyte-lengths` (`3c70a4b`), PR #171 (8/8 проверок зелёные), тредов ревью нет; вердикт ревью-бота на финальном head — «looks good to me»
