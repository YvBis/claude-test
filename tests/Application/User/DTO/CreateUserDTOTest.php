<?php

declare(strict_types=1);

namespace App\Tests\Application\User\DTO;

use App\Application\User\DTO\CreateUserDTO;
use PHPUnit\Framework\TestCase;

final class CreateUserDTOTest extends TestCase
{
    public function testNormalisesNameEmailAndRole(): void
    {
        $dto = new CreateUserDTO(
            name: '  John Doe  ',
            email: '  John.Doe@Example.COM ',
            password: 'securePassword123',
            role: '  ADMIN  ',
        );

        self::assertSame('John Doe', $dto->name);
        self::assertSame('john.doe@example.com', $dto->email);
        self::assertSame('admin', $dto->role);
    }

    public function testPasswordIsKeptVerbatim(): void
    {
        $dto = new CreateUserDTO(name: 'John Doe', email: 'john@example.com', password: ' pass with spaces ');

        self::assertSame(' pass with spaces ', $dto->password);
    }

    public function testRoleDefaultsToNull(): void
    {
        $dto = new CreateUserDTO(name: 'John Doe', email: 'john@example.com', password: 'securePassword123');

        self::assertNull($dto->role);
    }
}
