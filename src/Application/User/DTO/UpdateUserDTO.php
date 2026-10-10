<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

final readonly class UpdateUserDTO
{
    public function __construct(
        public ?bool $isActive = null,
    ) {
    }

    public function hasChanges(): bool
    {
        return null !== $this->isActive;
    }
}
