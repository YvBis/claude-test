<?php

declare(strict_types=1);

namespace App\Tests\Domain\Like\ValueObject;

use App\Domain\Like\ValueObject\LikeId;
use PHPUnit\Framework\TestCase;

final class LikeIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = LikeId::generate();

        $this->assertInstanceOf(LikeId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = LikeId::generate();
        $b = LikeId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = LikeId::generate();

        $this->assertTrue($id->equals(LikeId::fromString($id->toString())));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        LikeId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        LikeId::fromBytes('short');
    }

    public function testFromBytesRoundTrips(): void
    {
        $id = LikeId::generate();

        $this->assertSame($id->toBytes(), LikeId::fromBytes($id->toBytes())->toBytes());
    }

    public function testToBytesReturns16Bytes(): void
    {
        $this->assertSame(16, \strlen(LikeId::generate()->toBytes()));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = LikeId::generate();
        $b = LikeId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(LikeId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = LikeId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
