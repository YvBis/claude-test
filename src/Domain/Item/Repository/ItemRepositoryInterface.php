<?php

declare(strict_types=1);

namespace App\Domain\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;

interface ItemRepositoryInterface
{
    /**
     * Schedule the item for persistence (added to the Unit of Work).
     * The write is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function save(Item $item): void;

    /**
     * Schedule the item for removal (added to the Unit of Work).
     * The removal is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function remove(Item $item): void;

    public function findById(ItemId $id): ?Item;

    /** @return array<Item> ordered by createdAt ASC */
    public function findByCollectionId(CollectionId $collectionId, int $limit = 50, int $offset = 0): array;
}
