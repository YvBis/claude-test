<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\UserId;

interface UserRepositoryInterface
{
    /**
     * Schedule the user for persistence (added to the Unit of Work).
     * Call UnitOfWorkInterface::flush() to commit changes to the database.
     */
    public function save(User $user): void;

    /**
     * Schedule the user for removal (added to the Unit of Work).
     * Call UnitOfWorkInterface::flush() to commit changes to the database.
     */
    public function remove(User $user): void;

    public function findById(UserId $id): ?User;

    public function findByEmail(Email $email): ?User;

    /** @return array<User> */
    public function findAll(): array;

    public function existsByEmail(Email $email): bool;
}
