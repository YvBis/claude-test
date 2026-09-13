<?php

declare(strict_types=1);

namespace App\Tests\Application\Item\DTO;

use App\Application\Item\DTO\ItemDTO;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class ItemDTOTest extends TestCase
{
    private function createItem(): Item
    {
        $user = User::register(
            name: 'Item DTO Test',
            email: Email::fromString('item_dto_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $collection = Collection::create(
            owner: $user,
            name: CollectionName::fromString('Item DTO Collection'),
            theme: Theme::games(),
        );
        $item = Item::create($collection, 'Halo 3');

        $item->setSlotValue(FieldType::text(), 1, 'Shooter');
        $item->setSlotValue(FieldType::number(), 2, 10);
        $item->setSlotValue(FieldType::date(), 3, new \DateTimeImmutable('2026-09-12T10:00:00+00:00'));
        $item->setSlotValue(FieldType::bool(), 1, true);
        $item->addTag(Tag::create(TagName::fromString('Games')));

        return $item;
    }

    public function testFromEntityMapsAllFields(): void
    {
        $item = $this->createItem();
        $dto = ItemDTO::fromEntity($item);

        $this->assertSame($item->getId()->toString(), $dto->id);
        $this->assertSame('Halo 3', $dto->name);
        $this->assertSame($item->getCollection()->getId()->toString(), $dto->collectionId);
        $this->assertCount(4, $dto->slots);
        $this->assertCount(1, $dto->tags);
        $this->assertSame('Games', $dto->tags[0]->name);
    }

    public function testFromEntityIncludesOnlySetSlots(): void
    {
        $dto = ItemDTO::fromEntity($this->createItem());

        $this->assertContains(['type' => 'text', 'slot' => 1, 'value' => 'Shooter'], $dto->slots);
        $this->assertContains(['type' => 'number', 'slot' => 2, 'value' => 10.0], $dto->slots);
        $this->assertContains(['type' => 'bool', 'slot' => 1, 'value' => true], $dto->slots);
        // null slots are omitted
        $this->assertNotContains(['type' => 'text', 'slot' => 2, 'value' => null], $dto->slots);
    }

    public function testDateSlotSerializedToAtom(): void
    {
        $dto = ItemDTO::fromEntity($this->createItem());

        $dateSlot = \array_values(\array_filter(
            $dto->slots,
            static fn (array $slot): bool => 'date' === $slot['type'],
        ));
        $this->assertCount(1, $dateSlot);
        $this->assertSame('2026-09-12T10:00:00+00:00', $dateSlot[0]['value']);
    }

    public function testToArrayShape(): void
    {
        $array = ItemDTO::fromEntity($this->createItem())->toArray();

        $this->assertSame([
            'id', 'name', 'collection_id', 'slots', 'tags', 'created_at', 'updated_at',
        ], \array_keys($array));
        $this->assertIsString($array['created_at']);
        $this->assertIsArray($array['tags']);
        $this->assertSame('Games', $array['tags'][0]['name']);
    }
}
