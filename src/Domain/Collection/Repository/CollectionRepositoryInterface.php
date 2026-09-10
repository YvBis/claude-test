<?php

declare(strict_types=1);

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\User\Entity\User;

interface CollectionRepositoryInterface
{
    /**
     * Schedule the collection for persistence (added to the Unit of Work).
     * Call UnitOfWorkInterface::flush() to commit changes to the database.
     */
    public function save(Collection $collection): void;

    /**
     * Schedule the collection for removal (added to the Unit of Work).
     * Call UnitOfWorkInterface::flush() to commit changes to the database.
     */
    public function remove(Collection $collection): void;

    public function findById(CollectionId $id): ?Collection;

    /** @return array<Collection> ordered by createdAt DESC */
    public function findByOwner(User $owner, int $limit = 50, int $offset = 0): array;

    /** @return array<Collection> */
    public function findAll(int $limit = 50, int $offset = 0): array;
}
