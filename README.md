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

4. Выполнить миграции базы данных:
   ```bash
   docker compose exec app php bin/console doctrine:migrations:migrate
   ```

5. Приложение будет доступно по адресу: [http://localhost:8000](http://localhost:8000)
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
docker compose exec app composer ci:symfony-lsp -- --source-only   # статический анализ, как в CI
```

> В CI checker работает в **пилотном** режиме: `--source-only` (приложение не запускается), публикует
> аннотации и не блокирует мерж (`continue-on-error: true` в `.github/workflows/ci.yml`). Скрипт
> `scripts/symfony-lsp-check.sh` ставит закреплённую версию с проверкой SHA256 в `var/bin/`; версия
> переопределяется переменной `SYMFONY_LSP_VERSION`. На момент запуска пилота активных находок нет;
> найденная deprecated-настройка заведена задачей `5.12` в `Roadmap.md`.

## Smoke tests

После запуска приложения проверьте доступность основных эндпоинтов:
```bash
curl -I http://localhost:8000/api/doc
curl -I http://localhost:8000/api/register
```
Должен возвращаться HTTP 200 или 401 (для защищённых эндпоинтов).

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
- Smoke tests (docker compose up + проверка эндпоинтов)
- Мутационное тестирование (Infection)

## Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для подробной информации.