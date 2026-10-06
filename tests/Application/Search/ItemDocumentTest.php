<?php

declare(strict_types=1);

namespace App\Tests\Application\Search;

use App\Application\Search\ItemDocument;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;
use App\Domain\Tag\ValueObject\TagName;
use PHPUnit\Framework\TestCase;

final class ItemDocumentTest extends TestCase
{
    private function createItem(): Item
    {
        $collection = Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('Item Document Collection'),
            theme: Theme::games(),
        );
        $item = Item::create($collection, 'Halo 3');
        $item->addTag(Tag::create(TagName::fromString('Games')));

        return $item;
    }

    public function testFromEntityMapsAllFields(): void
    {
        $item = $this->createItem();
        $document = ItemDocument::fromEntity($item);

        self::assertSame($item->getId()->toString(), $document->id);
        self::assertSame('Halo 3', $document->name);
        self::assertSame($item->getCollection()->getId()->toString(), $document->collectionId);
        self::assertSame('Item Document Collection', $document->collectionName);
        self::assertSame($item->getCollection()->getOwnerId()->toString(), $document->ownerId);
    }

    public function testTagsAreDenormalizedAsPlainStrings(): void
    {
        $item = $this->createItem();
        $item->addTag(Tag::create(TagName::fromString('Shooter')));

        $document = ItemDocument::fromEntity($item);

        self::assertSame(['Games', 'Shooter'], $document->tags);
    }

    public function testItemWithoutTagsProducesEmptyList(): void
    {
        $collection = Collection::create(
            ownerId: OwnerId::generate(),
            name: CollectionName::fromString('Empty Tags'),
            theme: Theme::books(),
        );
        $document = ItemDocument::fromEntity(Item::create($collection, 'Untagged'));

        self::assertSame([], $document->tags);
    }

    public function testSlotsAreNotPartOfTheDocument(): void
    {
        $item = $this->createItem();
        $item->setSlotValue(FieldType::text(), 1, 'Shooter');
        $item->setSlotValue(FieldType::number(), 2, 10);

        $array = ItemDocument::fromEntity($item)->toArray();

        self::assertArrayNotHasKey('slots', $array);
    }

    public function testToArrayShape(): void
    {
        $array = ItemDocument::fromEntity($this->createItem())->toArray();

        self::assertSame(
            ['id', 'name', 'tags', 'collection_id', 'collection_name', 'owner_id'],
            \array_keys($array),
        );
    }
}
