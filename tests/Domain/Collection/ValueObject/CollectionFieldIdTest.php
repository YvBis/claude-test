<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\CollectionFieldId;
use PHPUnit\Framework\TestCase;

final class CollectionFieldIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = CollectionFieldId::generate();

        $this->assertInstanceOf(CollectionFieldId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = CollectionFieldId::generate();
        $b = CollectionFieldId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = CollectionFieldId::generate();
        $string = $id->toString();

        $restored = CollectionFieldId::fromString($string);

        $this->assertTrue($id->equals($restored));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        CollectionFieldId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        CollectionFieldId::fromBytes('short');
    }

    public function testToBytesReturns16Bytes(): void
    {
        $id = CollectionFieldId::generate();

        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = CollectionFieldId::generate();
        $b = CollectionFieldId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(CollectionFieldId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = CollectionFieldId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
