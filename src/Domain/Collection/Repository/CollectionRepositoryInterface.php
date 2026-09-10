<?php

declare(strict_types=1);

namespace App\Domain\Collection\Repository;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\User\Entity\User;

interface CollectionRepositoryInterface
{
    public function save(Collection $collection): void;

    public function remove(Collection $collection): void;

    public function findById(CollectionId $id): ?Collection;

    /** @return array<Collection> ordered by createdAt DESC */
    public function findByOwner(User $owner, int $limit = 50, int $offset = 0): array;

    /** @return array<Collection> */
    public function findAll(int $limit = 50, int $offset = 0): array;
}
