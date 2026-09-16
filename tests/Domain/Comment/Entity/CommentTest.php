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
use App\Domain\Item\Entity\Item;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class CommentTest extends TestCase
{
    private MockClock $clock;
    private User $owner;
    private Item $item;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($this->clock);

        $this->owner = new User(
            UserId::generate()->toBytes(),
            'Comment Author',
            Email::fromString('author@example.com'),
            PasswordHash::createFromPlain('password123'),
            Role::fromString('user')
        );

        $collection = new Collection(
            id: CollectionId::generate()->toBytes(),
            owner: $this->owner,
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
        $comment = Comment::create($this->owner, $this->item, CommentContent::fromString('Nice'));

        $this->assertInstanceOf(CommentId::class, $comment->getId());
        $this->assertSame($this->owner, $comment->getOwner());
        $this->assertSame($this->item, $comment->getItem());
        $this->assertSame('Nice', $comment->getContent()->value());
        $this->assertSame($comment->getCreatedAt(), $comment->getUpdatedAt());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Comment::create($this->owner, $this->item, CommentContent::fromString('a'));
        $b = Comment::create($this->owner, $this->item, CommentContent::fromString('b'));

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testChangeContentUpdatesContentAndTimestamp(): void
    {
        $comment = Comment::create($this->owner, $this->item, CommentContent::fromString('first'));
        $createdAt = $comment->getCreatedAt();

        $this->clock->modify('+1 minute');
        $comment->changeContent(CommentContent::fromString('edited'));

        $this->assertSame('edited', $comment->getContent()->value());
        $this->assertNotSame($createdAt, $comment->getUpdatedAt());
        $this->assertGreaterThan($createdAt, $comment->getUpdatedAt());
    }

    public function testChangeContentWithSameContentIsNoOp(): void
    {
        $comment = Comment::create($this->owner, $this->item, CommentContent::fromString('same'));
        $updatedAt = $comment->getUpdatedAt();

        $this->clock->modify('+1 minute');
        $comment->changeContent(CommentContent::fromString('same'));

        $this->assertSame($updatedAt, $comment->getUpdatedAt());
    }

    public function testChangeContentWithNormalizedEqualContentIsNoOp(): void
    {
        $comment = Comment::create($this->owner, $this->item, CommentContent::fromString('  hello  '));
        $updatedAt = $comment->getUpdatedAt();

        $this->clock->modify('+1 minute');
        $comment->changeContent(CommentContent::fromString('hello'));

        $this->assertSame('hello', $comment->getContent()->value());
        $this->assertSame($updatedAt, $comment->getUpdatedAt());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $comment = Comment::create($this->owner, $this->item, CommentContent::fromString('text'));
        $createdAt = $comment->getCreatedAt();
        $updatedAt = $comment->getUpdatedAt();

        $this->clock->modify('+1 minute');
        $comment->touch();

        $this->assertSame($createdAt, $comment->getCreatedAt());
        $this->assertGreaterThan($updatedAt, $comment->getUpdatedAt());
    }
}
