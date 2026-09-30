<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use App\Infrastructure\Security\UserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Pins fwd-31: a deactivated or deleted account must not be handed back to
 * the authenticator, so an already issued token stops working.
 *
 * Both methods are covered on purpose. The `api` firewall is stateless, so
 * Lexik calls `loadUserByIdentifier` on every request and never
 * `refreshUser` — guarding only the latter would guard nothing in production.
 */
final class UserProviderTest extends TestCase
{
    public function testLoadByIdentifierReturnsAnActiveUser(): void
    {
        $user = $this->user();

        self::assertSame($user, $this->providerWith($user)->loadUserByIdentifier('john@example.com'));
    }

    public function testLoadByIdentifierRejectsADeactivatedUser(): void
    {
        $user = $this->user();
        $user->deactivate();

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User not found');

        $this->providerWith($user)->loadUserByIdentifier('john@example.com');
    }

    public function testLoadByIdentifierRejectsAMissingUser(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User not found');

        $this->providerWith(null)->loadUserByIdentifier('john@example.com');
    }

    public function testLoadByIdentifierAcceptsAReactivatedUser(): void
    {
        // The symmetric boundary of the guard: `activate()` must open the door
        // again, so the check cannot rot into a one-way latch.
        $user = $this->user();
        $user->deactivate();
        $user->activate();

        self::assertSame($user, $this->providerWith($user)->loadUserByIdentifier('john@example.com'));
    }

    public function testRefreshReturnsAnActiveUser(): void
    {
        $user = $this->user();

        self::assertSame($user, $this->providerWith($user)->refreshUser($user));
    }

    public function testRefreshRejectsADeactivatedUser(): void
    {
        // A *different* instance than the argument, so the test fails if
        // `refreshUser` ever stops re-reading the repository and simply hands
        // its own argument back.
        $stored = $this->user();
        $stored->deactivate();

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User not found');

        $this->providerWith($stored)->refreshUser($this->user('john@example.com'));
    }

    public function testRefreshRejectsADeletedUser(): void
    {
        $user = $this->user();

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('User not found');

        $this->providerWith(null)->refreshUser($user);
    }

    public function testRefreshRejectsAForeignUserClass(): void
    {
        $foreign = new InMemoryUser('john', null);

        self::assertInstanceOf(UserInterface::class, $foreign);

        $this->expectException(UnsupportedUserException::class);

        $this->providerWith(null)->refreshUser($foreign);
    }

    public function testSupportsItsOwnClass(): void
    {
        self::assertTrue($this->providerWith(null)->supportsClass(User::class));
    }

    public function testDoesNotSupportAForeignClass(): void
    {
        self::assertFalse($this->providerWith(null)->supportsClass(InMemoryUser::class));
    }

    private function user(string $email = 'john@example.com'): User
    {
        return new User(
            id: UserId::generate()->toBytes(),
            name: 'John Doe',
            email: Email::fromString($email),
            passwordHash: PasswordHash::createFromPlain('securePassword123'),
            role: Role::user(),
            isActive: true,
        );
    }

    private function providerWith(?User $found): UserProvider
    {
        $repository = $this->createStub(UserRepositoryInterface::class);
        $repository->method('findByEmail')->willReturn($found);

        return new UserProvider($repository);
    }
}
