<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\CollectionName;
use PHPUnit\Framework\TestCase;

final class CollectionNameTest extends TestCase
{
    public function testFromStringCreatesName(): void
    {
        $name = CollectionName::fromString('My Books');

        $this->assertSame('My Books', $name->value());
    }

    public function testFromStringTrimsWhitespace(): void
    {
        $name = CollectionName::fromString('  My Books  ');

        $this->assertSame('My Books', $name->value());
    }

    public function testFromStringThrowsOnTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least');

        CollectionName::fromString('ab');
    }

    public function testFromStringThrowsOnTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        CollectionName::fromString(\str_repeat('a', 101));
    }

    public function testFromStringThrowsOnOnlyWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CollectionName::fromString('   ');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CollectionName::fromString('');
    }

    public function testFromStringAcceptsExactMinLength(): void
    {
        $name = CollectionName::fromString('abc');

        $this->assertSame('abc', $name->value());
    }

    public function testFromStringAcceptsExactMaxLength(): void
    {
        $max = \str_repeat('a', 100);

        $name = CollectionName::fromString($max);

        $this->assertSame($max, $name->value());
    }

    public function testEqualsReturnsTrueForSameName(): void
    {
        $a = CollectionName::fromString('Books');
        $b = CollectionName::fromString('Books');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentName(): void
    {
        $a = CollectionName::fromString('Books');
        $b = CollectionName::fromString('Games');

        $this->assertFalse($a->equals($b));
    }

    public function testCastToStringWorks(): void
    {
        $name = CollectionName::fromString('Books');

        $this->assertSame('Books', (string) $name);
    }
}
