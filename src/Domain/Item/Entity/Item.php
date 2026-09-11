<?php

declare(strict_types=1);

namespace App\Domain\Item\Entity;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Common\Constant\SlotLimits;
use App\Domain\Item\ValueObject\ItemId;
use App\Domain\Tag\Entity\Tag;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\ClockAwareTrait;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'items')]
#[ORM\Index(name: 'idx_item_collection', columns: ['collection_id'])]
#[ORM\HasLifecycleCallbacks]
final class Item
{
    use ClockAwareTrait {
        now as protected clockNow;
    }

    public const int MAX_NAME_LENGTH = 100;

    public const int MAX_TEXT_SLOT_LENGTH = 1000;

    /**
     * Allowed characters: Unicode letters, digits, space, underscore, hyphen, dot, forward slash.
     */
    private const string ALLOWED_PATTERN = '/^[\p{L}\p{N} _\-\.\/]+$/u';

    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'binary', length: 16)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Collection::class, fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'collection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private Collection $collection;

    #[ORM\Column(name: 'name', type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: self::MAX_NAME_LENGTH, charset: 'UTF-8')]
    #[Assert\Regex(pattern: self::ALLOWED_PATTERN)]
    private string $name;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'text_1', type: Types::STRING, length: 1000, nullable: true)]
    private ?string $text1 = null;

    #[ORM\Column(name: 'text_2', type: Types::STRING, length: 1000, nullable: true)]
    private ?string $text2 = null;

    #[ORM\Column(name: 'text_3', type: Types::STRING, length: 1000, nullable: true)]
    private ?string $text3 = null;

    #[ORM\Column(name: 'num_1', type: Types::FLOAT, nullable: true)]
    private ?float $num1 = null;

    #[ORM\Column(name: 'num_2', type: Types::FLOAT, nullable: true)]
    private ?float $num2 = null;

    #[ORM\Column(name: 'num_3', type: Types::FLOAT, nullable: true)]
    private ?float $num3 = null;

    #[ORM\Column(name: 'date_1', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date1 = null;

    #[ORM\Column(name: 'date_2', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date2 = null;

    #[ORM\Column(name: 'date_3', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $date3 = null;

    #[ORM\Column(name: 'bool_1', type: Types::BOOLEAN, nullable: true)]
    private ?bool $bool1 = null;

    #[ORM\Column(name: 'bool_2', type: Types::BOOLEAN, nullable: true)]
    private ?bool $bool2 = null;

    #[ORM\Column(name: 'bool_3', type: Types::BOOLEAN, nullable: true)]
    private ?bool $bool3 = null;

    /**
     * @var DoctrineCollection<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, fetch: 'LAZY')]
    #[ORM\JoinTable(name: 'item_tags')]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private DoctrineCollection $tags;

    /**
     * @internal This constructor is public to allow test object creation.
     * Use Item::create() for production code.
     */
    public function __construct(
        string $id,
        Collection $collection,
        string $name,
    ) {
        $this->id = $id;
        $this->collection = $collection;
        $this->name = $this->normalizeName($name);
        $this->tags = new ArrayCollection();
        $this->createdAt = $this->clockNow();
        $this->updatedAt = $this->createdAt;
    }

    public static function create(Collection $collection, string $name): self
    {
        return new self(
            id: ItemId::generate()->toBytes(),
            collection: $collection,
            name: $name,
        );
    }

    public function getId(): ItemId
    {
        return ItemId::fromBytes($this->id);
    }

    public function getCollection(): Collection
    {
        return $this->collection;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function changeName(string $name): void
    {
        $name = $this->normalizeName($name);

        if ($name === $this->name) {
            return;
        }

        $this->name = $name;
        $this->touch();
    }

    // Note: updatedAt is updated via explicit touch() calls from domain mutators
    // (changeName()). Doctrine @ORM\PreUpdate is NOT used
    // because the changeset is computed before preUpdate fires — touch() there
    // would never persist.

    public function touch(): void
    {
        $this->updatedAt = $this->clockNow();
    }

    /**
     * @return array<Tag>
     */
    public function getTags(): array
    {
        return \array_values($this->tags->toArray());
    }

    public function addTag(Tag $tag): void
    {
        if ($this->tags->contains($tag)) {
            return;
        }

        $this->tags->add($tag);
        $this->touch();
    }

    public function removeTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            return;
        }

        $this->tags->removeElement($tag);
        $this->touch();
    }

    public function hasTag(Tag $tag): bool
    {
        return $this->tags->contains($tag);
    }

    public function getSlotValue(FieldType $type, int $slot): string|float|\DateTimeImmutable|bool|null
    {
        $this->validateSlot($slot);

        return match ($type->value()) {
            'text' => match ($slot) {
                1 => $this->text1,
                2 => $this->text2,
                3 => $this->text3,
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            'number' => match ($slot) {
                1 => $this->num1,
                2 => $this->num2,
                3 => $this->num3,
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            'date' => match ($slot) {
                1 => $this->date1,
                2 => $this->date2,
                3 => $this->date3,
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            'bool' => match ($slot) {
                1 => $this->bool1,
                2 => $this->bool2,
                3 => $this->bool3,
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            default => throw new \InvalidArgumentException(\sprintf('Unknown field type "%s"', $type->value())),
        };
    }

    public function setSlotValue(FieldType $type, int $slot, string|float|int|\DateTimeImmutable|bool|null $value): void
    {
        $previous = $this->getSlotValue($type, $slot);

        match ($type->value()) {
            'text' => match ($slot) {
                1 => $this->text1 = $this->assertStringValue($value, $type, $slot),
                2 => $this->text2 = $this->assertStringValue($value, $type, $slot),
                3 => $this->text3 = $this->assertStringValue($value, $type, $slot),
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            'number' => match ($slot) {
                1 => $this->num1 = $this->assertNumeric($value, $type, $slot),
                2 => $this->num2 = $this->assertNumeric($value, $type, $slot),
                3 => $this->num3 = $this->assertNumeric($value, $type, $slot),
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            'date' => match ($slot) {
                1 => $this->date1 = $this->assertDateValue($value, $type, $slot),
                2 => $this->date2 = $this->assertDateValue($value, $type, $slot),
                3 => $this->date3 = $this->assertDateValue($value, $type, $slot),
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            'bool' => match ($slot) {
                1 => $this->bool1 = $this->assertBoolValue($value, $type, $slot),
                2 => $this->bool2 = $this->assertBoolValue($value, $type, $slot),
                3 => $this->bool3 = $this->assertBoolValue($value, $type, $slot),
                default => throw new \InvalidArgumentException('Slot index must be between 1 and '.SlotLimits::MAX_SLOTS_PER_TYPE),
            },
            default => throw new \InvalidArgumentException(\sprintf('Unknown field type "%s"', $type->value())),
        };

        if ($previous !== $this->getSlotValue($type, $slot)) {
            $this->touch();
        }
    }

    private function validateSlot(int $slot): void
    {
        if ($slot < 1 || $slot > SlotLimits::MAX_SLOTS_PER_TYPE) {
            throw new \InvalidArgumentException(\sprintf(
                'Slot index must be between 1 and %d, got %d',
                SlotLimits::MAX_SLOTS_PER_TYPE,
                $slot,
            ));
        }
    }

    private function assertStringValue(
        string|float|int|\DateTimeImmutable|bool|null $value,
        FieldType $type,
        int $slot,
    ): ?string {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(\sprintf(
                'Slot %d of type "%s" expects string, got %s',
                $slot,
                $type->value(),
                \get_debug_type($value),
            ));
        }

        if (\mb_strlen($value) > self::MAX_TEXT_SLOT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'Slot %d of type "%s" exceeds max length %d, got %d chars',
                $slot,
                $type->value(),
                self::MAX_TEXT_SLOT_LENGTH,
                \mb_strlen($value),
            ));
        }

        return $value;
    }

    private function assertDateValue(
        string|float|int|\DateTimeImmutable|bool|null $value,
        FieldType $type,
        int $slot,
    ): ?\DateTimeImmutable {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(\sprintf(
                'Slot %d of type "%s" expects DateTimeImmutable, got %s',
                $slot,
                $type->value(),
                \get_debug_type($value),
            ));
        }

        return $value;
    }

    private function assertBoolValue(
        string|float|int|\DateTimeImmutable|bool|null $value,
        FieldType $type,
        int $slot,
    ): ?bool {
        if (null === $value) {
            return null;
        }

        if (!\is_bool($value)) {
            throw new \InvalidArgumentException(\sprintf(
                'Slot %d of type "%s" expects bool, got %s',
                $slot,
                $type->value(),
                \get_debug_type($value),
            ));
        }

        return $value;
    }

    private function assertNumeric(
        string|float|int|\DateTimeImmutable|bool|null $value,
        FieldType $type,
        int $slot,
    ): ?float {
        if (null === $value) {
            return null;
        }

        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Slot %d of type "%s" expects numeric value, got %s',
            $slot,
            $type->value(),
            \get_debug_type($value),
        ));
    }

    /**
     * Sanitization is the domain source of truth: control chars stripped, trim,
     * whitespace collapsed, then validated (non-empty, <=100 UTF-8, whitelist).
     * Assert\* attributes on $name serve the Symfony Validator component only —
     * duplicates accepted deliberately (plain-string field, not a VO).
     */
    private function normalizeName(string $name): string
    {
        $name = \preg_replace('/\p{Cc}+/u', '', $name) ?? '';
        $name = \trim($name);
        $name = \preg_replace('/\s+/u', ' ', $name) ?? '';

        if ('' === $name) {
            throw new \InvalidArgumentException('Item name must not be empty');
        }

        if (\mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Item name cannot exceed %d characters', self::MAX_NAME_LENGTH));
        }

        if (1 !== \preg_match(self::ALLOWED_PATTERN, $name)) {
            throw new \InvalidArgumentException('Item name contains invalid characters. Allowed: letters, digits, space, underscore, hyphen, dot, forward slash');
        }

        return $name;
    }
}
