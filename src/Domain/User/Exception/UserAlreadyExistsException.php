<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

final class UserAlreadyExistsException extends \DomainException
{
    public static function withEmail(string $email, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('User with email "%s" already exists', $email), previous: $previous);
    }
}
