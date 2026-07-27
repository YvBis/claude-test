<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

use App\Domain\User\ValueObject\UserId;

final class UserDeactivatedException extends \DomainException
{
    public static function forUser(UserId $userId): self
    {
        return new self(\sprintf('User %s is deactivated', $userId->toString()));
    }
}
