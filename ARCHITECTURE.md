# Architecture — TaskFlow

## Обзор

TaskFlow — REST API для управления личными коллекциями. Clean Architecture на Symfony 7.

## Слои

```
┌─────────────────────────────────────────────┐
│              Infrastructure                  │
│  Controllers │ Repositories │ Doctrine │ etc │
├─────────────────────────────────────────────┤
│              Application                     │
│  Services (use cases) │ DTO │ Exceptions    │
├─────────────────────────────────────────────┤
│              Domain                          │
│  Entities │ Value Objects │ Repo interfaces  │
└─────────────────────────────────────────────┘
```

**Правило:** Зависимости идут только внутрь. Domain не знает ни о Application, ни о Infrastructure.

## Доменные сущности

### User (`src/Domain/User/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `User` | `Entity/User.php` | Сущность пользователя (id, name, email, passwordHash, role, isActive) |
| `UserId` | `ValueObject/UserId.php` | Бинарный UUID |
| `Email` | `ValueObject/Email.php` | Value Object email с валидацией |
| `PasswordHash` | `ValueObject/PasswordHash.php` | Хеш пароля (bcrypt) |
| `Role` | `ValueObject/Role.php` | Роль (user/admin) через RoleEnum |
| `UserRepositoryInterface` | `Repository/UserRepositoryInterface.php` | Интерфейс репозитория |

**Бизнес-правила:**
- Уникальность email
- Роли: `user`, `admin`
- Блокировка через `isActive`
- Пароль верифицируется через `verifyPassword()`

### Collection (`src/Domain/Collection/`)

| Компонент | Путь | Описание |
|-----------|------|----------|
| `Collection` | `Entity/Collection.php` | Коллекция (id, owner, name, theme, description, image) |
| `CollectionField` | `Entity/CollectionField.php` | Динамическое поле (id, collection, name, type, slotIndex) |
| `CollectionId` | `ValueObject/CollectionId.php` | Бинарный UUID |
| `CollectionName` | `ValueObject/CollectionName.php` | Название коллекции |
| `Theme` | `ValueObject/Theme.php` | Тема (Books, Games, Movies, Drinks) |
| `FieldType` | `ValueObject/FieldType.php` | Тип поля (text, number, date, bool) |
| `FieldName` | `ValueObject/FieldName.php` | Название поля |
| `CollectionFieldRepositoryInterface` | `Repository/CollectionFieldRepositoryInterface.php` | Интерфейс репозитория |

**Бизнес-правила:**
- Каждая коллекция принадлежит одному пользователю (owner)
- Максимум 100 полей на коллекцию (`MAX_FIELDS_PER_COLLECTION`)
- Поля имеют порядок через `slotIndex`
- Темы: Books, Games, Movies, Drinks

## Связи

```
User 1 ──── * Collection
Collection 1 ──── * CollectionField
```

## Application Services

| Сервис | Путь | Use Case |
|--------|------|----------|
| `RegistrationService` | `src/Application/User/Service/RegistrationService.php` | Регистрация пользователя |
| `AuthenticationService` | `src/Application/User/Service/AuthenticationService.php` | Аутентификация (login), JWT |

**DTO:**
- `RegisterUserDTO` — name, email, password
- `LoginUserDTO` — email, password
- `LoginResult` — accessToken, tokenType, expiresIn, user

## Infrastructure

| Компонент | Путь | Описание |
|-----------|------|----------|
| `LoginController` | `src/Infrastructure/Api/Controller/LoginController.php` | POST /api/login |
| `LogoutController` | `src/Infrastructure/Api/Controller/LogoutController.php` | POST /api/logout |
| `RegistrationController` | `src/Infrastructure/Api/Controller/RegistrationController.php` | POST /api/register |
| `DoctrineUserRepository` | `src/Infrastructure/User/Repository/DoctrineUserRepository.php` | Реализация репозитория User |
| `DoctrineCollectionFieldRepository` | `src/Infrastructure/Collection/Repository/DoctrineCollectionFieldRepository.php` | Реализация репозитория CollectionField |
| `UserProvider` | `src/Infrastructure/Security/UserProvider.php` | Symfony Security user provider |
| `ClockInjectListener` | `src/Infrastructure/Doctrine/Listener/ClockInjectListener.php` | Автоинъекция Clock в сущности |

**Doctrine Types:**
- `RoleEnumType` — маппинг RoleEnum
- `ThemeEnumType` — маппинг ThemeEnum
- `FieldTypeEnumType` — маппинг FieldTypeEnum

## CI/CD Pipeline

```
GitHub Actions
├── PHP CS Fixer (lint)
├── PHPStan (static analysis)
├── Rector (dry-run)
├── PHPCPD (copy-paste detection)
├── PHPUnit (tests + coverage)
└── Composer Audit (security)
```

**Локально:** `docker compose exec app composer ci:all`

## Инфраструктура (Docker)

| Сервис | Порт | Описание |
|--------|------|----------|
| app | 8000 | Symfony приложение |
| db | 3306 | MySQL |
| redis | 6379 | Redis (кэш, очереди) |
| meilisearch | 7700 | Поиск |

## Текущий статус

| Этап | Описание | Статус |
|------|----------|--------|
| 1 | Инфраструктура | ✅ завершён |
| 2 | Пользователь | ✅ завершён |
| 3 | Коллекция | 🔄 2/6 задач |
| 4 | Айтем | ⏳ |
| 5 | Социальное | ⏳ |
| 6 | Поиск | ⏳ |
| 7 | Админ | ⏳ |
| 8 | Тестирование | ⏳ |

---

*Обновлять при архитектурных изменениях.*
