<?php

declare(strict_types=1);

namespace App\Application\Like\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Like\DTO\LikeDTO;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\Like\ValueObject\LikeId;
use App\Domain\User\Entity\User;

final readonly class LikeService
{
    public function __construct(
        private LikeRepositoryInterface $likeRepository,
        private UnitOfWorkInterface $unitOfWork,
    ) {
    }

    /**
     * Idempotently likes an item: an existing like for the pair
     * (owner, item) is returned as-is, otherwise a new one is created.
     *
     * Note: this is a find-then-insert, so a concurrent duplicate for the same
     * pair can still hit the UNIQUE (owner_id, item_id) index and surface as a
     * UniqueConstraintViolation, which the API layer maps to an error. A
     * race-safe atomic upsert (like TagRepository::getOrCreate) is tracked as a
     * follow-up; see PRD/5.3 and AssumptionLog.
     */
    public function like(User $owner, Item $item): Like
    {
        $existing = $this->findLike($owner, $item);
        if ($existing instanceof Like) {
            return $existing;
        }

        $like = Like::create($owner, $item);
        $this->likeRepository->save($like);
        $this->unitOfWork->flush();

        return $like;
    }

    /**
     * Idempotently removes a like: a missing like is a no-op.
     */
    public function unlike(User $owner, Item $item): void
    {
        $existing = $this->findLike($owner, $item);
        if (!$existing instanceof Like) {
            return;
        }

        $this->likeRepository->remove($existing);
        $this->unitOfWork->flush();
    }

    /**
     * Toggles the like and returns the resulting state (true = liked).
     * Both branches commit their change with a single flush. Like like(), the
     * like branch is a find-then-insert and shares the same concurrency note.
     */
    public function toggle(User $owner, Item $item): bool
    {
        $existing = $this->findLike($owner, $item);
        if ($existing instanceof Like) {
            $this->likeRepository->remove($existing);
            $this->unitOfWork->flush();

            return false;
        }

        $this->likeRepository->save(Like::create($owner, $item));
        $this->unitOfWork->flush();

        return true;
    }

    public function isLikedBy(User $owner, Item $item): bool
    {
        return $this->findLike($owner, $item) instanceof Like;
    }

    /**
     * Returns a like by id, or null when it does not exist. Unlike
     * ItemService::getById() this does not throw a not-found exception: the
     * caller (5.5) maps null to 404 for the administrative delete path.
     */
    public function getById(string $id): ?Like
    {
        return $this->likeRepository->findById(LikeId::fromString($id));
    }

    public function countByItem(ItemId $itemId): int
    {
        return $this->likeRepository->countByItemId($itemId);
    }

    /**
     * @return array<Like>
     */
    public function listByItem(ItemId $itemId, int $limit = 50, int $offset = 0): array
    {
        return $this->likeRepository->findByItemId($itemId, $limit, $offset);
    }

    /**
     * Lists the likes given by an owner (the "my likes" listing), oldest first.
     *
     * @return array<Like>
     */
    public function listByOwner(OwnerId $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->likeRepository->findByOwnerId($ownerId, $limit, $offset);
    }

    /**
     * Removes a like directly — used by the administrative path (5.5 / 7.6).
     */
    public function removeLike(Like $like): void
    {
        $this->likeRepository->remove($like);
        $this->unitOfWork->flush();
    }

    public function toDTO(Like $like): LikeDTO
    {
        return LikeDTO::fromEntity($like);
    }

    /**
     * @param array<Like> $likes
     *
     * @return array<LikeDTO>
     */
    public function toDTOList(array $likes): array
    {
        return \array_map(
            fn (Like $like): LikeDTO => $this->toDTO($like),
            $likes,
        );
    }

    private function findLike(User $owner, Item $item): ?Like
    {
        return $this->likeRepository->findByOwnerAndItem(
            OwnerId::fromBytes($owner->getId()->toBytes()),
            $item->getId(),
        );
    }
}
