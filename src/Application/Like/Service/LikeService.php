<?php

declare(strict_types=1);

namespace App\Application\Like\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Like\DTO\LikeDTO;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\Like\ValueObject\LikeId;
use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\UserId;

final readonly class LikeService
{
    public function __construct(
        private LikeRepositoryInterface $likeRepository,
        private UnitOfWorkInterface $unitOfWork,
        private UserRepositoryInterface $userRepository,
    ) {
    }

    /**
     * Idempotently likes an item. The pair is resolved by an atomic upsert in
     * the repository, so a concurrent duplicate is absorbed by
     * `uniq_like_owner_item` and never surfaces as an exception; the call
     * commits itself and does not need `flush()`.
     */
    public function like(User $owner, Item $item): Like
    {
        return $this->likeRepository->likeOrGet(OwnerId::fromBytes($owner->getId()->toBytes()), $item);
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

    public function isLikedBy(?User $owner, Item $item): bool
    {
        if (!$owner instanceof User) {
            return false;
        }

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
     * Every like, newest first — the admin audit listing.
     *
     * @return array<Like>
     */
    public function listAll(int $limit = 50, int $offset = 0): array
    {
        return $this->likeRepository->findAll($limit, $offset);
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

    /**
     * Single-entity mapping only. It costs one name lookup for one like, so a
     * caller walking a list must use {@see self::toDTOList()}, which pays it once
     * for the whole page — looping over this method is the N+1 fwd-5 removed.
     */
    public function toDTO(Like $like): LikeDTO
    {
        return LikeDTO::fromEntity($like, $this->ownerNames([$like])[$like->getOwnerId()->toString()] ?? null);
    }

    /**
     * fwd-5: one extra statement for the whole page, not one per like.
     *
     * @param array<Like> $likes
     *
     * @return array<LikeDTO>
     */
    public function toDTOList(array $likes): array
    {
        $names = $this->ownerNames($likes);

        return \array_map(
            static fn (Like $like): LikeDTO => LikeDTO::fromEntity(
                $like,
                $names[$like->getOwnerId()->toString()] ?? null,
            ),
            $likes,
        );
    }

    /**
     * @param array<Like> $likes
     *
     * @return array<string, string>
     */
    private function ownerNames(array $likes): array
    {
        $userIds = [];
        foreach ($likes as $like) {
            $userIds[] = UserId::fromBytes($like->getOwnerId()->toBytes());
        }

        return $this->userRepository->findNamesByIds($userIds);
    }

    private function findLike(User $owner, Item $item): ?Like
    {
        return $this->likeRepository->findByOwnerAndItem(
            OwnerId::fromBytes($owner->getId()->toBytes()),
            $item->getId(),
        );
    }
}
