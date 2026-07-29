<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Domain\Collection\ValueObject\ThemeEnum;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class ThemeEnumType extends StringType
{
    public const string NAME = 'theme_enum';

    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function convertToPHPValue($value, AbstractPlatform $platform): ?ThemeEnum
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return ThemeEnum::from(\strtolower($value));
    }

    #[\Override]
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof ThemeEnum ? $value->value : $value;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
