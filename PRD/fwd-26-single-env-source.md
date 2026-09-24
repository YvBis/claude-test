# fwd-26 — Единый источник env для контейнера

## Проблема

`docker-compose.yml` задавал `env_file: .env` и одновременно `environment:`-блок
из шести ключей для сервиса `app`. В Compose `environment:` перебивает
`env_file`, поэтому правка `.env` для этих ключей молча не действовала:
файл говорил одно, контейнер видел другое, и отладка шла по неверному
источнику.

Состав блока проверен ключ за ключом:

| Ключ | Дубль в `.env` | Кто читает |
|---|---|---|
| `DATABASE_URL` | да, строка 3 | Doctrine |
| `REDIS_HOST`, `REDIS_PORT` | да, строки 7-8 | `framework.yaml:16` собирает DSN |
| `MEILISEARCH_URL` | да, строка 5 | никто (бандла нет, Этап 6) |
| `REDIS_URL` | нет | никто — DSN собирается из HOST/PORT |
| `MEILISEARCH_MASTER_KEY` | нет (в `.env` другой ключ — `MEILISEARCH_KEY`) | никто |

## Решение

- Удалить `environment:` (`docker-compose.yml:13-19`) целиком.
  `env_file: .env` — единственный источник. CI compose не использует вовсе.
- Переписать комментарий `.env.test:3-5` по фактическому пути каждого ключа:
  `tests/bootstrap.php:14` не грузит `.env` в test-окружении, строки 26-32
  извлекают **только** `DATABASE_URL`; остальное test-окружения даёт
  `phpunit.xml.dist:50-53` (`APP_ENV`, `KERNEL_CLASS`, `DEFAULT_URI`,
  `JWT_PASSPHRASE`); `messenger.yaml:10-15` под `when@test` форсит
  `in-memory://`, поэтому `MESSENGER_TRANSPORT_DSN` в тестах не используется;
  `MEILISEARCH_*` не читается нигде. Про `APP_SECRET` не утверждаем: путь не
  отслеживается, догадки в комментарий не идут.
- `.env.example`: добавить `DEFAULT_URI=http://localhost:8000` и
  `JWT_PASSPHRASE=CHANGE_ME_GENERATE_WITH_OPENSSL_RAND`. Без них свежий клон
  по `README.md:49` не поднимается (`routing.yaml:5` и
  `lexik_jwt_authentication.yaml:4` требуют оба ключа). Дефект найден попутно,
  существовал до fwd-26 — `environment:` их тоже никогда не давал.
- `PRD/1.1-docker-compose.md:24,48`: убрать `REDIS_URL` и
  `MEILISEARCH_MASTER_KEY` из перечня env приложения, снять обещание
  «consistent naming», которого нет.

## Не входит

- `MEILI_MASTER_KEY` (`docker-compose.yml:63`, серверный ключ самого
  Meilisearch) — не трогать. Расхождение имён `MEILISEARCH_KEY` /
  `MEILISEARCH_MASTER_KEY` зафиксировано здесь как находка для Этапа 6:
  приложение не читает ни один из ключей.

## Верификация

- Структурная проверка: `.services.app.environment` в `docker-compose.yml`
  отсутствует (`Yaml::parseFile`; `yq` нет ни на хосте, ни в контейнере).
  Диф `docker compose config` неинформативен — значения дублей и `.env`
  идентичны.
- `printenv` внутри контейнера после `up -d --force-recreate`: `REDIS_URL` и
  `MEILISEARCH_MASTER_KEY` исчезли, `DATABASE_URL`/`REDIS_HOST`/`REDIS_PORT`/
  `MEILISEARCH_URL`/`MESSENGER_TRANSPORT_DSN`/`JWT_PASSPHRASE`/`DEFAULT_URI`/
  `APP_SECRET` на месте.
- `composer ci:all`: 705 тестов, 2083 ассерта, exit 0.
- `cache:pool:clear cache.app --env=dev`: OK.
- Smoke 25/25 (`docker compose cp var/smoke515.sh app:/tmp/smoke.sh`
  — `var/` анонимный volume).
- Свежий клон: `cp .env.example .env` + recreate → `GET /health` 200
  (локальный `.env` перед пробой забэкаплен и возвращён байт-в-байт).
  `/health` ключи не трогает: JWT-путь требует `config/jwt/*.pem`
  (`README.md` шаг 4, `lexik:jwt:generate-keypair`), без них поднимается
  контейнер, но не auth.

## Связи

- Закрывает наблюдение `AssumptionLog.md:138-140` и дубликацию, оставшуюся
  после закрытия `fwd-10`.
- Оговорка: `.env` не трекается — машина с минимальным `.env` могла держать
  `REDIS_URL`/`MEILISEARCH_MASTER_KEY` только за счёт блока. Оба ключа
  приложением не читаются, ломаться нечему; заметка в CHANGELOG.
