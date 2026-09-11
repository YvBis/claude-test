<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tag\ValueObject;

use App\Domain\Tag\ValueObject\TagId;
use PHPUnit\Framework\TestCase;

final class TagIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = TagId::generate();

        $this->assertInstanceOf(TagId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = TagId::generate();
        $b = TagId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = TagId::generate();

        $restored = TagId::fromString($id->toString());

        $this->assertTrue($id->equals($restored));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        TagId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        TagId::fromBytes('short');
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = TagId::generate();
        $b = TagId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(TagId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = TagId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
