<?php

namespace App\Domain\User\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class Email
{
    #[ORM\Column(name: 'email', type: 'string', length: 255, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email(mode: 'html5')]
    #[Assert\Length(max: 255)]
    private string $email;

    private function __construct(string $email)
    {
        $this->email = \strtolower(\trim($email));
    }

    public static function fromString(string $email): self
    {
        $trimmed = \trim($email);

        if (!\filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(\sprintf('Invalid email: %s', $trimmed));
        }

        return new self($trimmed);
    }

    public function value(): string
    {
        return $this->email;
    }

    public function equals(self $other): bool
    {
        return $this->email === $other->email;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->email;
    }
}
