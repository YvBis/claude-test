<?php

declare(strict_types=1);

namespace App\Tests\Domain\Item\ValueObject;

use App\Domain\Item\ValueObject\ItemId;
use PHPUnit\Framework\TestCase;

final class ItemIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = ItemId::generate();

        $this->assertInstanceOf(ItemId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = ItemId::generate();
        $b = ItemId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = ItemId::generate();
        $string = $id->toString();

        $restored = ItemId::fromString($string);

        $this->assertTrue($id->equals($restored));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        ItemId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        ItemId::fromBytes('short');
    }

    public function testToBytesReturns16Bytes(): void
    {
        $id = ItemId::generate();

        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = ItemId::generate();
        $b = ItemId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(ItemId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = ItemId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
