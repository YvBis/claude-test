# Changelog

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