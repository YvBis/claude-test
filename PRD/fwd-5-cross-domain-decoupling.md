# fwd-5 — разрыв cross-domain зависимости Collection → User

Статус: в работе (один PR, три фазы)

Источник: строка Roadmap `fwd-5` (итог review-6, 2026-09-13).

## Что делаем

Query/DTO-слой уже на `OwnerId`. На старте задачи VO лежал в
`App\Domain\Collection\ValueObject\OwnerId`: каждый `findByOwnerId()` принимал
`OwnerId`, `CollectionDTO` нёс `OwnerId`. Сущности держали объекты `User`.

| Держатель | Где используется |
|---|---|
| `Collection.owner` (`ManyToOne User`) | `CollectionService::create(dto, User)`, `getOwner(): User`, `reassignOwner(User)` |
| `Like.owner`, `Comment.owner` | `LikeDTO:25-26`, `CommentDTO:27-28` (`getOwner()->getId()` и `getOwner()->getName()`) |
| `User::isOwnerOrAdminOf(self)` | `AbstractApiController::canManage` (`CollectionController` ×2, `ItemController` ×3) и `SocialContentVoter:83` |
| `CollectionController:322` | инлайн owner-only для PATCH — **fwd-33 не решается**, переносится механически |

Цель: сущности хранят `owner_id` как `OwnerId`, а не объект `User`. Поведение API
не меняется ни в одном ответе, **кроме одного нюанса**: `owner_name` в `LikeDTO` и
`CommentDTO` становится `?string` (см. Фазу 2). На реальных данных значения не
меняются — FK гарантирует наличие строки пользователя.

## Зафиксированные до кода решения

### 1. Представление: plain binary-колонка, НЕ embeddable

```php
#[ORM\Column(name: 'owner_id', type: 'binary', length: 16)]
private string $ownerId;

public function getOwnerId(): OwnerId { return OwnerId::fromBytes($this->ownerId); }
```

`OwnerId` на старте задачи был объявлен `#[ORM\Embeddable]` с внутренней
`#[ORM\Column(name: 'id')]`, и обе аннотации сняты: сущность, которая его встраивала,
единственная (`Collection`) ушла на колонку, а оставшийся атрибут читался как баг.
Встраивание с `columnPrefix: false` столкнулось бы с PK `Collection.id`, а с
префиксом потребовало бы проверенной гидратации `readonly` + private ctor.
Колонка — то, что репозиторийный слой уже делает байтами
(`OwnerId::toBytes()` во всех `setParameter`), поэтому это вариант без
сюрпризов. Минус осознанный: `OwnerId` остаётся embeddable-аннотированным ради
своих собственных целей и нигде не встраивается.

### 2. FK CASCADE — сохраняем (решение владельца, 2026-10-03)

Три `owner_id` → `users(id) ON DELETE CASCADE` остаются. Данные не двигаются,
но это **смена схемы, а не «миграция не нужна»**: снятие `ManyToOne` заставило бы
schema-diff выпустить `DROP FOREIGN KEY`, и каскадное удаление коллекций, лайков
и комментов при удалении юзера исчезло бы тихо.

Следствие, которое обязано быть записано: после снятия ассоциации Doctrine про эти
ограничения не знает, поэтому они объявлены **вручную в базовой миграции**. Любой
будущий `doctrine:schema:update` их снесёт — это зафиксировано в docblock миграции.

### 3. Компаратор owner-or-admin — в Infrastructure

`User::isOwnerOrAdminOf(self $owner)` физически не может принять `OwnerId`.
Инлайнить правило в контроллерах нельзя: вторая копия «admin ⇒ allowed» — ровно
та дивергенция, которую убирал fwd-14, а `SocialContentVoter` её повторил бы в
третий раз. Перегрузка `User` методом под `OwnerId` инвертировала бы зависимость
(`Domain\User` → `Domain\Collection`), ради удаления которой задача и существует.

Итог: один компаратор с одним местом жительства, используемый и контроллерами, и
вотером. Точное место назовёт правка (рядом с `AbstractApiController::denyUnlessCanManage`,
чтобы правило читалось и находилось в одной точке).

Реализовано как `src/Infrastructure/Security/OwnerAccess.php`. Сравнение идёт
побайтно (`$actor->getId()->toBytes() === $ownerId->toBytes()`), а не через
`UserId::equals()`: трейт `UuidBinaryValue::equals(self $other)` типизирован `self`,
`OwnerId` и `UserId` — разные классы, и `OwnerId` не может вызвать трейтовый метод с
`UserId`-аргументом. Предложение архитектора о едином месте семантики равенства
отклонено по этой причине.

### 3.1 `OwnerId` переезжает в `Domain\Common` (находка ревью)

Не входило в исходный план, принято по итогам архитектурного ревью. VO создавалась в
`Domain\Collection\ValueObject`, но после перехода владения на колонку её
использовали `Like`, `Comment` и `Item` — а также репозитории, сервисы и
контроллеры. Итог: задача с названием «разрыв cross-domain зависимости» убрала бы
одну междоменную связь (`Like → User`) и добавила бы пять (`Like`/`Comment`/`Item` →
`Collection`), то есть это перенос, а не развязка, и заявление в названии было бы
полуправдой.

`OwnerId` перенесён в `src/Domain/Common/ValueObject/OwnerId.php` — рядом с
`UuidBinaryValue`, который он использует. Механически — смена одного сегмента в 51
файле, без изменения поведения.

### 4. `ownerName` — batch, а не join ради одного поля

`LikeDTO`/`CommentDTO` печатают имя владельца. После снятия ассоциации брать его
из объекта неоткуда. Стратегия: новый bulk-метод в `UserRepositoryInterface`
(сегодня там только `findById`/`findByEmail`/`findAll`/`existsByEmail`) плюс
конверсия `OwnerId → UserId`; имена грузятся **одним** запросом на страницу, не
по одному на запись и не сохранением джойна.

Если владельца нет (FK снят — а здесь он сохранён, но ветка всё равно нужна для
устойчивости) — `name` = `null`, не исключение.

`UserRepositoryInterface` живёт в `Domain\User` — это не новый межслойный
импорт: `Application`-сервисы уже импортируют `Domain\User`.

### 5. `reassignOwner` — удалить

Производственных вызывающих нет (только `tests\Domain\Collection\Entity\CollectionTest.php:172`).
Портировать мёртвый API в новую сигнатуру незачем.

## Фаза 0 — сквош миграций (решение владельца: внутри fwd-5, релиз не было)

Семь файлов `migrations/Version*.php` → один `Version20261003120000`.

Доказательство эквивалентности (выполнено, не заявлено):

1. `squash_ref` — чистая БД, прогнаны все 7 миграций, `mysqldump --no-data`.
2. `squash_new` — чистая БД, прогнана одна базовая миграция, `mysqldump --no-data`.
3. `Compare-Object` по 118 DDL-строкам с каждой стороны: **`SCHEMAS-IDENTICAL`**.
   Проверено повторно и на финальном файле миграции, до его коммита.

Часть сквоша уже была сделана раньше (`Version20260911120000` заменял четыре
ранних), но с тех пор набежало ещё шесть инкрементов — поэтому история росла
обратно.

## Фаза 1 — `Collection.owner`

- Маппинг, `getOwnerId()`, `create(... OwnerId)`, `__construct(... OwnerId)`.
- `CollectionService::create(dto, OwnerId)`.
- `reassignOwner` удалён вместе с кейсом теста.
- `CollectionController:91` и `:322` — механически, `:322` остаётся owner-only.
- **Пять репозиториев** (это ломающая точка, не «очевидное дело»):
  `DoctrineCollectionRepository` (`:40,52,70`), `DoctrineItemRepository`
  (`:86,148` в `withCollectionAndOwner`), `DoctrineCollectionFieldRepository`
  (`:44,59,74`), `DoctrineLikeRepository` (`:106,109`),
  `DoctrineCommentRepository` (`:105,108`).
  `IDENTITY(x.owner) = :ownerId` → `x.ownerId = :ownerId`; джойн `collection.owner`
  снимается вместе с `addSelect` — он больше не существует.
- `withAll` в Like/Comment теряет `collection.owner` и `collectionOwner`: после
  развязки читать владельца коллекции не нужно никому, а `withAll` грузил его
  только ради этого. Это побочно снимает часть константного овер-фетча из fwd-6b.

## Фаза 2 — `Like.owner`, `Comment.owner`

- `getOwnerId(): OwnerId`, `create(OwnerId, Item)` для `Like` и
  `create(OwnerId, Item, CommentContent)` для `Comment` — перегрузки с `User` нет.
- `ownerName` через bulk-метод репозитория пользователей.
- `SocialContentVoter` — через общий компаратор из п.3.

## Красные тесты — что именно должно сломаться

1. **Контракт**: `Collection::create(owner: User)` / `new Like(..., User, ...)` /
   `CollectionService::create($dto, User)` перестают компилироваться — это и есть
   красный, показывающий, что сигнатуры сменились, а не что где-то опечатка.
2. **DQL**: пять репозиториев до переписывания дают `Semantic error` на
   `collection.owner` / `IDENTITY(...owner)`.
3. **Каскад**: удаление пользователя уносит его коллекции, лайки и комменты —
   иначе FK-решение владельца останется декларацией в комментарии. Реализовано
   как `tests/Infrastructure/Doctrine/OwnerCascadeDeletionTest.php`: удаление
   юзера делается сырым SQL, чтобы отделить каскад БД от PHP-каскада ORM.
4. **Гость-чтение**: `GET /api/items/{id}` и `/likes`, `/comments` (fwd-7) не
   должны сломаться — `liked_by_me` считается по `ownerId`.
5. **Бинарный биндинг батча имён**: `findNamesByIds()` биндит сырые 16 байт через
   `ArrayParameterType::BINARY`. Ошибка здесь не бросается, а молча даёт пустой
   результат — все `owner_name` стали бы `null`, а моки в сервисных тестах этого
   не увидели бы. Отсюда четыре интеграционных теста в
   `tests/Infrastructure/User/Repository/DoctrineUserRepositoryTest.php`: совпадение
   байт, фильтрация по запрошенным id, пропуск отсутствующего id и early return на
   пустом батче (пустой `IN ()` — синтаксическая ошибка в MySQL).

## Проверки

`ci:all` (тестовая БД строится миграциями, поэтому сквош проверяется гейтом
автоматически) плюс отдельный прогон гейта покрытия, `composer audit`,
`composer ci:symfony-lsp` и `scripts/check-openapi-fresh.sh` в контейнере.

## Чего не делаем

- Не решаем fwd-33 (owner-only на PATCH) — переносим как есть, но помечаем
  комментарием со ссылкой на трек, чтобы расхождение не выглядело недосмотром и
  его не «поправили» в `denyUnlessCanManage()` по незнанию.
- Не двигаем `fwd-6b` (проекция списков) сюда: проекция — отдельная работа, и
  после снятия ассоциации её предмет меняется.
- Не удаляем `ownerName` из ответа — это часть публичного контракта.