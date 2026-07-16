<?php

namespace App\Domain\User\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class Role
{
    public const string USER = 'user';

    public const string ADMIN = 'admin';

    #[ORM\Column(name: 'role', type: 'string', length: 20)]
    #[Assert\Choice(choices: [self::USER, self::ADMIN])]
    private string $role;

    private function __construct(string $role)
    {
        $this->role = \strtolower($role);
    }

    public static function user(): self
    {
        return new self(self::USER);
    }

    public static function admin(): self
    {
        return new self(self::ADMIN);
    }

    public static function fromString(string $role): self
    {
        $normalized = \strtolower($role);

        if (!\in_array($normalized, [self::USER, self::ADMIN], true)) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid role: %s. Allowed: user, admin', $role)
            );
        }

        return new self($normalized);
    }

    public function value(): string
    {
        return $this->role;
    }

    public function isUser(): bool
    {
        return self::USER === $this->role;
    }

    public function isAdmin(): bool
    {
        return self::ADMIN === $this->role;
    }

    /** @return array<string> */
    public static function values(): array
    {
        return [self::USER, self::ADMIN];
    }

    public function equals(self $other): bool
    {
        return $this->role === $other->role;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->role;
    }
}
