# review-9 — контроль дрейфа образа Meilisearch

**Статус:** in progress
**Ветка:** `task/review-9-meilisearch-drift`
**Зависимости:** `ADR/0001-search-engine.md` (Pin-политика), задача `5.14b` (cron `dependency-drift`)

## Контекст (измерено 2026-10-20)

- `ADR/0001:105-108` требует минорный тег (`v1.54`) и обязывает его обновлять: апстрим
  патчит только последний минор. Переход на `2.x` — триггер нового ADR, не апдейт.
- `ADR/0001:109-112` прямо фиксирует долг: автоматического контроля образа **нет**,
  cron `dependency-drift` следит только за `composer`; заведена задача `review-9`.
- Пин живёт в двух продюсерах: `docker-compose.yml` (dev-стек) и
  `.github/workflows/ci.yml` (сервис-контейнер CI). Оба — `getmeili/meilisearch:v1.54`.
- Апстрим latest stable: **`v1.55.0`** (2026-10-12). То есть дрейф существует уже сейчас,
  на один минор. `v1.54.3` — патч нашего минора; политикой он не считается «свежим».
- Механика-образец: `scripts/check-tool-version-drift.sh` (advisory-only, `EXIT`-trap,
  always `exit 0`, два якоря, proof-хуки) и weekly workflow `dependency-drift.yml`
  вне merge-gate.

## Решения

**D1. Два продюсера — единственный источник.** Скрипт читает `docker-compose.yml` и
`ci.yml`; тест в значения не лезет. Ровно одно совпадение на файл; иначе «not checked».

**D2. Сравнение по `major.minor`.** Тег `v1.54` плавает к `1.54.3`, поэтому патч
игнорируется: иначе проверка красная вечно. Это следует из pin-политики, а не из удобства.

**D3. Трёхстороннее сравнение.** `pinned < upstream` — лаг; `=` — fresh; `pinned > upstream`
— «впереди» (не тревога). Числовое сравнение major, затем minor, через `10#`, чтобы
`08` не читался как octal.

**D4. Расхождение якорей — отдельная тревога, exact-string.** Политика требует **минорный
тег**, поэтому `v1.54` и `v1.54.3` в двух файлах — это расхождение, а не «выровняй патч».

**D5. Источник апстрима — GitHub `releases/latest` + подтверждение публикации образа.**
`/releases/latest` исключает пререлизы контрактом (как у lsp). Но релиз выходит раньше,
чем образ появляется в Docker Hub, поэтому лаг подтверждается пробой
`https://hub.docker.com/v2/repositories/getmeili/meilisearch/tags/vX.Y` **только когда пин
позади того же мажора**: `200` — лаг, `404` — «образ ещё не опубликован» (не лаг),
любой другой статус (429/5xx/таймаут) — «unverifiable», тоже не лаг. Проба не выполняется
в стабильном состоянии: иначе флап Docker Hub превращал бы доказанный «fresh» в
«not checked».

**D11. `gh` требует токен в Actions — иначе весь cron молчит.** `gh` не получает
`GITHUB_TOKEN` из окружения автоматически; без явного `GH_TOKEN` каждый вызов падает,
`|| true` это глотает, и обе проверки — lsp-проверка с 5.14b тоже — рапортуют
«not checked» каждую неделю. Нашёл senior-review на PR: до этой правки cron существовал,
но ничего не сверял. Токен передаётся `env: GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}` на job,
guard-тест пинит переменную, потому что режим отказа здесь — тишина.

**D6. `2.x` — отдельная формулировка.** Warning говорит «ADR-0001 review trigger: new major»,
а не «обнови тег»: мажор по политике не апдейт.

**D7. Advisory-only.** Всегда `exit 0`, `::warning::` + `GITHUB_STEP_SUMMARY`, workflow
остаётся вне `ci-summary needs` и вне ruleset.

**D8. Рефактор ради второго блока.** Скрипт сегодня терминируется `exit 0` в семи местах
(`:59,64,73,85,106,124,130`), поэтому дописанный блок был бы недостижим на любом пути.
Тело → `check_symfony_lsp()`, новое → `check_meilisearch_image()`, внутри `exit 0` →
`return 0`, один `trap on_exit EXIT` в конце, вызовы обеих функций. Текст trap обобщён:
он больше не утверждает, что упала именно lsp-проверка.

**D9. Proof-хуки.** `DRIFT_IMAGE_PINNED` подменяет пин, `DRIFT_IMAGE_UPSTREAM` — GitHub-репу
(имя честное: это репозиторий релизов, не namespace образа). Workflow не содержит ни одного
из них — guard-тест сканирует файл целиком.

**D10. Бамп `v1.55` — в этом же PR.** Иначе мержим чекер, красный в первый прогон.

## Реализация

1. `scripts/check-tool-version-drift.sh` — рефактор (D8) + блок образа (D1–D6, D9).
2. `docker-compose.yml` и `.github/workflows/ci.yml` — `v1.54` → `v1.55` (D10).
3. `tests/Infrastructure/Ci/DependencyDriftConfigTest.php` — новые хуки запрещены в
   workflow; скрипт читает оба продюсера и `getmeili/meilisearch`; шаг переименован,
   фильтр по имени обновлён; тавтологичное «скрипт содержит `%%.*`» не добавляется.
4. Шапки `dependency-drift.yml` и скрипта описывают обе проверки.
5. `ADR/0001:109-112` — абзац переписан: контроль есть, долг закрыт.
6. Roadmap `review-9` → done, AssumptionLog — запись.

## Доказательства

| Сценарий | Как | Ожидание |
|---|---|---|
| fresh | `DRIFT_IMAGE_PINNED=1.55` | «matches», exit 0 |
| stale | `DRIFT_IMAGE_PINNED=1.54` | warning + рецепт, exit 0 |
| unreachable | `DRIFT_IMAGE_UPSTREAM=foo/nope-xyz` | «not checked», exit 0 |
| anchors disagree | временная правка одного файла, затем откат | warning про политику |
| guard red ×2 | хук в workflow; удалённый якорь из скрипта | тест красный, затем откат |
| движок | `pull` → `up -d` → `/health` → `/version` → `search:reindex` | 1.55.x, suites зелёные |

`bash -n scripts/check-tool-version-drift.sh`, `DependencyDriftConfigTest`, `ci:all`.

## Риски

- **Docker Hub probe**: недельный запрос, анонимный лимит не достигается; сеть/`404` →
  «not checked» (advisory, не ложный лаг).
- **1.55 breaking change**: по релиз-нотам единственное — порча binary-quantized embeddings
  при отключении квантования. В коде нет ни эмбеддингов, ни векторов (`embedder|vector|hybrid`
  — ноль совпадений), поэтому риск не наш.
- **Код ошибки `index_already_exists`** (`MeilisearchSearchAdapter.php:185`) — строка
  матчится; при переименовании в 1.55 повторное создание индекса станет исключением.
  Закрывается engine-backed тестами и `search:reindex`.
- **Раундтрип настроек** — `MeilisearchEngineAcceptanceTest` (`:39-53`) и `IndexSettings`.
- **Том `meilisearch_data`** (`docker-compose.yml:83`) мигрируется апстримом вперёд
  односторонне. Проверено на живом стеке: `v1.55` **отказывается стартовать** на томе от
  1.54.3 (`database version (1.54.3) is incompatible with your current engine version
  (1.55.0)`) и требует одноразового `meilisearch --upgrade-db`. В CI том пустой, поэтому
  шаг не нужен; локально данные восстанавливаются `search:reindex`.

## Результаты доказательств (2026-10-20)

- Ветви проверки (9 кейсов оффлайн-стенда, все exit 0): fresh / stale / ahead / major /
  unpublished (404) / unverifiable / unreachable / unparseable / lsp-блок не сломан
  рефактором; отдельный кейс доказывает, что обе проверки идут в одном вызове.
- Anchor-парсинг: и счётчик, и извлечение тега работают по одним и тем же
  `image:`-строкам — комментарий с историей версий больше не может подделать якорь
  (найдено senior-review).
- Реальное дерево: `anchors agree`, пин `v1.55` = upstream-минор. Временный откат compose
  до `v1.54` даёт тревогу о расхождении продюсеров (откат не попал в дерево).
- Guard-тест: 6 тестов / 34 ассерта. Четыре красных доказательства сняты и откатаны —
  хук в workflow, удалённый якорь `docker-compose.yml`, удалённый вызов
  `check_meilisearch_image` (регрессия мёртвого кода), отсутствующий `GH_TOKEN`.
- Движок: образ `v1.55` опубликован (pull успешен), `/version` = `1.55.0`,
  `/health` = available, `search:reindex` = 4 item + 4 collection, 42 search-теста зелёные.
- Побочно найден и обновлён существующий parity-guard `MeilisearchCiConfigTest` с
  захардкоженным `v1.54`.

## Вне объёма

- Пиннинг по digest, алерты на патч-уровень, Dependabot для docker-образов.
- Контроль `mysql`/`redis`/`nginx` образов (нет pin-политики в ADR).
