<?php

declare(strict_types=1);

namespace App\Domain\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;

/**
 * Read methods hydrate the Item together with its collection and the collection
 * owner (JOIN FETCH). Do not add read paths that load an Item without them:
 * Item.collection and Collection.owner are LAZY associations to final entities,
 * which Doctrine ORM 3 cannot ghost-proxy.
 *
 * Tags are pre-initialized too (fwd-6), in a second batch query rather than a
 * fetch join: joining the to-many side would inflate rows and break
 * setMaxResults/setFirstResult. A read therefore costs at most two statements —
 * one for the page, one for the tag batch, and the batch is skipped when the
 * page is empty or when findById found no row — instead of one per item.
 */
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

    /**
     * @param array<string> $tagNames AND-filter: item must have all given tag names
     *
     * @return array<Item> ordered by createdAt ASC
     */
    public function findByCollectionId(CollectionId $collectionId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array;

    /**
     * @param array<string> $tagNames AND-filter: item must have all given tag names
     *
     * @return array<Item> owned via their collection, ordered by createdAt ASC
     */
    public function findByOwnerId(OwnerId $ownerId, int $limit = 50, int $offset = 0, ?string $name = null, array $tagNames = []): array;
}
