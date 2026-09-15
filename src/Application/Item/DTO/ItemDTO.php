<?php

declare(strict_types=1);

namespace App\Application\Item\DTO;

use App\Application\Common\DTO\ArrayableInterface;
use App\Application\Tag\DTO\TagDTO;
use App\Domain\Collection\ValueObject\FieldType;
use App\Domain\Common\Constant\SlotLimits;
use App\Domain\Item\Entity\Item;

final readonly class ItemDTO implements ArrayableInterface
{
    /**
     * @param array<int, array{type: string, slot: int, value: mixed}> $slots
     * @param array<int, TagDTO>                                       $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $collectionId,
        public array $slots,
        public array $tags,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(Item $item): self
    {
        return new self(
            id: $item->getId()->toString(),
            name: $item->getName(),
            collectionId: $item->getCollection()->getId()->toString(),
            slots: self::slotsOf($item),
            tags: \array_map(TagDTO::fromEntity(...), $item->getTags()),
            createdAt: $item->getCreatedAt(),
            updatedAt: $item->getUpdatedAt(),
        );
    }

    /**
     * @return array<int, array{type: string, slot: int, value: mixed}>
     */
    private static function slotsOf(Item $item): array
    {
        $slots = [];
        foreach (FieldType::values() as $type) {
            $fieldType = FieldType::fromString($type);

            for ($slot = 1; $slot <= SlotLimits::MAX_SLOTS_PER_TYPE; ++$slot) {
                $value = $item->getSlotValue($fieldType, $slot);
                if (null !== $value) {
                    $slots[] = [
                        'type' => $type,
                        'slot' => $slot,
                        'value' => self::serializeValue($value),
                    ];
                }
            }
        }

        return $slots;
    }

    private static function serializeValue(string|float|\DateTimeImmutable|bool $value): string|float|bool
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'collection_id' => $this->collectionId,
            'slots' => $this->slots,
            'tags' => \array_map(
                static fn (TagDTO $tag): array => $tag->toArray(),
                $this->tags,
            ),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
