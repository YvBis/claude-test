<?php

declare(strict_types=1);

namespace App\Tests\Application\User\Service;

use App\Application\User\Service\UserService;
use App\Domain\User\Entity\User;
use App\Domain\User\Repository\UserRepositoryInterface;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use App\Domain\User\ValueObject\Role;
use PHPUnit\Framework\TestCase;

final class UserServiceTest extends TestCase
{
    public function testListUsersDelegatesWithPagination(): void
    {
        $users = [
            User::register('Ada', Email::fromString('ada@example.com'), PasswordHash::createFromPlain('secret123'), Role::admin()),
        ];

        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository
            ->expects($this->once())
            ->method('findAll')
            ->with(10, 5)
            ->willReturn($users);

        self::assertSame($users, (new UserService($repository))->listUsers(10, 5));
    }
}
