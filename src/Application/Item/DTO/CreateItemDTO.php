<?php

declare(strict_types=1);

namespace App\Application\Item\DTO;

use App\Domain\Common\Constant\SlotLimits;
use App\Domain\Item\Entity\Item;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateItemDTO
{
    #[Assert\NotBlank(message: 'Item name cannot be empty')]
    #[Assert\Length(max: Item::MAX_NAME_LENGTH, maxMessage: 'Item name cannot exceed {{ limit }} characters')]
    public string $name;

    /** @var array<string> */
    #[Assert\Count(max: Item::MAX_TAGS_PER_ITEM, maxMessage: 'Too many tags: at most {{ limit }} allowed')]
    #[Assert\All(constraints: [new Assert\Type('string')])]
    public array $tags;

    /** @var array<int, ItemSlotDTO> */
    #[Assert\Count(max: SlotLimits::MAX_SLOTS_PER_ITEM, maxMessage: 'Too many slots: at most {{ limit }} allowed')]
    #[Assert\Valid]
    public array $slots;

    /**
     * @param array<string>           $tags
     * @param array<int, ItemSlotDTO> $slots
     */
    public function __construct(
        string $name,
        array $tags = [],
        array $slots = [],
    ) {
        $this->name = \trim($name);
        $this->tags = \array_values($tags);
        $this->slots = \array_values($slots);
    }
}
