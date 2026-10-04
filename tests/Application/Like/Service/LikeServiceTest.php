<?php

declare(strict_types=1);

namespace App\Tests\Application\Like\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\Like\Service\LikeService;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\Repository\LikeRepositoryInterface;
use App\Domain\Like\ValueObject\LikeId;
use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class LikeServiceTest extends TestCase
{
    private LikeRepositoryInterface&MockObject $likeRepository;
    private UnitOfWorkInterface&MockObject $unitOfWork;
    private UserRepositoryInterface&MockObject $userRepository;
    private LikeService $service;
    private User $owner;
    private Item $item;

    protected function setUp(): void
    {
        $this->likeRepository = $this->createMock(LikeRepositoryInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->service = new LikeService($this->likeRepository, $this->unitOfWork, $this->userRepository);

        $this->owner = User::register(
            name: 'Like Service Test',
            email: Email::fromString('like_service_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );

        $collection = Collection::create(
            ownerId: OwnerId::fromBytes($this->owner->getId()->toBytes()),
            name: CollectionName::fromString('Like Service Collection'),
            theme: Theme::books(),
        );

        $this->item = Item::create($collection, '1984');
    }

    private function secondUser(): User
    {
        return User::register(
            name: 'Other Like Service Test',
            email: Email::fromString('like_service_other@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
    }

    public function testLikeCreatesAndFlushes(): void
    {
        $this->likeRepository->method('findByOwnerAndItem')->willReturn(null);
        $this->likeRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(
                fn (Like $like): bool => $like->getOwnerId()->equals(OwnerId::fromBytes($this->owner->getId()->toBytes())) && $like->getItem() === $this->item,
            ));
        $this->unitOfWork->expects($this->once())->method('flush');

        $like = $this->service->like($this->owner, $this->item);

        $this->assertTrue($like->getOwnerId()->equals(OwnerId::fromBytes($this->owner->getId()->toBytes())));
        $this->assertSame($this->item, $like->getItem());
    }

    public function testLikeIsIdempotentWhenAlreadyLiked(): void
    {
        $existing = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $this->likeRepository->method('findByOwnerAndItem')->willReturn($existing);
        $this->likeRepository->expects($this->never())->method('save');
        $this->unitOfWork->expects($this->never())->method('flush');

        $this->assertSame($existing, $this->service->like($this->owner, $this->item));
    }

    public function testUnlikeRemovesAndFlushes(): void
    {
        $existing = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $this->likeRepository->method('findByOwnerAndItem')->willReturn($existing);
        $this->likeRepository->expects($this->once())->method('remove')->with($existing);
        $this->unitOfWork->expects($this->once())->method('flush');

        $this->service->unlike($this->owner, $this->item);
    }

    public function testUnlikeIsNoOpWhenNotLiked(): void
    {
        $this->likeRepository->method('findByOwnerAndItem')->willReturn(null);
        $this->likeRepository->expects($this->never())->method('remove');
        $this->unitOfWork->expects($this->never())->method('flush');

        $this->service->unlike($this->owner, $this->item);
    }

    public function testToggleLikesWhenNotLiked(): void
    {
        $this->likeRepository->method('findByOwnerAndItem')->willReturn(null);
        $this->likeRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(
                fn (Like $like): bool => $like->getOwnerId()->equals(OwnerId::fromBytes($this->owner->getId()->toBytes())) && $like->getItem() === $this->item,
            ));
        $this->unitOfWork->expects($this->once())->method('flush');

        $this->assertTrue($this->service->toggle($this->owner, $this->item));
    }

    public function testToggleUnlikesWhenLiked(): void
    {
        $existing = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $this->likeRepository->method('findByOwnerAndItem')->willReturn($existing);
        $this->likeRepository->expects($this->once())->method('remove')->with($existing);
        $this->unitOfWork->expects($this->once())->method('flush');

        $this->assertFalse($this->service->toggle($this->owner, $this->item));
    }

    public function testIsLikedByTrueWhenLiked(): void
    {
        $this->likeRepository->method('findByOwnerAndItem')->willReturn(Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item));

        $this->assertTrue($this->service->isLikedBy($this->owner, $this->item));
    }

    public function testIsLikedByFalseWhenNotLiked(): void
    {
        $this->likeRepository->method('findByOwnerAndItem')->willReturn(null);

        $this->assertFalse($this->service->isLikedBy($this->owner, $this->item));
    }

    public function testIsLikedByFalseWhenOwnerIsNull(): void
    {
        $this->likeRepository->expects($this->never())->method('findByOwnerAndItem');

        $this->assertFalse($this->service->isLikedBy(null, $this->item));
    }

    public function testFindByOwnerAndItemUsesOwnerIdFromUser(): void
    {
        $expectedOwnerId = OwnerId::fromBytes($this->owner->getId()->toBytes());
        $this->likeRepository
            ->expects($this->once())
            ->method('findByOwnerAndItem')
            ->with(
                $this->callback(fn (OwnerId $ownerId): bool => $ownerId->equals($expectedOwnerId)),
                $this->callback(fn ($itemId): bool => $itemId->equals($this->item->getId())),
            )
            ->willReturn(null);
        $this->likeRepository->method('save');

        $this->service->like($this->owner, $this->item);
    }

    public function testGetByIdDelegatesAndReturnsLike(): void
    {
        $like = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $this->likeRepository
            ->expects($this->once())
            ->method('findById')
            ->with($this->callback(fn (LikeId $id): bool => $id->equals($like->getId())))
            ->willReturn($like);

        $this->assertSame($like, $this->service->getById($like->getId()->toString()));
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->likeRepository->method('findById')->willReturn(null);

        $this->assertNull($this->service->getById(LikeId::generate()->toString()));
    }

    public function testCountByItemDelegates(): void
    {
        $this->likeRepository
            ->expects($this->once())
            ->method('countByItemId')
            ->with($this->callback(fn ($itemId): bool => $itemId->equals($this->item->getId())))
            ->willReturn(7);

        $this->assertSame(7, $this->service->countByItem($this->item->getId()));
    }

    public function testListByItemDelegates(): void
    {
        $like = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $this->likeRepository
            ->expects($this->once())
            ->method('findByItemId')
            ->with(
                $this->callback(fn ($itemId): bool => $itemId->equals($this->item->getId())),
                10,
                5,
            )
            ->willReturn([$like]);

        $this->assertSame([$like], $this->service->listByItem($this->item->getId(), 10, 5));
    }

    public function testListByItemUsesDefaultPagination(): void
    {
        $this->likeRepository
            ->expects($this->once())
            ->method('findByItemId')
            ->with(
                $this->callback(fn ($itemId): bool => $itemId->equals($this->item->getId())),
                50,
                0,
            )
            ->willReturn([]);

        $this->assertSame([], $this->service->listByItem($this->item->getId()));
    }

    public function testListByOwnerDelegatesWithOwnerIdAndPagination(): void
    {
        $like = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $ownerId = OwnerId::fromBytes($this->owner->getId()->toBytes());
        $this->likeRepository
            ->expects($this->once())
            ->method('findByOwnerId')
            ->with(
                $this->callback(
                    fn (OwnerId $id): bool => $id->toBytes() === $ownerId->toBytes(),
                ),
                5,
                15,
            )
            ->willReturn([$like]);

        $this->assertSame([$like], $this->service->listByOwner($ownerId, 5, 15));
    }

    public function testListByOwnerUsesDefaultPagination(): void
    {
        $ownerId = OwnerId::fromBytes($this->owner->getId()->toBytes());
        $this->likeRepository
            ->expects($this->once())
            ->method('findByOwnerId')
            ->with($this->isInstanceOf(OwnerId::class), 50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->listByOwner($ownerId));
    }

    public function testRemoveLikeRemovesAndFlushes(): void
    {
        $like = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $this->likeRepository->expects($this->once())->method('remove')->with($like);
        $this->unitOfWork->expects($this->once())->method('flush');

        $this->service->removeLike($like);
    }

    public function testToDTOMappingAndToArray(): void
    {
        $like = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);

        // fwd-5: the name is resolved by a batch lookup, so the repository mock
        // has to answer before toDTO can.
        $this->userRepository->expects($this->once())
            ->method('findNamesByIds')
            ->willReturn([$this->owner->getId()->toString() => $this->owner->getName()]);

        $dto = $this->service->toDTO($like);

        $this->assertSame($like->getId()->toString(), $dto->id);
        $this->assertSame($this->owner->getId()->toString(), $dto->ownerId);
        $this->assertSame($this->item->getId()->toString(), $dto->itemId);
        $this->assertSame($like->getCreatedAt(), $dto->createdAt);

        $this->assertSame(
            [
                'id' => $like->getId()->toString(),
                'owner_id' => $this->owner->getId()->toString(),
                'owner_name' => $this->owner->getName(),
                'item_id' => $this->item->getId()->toString(),
                'created_at' => $like->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
            $dto->toArray(),
        );
    }

    public function testToDTOMapsANullNameWhenTheRepositoryHasNoSuchUser(): void
    {
        $like = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);

        $this->userRepository->expects($this->once())
            ->method('findNamesByIds')
            ->willReturn([]);

        $dto = $this->service->toDTO($like);

        $this->assertNull($dto->ownerName);
    }

    public function testToDTOListResolvesNamesInOneCall(): void
    {
        $other = $this->secondUser();
        $first = Like::create(OwnerId::fromBytes($this->owner->getId()->toBytes()), $this->item);
        $second = Like::create(OwnerId::fromBytes($other->getId()->toBytes()), $this->item);

        // One statement for the whole page: a per-row lookup would show up as
        // more than one call here.
        $this->userRepository->expects($this->once())
            ->method('findNamesByIds')
            ->willReturn([
                $this->owner->getId()->toString() => $this->owner->getName(),
                $other->getId()->toString() => $other->getName(),
            ]);

        $dtos = $this->service->toDTOList([$first, $second]);

        $this->assertCount(2, $dtos);
        $this->assertSame($first->getId()->toString(), $dtos[0]->id);
        $this->assertSame($second->getId()->toString(), $dtos[1]->id);
        $this->assertSame($this->owner->getId()->toString(), $dtos[0]->ownerId);
        $this->assertSame($other->getId()->toString(), $dtos[1]->ownerId);
        $this->assertSame($this->owner->getName(), $dtos[0]->ownerName);
        $this->assertSame($other->getName(), $dtos[1]->ownerName);
    }
}
