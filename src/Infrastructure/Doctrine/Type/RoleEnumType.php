<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Domain\User\ValueObject\RoleEnum;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

final class RoleEnumType extends StringType
{
    public const string NAME = 'role_enum';

    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function convertToPHPValue($value, AbstractPlatform $platform): ?RoleEnum
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return RoleEnum::from(\strtolower($value));
    }

    #[\Override]
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        return $value instanceof RoleEnum ? $value->value : $value;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
