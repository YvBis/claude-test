<?php

declare(strict_types=1);

namespace App\Tests\Application\User\Service;

use App\Application\Common\Transaction\UnitOfWorkInterface;
use App\Application\DTO\RegisterUserDTO;
use App\Application\User\Service\RegistrationService;
use App\Domain\User\Entity\User;
use App\Domain\User\Exception\UserAlreadyExistsException;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class RegistrationServiceTest extends TestCase
{
    private UserRepositoryInterface $userRepository;
    private UnitOfWorkInterface $unitOfWork;
    private RegistrationService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepositoryInterface::class);
        $this->unitOfWork = $this->createMock(UnitOfWorkInterface::class);
        $this->service = new RegistrationService($this->userRepository, $this->unitOfWork);
    }

    public function testRegisterCreatesUserAndSaves(): void
    {
        $dto = new RegisterUserDTO('John Doe', 'john@example.com', 'securePassword123');

        $this->userRepository
            ->expects($this->once())
            ->method('existsByEmail')
            ->with($this->callback(static function (Email $email): bool {
                return 'john@example.com' === $email->value();
            }))
            ->willReturn(false);

        $this->userRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (User $user): bool {
                return 'John Doe' === $user->getName()
                    && 'john@example.com' === $user->getEmail()->value()
                    && 'user' === $user->getRole()->value()
                    && true === $user->isActive();
            }));

        $this->unitOfWork->expects($this->once())->method('flush');

        $user = $this->service->register($dto);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('John Doe', $user->getName());
        $this->assertSame('john@example.com', $user->getEmail()->value());
        $this->assertSame('user', $user->getRole()->value());
        $this->assertTrue($user->isActive());
        $this->assertInstanceOf(PasswordHash::class, $user->getPasswordHash());
        $this->assertTrue($user->getPasswordHash()->verify('securePassword123'));
    }

    public function testRegisterThrowsWhenEmailExists(): void
    {
        $dto = new RegisterUserDTO('John Doe', 'john@example.com', 'securePassword123');

        $this->userRepository
            ->expects($this->once())
            ->method('existsByEmail')
            ->willReturn(true);

        $this->expectException(UserAlreadyExistsException::class);
        $this->expectExceptionMessage('User with email "john@example.com" already exists');

        $this->unitOfWork->expects($this->never())->method('flush');

        $this->service->register($dto);
    }

    public function testRegisterTrimsNameAndLowercasesEmail(): void
    {
        $dto = new RegisterUserDTO('  John Doe  ', '  JOHN@EXAMPLE.COM  ', 'securePassword123');

        $this->userRepository
            ->method('existsByEmail')
            ->willReturn(false);

        $this->userRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (User $user): bool {
                return 'John Doe' === $user->getName()
                    && 'john@example.com' === $user->getEmail()->value();
            }));

        $this->unitOfWork->expects($this->once())->method('flush');

        $this->service->register($dto);
    }
}
