<?php

declare(strict_types=1);

namespace App\Application\Item\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateItemDTO
{
    #[Assert\Length(max: 100, maxMessage: 'Item name cannot exceed {{ limit }} characters')]
    public ?string $name;

    /** @var array<string>|null */
    #[Assert\All(constraints: [new Assert\Type('string')])]
    public ?array $tags;

    /** @var array<int, ItemSlotDTO>|null */
    #[Assert\Valid]
    public ?array $slots;

    /**
     * @param array<string>           $tags
     * @param array<int, ItemSlotDTO> $slots
     */
    public function __construct(
        ?string $name = null,
        ?array $tags = null,
        ?array $slots = null,
    ) {
        $this->name = (null !== $name && '' !== \trim($name)) ? \trim($name) : null;
        $this->tags = $tags;
        $this->slots = $slots;
    }

    public function hasChanges(): bool
    {
        return null !== $this->name || null !== $this->tags || null !== $this->slots;
    }
}
