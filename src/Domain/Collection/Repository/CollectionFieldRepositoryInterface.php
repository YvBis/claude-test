<?php

declare(strict_types=1);

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Entity\CollectionField;
use App\Domain\Collection\ValueObject\CollectionFieldId;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\FieldType;

interface CollectionFieldRepositoryInterface
{
    /**
     * Schedule the field for persistence (added to the Unit of Work).
     * The write is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function save(CollectionField $field): void;

    /**
     * Schedule the field for removal (added to the Unit of Work).
     * The removal is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function remove(CollectionField $field): void;

    public function findById(CollectionFieldId $id): ?CollectionField;

    /** @return array<CollectionField> ordered by slot_index ASC */
    public function findByCollection(Collection $collection): array;

    /**
     * Find a field by collection id, type and slot. The collection and its
     * owner are JOIN FETCHed — do not pass a managed proxy that must not
     * hydrate the final Collection/User entities.
     */
    public function findByCollectionAndTypeAndSlot(CollectionId $collectionId, FieldType $type, int $slotIndex): ?CollectionField;

    public function nextSlotIndexFor(Collection $collection): int;

    public function countByCollection(Collection $collection): int;
}
