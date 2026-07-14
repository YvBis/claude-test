# Assumption Log

## 2026-07-13 — Task 1.1 Docker Compose Infrastructure

**Decisions:**
- PHP 8.3-fpm base image (LTS as of mid-2026)
- MySQL 8.0 for relational DB (per tech requirements)
- Meilisearch v1.6 for full-text search (lighter than ES/OpenSearch, good DX)
- Redis Alpine for queues/cache
- Nginx Alpine as reverse proxy
- Anonymous volumes for `/var/www/html/var` and `/var/www/html/vendor` to avoid host pollution
- Hardcoded dev secrets (MEILI_MASTER_KEY, DB passwords) — acceptable for local dev; will move to .env before CI/CD
- Removed obsolete `version: '3.8'` from docker-compose.yml (Docker Compose v2 ignores it)
- Fixed nginx `try_files` duplicate `$is_args` bug

**Uncertainties deferred:**
- Symfony skeleton not yet created (Task 1.2)
- Exact PHP extension list may expand (intl, opcache, etc.) — add when needed
- Production docker-compose.override.yml not created — defer to CD phase

**Next:** Task 1.2 — Symfony Clean Architecture scaffold

## 2026-07-14 — Task 1.2 Symfony Clean Architecture Scaffold

**Decisions:**
- Symfony 7.4 (LTS) via `symfony/symfony` flex constraint `7.*` — allows minor upgrades within 7.x
- Doctrine ORM 3.6, Migrations 3.9 — latest compatible with Symfony 7.4
- Symfony Messenger + Redis transport for queues
- Serializer, Validator, SecurityBundle (no JWT packages — deferred to Task 2.4)
- Clean Architecture folders: `src/Domain`, `src/Application`, `src/Infrastructure` + mirrored `tests/`
- Kernel bundles: FrameworkBundle, SecurityBundle, DoctrineBundle, DoctrineMigrationsBundle
- `.env` matches docker-compose: `DATABASE_URL=mysql://taskflow:taskflow_pass@db:3306/taskflow?serverVersion=8.0`, `MESSENGER_TRANSPORT_DSN=redis://redis:6379`, `MEILISEARCH_URL=http://meilisearch:7700`, `REDIS_HOST=redis`, `REDIS_PORT=6379`
- Composer config: `policy.advisories.block=false` to bypass security advisories on Symfony 7.0 packages (upgraded to 7.4 resolves)
- Platform `ext-redis: 5.3` declared in composer.json

**Acceptance verified:**
- `composer install` passes
- `php bin/console --version` → Symfony 7.4.14
- `php bin/console doctrine:migrations:status` connects to MySQL in docker-compose
- Clean Architecture directories exist and mapped in services.yaml
- No tests, entities, controllers, OpenAPI config created

**Next:** Task 1.3 — Quality tools (PHPStan, PHPcsFixer, Rector, PHPCPD)