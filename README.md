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

5. (Опционально) Загрузить фикстуры для разработки:
   ```bash
   docker compose exec app php bin/console doctrine:fixtures:load
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
docker compose exec app vendor/bin/phpunit
```

Для запуска проверок качества кода (linting, static analysis, и т.д.):
```bash
docker compose exec app vendor/bin/php-cs-fixer fix --dry-run
docker compose exec app vendor/bin/phpstan analyse
docker compose exec app vendor/bin/rector process --dry-run
docker compose exec app vendor/bin/phpcpd --src src
docker compose exec app composer audit
```

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
var/                     # VARIABLE данные (логи, кэш, etc.)
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
- Проверка на дублирование кода (PHPCPD)
- Rector (dry-run)
- Audit зависимостей (composer audit)

Дополнительные workflows:
- E2E тесты
- Smoke tests (docker compose up + проверка эндпоинтов)
- Мутационное тестирование (Infection)

## Вклад в проект

Если вы хотите внести вклад в проект:
1. Форкните репозиторий
2. Создайте ветку для своей функции или исправления
3. Внесите изменения
4. Убедитесь, что все тесты проходят
5. Отправьте Pull Request

## Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для подробной информации.