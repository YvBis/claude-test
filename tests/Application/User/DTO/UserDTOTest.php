<?php

declare(strict_types=1);

namespace App\Tests\Application\User\DTO;

use App\Application\User\DTO\UserDTO;
use App\Domain\User\Entity\User;
use App\Domain\User\ValueObject\Email;
use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class UserDTOTest extends TestCase
{
    public function testFromEntityCarriesEveryFieldExceptTheHash(): void
    {
        $dto = UserDTO::fromEntity(User::createAdmin(
            name: 'Ada Admin',
            email: Email::fromString('ada@example.com'),
            passwordHash: PasswordHash::createFromPlain('secret123'),
        ));

        $array = $dto->toArray();

        self::assertSame('Ada Admin', $array['name']);
        self::assertSame('ada@example.com', $array['email']);
        self::assertSame('admin', $array['role']);
        self::assertTrue($array['is_active']);
        self::assertArrayHasKey('id', $array);
        self::assertArrayHasKey('created_at', $array);
        self::assertArrayHasKey('updated_at', $array);
        self::assertArrayNotHasKey('password_hash', $array);
        self::assertArrayNotHasKey('passwordHash', $array);
    }
}
