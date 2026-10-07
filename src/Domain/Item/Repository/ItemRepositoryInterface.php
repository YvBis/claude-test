<?php

declare(strict_types=1);

namespace App\Domain\Item\Repository;

use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;

/**
 * Read methods hydrate the Item together with its collection (JOIN FETCH). Do not
 * add read paths that load an Item without it: Item.collection is a LAZY
 * association to a final entity, which Doctrine ORM 3 cannot ghost-proxy.
 *
 * The collection's owner is not among them, and stopped being part of this
 * contract in fwd-5: `Collection.owner` is a plain `owner_id` column, so there is
 * no association left to hydrate and no row to fetch for it.
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

    /**
     * Every item, page by page — the search reindex walk. Ordered by
     * createdAt ASC then id ASC so pages are stable; offset paging can skip or
     * repeat a row under concurrent writes, which is accepted because the
     * reindex is idempotent and a second run picks up the difference.
     *
     * @return array<Item> collection and tags initialized
     */
    public function findAll(int $limit = 50, int $offset = 0): array;
}
