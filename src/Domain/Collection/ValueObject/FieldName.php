<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class FieldName
{
    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 50;

    /**
     * Allowed characters: Unicode letters, digits, space, underscore, hyphen, dot, forward slash.
     * Matches typical field label patterns (e.g., "Pages", "ISBN-10", "Author/Full").
     */
    private const string ALLOWED_PATTERN = '/^[\p{L}\p{N} _\-\.\/]+$/u';

    #[ORM\Column(name: 'field_name', type: 'string', length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH, charset: 'UTF-8')]
    #[Assert\Regex(pattern: self::ALLOWED_PATTERN)]
    private string $value;

    private function __construct(string $value)
    {
        $value = \trim($value);

        if (\mb_strlen($value, 'UTF-8') < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Field name must be at least %d characters', self::MIN_LENGTH));
        }

        if (\mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Field name cannot exceed %d characters', self::MAX_LENGTH));
        }

        if (1 !== \preg_match(self::ALLOWED_PATTERN, $value)) {
            throw new \InvalidArgumentException('Field name contains invalid characters. Allowed: letters, digits, space, underscore, hyphen, dot, forward slash');
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
