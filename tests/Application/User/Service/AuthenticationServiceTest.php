<?php

declare(strict_types=1);

namespace App\Tests\Application\User\Service;

use App\Application\User\DTO\LoginResult;
use App\Application\User\DTO\LoginUserDTO;
use App\Application\User\Service\AuthenticationService;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\InvalidCredentialsException;
use App\Domain\User\Exception\UserDeactivatedException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use App\Domain\User\ValueObject\UserId;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\TestCase;

final class AuthenticationServiceTest extends TestCase
{
    private UserRepositoryInterface $userRepository;
    private JWTTokenManagerInterface $jwtManager;
    private AuthenticationService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->jwtManager = $this->createMock(JWTTokenManagerInterface::class);
        $this->service = new AuthenticationService($this->userRepository, $this->jwtManager);
    }

    public function testAuthenticateReturnsLoginResult(): void
    {
        $dto = new LoginUserDTO('john@example.com', 'securePassword123');

        $user = $this->createUser('John Doe', 'john@example.com', 'securePassword123');

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->with($this->callback(static function (Email $email): bool {
                return 'john@example.com' === $email->value();
            }))
            ->willReturn($user);

        $this->jwtManager
            ->expects($this->once())
            ->method('create')
            ->with($user)
            ->willReturn('mocked.jwt.token');

        $result = $this->service->authenticate($dto);

        $this->assertInstanceOf(LoginResult::class, $result);
        $this->assertSame('Bearer', $result->tokenType);
        $this->assertSame(3600, $result->expiresIn);
        $this->assertSame($user, $result->user);
    }

    public function testAuthenticateThrowsWhenUserNotFound(): void
    {
        $dto = new LoginUserDTO('notfound@example.com', 'securePassword123');

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);

        $this->expectException(InvalidCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials for email: notfound@example.com');

        $this->service->authenticate($dto);
    }

    public function testAuthenticateThrowsWhenUserDeactivated(): void
    {
        $dto = new LoginUserDTO('john@example.com', 'securePassword123');

        $user = $this->createUser('John Doe', 'john@example.com', 'securePassword123');
        $user->deactivate();

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->willReturn($user);

        $this->expectException(UserDeactivatedException::class);
        $this->expectExceptionMessageMatches('/User .* is deactivated/');

        $this->service->authenticate($dto);
    }

    public function testAuthenticateThrowsWhenPasswordInvalid(): void
    {
        $dto = new LoginUserDTO('john@example.com', 'wrongPassword123');

        $user = $this->createUser('John Doe', 'john@example.com', 'securePassword123');

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->willReturn($user);

        $this->expectException(InvalidCredentialsException::class);
        $this->expectExceptionMessage('Invalid credentials for email: john@example.com');

        $this->service->authenticate($dto);
    }

    public function testAuthenticateTrimsAndLowercasesEmail(): void
    {
        $dto = new LoginUserDTO('  JOHN@EXAMPLE.COM  ', 'securePassword123');

        $user = $this->createUser('John Doe', 'john@example.com', 'securePassword123');

        $this->userRepository
            ->expects($this->once())
            ->method('findByEmail')
            ->with($this->callback(static function (Email $email): bool {
                return 'john@example.com' === $email->value();
            }))
            ->willReturn($user);

        $this->jwtManager
            ->method('create')
            ->willReturn('mocked.jwt.token');

        $this->service->authenticate($dto);
    }

    private function createUser(string $name, string $email, string $password): User
    {
        $emailVo = Email::fromString($email);
        $passwordHash = PasswordHash::createFromPlain($password);
        $role = Role::user();

        return new User(
            id: UserId::generate()->toBytes(),
            name: $name,
            email: $emailVo,
            passwordHash: $passwordHash,
            role: $role,
            isActive: true
        );
    }
}
