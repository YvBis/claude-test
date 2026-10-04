<?php

declare(strict_types=1);

namespace App\Tests\Domain\Comment\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Comment\Entity\Comment;
use App\Domain\Comment\ValueObject\CommentContent;
use App\Domain\Comment\ValueObject\CommentId;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class CommentTest extends TestCase
{
    private MockClock $clock;
    private OwnerId $ownerId;
    private Item $item;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($this->clock);

        $this->ownerId = OwnerId::generate();

        $collection = new Collection(
            id: CollectionId::generate()->toBytes(),
            ownerId: $this->ownerId,
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
        );

        $this->item = Item::create($collection, '1984');
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
    }

    public function testCreateFactoryCreatesEntity(): void
    {
        $comment = Comment::create($this->ownerId, $this->item, CommentContent::fromString('Nice'));

        $this->assertInstanceOf(CommentId::class, $comment->getId());
        $this->assertTrue($comment->getOwnerId()->equals($this->ownerId));
        $this->assertSame($this->item, $comment->getItem());
        $this->assertSame('Nice', $comment->getContent()->value());
        $this->assertSame($comment->getCreatedAt(), $comment->getUpdatedAt());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Comment::create($this->ownerId, $this->item, CommentContent::fromString('a'));
        $b = Comment::create($this->ownerId, $this->item, CommentContent::fromString('b'));

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testChangeContentUpdatesContentAndTimestamp(): void
    {
        $comment = Comment::create($this->ownerId, $this->item, CommentContent::fromString('first'));
        $createdAt = $comment->getCreatedAt();

        $this->clock->modify('+1 minute');
        $comment->changeContent(CommentContent::fromString('edited'));

        $this->assertSame('edited', $comment->getContent()->value());
        $this->assertNotSame($createdAt, $comment->getUpdatedAt());
        $this->assertGreaterThan($createdAt, $comment->getUpdatedAt());
    }

    public function testChangeContentWithSameContentIsNoOp(): void
    {
        $comment = Comment::create($this->ownerId, $this->item, CommentContent::fromString('same'));
        $updatedAt = $comment->getUpdatedAt();

        $this->clock->modify('+1 minute');
        $comment->changeContent(CommentContent::fromString('same'));

        $this->assertSame($updatedAt, $comment->getUpdatedAt());
    }

    public function testChangeContentWithNormalizedEqualContentIsNoOp(): void
    {
        $comment = Comment::create($this->ownerId, $this->item, CommentContent::fromString('  hello  '));
        $updatedAt = $comment->getUpdatedAt();

        $this->clock->modify('+1 minute');
        $comment->changeContent(CommentContent::fromString('hello'));

        $this->assertSame('hello', $comment->getContent()->value());
        $this->assertSame($updatedAt, $comment->getUpdatedAt());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $comment = Comment::create($this->ownerId, $this->item, CommentContent::fromString('text'));
        $createdAt = $comment->getCreatedAt();
        $updatedAt = $comment->getUpdatedAt();

        $this->clock->modify('+1 minute');
        $comment->touch();

        $this->assertSame($createdAt, $comment->getCreatedAt());
        $this->assertGreaterThan($updatedAt, $comment->getUpdatedAt());
    }
}
