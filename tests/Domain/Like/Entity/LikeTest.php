<?php

declare(strict_types=1);

namespace App\Tests\Domain\Like\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\ValueObject\LikeId;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class LikeTest extends TestCase
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
            'Like Owner',
            Email::fromString('liker@example.com'),
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
        $like = Like::create($this->owner, $this->item);

        $this->assertInstanceOf(LikeId::class, $like->getId());
        $this->assertSame($this->owner, $like->getOwner());
        $this->assertSame($this->item, $like->getItem());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Like::create($this->owner, $this->item);
        $b = Like::create($this->owner, $this->item);

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testCreateSetsCreatedAtFromClock(): void
    {
        $like = Like::create($this->owner, $this->item);

        $this->assertSame('2026-01-01 10:00:00', $like->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testConstructorAcceptsExplicitId(): void
    {
        $id = LikeId::generate()->toBytes();

        $like = new Like($id, $this->owner, $this->item);

        $this->assertSame($id, $like->getId()->toBytes());
    }

    public function testGettersReturnPassedEntities(): void
    {
        $like = Like::create($this->owner, $this->item);

        $this->assertSame($this->owner->getId()->toString(), $like->getOwner()->getId()->toString());
        $this->assertSame($this->item->getId()->toString(), $like->getItem()->getId()->toString());
    }
}
