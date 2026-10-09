<?php

declare(strict_types=1);

namespace App\Application\User\DTO;

use App\Application\Common\DTO\ArrayableInterface;
use App\Domain\User\Entity\User;

final readonly class UserDTO implements ArrayableInterface
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $role,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function fromEntity(User $user): self
    {
        return new self(
            id: $user->getId()->toString(),
            name: $user->getName(),
            email: $user->getEmail()->value(),
            role: $user->getRole()->value(),
            isActive: $user->isActive(),
            createdAt: $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /** @return array{id: string, name: string, email: string, role: string, is_active: bool, created_at: string, updated_at: string} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
