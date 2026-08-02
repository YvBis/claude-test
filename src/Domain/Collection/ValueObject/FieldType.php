<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

use App\Infrastructure\Doctrine\Type\FieldTypeEnumType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class FieldType
{
    #[ORM\Column(name: 'field_type', type: FieldTypeEnumType::NAME, length: 20)]
    #[Assert\Choice(callback: [self::class, 'values'])]
    private FieldTypeEnum $type;

    private function __construct(FieldTypeEnum $type)
    {
        $this->type = $type;
    }

    public static function text(): self
    {
        return new self(FieldTypeEnum::TEXT);
    }

    public static function number(): self
    {
        return new self(FieldTypeEnum::NUMBER);
    }

    public static function date(): self
    {
        return new self(FieldTypeEnum::DATE);
    }

    public static function bool(): self
    {
        return new self(FieldTypeEnum::BOOL);
    }

    public static function fromString(string $type): self
    {
        try {
            $enum = FieldTypeEnum::from(\strtolower($type));
        } catch (\ValueError $valueError) {
            throw new \InvalidArgumentException(\sprintf('Invalid field type: %s. Allowed: text, number, date, bool', $type), $valueError->getCode(), $valueError);
        }

        return new self($enum);
    }

    public function value(): string
    {
        return $this->type->value;
    }

    public function isText(): bool
    {
        return FieldTypeEnum::TEXT === $this->type;
    }

    public function isNumber(): bool
    {
        return FieldTypeEnum::NUMBER === $this->type;
    }

    public function isDate(): bool
    {
        return FieldTypeEnum::DATE === $this->type;
    }

    public function isBool(): bool
    {
        return FieldTypeEnum::BOOL === $this->type;
    }

    /** @return array<string> */
    public static function values(): array
    {
        return \array_column(FieldTypeEnum::cases(), 'value');
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->type->value;
    }
}
