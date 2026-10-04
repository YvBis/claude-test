<?php

declare(strict_types=1);

namespace App\Tests\Application\Like\DTO;

use App\Application\Like\DTO\LikeDTO;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use PHPUnit\Framework\TestCase;

final class LikeDTOTest extends TestCase
{
    private OwnerId $ownerId;

    private Item $item;

    protected function setUp(): void
    {
        $this->ownerId = OwnerId::generate();

        $collection = Collection::create(
            ownerId: $this->ownerId,
            name: CollectionName::fromString('Like DTO Collection'),
            theme: Theme::books(),
        );

        $this->item = Item::create($collection, '1984');
    }

    public function testFromEntityMapsFields(): void
    {
        $like = Like::create($this->ownerId, $this->item);

        // fwd-5: the display name is no longer read off the entity — the caller
        // resolves it through the user repository and hands it in.
        $dto = LikeDTO::fromEntity($like, 'John Doe');

        $this->assertSame($like->getId()->toString(), $dto->id);
        $this->assertSame($this->ownerId->toString(), $dto->ownerId);
        $this->assertSame('John Doe', $dto->ownerName);
        $this->assertSame($this->item->getId()->toString(), $dto->itemId);
        $this->assertSame($like->getCreatedAt(), $dto->createdAt);
    }

    public function testFromEntityAcceptsAMissingOwnerName(): void
    {
        $like = Like::create($this->ownerId, $this->item);

        $dto = LikeDTO::fromEntity($like, null);

        $this->assertNull($dto->ownerName);
        $this->assertNull($dto->toArray()['owner_name']);
    }

    public function testToArrayShape(): void
    {
        $like = Like::create($this->ownerId, $this->item);

        $array = LikeDTO::fromEntity($like, 'John Doe')->toArray();

        $this->assertSame(
            ['id', 'owner_id', 'owner_name', 'item_id', 'created_at'],
            \array_keys($array),
        );
        $this->assertSame('John Doe', $array['owner_name']);
        $this->assertSame($this->item->getId()->toString(), $array['item_id']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $array['created_at'],
        );
    }
}
