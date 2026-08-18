<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Domain\Collection\ValueObject\FieldTypeEnum;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class FieldTypeEnumType extends StringType
{
    public const string NAME = 'field_type_enum';

    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function convertToPHPValue($value, AbstractPlatform $platform): ?FieldTypeEnum
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return FieldTypeEnum::from(\strtolower($value));
    }

    #[\Override]
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof FieldTypeEnum ? $value->value : $value;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
