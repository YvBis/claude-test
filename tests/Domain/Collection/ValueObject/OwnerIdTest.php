<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\OwnerId;
use PHPUnit\Framework\TestCase;

final class OwnerIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = OwnerId::generate();

        $this->assertInstanceOf(OwnerId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = OwnerId::generate();
        $b = OwnerId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = OwnerId::generate();
        $string = $id->toString();

        $restored = OwnerId::fromString($string);

        $this->assertTrue($id->equals($restored));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        OwnerId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        OwnerId::fromBytes('short');
    }

    public function testToBytesReturns16Bytes(): void
    {
        $id = OwnerId::generate();

        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = OwnerId::generate();
        $b = OwnerId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(OwnerId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = OwnerId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
