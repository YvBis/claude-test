# PRD — удаление коллекции доступно владельцу и администратору

Задача атомарна: одна проверка в контроллере плюс два функциональных теста.
Декомпозиция не требуется.

## Решение владельца

`DELETE /api/collections/{id}` должен быть доступен **владельцу и
администратору**. Асимметрия, оставшаяся после fwd-14, снята: удаление
коллекции следует тому же правилу owner-or-admin, что и все прочие мутации.

## Что было

`CollectionController::delete:382-385` проверял владельца инлайн и **без
админа**:

```php
// Authorization: only owner can delete
if ($collection->getOwner()->getId()->toString() !== $user->getId()->toString()) {
    throw new AccessDeniedException('Forbidden');
}
```

Пока это не выглядело последствием: `item.collection_id` несёт
`ON DELETE CASCADE`, поэтому админ мог удалить каждый айтем чужой коллекции
поштучно через `DELETE /api/items/{id}` (там owner-or-admin), но не мог удалить
саму коллекцию. То есть owner-only здесь вел себя как недосмотр, а не как
решение — это и подняли ревью fwd-14 (senior, architect).

## Изменения

- `src/Infrastructure/Api/Controller/CollectionController.php`
  - `delete()` — инлайновая проверка заменена на `$this->denyUnlessCanManage($user, $collection->getOwner())`,
    то есть на делегат к `User::isOwnerOrAdminOf()` (fwd-14). `AccessDeniedException`
    по-прежнему бросается, поэтому 403-конверт остаётся прежним: его рисует
    `ApiExceptionSubscriber` на `kernel.exception`, а не контроллер.
  - описание операции в `#[OA\Delete]` дополнено «or if the authenticated user
    is an administrator».
- `tests/Infrastructure/Api/Controller/CollectionControllerTest.php`
  - `testAdminCanDeleteForeignCollection` — **новое поведение**, красный до правки.
  - `testDeleteForeignCollectionReturns403` — граница: обычный чужой по-прежнему 403.
  - `authHeaders()` принимает необязательный токен (как в `CommentControllerTest`),
    добавлены хелперы `createCollectionId()` и `promoteToAdmin()`.

## Граница 403 не изменилась

`denyUnlessCanManage` бросает тот же `AccessDeniedException('Forbidden')`, что и
прежняя инлайновая проверка, поэтому конверт, код и отсутствие деталей в ответе
те же. Проверено тестом на чужого обычного пользователя.

## Открытый вопрос, который эта задача НЕ закрывает

`CollectionController::update:326-329` содержит **тот же** инлайновый
owner-only чек. Он обнаружен при работе над этой задачей и намеренно не
тронут: решение владельца было про удаление, а расширять продуктовое решение на
соседний эндпоинт без спроса — значит решать за него. Если правило
owner-or-admin верное, то админ, который может удалить коллекцию, но не может
её переименовать, оказывается в странном положении. Заведено строкой
Roadmap `fwd-33` — чтобы асимметрия не осталась без владельца.

## Что лежало рядом и что с этим сделано

- `CollectionController::update:326-329` — тот же инлайновый owner-чек. Не тронут
  (решение владельца было про удаление), но **не оставлен без следа**: завдена
  строка Roadmap `fwd-33` с формулировкой вопроса и оценкой, а `ARCHITECTURE.md`
  («Авторизация») теперь называет `update` единственным оставшимся выходом из
  правила.
- `ARCHITECTURE.md` и `config/packages/security.yaml` утверждали, что
  `CollectionController::delete` — owner-only. После правки это ложь, поэтому
  обе формулировки переписаны: удаление идёт через `denyUnlessCanManage`.
- Строка Roadmap `fwd-14` оставалась `todo` после мержа — флип пропущен в том
  PR. Исправлено здесь вместе с ретроспективной строкой `fwd-32` на эту работу
  (CLAUDE.md: Workplan — единственный источник очереди, задачи вне него не
  заводятся; решение владельца тоже должно быть видно в плане).

## Доказательства

- `testAdminCanDeleteForeignCollection` красный до правки (403 `Forbidden` на теле запроса вместо 204), зелёный после.
- `testDeleteForeignCollectionReturns403` зелёный и до, и после — граница не сдвинулась; ассертится **тело** `{error: Forbidden, message: Forbidden}`, а не только код.
- Оба теста проверяют реальный путь: регистрация, логин, `promoteToAdmin` прямым SQL (приём из `CommentControllerTest`).
- `composer ci:all` зелёный, `composer audit` чист, php-cs-fixer чист, `public/api/openapi.json` перегенерирован под `@openapi:fresh`.

### Ложь в первой редакции этого PRD

Первая версия PRD и лога утверждала «`ci:all` зелёный» **до того, как прогон
завершился**. Прогон упал: `ERROR: public/api/openapi.json is stale`. Это тот же
класс ошибки, что синьор поймал в fwd-14 («оба функциональных теста красные»,
когда один был зелёным) — я записал результат, которого ещё не было. Строка
«`ci:all` зелёный» в чеклисте ниже ставится только после фактического прогона.

## Критерии приёмки

- [x] Админ удаляет чужую коллекцию (204)
- [x] Обычный чужой — 403, конверт не изменился (код + тело)
- [x] Проверка идёт через общий предикат, а не через новую копию правила
- [x] Описание OpenAPI обновлено, `public/api/openapi.json` перегенерирован
- [x] `ARCHITECTURE.md` и `security.yaml` приведены в соответствие с кодом
- [x] `update` не тронут, заверен строкой Roadmap `fwd-33`
- [x] Строки Roadmap `fwd-32` (эта работа) и `fwd-14` (пропущенный флип) обновлены
- [x] `ci:all` зелёный, один сквош-коммит
