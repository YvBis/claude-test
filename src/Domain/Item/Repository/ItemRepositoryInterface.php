<?php

declare(strict_types=1);

namespace App\Domain\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\User\ValueObject\UserId;

interface ItemRepositoryInterface
{
    /**
     * Read methods hydrate the Item together with its collection and the
     * collection owner (JOIN FETCH). Do not add read paths that load an Item
     * without them: Item.collection and Collection.owner are LAZY associations
     * to final entities, which Doctrine ORM 3 cannot ghost-proxy.
     */
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

    /** @return array<Item> owned via their collection, ordered by createdAt ASC */
    public function findByOwnerId(UserId $ownerId, int $limit = 50, int $offset = 0): array;
}
