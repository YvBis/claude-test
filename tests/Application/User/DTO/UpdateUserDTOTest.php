<?php

declare(strict_types=1);

namespace App\Tests\Application\User\DTO;

use App\Application\User\DTO\UpdateUserDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdateUserDTOTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?bool, 1: ?string, 2: bool}>
     */
    public static function changesProvider(): iterable
    {
        yield 'empty' => [null, null, false];
        yield 'only isActive' => [true, null, true];
        yield 'isActive false counts as a change' => [false, null, true];
        yield 'only role' => [null, 'admin', true];
        yield 'both' => [false, 'user', true];
    }

    #[DataProvider('changesProvider')]
    public function testHasChanges(?bool $isActive, ?string $role, bool $expected): void
    {
        $dto = new UpdateUserDTO($isActive, $role);

        self::assertSame($expected, $dto->hasChanges());
    }

    public function testNormalisesRole(): void
    {
        $dto = new UpdateUserDTO(role: '  ADMIN ');

        self::assertSame('admin', $dto->role);
    }

    public function testRoleDefaultsToNull(): void
    {
        $dto = new UpdateUserDTO();

        self::assertNull($dto->role);
        self::assertNull($dto->isActive);
    }
}
