<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;

interface UserRepositoryInterface
{
    /**
     * Schedule the user for persistence (added to the Unit of Work).
     * The write is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function save(User $user): void;

    /**
     * Schedule the user for removal (added to the Unit of Work).
     * The removal is deferred and happens later, when a decision is made
     * to commit pending changes.
     */
    public function remove(User $user): void;

    public function findById(UserId $id): ?User;

    public function findByEmail(Email $email): ?User;

    /** @return array<User> */
    /**
     * Every user, page by page — the admin list. Ordered by createdAt DESC
     * then id DESC so pages are stable (the tiebreak follows the primary
     * direction, fwd-27); offset drift under concurrent writes is accepted.
     *
     * @return array<User>
     */
    public function findAll(int $limit = 50, int $offset = 0): array;

    /**
     * Display names for a batch of users, keyed by id string.
     *
     * fwd-5: like and comment listings print the owner's name, and since
     * ownership became an `owner_id` column the name is no longer reachable from
     * the entity. The batch form keeps a page of social content to one extra
     * statement instead of one per row. An id with no matching user is absent
     * from the returned map rather than mapped to null.
     *
     * @param array<UserId> $userIds
     *
     * @return array<string, string>
     */
    public function findNamesByIds(array $userIds): array;

    public function existsByEmail(Email $email): bool;

    /**
     * How many users carry the role — the last-admin guard.
     */
    public function countByRole(Role $role): int;
}
