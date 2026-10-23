<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Application\Item\Service\ItemSlotMapper;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\ValueObject\OwnerId;
use App\Domain\Item\Entity\Item;
use App\Domain\Tag\ValueObject\TagName;
use PHPUnit\Framework\TestCase;

/**
 * The catalog is written by hand, so its literals are checked against the
 * domain's own validation before the story ever runs: an apostrophe in an item
 * name (the classic "Schindler's List") is rejected here in a unit test, not in
 * the middle of a thousand-row seed.
 */
final class DemoCatalogTest extends TestCase
{
    public function testTagNamesPassTheDomainValidationAndAreUniqueCaseInsensitively(): void
    {
        $seen = [];

        foreach (DemoCatalog::TAG_NAMES as $name) {
            TagName::fromString($name);

            $folded = \mb_strtolower($name);
            self::assertArrayNotHasKey($folded, $seen, 'Tag names must differ case-insensitively: '.$name);
            $seen[$folded] = true;
        }

        self::assertCount(DemoCatalog::TAGS, DemoCatalog::TAG_NAMES);
    }

    public function testItemNamesPassTheDomainValidation(): void
    {
        $collection = Collection::create(
            OwnerId::generate(),
            CollectionName::fromString('Catalog Validation'),
            Theme::books(),
        );

        foreach (DemoCatalog::itemNames() as $theme => $names) {
            self::assertCount(25, $names, 'Each theme needs 25 item names: '.$theme);

            foreach ($names as $name) {
                Item::create($collection, $name);
            }
        }
    }

    public function testCollectionNamesAreUniqueAcrossTheWholeCatalog(): void
    {
        $bases = DemoCatalog::collectionBases();
        $names = [];

        foreach (DemoCatalog::themes() as $theme) {
            for ($i = 0; $i < DemoCatalog::COLLECTIONS_PER_THEME; ++$i) {
                $name = \sprintf('%s %02d', $bases[$theme->value()][$i % 10], \intdiv($i, 10) + 1);
                CollectionName::fromString($name);

                self::assertArrayNotHasKey($name, $names, 'Collection names must be unique: '.$name);
                $names[$name] = true;
            }
        }

        self::assertCount(200, $names);
    }

    public function testDateSlotValuesAreAcceptedByTheMapper(): void
    {
        $collection = Collection::create(
            OwnerId::generate(),
            CollectionName::fromString('Date Validation'),
            Theme::books(),
        );
        $item = Item::create($collection, 'Date Probe');
        $mapper = new ItemSlotMapper();

        foreach (DemoCatalog::DATES as $date) {
            $mapper->applySlots($item, [new \App\Application\Item\DTO\ItemSlotDTO('date', 1, $date)]);
            self::assertSame($date, $item->getSlotValue(\App\Domain\Collection\ValueObject\FieldType::date(), 1)?->format('Y-m-d'));
        }
    }

    public function testNumbersAndBoolsAreNonNullScalars(): void
    {
        foreach (DemoCatalog::NUMBERS as $number) {
            self::assertTrue(\is_int($number) || \is_float($number));
        }

        foreach (DemoCatalog::BOOLS as $bool) {
            self::assertIsBool($bool);
        }
    }
}
