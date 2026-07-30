<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class CollectionName
{
    /** Minimum number of UTF-8 bytes permitted in a collection display name. Trims input, then enforces. */
    private const int MIN_NAME_LENGTH = 3;

    /** Maximum number of UTF-8 bytes permitted in a collection display name. */
    private const int MAX_NAME_LENGTH = 100;

    #[ORM\Column(name: 'name', type: 'string', length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 3, max: 100)]
    private string $value;

    private function __construct(string $value)
    {
        $value = \trim($value);

        if (\strlen($value) < self::MIN_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Collection name must be at least %d characters', self::MIN_NAME_LENGTH));
        }

        if (\strlen($value) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Collection name cannot exceed %d characters', self::MAX_NAME_LENGTH));
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
