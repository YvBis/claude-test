<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObject;

use App\Infrastructure\Doctrine\Type\RoleEnumType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class Role
{
    #[ORM\Column(name: 'role', type: RoleEnumType::NAME, length: 20)]
    #[Assert\Choice(choices: [RoleEnum::USER->value, RoleEnum::ADMIN->value])]
    private RoleEnum $role;

    private function __construct(RoleEnum $role)
    {
        $this->role = $role;
    }

    public static function user(): self
    {
        return new self(RoleEnum::USER);
    }

    public static function admin(): self
    {
        return new self(RoleEnum::ADMIN);
    }

    public static function fromString(string $role): self
    {
        try {
            $enum = RoleEnum::from(\strtolower($role));
        } catch (\ValueError $valueError) {
            throw new \InvalidArgumentException(\sprintf('Invalid role: %s. Allowed: user, admin', $role), $valueError->getCode(), $valueError);
        }

        return new self($enum);
    }

    public function value(): string
    {
        return $this->role->value;
    }

    public function isUser(): bool
    {
        return RoleEnum::USER === $this->role;
    }

    public function isAdmin(): bool
    {
        return RoleEnum::ADMIN === $this->role;
    }

    /** @return array<string> */
    public static function values(): array
    {
        return [RoleEnum::USER->value, RoleEnum::ADMIN->value];
    }

    public function equals(self $other): bool
    {
        return $this->role === $other->role;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->role->value;
    }
}

enum RoleEnum: string
{
    case USER = 'user';
    case ADMIN = 'admin';
}
