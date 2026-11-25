<?php

declare(strict_types=1);

namespace App\Application\User\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Search\SearchIndexerInterface;
use App\Domain\Collection\Repository\CollectionRepositoryInterface;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Repository\ItemRepositoryInterface;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\LastAdminException;
use App\Domain\User\Exception\SelfActionForbiddenException;
use App\Domain\User\Exception\UserNotFoundException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;

final readonly class UserService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UnitOfWorkInterface $unitOfWork,
        private ItemRepositoryInterface $itemRepository,
        private CollectionRepositoryInterface $collectionRepository,
        private SearchIndexerInterface $searchIndexer,
    ) {
    }

    /** @return array<User> */
    public function listUsers(int $limit = 50, int $offset = 0): array
    {
        return $this->userRepository->findAll($limit, $offset);
    }

    public function getById(string $id): User
    {
        $userId = UserId::fromString($id);
        $user = $this->userRepository->findById($userId);

        if (!$user instanceof User) {
            throw UserNotFoundException::withId($userId);
        }

        return $user;
    }

    /**
     * Applies an admin-driven state change: active flag, role, or both.
     *
     * The whole operation runs in one transaction and reloads the target under
     * a row lock first: the last-admin guard counts admins with a locking read,
     * so two concurrent demotions serialize instead of both seeing "two
     * admins". The reload also drops a stale identity-map copy — a concurrent
     * block would otherwise leave `isActive()` lying. The reloaded entity is
     * managed, so the mutation is written by dirty checking when the
     * transaction commits; no explicit save() is needed.
     */
    public function update(User $target, User $actor, ?bool $isActive, ?Role $role): User
    {
        return $this->unitOfWork->transactional(function () use ($target, $actor, $isActive, $role): User {
            $target = $this->reloadForUpdate($target);
            $self = $target->getId()->toString() === $actor->getId()->toString();

            if (false === $isActive) {
                if ($self) {
                    throw SelfActionForbiddenException::block();
                }

                if ($this->isLastAdmin($target)) {
                    throw LastAdminException::block();
                }

                $target->deactivate();
            } elseif (true === $isActive) {
                if ($self) {
                    throw SelfActionForbiddenException::unblock();
                }

                $target->activate();
            }

            // instanceof over null !== : rector's rule (both equivalent here —
            // the controller only ever passes null or a fromString() Role).
            if ($role instanceof Role && !$target->getRole()->equals($role)) {
                if ($self) {
                    throw SelfActionForbiddenException::changeRole();
                }

                if ($role->isUser() && $this->isLastAdmin($target)) {
                    throw LastAdminException::demote();
                }

                if ($role->isAdmin()) {
                    $target->promoteToAdmin();
                } else {
                    $target->demoteToUser();
                }
            }

            return $target;
        });
    }

    public function deleteUser(User $target, User $actor): void
    {
        [$itemIds, $collectionIds] = $this->unitOfWork->transactional(function () use ($target, $actor): array {
            $target = $this->reloadForUpdate($target);
            $this->denySelf($target, $actor, SelfActionForbiddenException::delete(...));

            if ($this->isLastAdmin($target)) {
                throw LastAdminException::delete();
            }

            // Ids are collected BEFORE remove(): the DB cascade deletes the rows
            // without the application ever enumerating them (same hazard as the
            // 6.5 collection delete), which would orphan the search documents.
            $ownerId = OwnerId::fromString($target->getId()->toString());
            $itemIds = $this->itemRepository->findIdsByOwnerId($ownerId);
            $collectionIds = $this->collectionRepository->findIdsByOwnerId($ownerId);

            $this->userRepository->remove($target);

            return [$itemIds, $collectionIds];
        });

        $this->searchIndexer->removeItems($itemIds);
        $this->searchIndexer->removeCollections($collectionIds);
    }

    /** @param callable(): SelfActionForbiddenException $factory */
    private function denySelf(User $target, User $actor, callable $factory): void
    {
        if ($target->getId()->toString() === $actor->getId()->toString()) {
            throw $factory();
        }
    }

    private function reloadForUpdate(User $target): User
    {
        $userId = $target->getId();
        $reloaded = $this->userRepository->findByIdForUpdate($userId);

        if (!$reloaded instanceof User) {
            throw UserNotFoundException::withId($userId);
        }

        return $reloaded;
    }

    private function isLastAdmin(User $target): bool
    {
        return $target->isActive()
            && $target->getRole()->isAdmin()
            && 1 === $this->userRepository->countByRoleExclusively(Role::admin());
    }
}
