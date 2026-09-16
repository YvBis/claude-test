<?php

declare(strict_types=1);

namespace App\Tests\Domain\Comment\ValueObject;

use App\Domain\Comment\ValueObject\CommentId;
use PHPUnit\Framework\TestCase;

final class CommentIdTest extends TestCase
{
    public function testGenerateCreatesValidUuid(): void
    {
        $id = CommentId::generate();

        $this->assertInstanceOf(CommentId::class, $id);
        $this->assertSame(16, \strlen($id->toBytes()));
    }

    public function testGenerateCreatesUniqueIds(): void
    {
        $a = CommentId::generate();
        $b = CommentId::generate();

        $this->assertFalse($a->equals($b));
    }

    public function testFromStringAcceptsValidUuid(): void
    {
        $id = CommentId::generate();

        $this->assertTrue($id->equals(CommentId::fromString($id->toString())));
    }

    public function testFromStringThrowsOnInvalidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid UUID');

        CommentId::fromString('not-a-uuid');
    }

    public function testFromBytesThrowsOnWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('16 bytes');

        CommentId::fromBytes('short');
    }

    public function testToBytesReturns16Bytes(): void
    {
        $this->assertSame(16, \strlen(CommentId::generate()->toBytes()));
    }

    public function testEqualsReturnsTrueForSameId(): void
    {
        $a = CommentId::generate();
        $b = CommentId::fromString($a->toString());

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(CommentId::generate()));
    }

    public function testCastToStringWorks(): void
    {
        $id = CommentId::generate();

        $this->assertSame($id->toString(), (string) $id);
    }
}
