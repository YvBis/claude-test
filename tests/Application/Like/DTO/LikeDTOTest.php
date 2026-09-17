<?php

declare(strict_types=1);

namespace App\Tests\Application\Like\DTO;

use App\Application\Like\DTO\LikeDTO;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Like\Entity\Like;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class LikeDTOTest extends TestCase
{
    private User $owner;

    private Item $item;

    protected function setUp(): void
    {
        $this->owner = User::register(
            name: 'John Doe',
            email: Email::fromString('like_dto_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );

        $collection = Collection::create(
            owner: $this->owner,
            name: CollectionName::fromString('Like DTO Collection'),
            theme: Theme::books(),
        );

        $this->item = Item::create($collection, '1984');
    }

    public function testFromEntityMapsFields(): void
    {
        $like = Like::create($this->owner, $this->item);

        $dto = LikeDTO::fromEntity($like);

        $this->assertSame($like->getId()->toString(), $dto->id);
        $this->assertSame($this->owner->getId()->toString(), $dto->ownerId);
        $this->assertSame('John Doe', $dto->ownerName);
        $this->assertSame($this->item->getId()->toString(), $dto->itemId);
        $this->assertSame($like->getCreatedAt(), $dto->createdAt);
    }

    public function testToArrayShape(): void
    {
        $like = Like::create($this->owner, $this->item);

        $array = LikeDTO::fromEntity($like)->toArray();

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
