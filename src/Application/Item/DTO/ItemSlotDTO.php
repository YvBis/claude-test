<?php

declare(strict_types=1);

namespace App\Application\Item\DTO;

use App\Domain\Common\Constant\SlotLimits;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ItemSlotDTO
{
    #[Assert\NotBlank(message: 'Slot type cannot be empty')]
    #[Assert\Choice(
        choices: ['text', 'number', 'date', 'bool'],
        message: 'Invalid slot type: {{ value }}. Allowed: text, number, date, bool',
    )]
    public string $type;

    #[Assert\NotBlank]
    #[Assert\Range(
        min: 1,
        max: SlotLimits::MAX_SLOTS_PER_TYPE,
        notInRangeMessage: 'Slot index must be between {{ min }} and {{ max }}',
    )]
    public int $slot;

    public string|int|float|bool|null $value;

    public function __construct(
        string $type,
        int $slot,
        string|int|float|bool|null $value = null,
    ) {
        $this->type = \strtolower(\trim($type));
        $this->slot = $slot;
        $this->value = $value;
    }
}
