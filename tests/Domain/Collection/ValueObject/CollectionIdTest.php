<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\CollectionId;
use PHPUnit\Framework\TestCase;

final class CollectionIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = CollectionId::generate();

        $this->assertInstanceOf(CollectionId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = CollectionId::generate();
        $b = CollectionId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = CollectionId::generate();
        $string = $id->toString();

        $restored = CollectionId::fromString($string);

        $this->assertTrue($id->equals($restored));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        CollectionId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        CollectionId::fromBytes('short');
    }

    public function testToBytesReturns16Bytes(): void
    {
        $id = CollectionId::generate();

        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = CollectionId::generate();
        $b = CollectionId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(CollectionId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = CollectionId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
