# PRD: fwd-11 — политика авто-генерируемых трекаемых артефактов

Задача из Review Backlog (`Roadmap.md`, строка `fwd-11`). Реализация — 2026-09-27,
в одном PR с `fwd-18` (решение пользователя: вклинить). Три подпункта:

- (a) `config/reference.php` — политика хранения;
- (b) свежесть `public/api/openapi.json` — блокирующий чек;
- (c) ложь `bin/console --env=test` про целевую БД — обёртка `scripts/console-test.sh`.

## (a) `config/reference.php`: untrack + gitignore

**Атрибуция (2026-09-27, выполнена до реализации).** `config:dump-reference` не
встречается нигде в проекте (только в `vendor/.../CHANGELOG.md`); файл — снимок от
2026-08-18 (Task 3.2), чистый в покое. Проба: `git status --short config/reference.php`
после каждого шага — `composer phpstan`, `composer phpcs:check`, `composer
rector:dry-run`, `composer phpunit:no-coverage` (весь `ci:all`) файл **не** трогают;
`composer update --lock` (Flex auto-scripts) — трогает (+341/−154, откачено).

Вывод: посылка строки Roadmap («регенерировать в CI + проверять диф») неверна —
в CI файл никто не регенерирует. Конечное состояние: `git rm --cached
config/reference.php` + строка в `.gitignore`. Обоснование: файл не читает никто
(`.php-cs-fixer.dist.php:10` и `.symfony-lsp.json:3` его исключают, `phpstan.neon`
анализирует только `src`); это `PhpConfigReferenceDumpPass` — debug-схема,
зависящая от окружения (DAMA только в test, Nelmio в dev+test), регенерация даст
diff от погоды и не поймает deprecated-ключи. Ручной дамп — по требованию командой
`config:dump-reference`.

## (b) Свежесть `openapi.json`: блокирующий чек

`public/api/openapi.json` трекается, но ничто не проверяет его свежесть — гард
`OpenApiSpecTest` (fwd-23) читает закоммиченный файл и проходит на устаревшем.
Решение пользователя: чек блокирующий, сравнение нормализованное.

- Предусловие: два дампа подряд побайтово идентичны (детерминированность) —
  иначе нормализованное сравнение бессмысленно.
- `scripts/check-openapi-fresh.sh`: дамп в `mktemp` (`--format=json --no-pretty`,
  тот же `APP_ENV`, без `--server-url` — дословно как `composer.json:101`),
  нормализация обоих JSON (сортированные ключи), `diff`; при расхождении —
  «run composer openapi:generate», `exit 1`. Без мутации дерева (не overwrite +
  `git diff`, а tmp + `cmp` — спецификация синьором исправлена, план был неверен).
- `composer.json`: `"openapi:fresh"` + `@openapi:fresh` первым в `ci:static:quality`
  (джоба уже провижинит mysql/redis, первая загрузка ядра дешёвая); блокировка —
  через существующий `needs` `ci-summary`). Новый джоб не заводится.
- Дамп (и в `openapi:generate`, и в скрипте) обязан пиннить `--env=dev`: все
  компонентные схемы живут в `config/packages/dev/`, а CI копирует `.env.test`
  поверх `.env` (setup-ci), т.е. грузится с `APP_ENV=test` — без флага каждый
  `$ref` неразрешим и дамп падает (`$ref "#/components/schemas/" not found`,
  впервые поймано этим же чеком в CI PR #106; воспроизведено локально через
  `APP_ENV=test`, флаг побеждает — дамп побайтово идентичен). Гард пиннит и флаг.
- Гард в `tests/Infrastructure/Ci/`: парсит `composer.json` как JSON (приём
  `SymfonyLspConfigTest.php:28-43`, не подстрока) и проверяет, что
  `ci:static:quality` содержит `@openapi:fresh`.
- `composer openapi:validate` (`|| true`, никогда не падает) в гейт не тащим.
- Негативная проба: правлю аннотацию без регенерации → красное; откат → зелёное.

## (c) `console-test.sh`: обёртка против `env_file`-тени

Корневая причина (2026-09-27): `docker-compose.yml:7-8` объявляет `env_file: - .env`,
поэтому `DATABASE_URL` из `.env:3` попадает в реальное окружение контейнера, а
Symfony Dotenv реальные переменные не перекрывает — `.env.test:12`
(`taskflow_test`) бессилен, и `bin/console --env=test` в контейнере ходит в dev-БД
(в fwd-27 миграция «для test» отработала по dev). Снос `env_file` отвергнут (`.env`
нужен контейнеру шире Symfony); `docker compose --env-file .env.test` отвергнут
(меняет все переменные разом, dev-ключей там нет).

- `scripts/console-test.sh`: читает `DATABASE_URL` из `.env.test`, зовёт
  `docker compose exec -e DATABASE_URL=<url> app php bin/console --env=test "$@"`.
  Требования: кавычки вокруг URL (`?`/`&`), `exec` (не `run`), громкий фейл без
  контейнера/ключа, executable-бит + LF (правило 5.21).
- Доказательство: `scripts/console-test.sh doctrine:query:sql "SELECT DATABASE() AS
  db"` → `taskflow_test`; та же команда без обёртки → `taskflow`
  (фолбэк-проба — `dbal:run-sql`, если `doctrine:query:sql` нет в ORM 3.7).
- Строка в README: инструмент локальный, путь CI не трогает (CI поднимает тестовую
  БД сам, `ci.yml:173-180`).

## Вне объёма

- Удаление плоского DELETE (это fwd-18, соседний PRD).
- `Sunset`-заголовок и дата удаления плоского пути (продуктовое решение).
- Регенерация `config/reference.php` в CI (отвергнуто атрибуцией).

## Декомпозиция

1. Атрибуция (a) — выполнена, вывод выше (0.25ч)
2. PRD fwd-18 + fwd-11 (0.25ч)
3. fwd-18: аннотации + 4 теста + Roadmap/гейт (0.5ч)
4. (b) детерминированность → скрипт → wiring → гард → негативная проба (0.5ч)
5. (c) скрипт → доказательство → README (0.25ч)
6. (a) `git rm --cached` + `.gitignore` (0.25ч)

## Приёмочные критерии

- [x] fwd-18: вложенный канонический, плоский `deprecated: true` в спеке и рабочий;
      4 теста зелёные; гейт этапа 6 не ждёт 5A
- [x] (b) умышленно устаревшая спека краснеет в `ci:all`, свежая зеленеет; гард
      пиннит wiring; `openapi.json` в дифе обновлён
- [x] (c) `SELECT DATABASE()` через обёртку → `taskflow_test`; README обновлён
- [x] (a) `config/reference.php` не трекается; в `.gitignore`; `git status` чист
      после `composer update --lock` (файл перегенерируется, но не трекается)
- [x] `composer ci:all` зелёный; `composer audit` чист
- [x] Self-review + 3 ревьюера (senior SHIP-WITH-NITS, архитектор SHIP-WITH-NITS, техлид APPROVE — разобрано) → сквош → push → CI зелёный; мерж за пользователем
