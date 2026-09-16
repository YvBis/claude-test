<?php

declare(strict_types=1);

namespace App\Domain\Comment\ValueObject;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class CommentContent
{
    public const int MIN_LENGTH = 1;

    public const int MAX_LENGTH = 3000;

    /**
     * Comment bodies are Markdown, so — unlike TagName/FieldName — internal
     * whitespace and line breaks are preserved verbatim (no collapsing):
     * only the surrounding whitespace is trimmed. Newlines are normalised to
     * "\n" and control characters other than "\n"/"\t" are stripped. Markup is
     * stored as-is; escaping/rendering is the frontend's responsibility.
     */
    #[ORM\Column(name: 'content', type: 'string', length: 3000)]
    #[Assert\NotBlank]
    #[Assert\Length(min: self::MIN_LENGTH, max: self::MAX_LENGTH, charset: 'UTF-8')]
    private string $value;

    private function __construct(string $value)
    {
        $value = \str_replace(["\r\n", "\r"], "\n", $value);

        $stripped = \preg_replace('/[^\P{Cc}\n\t]/u', '', $value);
        if (null === $stripped) {
            throw new \InvalidArgumentException('Comment content must be valid UTF-8');
        }

        $value = \trim($stripped);

        $length = \mb_strlen($value, 'UTF-8');

        if ($length < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('Comment content cannot be empty');
        }

        if ($length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('Comment content cannot exceed %d characters', self::MAX_LENGTH));
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
