<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\Entity\CollectionField;
use App\Domain\Collection\ValueObject\CollectionFieldId;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\FieldName;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Collection\ValueObject\Theme;
use App\Domain\Common\Constant\SlotLimits;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class CollectionFieldTest extends TestCase
{
    private MockClock $clock;
    private Collection $collection;
    private FieldName $fieldName;
    private FieldType $fieldType;

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

        $this->fieldName = FieldName::fromString('Pages');
        $this->fieldType = FieldType::number();
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
    }

    public function testCreateFactoryCreatesEntity(): void
    {
        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);

        $this->assertInstanceOf(CollectionField::class, $field);
        $this->assertInstanceOf(CollectionFieldId::class, $field->getId());
        $this->assertSame($this->collection, $field->getCollection());
        $this->assertSame($this->fieldName, $field->getName());
        $this->assertSame($this->fieldType, $field->getType());
        $this->assertSame(1, $field->getSlotIndex());
        $this->assertNotNull($field->getCreatedAt());
        $this->assertNotNull($field->getUpdatedAt());
        $this->assertSame($field->getCreatedAt(), $field->getUpdatedAt());
    }

    public function testCreateSetsCreatedAtAndUpdatedAt(): void
    {
        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);

        $this->assertInstanceOf(\DateTimeImmutable::class, $field->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $field->getUpdatedAt());
    }

    public function testRenameUpdatesNameAndTouches(): void
    {
        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);
        $originalUpdatedAt = $field->getUpdatedAt();
        $originalCreatedAt = $field->getCreatedAt();

        $this->clock->modify('+1 microsecond');
        $field->rename(FieldName::fromString('Author'));

        $this->assertSame('Author', $field->getName()->value());
        $this->assertGreaterThan($originalUpdatedAt, $field->getUpdatedAt());
        $this->assertSame($originalCreatedAt, $field->getCreatedAt());
    }

    public function testRenameDoesNotChangeTypeOrSlot(): void
    {
        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);

        $field->rename(FieldName::fromString('New Name'));

        $this->assertSame($this->fieldType, $field->getType());
        $this->assertSame(1, $field->getSlotIndex());
        $this->assertSame($this->collection, $field->getCollection());
    }

    public function testReassignToCollectionUpdatesAssociation(): void
    {
        $user = new User(
            UserId::generate()->toBytes(),
            'Other User',
            Email::fromString('other@example.com'),
            PasswordHash::createFromPlain('password123'),
            Role::fromString('user')
        );
        $otherCollection = new Collection(
            id: CollectionId::generate()->toBytes(),
            owner: $user,
            name: CollectionName::fromString('Other'),
            theme: Theme::games(),
        );

        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);
        $originalUpdatedAt = $field->getUpdatedAt();

        $this->clock->modify('+1 microsecond');
        $field->reassignToCollection($otherCollection);

        $this->assertSame($otherCollection, $field->getCollection());
        $this->assertGreaterThan($originalUpdatedAt, $field->getUpdatedAt());
    }

    public function testTouchUpdatesUpdatedAt(): void
    {
        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);
        $originalUpdatedAt = $field->getUpdatedAt();
        $originalCreatedAt = $field->getCreatedAt();

        $this->clock->modify('+1 microsecond');
        $field->touch();

        $this->assertGreaterThan($originalUpdatedAt, $field->getUpdatedAt());
        $this->assertSame($originalCreatedAt, $field->getCreatedAt());
    }

    public function testConstructorValidatesSlotIndexRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CollectionField(
            id: CollectionFieldId::generate()->toBytes(),
            collection: $this->collection,
            name: $this->fieldName,
            type: $this->fieldType,
            slotIndex: 0, // below min
        );
    }

    public function testConstructorValidatesSlotIndexMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CollectionField(
            id: CollectionFieldId::generate()->toBytes(),
            collection: $this->collection,
            name: $this->fieldName,
            type: $this->fieldType,
            slotIndex: SlotLimits::MAX_SLOTS_PER_TYPE + 1, // above max
        );
    }

    public function testGetIdReturnsCollectionFieldId(): void
    {
        $field = CollectionField::create($this->collection, $this->fieldName, $this->fieldType, 1);

        $this->assertInstanceOf(CollectionFieldId::class, $field->getId());
    }
}
