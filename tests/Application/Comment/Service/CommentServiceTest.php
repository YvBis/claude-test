<?php

declare(strict_types=1);

namespace App\Tests\Application\Comment\Service;

use App\Application\Comment\Service\CommentService;
use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\OwnerId;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\Repository\CommentRepositoryInterface;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CommentServiceTest extends TestCase
{
    private CommentRepositoryInterface&MockObject $commentRepository;
    private UnitOfWorkInterface&MockObject $unitOfWork;
    private CommentService $service;
    private User $author;
    private Item $item;

    protected function setUp(): void
    {
        $this->commentRepository = $this->createMock(CommentRepositoryInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->service = new CommentService($this->commentRepository, $this->unitOfWork);

        $this->author = User::register(
            name: 'Comment Service Test',
            email: Email::fromString('comment_service_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );

        $collection = Collection::create(
            owner: $this->author,
            name: CollectionName::fromString('Comment Service Collection'),
            theme: Theme::books(),
        );

        $this->item = Item::create($collection, '1984');
    }

    private function comment(string $content = 'Great read'): Comment
    {
        return Comment::create($this->author, $this->item, CommentContent::fromString($content));
    }

    public function testCreateSavesAndFlushes(): void
    {
        $this->commentRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(
                fn (Comment $comment): bool => $comment->getOwner() === $this->author
                    && $comment->getItem() === $this->item
                    && 'Great read' === $comment->getContent()->value(),
            ));
        $this->unitOfWork->expects($this->once())->method('flush');

        $comment = $this->service->create($this->author, $this->item, 'Great read');

        $this->assertSame($this->author, $comment->getOwner());
        $this->assertSame($this->item, $comment->getItem());
        $this->assertSame('Great read', $comment->getContent()->value());
    }

    public function testCreateThrowsOnEmptyContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->commentRepository->expects($this->never())->method('save');
        $this->unitOfWork->expects($this->never())->method('flush');

        $this->service->create($this->author, $this->item, '   ');
    }

    public function testCreateThrowsOnTooLongContent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->commentRepository->expects($this->never())->method('save');
        $this->unitOfWork->expects($this->never())->method('flush');

        $this->service->create($this->author, $this->item, \str_repeat('a', CommentContent::MAX_LENGTH + 1));
    }

    public function testGetByIdReturnsComment(): void
    {
        $comment = $this->comment('Great read');
        $this->commentRepository
            ->expects($this->once())
            ->method('findById')
            ->with($this->callback(
                fn (CommentId $id): bool => $id->toString() === $comment->getId()->toString(),
            ))
            ->willReturn($comment);

        $this->assertSame($comment, $this->service->getById($comment->getId()->toString()));
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->commentRepository->method('findById')->willReturn(null);

        $this->assertNull($this->service->getById(CommentId::generate()->toString()));
    }

    public function testChangeContentAppliesAndFlushes(): void
    {
        $comment = $this->comment();
        $this->commentRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(
                fn (Comment $saved): bool => 'Edited body' === $saved->getContent()->value(),
            ));
        $this->unitOfWork->expects($this->once())->method('flush');

        $result = $this->service->changeContent($comment, 'Edited body');

        $this->assertSame($comment, $result);
        $this->assertSame('Edited body', $comment->getContent()->value());
    }

    public function testChangeContentIsNoOpWhenNormalisationEqual(): void
    {
        $comment = $this->comment('Great read');
        $this->commentRepository->expects($this->never())->method('save');
        $this->unitOfWork->expects($this->never())->method('flush');

        $result = $this->service->changeContent($comment, '  Great read  ');

        $this->assertSame($comment, $result);
        $this->assertSame('Great read', $comment->getContent()->value());
    }

    public function testChangeContentThrowsOnInvalidContent(): void
    {
        $comment = $this->comment();
        $this->expectException(\InvalidArgumentException::class);
        $this->commentRepository->expects($this->never())->method('save');
        $this->unitOfWork->expects($this->never())->method('flush');

        $this->service->changeContent($comment, '');
    }

    public function testDeleteRemovesAndFlushes(): void
    {
        $comment = $this->comment();
        $this->commentRepository->expects($this->once())->method('remove')->with($comment);
        $this->unitOfWork->expects($this->once())->method('flush');

        $this->service->delete($comment);
    }

    public function testListByItemDelegatesWithPagination(): void
    {
        $comment = $this->comment();
        $this->commentRepository
            ->expects($this->once())
            ->method('findByItemId')
            ->with(
                $this->callback(
                    fn (ItemId $id): bool => $id->toString() === $this->item->getId()->toString(),
                ),
                10,
                20,
            )
            ->willReturn([$comment]);

        $this->assertSame([$comment], $this->service->listByItem($this->item->getId(), 10, 20));
    }

    public function testListByItemUsesDefaultPagination(): void
    {
        $this->commentRepository
            ->expects($this->once())
            ->method('findByItemId')
            ->with($this->isInstanceOf(ItemId::class), 50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->listByItem($this->item->getId()));
    }

    public function testListByOwnerDelegatesWithOwnerIdAndPagination(): void
    {
        $comment = $this->comment();
        $ownerId = OwnerId::fromBytes($this->author->getId()->toBytes());
        $this->commentRepository
            ->expects($this->once())
            ->method('findByOwnerId')
            ->with(
                $this->callback(
                    fn (OwnerId $id): bool => $id->toBytes() === $ownerId->toBytes(),
                ),
                5,
                15,
            )
            ->willReturn([$comment]);

        $this->assertSame([$comment], $this->service->listByOwner($ownerId, 5, 15));
    }

    public function testListByOwnerUsesDefaultPagination(): void
    {
        $ownerId = OwnerId::fromBytes($this->author->getId()->toBytes());
        $this->commentRepository
            ->expects($this->once())
            ->method('findByOwnerId')
            ->with($this->isInstanceOf(OwnerId::class), 50, 0)
            ->willReturn([]);

        $this->assertSame([], $this->service->listByOwner($ownerId));
    }

    public function testCountByItemDelegates(): void
    {
        $this->commentRepository
            ->expects($this->once())
            ->method('countByItemId')
            ->with($this->isInstanceOf(ItemId::class))
            ->willReturn(3);

        $this->assertSame(3, $this->service->countByItem($this->item->getId()));
    }

    public function testCountByOwnerDelegates(): void
    {
        $ownerId = OwnerId::fromBytes($this->author->getId()->toBytes());
        $this->commentRepository
            ->expects($this->once())
            ->method('countByOwnerId')
            ->with($this->callback(
                fn (OwnerId $id): bool => $id->toBytes() === $ownerId->toBytes(),
            ))
            ->willReturn(7);

        $this->assertSame(7, $this->service->countByOwner($ownerId));
    }

    public function testToDTOMapsComment(): void
    {
        $dto = $this->service->toDTO($this->comment('Nice one'));

        $this->assertSame('Nice one', $dto->content);
        $this->assertSame('Comment Service Test', $dto->ownerName);
        $this->assertSame($this->item->getId()->toString(), $dto->itemId);
    }

    public function testToDTOListMapsEveryComment(): void
    {
        $dtos = $this->service->toDTOList([$this->comment('One'), $this->comment('Two')]);

        $this->assertCount(2, $dtos);
        $this->assertSame('One', $dtos[0]->content);
        $this->assertSame('Two', $dtos[1]->content);
    }
}
