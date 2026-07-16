<?php

declare(strict_types=1);

namespace App\Tests\Domain\User\ValueObject;

use App\Domain\User\ValueObject\UserId;
use PHPUnit\Framework\TestCase;

final class UserIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $userId = UserId::generate();

        $this->assertInstanceOf(UserId::class, $userId);
        $this->assertTrue(\Ramsey\Uuid\Uuid::isValid((string) $userId));
    }

    public function testGenerateProducesUniqueIds(): void
    {
        $id1 = UserId::generate();
        $id2 = UserId::generate();

        $this->assertNotEquals($id1, $id2);
    }

    public function testFromStringCreatesValidId(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $userId = UserId::fromString($uuid);

        $this->assertEquals($uuid, (string) $userId);
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        UserId::fromString('not-a-uuid');
    }

    public function testFromBytesCreatesValidId(): void
    {
        $bytes = \random_bytes(16);
        $userId = UserId::fromBytes($bytes);

        $this->assertEquals($bytes, $userId->toBytes());
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        UserId::fromBytes(\random_bytes(8));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id1 = UserId::fromString($uuid);
        $id2 = UserId::fromString($uuid);

        $this->assertTrue($id1->equals($id2));
    }

    public function testEqualsReturnsFalseForDifferentId(): void
    {
        $id1 = UserId::fromString('550e8400-e29b-41d4-a716-446655440000');
        $id2 = UserId::fromString('550e8400-e29b-41d4-a716-446655440001');

        $this->assertFalse($id1->equals($id2));
    }

    public function testToStringReturnsUuidString(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $userId = UserId::fromString($uuid);

        $this->assertEquals($uuid, $userId->toString());
    }

    public function testToBytesReturnsRawBytes(): void
    {
        $userId = UserId::generate();
        $bytes = $userId->toBytes();

        $this->assertIsString($bytes);
        $this->assertEquals(16, \strlen($bytes));
    }

    public function testCastToStringWorks(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $userId = UserId::fromString($uuid);

        $this->assertEquals($uuid, (string) $userId);
    }
}
