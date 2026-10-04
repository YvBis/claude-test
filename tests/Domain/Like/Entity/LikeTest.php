<?php

declare(strict_types=1);

namespace App\Tests\Domain\Like\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\Like\ValueObject\LikeId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class LikeTest extends TestCase
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
        $like = Like::create($this->ownerId, $this->item);

        $this->assertInstanceOf(LikeId::class, $like->getId());
        $this->assertTrue($like->getOwnerId()->equals($this->ownerId));
        $this->assertSame($this->item, $like->getItem());
    }

    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Like::create($this->ownerId, $this->item);
        $b = Like::create($this->ownerId, $this->item);

        $this->assertFalse($a->getId()->equals($b->getId()));
    }

    public function testCreateSetsCreatedAtFromClock(): void
    {
        $like = Like::create($this->ownerId, $this->item);

        $this->assertSame('2026-01-01 10:00:00', $like->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testConstructorAcceptsExplicitId(): void
    {
        $id = LikeId::generate()->toBytes();

        $like = new Like($id, $this->ownerId, $this->item);

        $this->assertSame($id, $like->getId()->toBytes());
    }

    public function testGettersReturnPassedEntities(): void
    {
        $like = Like::create($this->ownerId, $this->item);

        $this->assertTrue($like->getOwnerId()->equals($this->ownerId));
        $this->assertSame($this->item->getId()->toString(), $like->getItem()->getId()->toString());
    }
}
