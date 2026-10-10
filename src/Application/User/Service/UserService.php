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

    public function blockUser(User $target, User $actor): User
    {
        $this->denySelf($target, $actor, SelfActionForbiddenException::block(...));

        if ($target->isActive() && $this->isLastAdmin($target)) {
            throw LastAdminException::block();
        }

        $target->deactivate();
        $this->userRepository->save($target);
        $this->unitOfWork->flush();

        return $target;
    }

    public function unblockUser(User $target, User $actor): User
    {
        $this->denySelf($target, $actor, SelfActionForbiddenException::unblock(...));

        $target->activate();
        $this->userRepository->save($target);
        $this->unitOfWork->flush();

        return $target;
    }

    public function deleteUser(User $target, User $actor): void
    {
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
        $this->unitOfWork->flush();

        foreach ($itemIds as $itemId) {
            $this->searchIndexer->removeItem($itemId);
        }

        foreach ($collectionIds as $collectionId) {
            $this->searchIndexer->removeCollection($collectionId);
        }
    }

    /** @param callable(): SelfActionForbiddenException $factory */
    private function denySelf(User $target, User $actor, callable $factory): void
    {
        if ($target->getId()->toString() === $actor->getId()->toString()) {
            throw $factory();
        }
    }

    private function isLastAdmin(User $target): bool
    {
        return $target->isActive()
            && $target->getRole()->isAdmin()
            && 1 === $this->userRepository->countByRole(Role::admin());
    }
}
