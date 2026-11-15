<?php

declare(strict_types=1);

namespace App\Domain\Like\Repository;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\ValueObject\LikeId;

interface LikeRepositoryInterface
{
    /**
     * Idempotently likes an item for an owner: an existing pair resolves to the
     * stored like, otherwise the row is inserted.
     *
     * Atomic on purpose — the INSERT carries `ON DUPLICATE KEY UPDATE id = id`,
     * so a concurrent duplicate becomes a no-op instead of a UNIQUE violation.
     * It writes and reads the canonical row itself; the caller must NOT wrap it
     * in `UnitOfWorkInterface::transactional()`: the follow-up SELECT needs the
     * winner's row committed, and a REPEATABLE READ snapshot older than that
     * commit would read nothing (see PRD/10.3a).
     *
     * @throws \LogicException   when called inside an active transaction
     * @throws \RuntimeException when the row cannot be read back after the upsert
     */
    public function likeOrGet(OwnerId $ownerId, Item $item): Like;

    /**
     * Schedules the like for removal (UoW); flush() commits the transaction.
     */
    public function remove(Like $like): void;

    /**
     * Finds a like by id. The returned like has its item and the item's
     * collection hydrated — final classes are not proxiable in ORM 3, so the
     * object hydrator has to resolve `Item.collection` while loading the item
     * and would throw instead of deferring it.
     *
     * fwd-6b: that join is load-bearing for hydration, not for a reader. Do
     * not remove it on the grounds that nothing reads `getCollection()`.
     */
    public function findById(LikeId $id): ?Like;

    /**
     * Finds a like by owner and item. The returned like has its item and the
     * item's collection hydrated — see {@see self::findById()} for why.
     */
    public function findByOwnerAndItem(OwnerId $ownerId, ItemId $itemId): ?Like;

    /**
     * Every like, newest first — the admin audit walk. Ordered by
     * (created_at, id) so pages are stable; see idx_like_created.
     *
     * @return array<Like>
     */
    public function findAll(int $limit = 50, int $offset = 0): array;

    /**
     * Lists likes of an item ordered by createdAt ASC (id as deterministic
     * tiebreaker for equal microsecond timestamps).
     *
     * @return array<Like>
     */
    public function findByItemId(ItemId $itemId, int $limit = 50, int $offset = 0): array;

    /**
     * Lists likes authored by an owner ordered by createdAt ASC (id as
     * deterministic tiebreaker for equal microsecond timestamps).
     *
     * @return array<Like>
     */
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array;

    public function countByItemId(ItemId $itemId): int;
}
