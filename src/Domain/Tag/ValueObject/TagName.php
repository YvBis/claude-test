<?php

declare(strict_types=1);

namespace App\Domain\Tag\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class TagName
{
    /**
     * Tag names are stored with the casing first entered (case is preserved).
     * Case-insensitive uniqueness and lookup are enforced by the database
     * column collation (utf8mb4_0900_ai_ci), not by this value object: equals()
     * is exact, while "Books" and "books" collide on the UNIQUE index. The
     * first-seen casing is the one persisted; the Tag service (Task 4.3)
     * resolves reuse through TagRepositoryInterface::findByName().
     */
    public const int MIN_LENGTH = 2;

    public const int MAX_LENGTH = 30;

    /**
     * Allowed characters: Unicode letters, digits, space, underscore, hyphen, dot, forward slash.
     */
    private const string ALLOWED_PATTERN = '/^[\p{L}\p{N} _\-\.\/]+$/u';

    #[ORM\Column(name: 'name', type: 'string', length: 30)]
    #[Assert\NotBlank]
    #[Assert\Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH, charset: 'UTF-8')]
    #[Assert\Regex(pattern: self::ALLOWED_PATTERN)]
    private string $value;

    private function __construct(string $value)
    {
        $value = \preg_replace('/\p{Cc}+/u', '', $value) ?? '';
        $value = \trim($value);
        $value = \preg_replace('/\s+/u', ' ', $value) ?? '';

        if (\mb_strlen($value, 'UTF-8') < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Tag name must be at least %d characters', self::MIN_LENGTH));
        }

        if (\mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Tag name cannot exceed %d characters', self::MAX_LENGTH));
        }

        if (1 !== \preg_match(self::ALLOWED_PATTERN, $value)) {
            throw new \InvalidArgumentException('Tag name contains invalid characters. Allowed: letters, digits, space, underscore, hyphen, dot, forward slash');
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
