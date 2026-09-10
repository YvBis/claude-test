# PRD: review-5 — Fix ghost-proxy in CollectionFieldRepository

## Проблема

`DoctrineCollectionFieldRepository::findById()` использует persister `find()` → LAZY ManyToOne на `final` `Collection` (и `Collection.owner` на `final` `User`). Doctrine ORM 3 не может генерировать lazy/ghost-прокси для `final` сущностей. При свежем UoW (коллекция не в identity map) любой `getCollection()` после `findById` падает: `Cannot generate lazy ghost: class ... is final`.

Ранее баг был латентным: тесты держали коллекцию в identity map того же UoW. Актуален для 3.5 (чтение поля по id + авторизация "поле принадлежит коллекции").

## Решение

Переписать `findById` на DQL с цепным JOIN:
`f -> collection -> owner` (оба `innerJoin` + `addSelect`), binary-параметр по `CollectionFieldId::toBytes()`.

Это зеркалит уже подтверждённый паттерн `DoctrineCollectionRepository` (empirically verified: EAGER не работает, JOIN FETCH — работает).

## Критерии приёмки

- [ ] Новый интеграционный тест: `em->clear()` + `findById` + `getCollection()` ≠ исключение, id совпадает
- [ ] Тест красный до фикса (воспроизводит ghost-proxy)
- [ ] `composer ci:all` exit 0
- [ ] Roadmap: review-5 → done; AssumptionLog: запись

## Скоуп

Не трогаем: остальные методы репо (принимают `Collection` параметром, уже гидратирован), `findByCollection` (возвращает поля «своей» коллекции), Other domain logic. `transactional()` — остаётся в fwd-1 (Этап 4).