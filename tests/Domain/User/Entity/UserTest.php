<?php

declare(strict_types=1);

namespace App\Tests\Domain\User\Entity;

use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testRegisterCreatesUserWithDefaults(): void
    {
        $email = Email::fromString('user@example.com');
        $password = PasswordHash::createFromPlain('password123');

        $user = User::register('John Doe', $email, $password);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('John Doe', $user->getName());
        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals($password, $user->getPasswordHash());
        $this->assertTrue($user->isActive());
        $this->assertTrue($user->getRole()->isUser());
        $this->assertNotNull($user->getCreatedAt());
        $this->assertNotNull($user->getUpdatedAt());
        $this->assertLessThanOrEqual(
            new \DateTimeImmutable('+1 second'),
            $user->getCreatedAt()
        );
        $this->assertEquals(
            $user->getCreatedAt()->getTimestamp(),
            $user->getUpdatedAt()->getTimestamp()
        );
    }

    public function testRegisterWithCustomRole(): void
    {
        $email = Email::fromString('admin@example.com');
        $password = PasswordHash::createFromPlain('password123');
        $role = Role::admin();

        $user = User::register('Admin User', $email, $password, $role);

        $this->assertTrue($user->getRole()->isAdmin());
    }

    public function testCreateAdminCreatesAdminUser(): void
    {
        $email = Email::fromString('admin@example.com');
        $password = PasswordHash::createFromPlain('password123');

        $user = User::createAdmin('Admin User', $email, $password);

        $this->assertTrue($user->getRole()->isAdmin());
        $this->assertTrue($user->isActive());
    }

    public function testChangeNameUpdatesNameAndTimestamp(): void
    {
        $user = $this->createUser();
        $originalUpdatedAt = $user->getUpdatedAt();

        \usleep(1000); // Ensure time difference
        $user->changeName('Jane Doe');

        $this->assertEquals('Jane Doe', $user->getName());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }

    public function testChangeNameThrowsOnEmptyName(): void
    {
        $user = $this->createUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        $user->changeName('');
    }

    public function testChangeNameThrowsOnTooLongName(): void
    {
        $user = $this->createUser();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('100 characters');

        $user->changeName(\str_repeat('a', 101));
    }

    public function testChangeEmailUpdatesEmailAndTimestamp(): void
    {
        $user = $this->createUser();
        $originalUpdatedAt = $user->getUpdatedAt();
        $newEmail = Email::fromString('new@example.com');

        \usleep(1000);
        $user->changeEmail($newEmail);

        $this->assertEquals($newEmail, $user->getEmail());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }

    public function testChangePasswordUpdatesHashAndTimestamp(): void
    {
        $user = $this->createUser();
        $originalUpdatedAt = $user->getUpdatedAt();
        $newHash = PasswordHash::createFromPlain('newpassword123');

        \usleep(1000);
        $user->changePassword($newHash);

        $this->assertEquals($newHash, $user->getPasswordHash());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }

    public function testPromoteToAdminChangesRole(): void
    {
        $user = $this->createUser();
        $this->assertTrue($user->getRole()->isUser());

        \usleep(1000);
        $user->promoteToAdmin();

        $this->assertTrue($user->getRole()->isAdmin());
        $this->assertGreaterThan($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testPromoteToAdminThrowsIfAlreadyAdmin(): void
    {
        $user = User::createAdmin(
            'Admin',
            Email::fromString('admin@example.com'),
            PasswordHash::createFromPlain('password123')
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already admin');

        $user->promoteToAdmin();
    }

    public function testDemoteToUserChangesRole(): void
    {
        $user = User::createAdmin(
            'Admin',
            Email::fromString('admin@example.com'),
            PasswordHash::createFromPlain('password123')
        );
        $this->assertTrue($user->getRole()->isAdmin());

        \usleep(1000);
        $user->demoteToUser();

        $this->assertTrue($user->getRole()->isUser());
        $this->assertGreaterThan($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testDemoteToUserThrowsIfAlreadyUser(): void
    {
        $user = $this->createUser();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already regular user');

        $user->demoteToUser();
    }

    public function testActivateSetsIsActiveTrue(): void
    {
        $user = $this->createUser();
        $user->deactivate();
        $this->assertFalse($user->isActive());

        \usleep(1000);
        $user->activate();

        $this->assertTrue($user->isActive());
        $this->assertGreaterThan($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testDeactivateSetsIsActiveFalse(): void
    {
        $user = $this->createUser();
        $this->assertTrue($user->isActive());

        \usleep(1000);
        $user->deactivate();

        $this->assertFalse($user->isActive());
        $this->assertGreaterThan($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testVerifyPasswordReturnsTrueForCorrectPassword(): void
    {
        $plainPassword = 'mySecurePassword123';
        $passwordHash = PasswordHash::createFromPlain($plainPassword);
        $user = User::register('User', Email::fromString('user@example.com'), $passwordHash);

        $this->assertTrue($user->verifyPassword($plainPassword));
    }

    public function testVerifyPasswordReturnsFalseForWrongPassword(): void
    {
        $user = $this->createUser('correctPassword123');

        $this->assertFalse($user->verifyPassword('wrongPassword123'));
    }

    public function testGettersReturnCorrectValues(): void
    {
        $user = $this->createUser();

        $this->assertInstanceOf(\App\Domain\User\ValueObject\UserId::class, $user->getId());
        $this->assertIsString($user->getName());
        $this->assertInstanceOf(Email::class, $user->getEmail());
        $this->assertInstanceOf(PasswordHash::class, $user->getPasswordHash());
        $this->assertInstanceOf(Role::class, $user->getRole());
        $this->assertIsBool($user->isActive());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getUpdatedAt());
    }

    private function createUser(string $password = 'password123'): User
    {
        return User::register(
            'Test User',
            Email::fromString('test@example.com'),
            PasswordHash::createFromPlain($password)
        );
    }
}
