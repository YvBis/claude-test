<?php

declare(strict_types=1);

namespace App\Tests\Application\Item\Service;

use App\Application\Item\DTO\ItemSlotDTO;
use App\Application\Item\Service\ItemSlotMapper;
use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class ItemSlotMapperTest extends TestCase
{
    private ItemSlotMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ItemSlotMapper();
    }

    private function createItem(): Item
    {
        $user = User::register(
            name: 'Slot Mapper Test',
            email: Email::fromString('slot_mapper_test@example.com'),
            passwordHash: PasswordHash::createFromPlain('Pass123!'),
        );
        $collection = Collection::create(
            owner: $user,
            name: CollectionName::fromString('Slot Mapper Collection'),
            theme: Theme::books(),
        );

        return Item::create($collection, 'Mapper Item');
    }

    public function testAppliesTextSlot(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('text', 1, 'Hello')]);

        $this->assertSame('Hello', $item->getSlotValue(FieldType::text(), 1));
    }

    public function testAppliesNumberSlotCastingIntToFloat(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('number', 2, 42)]);

        $this->assertSame(42.0, $item->getSlotValue(FieldType::number(), 2));
    }

    public function testAppliesDateSlotFromIsoString(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00+00:00')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T10:00:00+00:00', $value->format(\DateTimeInterface::ATOM));
    }

    public function testAppliesBoolSlot(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('bool', 3, true)]);

        $this->assertTrue($item->getSlotValue(FieldType::bool(), 3));
    }

    public function testNullClearsSlot(): void
    {
        $item = $this->createItem();
        $item->setSlotValue(FieldType::text(), 1, 'Existing');

        $this->mapper->applySlots($item, [new ItemSlotDTO('text', 1, null)]);

        $this->assertNull($item->getSlotValue(FieldType::text(), 1));
    }

    public function testAppliesMultipleSlots(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [
            new ItemSlotDTO('text', 1, 'Title'),
            new ItemSlotDTO('number', 1, 7.5),
            new ItemSlotDTO('bool', 2, false),
        ]);

        $this->assertSame('Title', $item->getSlotValue(FieldType::text(), 1));
        $this->assertSame(7.5, $item->getSlotValue(FieldType::number(), 1));
        $this->assertFalse($item->getSlotValue(FieldType::bool(), 2));
    }

    public function testThrowsOnUnknownType(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('json', 1, 'x')]);
    }

    public function testThrowsOnNonStringForTextSlot(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('text', 1, 123)]);
    }

    public function testThrowsOnNonNumericForNumberSlot(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('number', 1, 'abc')]);
    }

    public function testThrowsOnInvalidDateString(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, 'not-a-date')]);
    }

    public function testThrowsOnRelativeDateString(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, 'tomorrow')]);
    }

    public function testDateOnlyStringParsesAtUtcMidnight(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T00:00:00+00:00', $value->format(\DateTimeInterface::ATOM));
    }

    public function testDateWithOffsetNormalizedToUtc(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00+02:00')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T08:00:00+00:00', $value->format(\DateTimeInterface::ATOM));
    }

    public function testDateWithZuluSuffixParsesAsUtc(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00Z')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T10:00:00+00:00', $value->format(\DateTimeInterface::ATOM));
    }

    public function testDateWithMillisecondsAndOffset(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00.123+02:00')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T08:00:00.123000+00:00', $value->format('Y-m-d\TH:i:s.uP'));
    }

    public function testDateWithMicrosecondsZulu(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00.123456Z')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T10:00:00.123456+00:00', $value->format('Y-m-d\TH:i:s.uP'));
    }

    public function testOffsetLessDateTimeParsesAsUtc(): void
    {
        $item = $this->createItem();

        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00')]);

        $value = $item->getSlotValue(FieldType::date(), 1);
        $this->assertInstanceOf(\DateTimeImmutable::class, $value);
        $this->assertSame('2026-09-12T10:00:00+00:00', $value->format(\DateTimeInterface::ATOM));
    }

    public function testThrowsOnTrailingGarbageAfterDate(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12junk')]);
    }

    public function testThrowsOnTrailingGarbageAfterFullDate(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('date', 1, '2026-09-12T10:00:00Zjunk')]);
    }

    public function testThrowsOnNonBoolForBoolSlot(): void
    {
        $item = $this->createItem();

        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->applySlots($item, [new ItemSlotDTO('bool', 1, 'yes')]);
    }
}
