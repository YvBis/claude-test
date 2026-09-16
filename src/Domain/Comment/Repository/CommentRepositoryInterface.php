<?php

declare(strict_types=1);

namespace App\Domain\Comment\Repository;

use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Item\ValueObject\ItemId;

interface CommentRepositoryInterface
{
    /**
     * Schedules the comment for insertion (UoW); flush() commits the transaction.
     */
    public function save(Comment $comment): void;

    /**
     * Schedules the comment for removal (UoW); flush() commits the transaction.
     */
    public function remove(Comment $comment): void;

    /**
     * Finds a comment by id. The returned comment has owner and item (with its
     * collection and collection owner) hydrated — final classes are not
     * proxiable in ORM 3, so every read joins the full chain.
     */
    public function findById(CommentId $id): ?Comment;

    /**
     * Lists comments of an item ordered by createdAt ASC (id as deterministic
     * tiebreaker for equal microsecond timestamps).
     *
     * @return array<Comment>
     */
    public function findByItemId(ItemId $itemId, int $limit = 50, int $offset = 0): array;

    /**
     * Lists comments authored by an owner ordered by createdAt ASC (id as
     * deterministic tiebreaker). The returned comments have owner and item
     * (with its collection and collection owner) hydrated.
     *
     * @return array<Comment>
     */
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0): array;

    /**
     * Counts the comments of an item (scalar-only; no association hydration).
     */
    public function countByItemId(ItemId $itemId): int;

    /**
     * Counts the comments authored by an owner (scalar-only; no association hydration).
     */
    public function countByOwnerId(OwnerId $ownerId): int;
}
