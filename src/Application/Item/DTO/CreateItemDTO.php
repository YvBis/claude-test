<?php

declare(strict_types=1);

namespace App\Application\Item\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateItemDTO
{
    #[Assert\NotBlank(message: 'Item name cannot be empty')]
    #[Assert\Length(max: 100, maxMessage: 'Item name cannot exceed {{ limit }} characters')]
    public string $name;

    /** @var array<string> */
    #[Assert\All(constraints: [new Assert\Type('string')])]
    public array $tags;

    /** @var array<int, ItemSlotDTO> */
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
