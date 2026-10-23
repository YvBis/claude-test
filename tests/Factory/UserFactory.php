<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds a real `User` through its public constructor, so the entity's own
 * invariants run: the email is a validated `Email`, the password is a real
 * bcrypt hash, the role is a `Role` VO.
 *
 * Deliberately not using `User::register()`: it generates its own id, and
 * tests occasionally need to pin one (`createOne(['id' => ...])`).
 *
 * The default password is fixed and public on purpose - demo/test logins must
 * be predictable, and a random one would make every seeded account unusable.
 *
 * The hash is memoized for the process: `PasswordHash::createFromPlain()` always
 * uses the domain cost (13), not the framework hasher's test cost, so hashing
 * per user would add ~0.4 s to each creation. One hash for one fixed password is
 * not a security concern in tests and keeps a 50-user story fast.
 *
 * @extends PersistentObjectFactory<User>
 */
final class UserFactory extends PersistentObjectFactory
{
    public const string DEFAULT_PASSWORD = 'password123';

    private static ?PasswordHash $defaultPasswordHash = null;

    public static function class(): string
    {
        return User::class;
    }

    public function admin(): static
    {
        return $this->with(['role' => Role::admin()]);
    }

    public function inactive(): static
    {
        return $this->with(['isActive' => false]);
    }

    protected function defaults(): array
    {
        return [
            'id' => UserId::generate()->toBytes(),
            'name' => self::faker()->name(),
            'email' => Email::fromString(self::faker()->unique()->safeEmail()),
            'passwordHash' => self::$defaultPasswordHash ??= PasswordHash::createFromPlain(self::DEFAULT_PASSWORD),
            'role' => Role::user(),
            'isActive' => true,
        ];
    }
}
