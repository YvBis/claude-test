<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;

final class UserFactory
{
    public static function create(
        string $name,
        string $email,
        string $password,
        ?Role $role = null,
        bool $isActive = true
    ): User {
        return new User(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain($password),
            role: $role ?? Role::user(),
            isActive: $isActive
        );
    }

    public static function createWithId(
        UserId $id,
        string $name,
        string $email,
        string $password,
        ?Role $role = null,
        bool $isActive = true
    ): User {
        return new User(
            id: $id->toBytes(),
            name: $name,
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain($password),
            role: $role ?? Role::user(),
            isActive: $isActive
        );
    }
}
