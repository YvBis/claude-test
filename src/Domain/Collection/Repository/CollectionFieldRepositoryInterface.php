<?php

declare(strict_types=1);

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Entity\CollectionField;
use App\Domain\Collection\ValueObject\CollectionFieldId;

interface CollectionFieldRepositoryInterface
{
    /**
     * Schedule the field for persistence (added to the Unit of Work).
     * The write is deferred: the Application layer decides when changes
     * are committed to the database.
     */
    public function save(CollectionField $field): void;

    /**
     * Schedule the field for removal (added to the Unit of Work).
     * The removal is deferred: the Application layer decides when changes
     * are committed to the database.
     */
    public function remove(CollectionField $field): void;

    public function findById(CollectionFieldId $id): ?CollectionField;

    /** @return array<CollectionField> ordered by slot_index ASC */
    public function findByCollection(Collection $collection): array;

    public function findByCollectionAndSlot(Collection $collection, int $slotIndex): ?CollectionField;

    public function nextSlotIndexFor(Collection $collection): int;

    public function countByCollection(Collection $collection): int;
}
