# Changelog

## Этап 5 — Социальное (core завершён 2026-09-17; этап не закрыт: periodic review 20.09 — в работе; 5.10 и smoke test выполнены)

### Фичи
- **Like domain** (5.1): сущность с UNIQUE `(owner_id, item_id)`, `LikeId`, каскадные FK, индекс `idx_like_item`.
- **Comment domain** (5.2): сущность с Markdown-контентом 1..3000 (`CommentContent`, переносы строк сохраняются), правка с no-op при нормализованно равном контенте, композитные индексы под сортировку.
- **LikeService** (5.3): `like`/`unlike` идемпотентны, `toggle`, `isLikedBy`, `countByItem`, `listByItem`, админский `removeLike`.
- **CommentService** (5.4): `create`/`changeContent`/`delete`, списки по айтему и по владельцу, счётчики, `CommentDTO.owner_name`.
- **Likes & comments API** (5.5): 9 эндпоинтов — лайки (`POST`/`DELETE`/`GET /api/items/{id}/likes`, `DELETE /api/likes/{id}`), комментарии (`POST`/`GET /api/items/{id}/comments`, `GET /api/comments`, `PATCH`/`DELETE /api/comments/{id}`); автор или админ на модерации; карта ошибок id→404 / контент→422 / тело→400.
- **Like domain tests** (5.6): прямые unit-тесты `LikeId` и `Like` (паритет с `Comment`).
- **Social moderation Voter** (5.10): `SocialContentVoter` (`SOCIAL_EDIT`/`SOCIAL_DELETE` для `Like`/`Comment`, автор или админ; `Like`+`SOCIAL_EDIT` запрещён всем); ручной JSON-403 в контроллерах сохранён.
- **Symfony-aware диагностика** (5.11): `symfony-lsp check` в CI (source-only пилот, non-blocking) + локальный `composer ci:symfony-lsp`; первая находка — deprecated `lexik_jwt_authentication.encoder.crypto_engine` (задача 5.12).

### Изменения поведения
- **Чтение айтемов и коллекций** открыто любому аутентифицированному пользователю (требование соцфункций); `GET /api/items` остаётся списком своих айтемов.
- **`GET /api/items/{id}`** отдаёт счётчики `likes_count`, `comments_count` и флаг `liked_by_me` (`ItemDetailDTO`); списки счётчики не отдают во избежание N+1.
- **Валидация → 422** (5.9): семантически невалидный контент (слот, пустой PATCH) и DTO-валидация отвечают `422`; раньше у айтемов/коллекций/логина/регистрации было `400`, у комментариев `422` уже было. Битый JSON и невалидные query-параметры остаются `400` (правило: 400 — синтаксис и параметры, 422 — семантика). Внешних клиентов у API нет, изменение бесплатное (fwd-18).

### Исправления
- Латентный 500 в `CollectionController::get` на некорректном UUID коллекции → 404.
- Списки айтемов и коллекций приведены к единому snake_case/ATOM-контракту (`toArrayPayload()`); ранее сериализовались camelCase-свойства DTO и сырые `DateTimeImmutable`.
- Некорректный id на путях лайков/комментариев → 404 вместо необработанного исключения (500).
- Битый JSON в `Item`/`Collection`/`Login`/`Registration` → `400` вместо необработанного serializer-исключения (500) (5.9).
- Некорректный id в `PATCH`/`DELETE /api/collections/{id}` → `404` вместо `500` (5.9).

### Рефакторинг
- **Единые конверты ошибок** (5.9): `AbstractApiController` получил `errorResponse()` и статусные хелперы (`unauthorized`, `notFound`, `forbidden`, `badRequest`, `unprocessable`, `conflict`, `internalError`); из 8 контроллеров удалены ~81 рукописный конверт и 23 guard'а `@var User`/`instanceof User`. В наследниках не осталось ни одного литерала `'error' =>` — конверт собирается в одном месте.
- **Тело ошибки не пересказывает внутренние сообщения** (5.9, ревью PR #74): `message` — стабильная обобщённая фраза (`Item not found` без id, `Invalid query parameters`, `Invalid content`, `Malformed request body`, `Forbidden`, `User already exists` без email), конкретика причин — в `details`; 56 конвертов и 6 примеров OpenAPI приведены к политике, из 409 убран email (user-enumeration).

### CI
- Провайдеры AI-ревью поменяны местами: OpenRouter free — основной, NVIDIA NIM — фолбэк; время ревью сократилось с ~13–15 мин до ~1 мин.
- `symfony-diagnostics`: `symfony-lsp check` в режиме source-only (пилот, non-blocking, GitHub-аннотации).
- `symfony-diagnostics` (5.13): job прогоняет checker **дважды** — source baseline и runtime-анализ с явным `--environment=test` (checker по умолчанию берёт `dev` и не читает `.env`; в `dev` кэш — Redis, в `test` приложение поднимается без БД/кэша/транспорта). Скачивание релиза получило retry с backoff и таймауты, а архив и `SHA256SUMS` кэшируются в `var/symfony-lsp/bin` с обязательной SHA256-проверкой на кэш-хите. Пилот остаётся non-blocking (блокирующим делает 5.14).

### Тесты
- +154 теста с Этапа 4 (615 tests суммарно; coverage gate ≥80% зелёный).
- 682 tests / 1889 assertions после 5.7–5.12 (638 на момент review 20.09; 5.10: 634; 5.11: +4 guard-теста); финальная цифра — при закрытии этапа.

## Этап 4 — Доменная модель: Айтем (завершён 2026-09-15)

### Фичи
- **Item entity** (4.1): 12 типизированных слотов (text/num/date/bool × 1–3), санитизация имени (trim, control-символы, whitelist), `MAX_TEXT_SLOT_LENGTH = 1000`.
- **Tag + item_tags M2M** (4.2): глобальные теги, регистронезависимая уникальность (коллация `utf8mb4_0900_ai_ci`), имя иммутабельно, join-таблица с каскадным удалением.
- **TagService find-or-create** (4.3): `resolveByNames` — нормализация/дедуп, атомарный MySQL upsert (`getOrCreate`), без flush в репозитории.
- **ItemService + DoctrineItemRepository** (4.4): CRUD, списки по коллекции/владельцу, `ItemSlotMapper` (ISO-8601/UTC), теги replace-by-TagId, JOIN FETCH против ghost-proxy.
- **Item API** (4.5): `POST/GET /api/collections/{id}/items`, `GET /api/items`, `GET/PATCH/DELETE /api/items/{id}`; фильтры `?name` (LIKE, ci) и `?tags[]` (AND); доступ владелец+админ.
- **Tag API** (4.7): `GET /api/tags?search=&limit=&offset=` — список/поиск для выбора при тегировании.

### Фиксы
- Ghost-proxy final-сущностей (Collection/User) во всех read-методах репозиториев Item/CollectionField — JOIN FETCH-цепочки.
- `ItemSlotMapper::asDate` — строгая ISO-8601 (Z, милли/микросекунды, offset-less), rejection джанка.
- `default => throw` в match-армах слотов (slot > 3 больше не пишет в text3 молча).
- Дедупликация исключений (Domain vs Application `CollectionNotFoundException`).

### Рефакторинг
- UoW: flush вынесен из репозиториев в Application (`UnitOfWorkInterface`), `transactional()` для атомарного create/update айтемов (fwd-1).
- `OwnerId` VO декоплт `findByOwnerId` от `User` (review-6).
- `ArrayableInterface` для response-DTO (fwd-3).
- `parsePagination` — общий для контроллеров, контракт выровнен (limit 1..100), границы — константы.
- `TagDTO` перенесён в bounded-context Tag (4.7).

### CI/инфраструктура
- AI review: Groq (мёртвые лимиты) → **NVIDIA NIM primary** (`gpt-oss-20b`) + OpenRouter free fallback.
- Coverage gate: консольный summary + clover, PHP-гейт `scripts/coverage-gate.php` (≥80%).
- `composer audit` — настоящий gate (убрано `|| true`); phpcpd удалён; symfony/cache 7.3→7.4.18 (CVE).
- Миграции сжаты в одну baseline (4 → 1); php-cs-fixer видит `migrations/`.

### Тесты
- 457 → 461 (1048 ассертов), покрытие измеряемых строк (Domain+Application) 99.59%.
- Интеграционные тесты на реальном MySQL (ci-поиск, ghost-proxy, атомарность, фильтры).

---

*Этапы 1–3 (инфраструктура, пользователь, коллекция) — до начала ведения changelog; описание в `Roadmap.md`.*