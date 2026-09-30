# PRD fwd-15 — явная стратегия access decision manager

Задача атомарна: оценка строки 0.5ч, изменение — одна строка конфигурации плюс
один тестовый файл. Дальнейшая декомпозиция не требуется.

## Что говорит строка Roadmap (канон)

`Roadmap.md:81`: явно определить `access_decision_manager.strategy` и
закрепить guard-тестом, что `Like` + `SOCIAL_EDIT` остаётся denied при
появлении второго Voter. Сейчас affirmative-стратегия работает молча, т.к.
voter один. Триггер: первый Voter для Item/Collection.

## Почему стратегия выведена, а не выбрана

Строка требует, чтобы DENY пережил чужой GRANT. Из четырёх стратегий Symfony
это умеет ровно одна:

- `affirmative` (`AffirmativeStrategy::decide`, `AffirmativeStrategy.php:39`) —
  `return true` на первом GRANT. Второй вотер, разрешивший редактирование
  лайка, **перебивает** DENY социального вотера. Строку не выполняет.
- `unanimous` (`UnanimousStrategy::decide`, `UnanimousStrategy.php:37`) —
  `return false` на первом DENY. Единственная, где DENY переживает GRANT.
- `consensus`, `priority` — тот же перебивающий GRANT для consensus
  (`AffirmativeStrategy`-подобный подсчёт), и посторонние для задачи.

Значит `unanimous` — следствие требования строки, а не вкусовое решение.

Почему это безопасно сегодня:

- При одном вотере `unanimous` ≡ `affirmative`: грант ⇔ вотер голосовал за,
  abstain → `allowIfAllAbstainDecisions` (дефолт `false`) → deny.
- Будущий вотер Item/Collection отвечает abstain на социальные атрибуты, а
  abstain unanimity **не блокирует** (блокирует только DENY, строка 37-39).
  То есть `unanimous` защищает ровно тот случай, который называет строка, и
  не превращается в глобальный запрет.

## Что измерено до правки

- `config/packages/security.yaml` не содержит `access_decision_manager` вообще
  — работал молчаливый дефолт `new AffirmativeStrategy()`
  (`AccessDecisionManager.php:49`).
- Симметричное чтение вендоренного кода вместо памяти: имена классов —
  `UnanimousStrategy` / `AffirmativeStrategy` (не `*Based`), конструктор
  `__construct(bool $allowIfAllAbstainDecisions = false)`.
- **Через ADM идёт больше, чем одни `SOCIAL_*`.** Первая редакция этого PRD
  утверждала обратное — проверка шла от точек `denyAccessUnlessGranted` и
  пропустила два других пути, найденных ревью и подтверждённых чтением вендора:
  - `access_control` — каждая строка проходит через
    `AccessListener.php:78` (`$this->accessDecisionManager->decide($token, $attributes, $request, …)`),
    то есть **subject здесь `Request`**;
  - `#[IsGranted]` — `LogoutController.php:17` через `security.authorization_checker`,
    тот же ADM.

  Поведенчески это сегодня инертно: атрибуты `PUBLIC_ACCESS` /
  `IS_AUTHENTICATED_FULLY` решает один `AuthenticatedVoter`, остальные вотеры
  abstain'ят, поэтому `unanimous ≡ affirmative`. Но инвариант на будущее
  строже, чем казалось: **каждый будущий вотер обязан abstain'ить на атрибутах,
  которые не его**, иначе его DENY станет блокирующим для всех маршрутов под
  `access_control`.
- Инлайновые пути в ADM **не** заходят: `AbstractApiController::canManage`
  (`:164-167`) и `denyUnlessCanManage` (`:176-181`) — булевы проверки;
  `CollectionController::delete:383` проверяет владельца инлайн. Стратегия их
  не касается — это зафиксировано в `ARCHITECTURE.md`, раздел «Авторизация».

## Изменения

- `config/packages/security.yaml` — `access_decision_manager: { strategy:
  unanimous }` с комментарием: почему affirmative не годится, каков реальный
  охват стратегии и почему `allow_if_all_abstain` оставлен на дефолте.
- `tests/Infrastructure/Ci/SecurityConfigTest.php` (новый) — **проводка**:
  `Yaml::parseFile` и ассерт на ключ `security.access_decision_manager.strategy`
  (не на подстроку в тексте: опечатка во вложенности или появление
  `strategy_service` оставили бы строку на месте при эффективном affirmative),
  плюс ассерт, что `allow_if_all_abstain` не ослаблен. Жил в `Ci/` рядом с
  остальными конфиг-гардами, а не рядом с поведенческими тестами.
- `tests/Infrastructure/Security/Voter/AccessDecisionStrategyTest.php` (новый) —
  **семантика**, четыре теста:
  1. матрица: unanimous → denied при чужом GRANT; affirmative → granted
     (зеркало, доказывающее, что тест не вакуумен и чувствителен именно к
     стратегии);
  2. unanimous не глобальный запрет: оба вотера grant → granted;
  3. abstain'нувший второй вотер не превращает грант в deny;
  4. (проводка вынесена в `Ci/SecurityConfigTest.php`.)

## Почему два гарда, а не один

Поведенческий тест строится руками (`new AccessDecisionManager([...], $strategy)`)
и **зелёный до и после** правки конфига — он фиксирует смысл стратегии, а не
проводку. Конфиг-гард ловит регресс проводки и был красным до правки. Второй
вотер в тестовом окружении сознательно **не** заводился: глобальный
grant-all вотер сломал бы все существующие 403-ожидания Like/Comment-контроллеров
— цена выше проверяемого. Отдельный тестовый файрвол потребовал бы второй
загрузки ядра на тест без выигрыша.

## Не трогается

- Сам `SocialContentVoter` — он уже голосует DENY, а не abstain.
- Инлайновые owner-or-admin проверки (`canManage`, `CollectionController::delete`)
  — вне ADM, отдельная задача `fwd-14`.
- Второй вотер не создаётся — триггер строки не наступил, работа сделана
  **до** триггера, чтобы его PR не открыл `Like` + `SOCIAL_EDIT` молча.

## Доказательства

- Конфиг-гард красный до правки (`security.yaml must set security.access_decision_manager.strategy explicitly`), зелёный после.
- Поведенческие тесты зелёные и до, и после — это осознанная граница, а не
  второй независимый замок; зафиксировано здесь явно.
- `composer ci:all` зелёный, `composer audit` чист, `lint:container` OK.

## Критерии приёмки

- [x] Стратегия выведена из требования строки, а не выбрана по вкусу
- [x] Конфиг-гард красный до правки, зелёный после; проверяет ключ YAML, не подстроку
- [x] Зеркало affirmative → granted (тест не вакуумен)
- [x] `unanimous` не превращён в глобальный запрет
- [x] Проверен **полный** список потребителей ADM, включая `access_control` и `#[IsGranted]`
- [x] Реальный охват стратегии зафиксирован в `ARCHITECTURE.md` («Авторизация»)
- [x] `ci:all` зелёный, один сквош-коммит
- [x] Строка Roadmap обновлена
