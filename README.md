# TaskFlow

TaskFlow — это REST API для управления личными коллекциями (книги, марки, значки, виски и т.д.). 
Проект построен на Symfony 7 с использованием Clean Architecture и предназначен для локальной разработки через Docker Compose.

## Особенности

- Регистрация и аутентификация пользователей (JWT)
- Управление коллекциями (создание, редактирование, просмотр)
- Управление элементами коллекций (айтемами) с динамическими полями
- Тегирование элементов
- Лайки и комментарии к элементам
- Административные возможности (управление пользователями, коллекциями и элементами)
- Полнотекстовый поиск (Meilisearch)
- Документация API (OpenAPI/Swagger)
- Unit тесты для ключевых сценариев
- CI/CD с проверками качества кода

## Технический стек

- PHP 8.3+
- Symfony LTS
- Doctrine ORM
- Redis (очереди и кэш)
- Meilisearch (поиск)
- MySQL/PostgreSQL
- Docker Compose
- PHPUnit, PHPStan, PHP_CS_Fixer, Rector, Infection

## Архитектура

Проект следует принципам Чистой архитектуры с разбиением на слои:
- **Domain** — бизнес-логика, сущности, value objects, репозитории интерфейсы
- **Application** — сервисы приложения (use cases), DTO
- **Infrastructure** — реализации репозиториев, контроллеры, внешние сервисы (БД, поиск, очередь)

## Установка и запуск

Требуются установленные [Docker](https://www.docker.com/get-started) и [Docker Compose](https://docs.docker.com/compose/).

1. Клонировать репозиторий:
   ```bash
   git clone <repository-url>
   cd taskflow
   ```

2. Скопировать переменные окружения:
   ```bash
   cp .env.example .env
   # При необходимости отредактировать .env (по умолчанию настроено для разработки)
   ```

3. Запустить контейнеры:
   ```bash
   docker compose up -d
   ```

4. Сгенерировать JWT-ключи (один раз; с фразой из `JWT_PASSPHRASE`):
   ```bash
   docker compose exec app php bin/console lexik:jwt:generate-keypair --skip-if-exists
   ```

5. Выполнить миграции базы данных:
   ```bash
   docker compose exec app php bin/console doctrine:migrations:migrate
   ```

6. Приложение будет доступно по адресу: [http://localhost:8000](http://localhost:8000)
   Документация API: [http://localhost:8000/api/doc](http://localhost:8000/api/doc)

> **Примечание**: Если документация API не открывается, инициализируйте кэш:
> ```bash
> docker compose exec app mkdir -p var/cache/dev var/cache/prod var/log
> docker compose exec app chown -R www-data:www-data var/cache var/log
> ```

## Запуск тестов

Для запуска unit тестов:
```bash
docker compose exec app composer test
# или эквивалент:
docker compose exec app composer phpunit:no-coverage
```

> **Тестовая БД**: локальные тесты идут в отдельную БД `taskflow_test`, а не в dev `taskflow`.
> `tests/bootstrap.php` переопределяет `DATABASE_URL` значением из `.env.test`, если текущее
> значение ещё не указывает на `taskflow_test` (контейнер экспортирует dev-значение через
> `env_file`, которое иначе затеняет `.env.test`; CI сам задаёт `taskflow_test` и не трогается).
> Подготовить тестовую БД (один раз; для свежего volume создаётся автоматически через
> `docker/mysql/init/01-test-db.sql`):
> ```bash
> docker compose exec -e DATABASE_URL="mysql://taskflow:taskflow_pass@db:3306/taskflow_test?serverVersion=8.0" app sh -c "php bin/console doctrine:database:create --if-not-exists --env=test && php bin/console doctrine:migrations:migrate --env=test"
> ```
> Короткая форма для любых test-команд консоли — `scripts/console-test.sh` (Git Bash):
> URL берётся из `.env.test` автоматически. Локальный инструмент; путь CI не трогает.
>
> **Тестовый движок поиска**: с 6.3 часть сьюта идёт против настоящего Meilisearch, поэтому
> `meilisearch` должен быть поднят (`docker compose up -d` поднимает всё). Тесты пишут только
> в `items_test`/`collections_test` (forced в `phpunit.xml.dist`), продовые `items`/`collections`
> не трогаются. Если движок остановлен, падают только engine-backed тесты — остальной сьют зелёный.

### Мутационное тестирование (Infection)

Mutation testing проверяет, что тесты *ловят* изменение поведения, а не просто исполняют строки
(line coverage этого не показывает). Мутируется тот же слой, что и измеряется покрытием —
`src/Domain` + `src/Application`; прогон идёт по юнит-тестам (`tests/Domain` + `tests/Application`),
поэтому ни БД, ни поисковый движок не нужны.

```bash
# полный прогон по исходникам слоя (долго: ~1000 мутантов)
docker compose exec app composer mutation

# то же, что гоняет CI: только строки текущего диффа, покрывающие тесты, все потоки
docker compose exec app composer mutation:ci -- --git-diff-lines --git-diff-base=main
```

Отчёты (`MSI/MCC`, таблица по мутаторам, HTML) пишутся в `var/infection/`; временные файлы —
в `var/infection-tmp/`. Оба скрипта идут с `--show-mutations=0`: печать диффов выживших
мутантов роняет Symfony Console («Incorrectly nested style tag found.») на этом коде — диффы
доступны в HTML/JSON-отчётах. Порог `minMsi` живёт **только** в `infection.json`; CI его
не переопределяет, иначе локальный и CI пороги разъедутся молча. Полный прогон на CI
запускается вручную: `CI Pipeline` → *Run workflow* → вход `infection_full` (артефакт
`infection-reports`).

> **Почему `timeoutsAsEscaped: true`**: Infection по умолчанию считает таймаут
> «убитым» мутантом. Замер 2026-10-16 (первый полный локальный прогон) дал 647 таймаутов
> из 962 мутантов и MSI 97% при 295 реально убитых — число ничего не значит, пока таймауты
> не вынесены в выжившие. С флагом MSI считается честно.

### Поиск: перестроение индекса

Создание, изменение и удаление айтема индексируется сразу после коммита. Если движок был
недоступен (fail-open: запись в БД проходит, документ теряется) или индекс пустой, перестройте:

```bash
docker compose exec app php bin/console search:reindex                 # пачками по 100
docker compose exec app php bin/console search:reindex --batch-size=500
```

Команда идемпотентна: создаёт индексы и настройки, если их нет, затем upsert всех айтемов и коллекций.

> Fresh engine: записи сами создают индексы, но НЕ пушат настройки — любой поиск
> с фильтром на таком индексе отвечает 500 (`invalid_search_filter` движка).
> Поэтому после поднятия чистого Meilisearch (и в CI это делает каждый тест
> сам через `ensureIndexes()` в setUp) прогони `search:reindex` один раз до
> приёма трафика.

Для запуска тестов с покрытием (Xdebug включается через `XDEBUG_MODE=coverage`; `composer coverage:check` делает это автоматически):
```bash
docker compose exec app composer coverage:check
```

> **Покрытие в CI и локально**: в GitHub Actions `unit-tests` всегда гоняет тесты с
> покрытием и проваливает CI при `Lines < COVERAGE_MIN` (по умолчанию 80%,
> настраивается в `.github/workflows/ci.yml`). Локально `composer test` / `composer ci:all`
> идут без покрытия; с покрытием и тем же порогом:
> ```bash
> docker compose exec app composer coverage:gate
> ```

### E2E-сценарии (Bruno)

Сквозные пользовательские пути проверяются против **собранного стека**
(nginx → php-fpm → MySQL/Meilisearch; Redis поднят, но этими путями не задействован),
а не ядра в PHPUnit. Коллекция — plain-text
`.bru` в `tests/E2E/`; правка — в Bruno Desktop, запуск — CLI:

```bash
docker compose up -d
docker compose exec app php bin/console doctrine:migrations:migrate
npm install -g @usebruno/cli@4.2.1     # Node 20+
cd tests/E2E && bru run --env local --exclude-tags=smoke
```

Голый `bru run` в `@usebruno/cli@4.2.1` рекурсивен и подхватил бы `smoke/`; journeys всегда
гоняются с `--exclude-tags=smoke` (так же в CI-job). Локальный smoke — отдельно, см. ниже.

`--env local` берёт `baseUrl=http://localhost:8000`; CI-job **E2E (Bruno)** — то же на стеке из
`docker compose` с `--env ci` (`http://127.0.0.1:8000`), но перед прогоном journeys job
загружает демо-набор (`app:seed:demo --batch-size 500`) и проверяет его по HTTP
(login демо-админом → коллекций ≥1 → айтемов ≥1 — пре-степ `Seed demo data and
smoke-test it`). Обоснование выбора инструмента —
`PRD/8.1-e2e-bruno.md`.

> **Примечание**: после изменения `docker/php/custom.ini` или `Dockerfile` пересоберите образ:
> ```bash
> docker compose build app
> ```

Для запуска проверок качества кода (linting, static analysis, и т.д.):
```bash
docker compose exec app composer phpcs:check
docker compose exec app composer phpstan
docker compose exec app composer rector:dry-run
docker compose exec app composer audit
```

Доступен составной шорткат для CI:
```bash
docker compose exec app composer ci:all       # все проверки + тесты
docker compose exec app composer ci:static:quality  # только статический анализ
```

Symfony-aware диагностика (маршруты, DI-контейнер, Twig, переводы, конфиги бандлов) — внешний
checker [`symfony-lsp`](https://github.com/symfony/language-tools) (`symfony/language-tools`):
```bash
docker compose exec app composer ci:symfony-lsp                    # runtime-анализ (запускает приложение)
docker compose exec app composer ci:symfony-lsp -- --source-only   # статический анализ (без запуска приложения)
```

> В CI checker прогоняется **один раз** — runtime-анализ с `--environment=test` (приложение
> действительно запускается: маршруты, DI-контейнер, Twig, Doctrine-метаданные). Окружение задаётся
> явно, потому что checker по умолчанию берёт `dev` и не читает `.env`, а в `dev` `cache.app` —
> Redis-адаптер, и job'у потребовался бы сервис; в `test` приложение поднимается без БД, кэша и
> транспорта. Второй прогон `--source-only` снят в задаче `5.17`: parity-зонд (см.
> `PRD/5.17-parity-probe.md`) инжектировал 6 дефектов по классам route / template / service / parameter /
> bundle-config, и runtime-прогон нашёл **4 из 6** (два не видит ни один режим), а `--source-only` —
> **ни одного** (исправный прогон,
> `complete: true`, `analysis.mode: source-only`): все коды, которые checker предлагает, разрешают ссылки
> по runtime-индексу, поэтому лишний прогон только тратил время. Job с задачи `5.14` **блокирующий**:
> один прогон `--format=json` в файл, вердикт из него выносит `scripts/symfony-lsp-gate.php`
> (аннотации плюс проверка `complete: true`, `runtime.state: ready`, `blocking: 0`); только по
> `blocking` гейт не строится — при неполном индексе список пуст и `blocking: 0` (замерено в 5.14).
> Скрипт `scripts/symfony-lsp-check.sh` ставит закреплённую версию с проверкой SHA256; архив и список
> контрольных сумм кэшируются в `var/bin/` (в CI каталог переопределён на `var/symfony-lsp/bin`, чтобы
> попасть в общий кэш вместе с индексом checker'а), и на кэш-хите архив всё равно проверяется перед
> распаковкой, а бинарник всегда извлекается из проверенного архива (инвариант — «хеш проверен перед
> запуском», а не «скачиваем каждый раз»). Версия переопределяется переменной `SYMFONY_LSP_VERSION`,
> каталог — `SYMFONY_LSP_BIN_DIR`. Найденная ранее deprecated-настройка закрыта задачей `5.12`;
> на 2026-09-23 — 0 диагностик (runtime). Бинарник checker'а скачивается, а не вендорится,
> поэтому Dependabot его не видит: дрейф версии отслеживает еженедельный advisory-workflow
> `dependency-drift` (`scripts/check-tool-version-drift.sh` сверяет оба якоря пина —
> дефолт скрипта и env в `ci.yml` — с новым релизом апстрима и печатает только `::warning::`,
> в гейт не входит; детали — `PRD/5.14b-version-drift-cron.md`).

## Демо-данные для фронтенда

Для разработки фронтенда против локального стека — готовый набор: около 50
пользователей, 200 коллекций (по 50 на тему), 1000 айтемов, 40 тегов, лайки и
комментарии. Точный состав фиксирует золотой отпечаток `DemoStoryTest`
(числа здесь — ориентир, контракт — отпечаток).

```bash
docker compose up -d
docker compose exec -T app php bin/console doctrine:migrations:migrate
docker compose exec -T app php bin/console app:seed:demo
```

> Команда **полностью заменяет** содержимое dev/test-БД (каскадное удаление всех
> пользователей и тегов, затем загрузка демо-набора с пересборкой поисковых
> индексов). В `prod` она не запускается — guard по окружению.

Вход — любой из полусотни аккаунтов, пароль у всех одинаковый:

| email | роль |
|---|---|
| `admin@example.com` | admin |
| `user01@example.com` … `user49@example.com` | user |

Пароль: `password123`.

Идентификаторы демо-сущностей эфемерны (uuid7 генерируется при каждом сиде):
не храните их в фикстурах фронта и не вшивайте в ссылки — ходите через
`GET /api/collections`, `GET /api/collections/{id}/items` и поиск
(`GET /api/search/items`, `GET /api/search/collections`).

Опция `--skip-search` пропускает пересборку индексов (движок не нужен), но тогда
индекс хранит документы удалённых строк — перед использованием поиска прогоните
`search:reindex --reset`. Детали устройства сида — `PRD/9.3-demo-story.md` и
`PRD/9.4-seed-command.md`.

## Smoke tests

Быстрая локальная проверка «поднялось и отвечает» (~45 с, 5 запросов, только Bruno —
единообразие с E2E по `ADR/0002-e2e-client.md`):

```bash
docker compose up -d
cd tests/E2E && bru run smoke --env local
```

Папка `tests/E2E/smoke/`: register → login → свои айтемы → гостевой поиск → admin-403.
Создаёт одну строку юзера (`smoke_*`) в dev-БД за прогон — приемлемо на dev-стеке. Все 5 запросов
несут meta-тег `smoke`: голый `bru run` в `@usebruno/cli@4.2.1` рекурсивен и подхватил бы папку,
поэтому journeys всегда гоняются с `--exclude-tags=smoke` (и локально, и в CI-job). В CI smoke
не гоняется отдельным шагом: те же пути покрывает job `E2E (Bruno)`, и дублировать их ради
раннего падения не стали (решение — `PRD/8.2-smoke-bruno.md`).

## Структура проекта

```
src/
├── Controller/          # Системные контроллеры (health check и др.)
├── Domain/              # Доменный слой (сущности, value objects, репозитории, исключения)
│   └── User/
│       ├── Entity/      # Доменные сущности (User)
│       ├── Repository/  # Интерфейсы репозиториев
│       ├── ValueObject/ # Value objects (UserId, Email, PasswordHash, Role)
│       └── Exception/   # Доменные исключения
├── Application/         # Сервисы приложения (use cases), DTO
│   └── User/
│       ├── DTO/         # Data Transfer Objects
│       └── Service/     # Сервисы приложения (Registration, Authentication)
├── Infrastructure/      # Инфраструктурный слой
│   ├── Api/
│   │   └── Controller/  # REST API контроллеры (Login, Registration, Logout)
│   ├── User/
│   │   └── Repository/  # Реализация репозитория пользователя (Doctrine)
│   ├── Doctrine/        # Doctrine типы и расширения
│   ├── Security/        # Security провайдеры
│   └── Common/          # Marker интерфейсы слоёв
├── Kernel.php           # Ядро Symfony
└── ...

tests/                   # Юнит-тесты
# var/                   # Runtime данные (логи, кэш, etc.)
var/log/
var/cache/
var/data/
```

## Документация API

API документировано с использованием OpenAPI 3.0. После запуска приложения документация доступна по адресу:
[http://localhost:8000/api/doc](http://localhost:8000/api/doc)

## CI/CD

Проект использует GitHub Actions для непрерывной интеграции. Pipeline включает:
- Установка зависимостей
- Проверка стиля кода (PHP_CS_Fixer)
- Статический анализ (PHPStan)
- Запуск unit тестов с проверкой покрытия
- Rector (dry-run)
- Audit зависимостей (composer audit)

Дополнительные workflows:
- E2E тесты
- Мутационное тестирование (Infection)

## Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для подробной информации.
