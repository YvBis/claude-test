<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

use App\Domain\User\ValueObject\Role;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateUserDTO
{
    #[Assert\Choice(callback: [Role::class, 'values'], message: 'Invalid role: {{ value }}. Allowed: user, admin')]
    public ?string $role;

    public function __construct(
        public ?bool $isActive = null,
        ?string $role = null,
    ) {
        $this->role = null === $role ? null : \strtolower(\trim($role));
    }

    public function hasChanges(): bool
    {
        return null !== $this->isActive || null !== $this->role;
    }
}
