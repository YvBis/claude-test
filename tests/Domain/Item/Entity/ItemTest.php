<?php

declare(strict_types=1);

namespace App\Tests\Domain\Item\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Item\Entity\Item;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class ItemTest extends TestCase
{
    private MockClock $clock;
    private Collection $collection;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 10:00:00');
        Clock::set($this->clock);

        $user = new User(
            UserId::generate()->toBytes(),
            'Test User',
            Email::fromString('test@example.com'),
            PasswordHash::createFromPlain('password123'),
            Role::fromString('user')
        );

        $this->collection = new Collection(
            id: CollectionId::generate()->toBytes(),
            owner: $user,
            name: CollectionName::fromString('My Books'),
            theme: Theme::books(),
        );
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
    }

    public function testCreateFactoryCreatesEntity(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->assertInstanceOf(Item::class, $item);
        $this->assertInstanceOf(ItemId::class, $item->getId());
        $this->assertSame($this->collection, $item->getCollection());
        $this->assertSame('1984', $item->getName());
        $this->assertNotNull($item->getCreatedAt());
        $this->assertSame($item->getCreatedAt(), $item->getUpdatedAt());
    }

    public function testCreateSetsCreatedAtAndUpdatedAt(): void
    {
        $item = Item::create($this->collection, 'Dune');

        $this->assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $item->getUpdatedAt());
    }

    public function testCreateTrimsName(): void
    {
        $item = Item::create($this->collection, '  Dune  ');

        $this->assertSame('Dune', $item->getName());
    }

    public function testCreateCollapsesWhitespace(): void
    {
        $item = Item::create($this->collection, 'Brave   New   World');

        $this->assertSame('Brave New World', $item->getName());
    }

    public function testCreateStripsControlCharacters(): void
    {
        $item = Item::create($this->collection, "Dune\x00\x01Test\x7F");

        $this->assertSame('DuneTest', $item->getName());
    }

    public function testCreateThrowsOnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        Item::create($this->collection, '   ');
    }

    public function testCreateThrowsOnTooLongName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 100');

        Item::create($this->collection, \str_repeat('a', 101));
    }

    public function testCreateThrowsOnInvalidCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        Item::create($this->collection, 'Dune <script>');
    }

    public function testCreateAllowsMultibyteCharacters(): void
    {
        $item = Item::create($this->collection, 'Братья Карамазовы');

        $this->assertSame('Братья Карамазовы', $item->getName());
    }

    public function testCreateAllowsAllowedSpecialCharacters(): void
    {
        $item = Item::create($this->collection, 'Author/Full-Name.2026_Vol');

        $this->assertSame('Author/Full-Name.2026_Vol', $item->getName());
    }

    public function testChangeNameUpdatesNameAndTouches(): void
    {
        $item = Item::create($this->collection, '1984');
        $originalUpdatedAt = $item->getUpdatedAt();
        $originalCreatedAt = $item->getCreatedAt();

        $this->clock->modify('+1 microsecond');
        $item->changeName('Animal Farm');

        $this->assertSame('Animal Farm', $item->getName());
        $this->assertGreaterThan($originalUpdatedAt, $item->getUpdatedAt());
        $this->assertSame($originalCreatedAt, $item->getCreatedAt());
    }

    public function testChangeNameTrimsValue(): void
    {
        $item = Item::create($this->collection, '1984');

        $item->changeName('  Animal Farm  ');

        $this->assertSame('Animal Farm', $item->getName());
    }

    public function testChangeNameThrowsOnInvalidCharacters(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        $item->changeName('Dune </script>');
    }

    public function testChangeNameCollapsesWhitespace(): void
    {
        $item = Item::create($this->collection, '1984');

        $item->changeName('Brave   New   World');

        $this->assertSame('Brave New World', $item->getName());
    }

    public function testChangeNameStripsControlCharacters(): void
    {
        $item = Item::create($this->collection, '1984');

        $item->changeName("Dune\x00\x01Test\x7F");

        $this->assertSame('DuneTest', $item->getName());
    }

    public function testChangeNameThrowsOnEmptyName(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $item->changeName('   ');
    }

    public function testChangeNameThrowsOnTooLongName(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 100');

        $item->changeName(\str_repeat('a', 101));
    }

    public function testChangeNameSameValueDoesNotTouchEntity(): void
    {
        $item = Item::create($this->collection, '1984');
        $updatedAt = $item->getUpdatedAt();

        $this->clock->modify('+1 microsecond');
        $item->changeName('1984');

        $this->assertSame($updatedAt, $item->getUpdatedAt());
    }

    public function testChangeNameNormalizedEqualDoesNotTouchEntity(): void
    {
        $item = Item::create($this->collection, '1984');
        $updatedAt = $item->getUpdatedAt();

        $this->clock->modify('+1 microsecond');
        $item->changeName('  1984  ');

        $this->assertSame($updatedAt, $item->getUpdatedAt());
        $this->assertSame('1984', $item->getName());
    }

    public function testChangeNameAllowsMultibyte(): void
    {
        $item = Item::create($this->collection, '1984');

        $item->changeName('Онегин Евгений');

        $this->assertSame('Онегин Евгений', $item->getName());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $item = Item::create($this->collection, '1984');
        $originalUpdatedAt = $item->getUpdatedAt();
        $originalCreatedAt = $item->getCreatedAt();

        $this->clock->modify('+1 microsecond');
        $item->touch();

        $this->assertGreaterThan($originalUpdatedAt, $item->getUpdatedAt());
        $this->assertSame($originalCreatedAt, $item->getCreatedAt());
    }

    public function testSetAndGetTextSlotValuesRoundtrip(): void
    {
        $item = Item::create($this->collection, '1984');

        foreach ([1, 2, 3] as $slot) {
            $item->setSlotValue(FieldType::text(), $slot, "value-$slot");
            $this->assertSame("value-$slot", $item->getSlotValue(FieldType::text(), $slot));
        }
    }

    public function testSetAndGetNumberSlotValuesRoundtrip(): void
    {
        $item = Item::create($this->collection, '1984');

        foreach ([1, 2, 3] as $slot) {
            $item->setSlotValue(FieldType::number(), $slot, 12.5 + $slot);
            $this->assertSame(12.5 + $slot, $item->getSlotValue(FieldType::number(), $slot));
        }
    }

    public function testSetNumberSlotAcceptsIntAndCastsToFloat(): void
    {
        $item = Item::create($this->collection, '1984');

        $item->setSlotValue(FieldType::number(), 1, 42);

        $this->assertSame(42.0, $item->getSlotValue(FieldType::number(), 1));
    }

    public function testSetAndGetDateSlotValuesRoundtrip(): void
    {
        $item = Item::create($this->collection, '1984');
        $dates = [new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-03-01')];

        foreach ([1, 2, 3] as $slot) {
            $item->setSlotValue(FieldType::date(), $slot, $dates[$slot - 1]);
            $this->assertSame($dates[$slot - 1], $item->getSlotValue(FieldType::date(), $slot));
        }
    }

    public function testSetAndGetBoolSlotValuesRoundtrip(): void
    {
        $item = Item::create($this->collection, '1984');
        $values = [true, false, true];

        foreach ([1, 2, 3] as $slot) {
            $item->setSlotValue(FieldType::bool(), $slot, $values[$slot - 1]);
            $this->assertSame($values[$slot - 1], $item->getSlotValue(FieldType::bool(), $slot));
        }
    }

    public function testGetSlotValueReturnsNullForUnsetSlot(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->assertNull($item->getSlotValue(FieldType::text(), 2));
        $this->assertNull($item->getSlotValue(FieldType::number(), 1));
        $this->assertNull($item->getSlotValue(FieldType::date(), 3));
        $this->assertNull($item->getSlotValue(FieldType::bool(), 1));
    }

    public function testSetSlotValueResetsToNull(): void
    {
        $item = Item::create($this->collection, '1984');
        $item->setSlotValue(FieldType::text(), 1, 'value');

        $item->setSlotValue(FieldType::text(), 1, null);

        $this->assertNull($item->getSlotValue(FieldType::text(), 1));
    }

    public function testSetSlotValueTouchesEntity(): void
    {
        $item = Item::create($this->collection, '1984');
        $originalUpdatedAt = $item->getUpdatedAt();

        $this->clock->modify('+1 microsecond');
        $item->setSlotValue(FieldType::text(), 1, 'value');

        $this->assertGreaterThan($originalUpdatedAt, $item->getUpdatedAt());
    }

    public function testSetSlotValueNullToNullDoesNotTouchEntity(): void
    {
        $item = Item::create($this->collection, '1984');
        $updatedAt = $item->getUpdatedAt();

        $this->clock->modify('+1 microsecond');
        $item->setSlotValue(FieldType::text(), 1, null);

        $this->assertSame($updatedAt, $item->getUpdatedAt());

        $item->setSlotValue(FieldType::number(), 2, null);
        $this->assertSame($updatedAt, $item->getUpdatedAt());
    }

    public function testSetSlotValueSameValueDoesNotTouchEntity(): void
    {
        $item = Item::create($this->collection, '1984');
        $item->setSlotValue(FieldType::text(), 1, 'value');

        $updatedAt = $item->getUpdatedAt();

        $this->clock->modify('+1 microsecond');
        $item->setSlotValue(FieldType::text(), 1, 'value');

        $this->assertSame($updatedAt, $item->getUpdatedAt());
    }

    public function testSetTextSlotAcceptsMultibyte(): void
    {
        $item = Item::create($this->collection, '1984');

        $item->setSlotValue(FieldType::text(), 2, 'Преступление и наказание');

        $this->assertSame('Преступление и наказание', $item->getSlotValue(FieldType::text(), 2));
    }

    public function testSetSlotValueThrowsOnWrongTypeForKeySlot(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects string');

        $item->setSlotValue(FieldType::text(), 1, 123.5);
    }

    public function testSetNumberSlotThrowsOnWrongType(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects numeric');

        $item->setSlotValue(FieldType::number(), 1, 'not-a-number');
    }

    public function testSetDateSlotThrowsOnWrongType(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects DateTimeImmutable');

        $item->setSlotValue(FieldType::date(), 2, '2026-01-01');
    }

    public function testSetBoolSlotThrowsOnWrongType(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects bool');

        $item->setSlotValue(FieldType::bool(), 1, 'true');
    }

    public function testSetSlotValueThrowsOnSlotOutOfRange(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot index must be between 1 and 3');

        $item->setSlotValue(FieldType::text(), 4, 'value');
    }

    public function testGetSlotValueThrowsOnSlotOutOfRange(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot index must be between 1 and 3');

        $item->getSlotValue(FieldType::text(), 0);
    }

    public function testSetSlotValueThrowsOnIntForTextSlot(): void
    {
        $item = Item::create($this->collection, '1984');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects string');

        $item->setSlotValue(FieldType::text(), 1, 42);
    }
}
