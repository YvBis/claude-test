<?php

declare(strict_types=1);

namespace App\Domain\Like\Repository;

use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\ValueObject\LikeId;

interface LikeRepositoryInterface
{
    /**
     * Schedules the like for insertion (UoW); flush() commits the transaction.
     */
    public function save(Like $like): void;

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
