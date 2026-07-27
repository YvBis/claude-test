<?php

declare(strict_types=1);

namespace App\Domain\User\Exception;

final class InvalidCredentialsException extends \DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(\sprintf('Invalid credentials for email: %s', $email));
    }
}
