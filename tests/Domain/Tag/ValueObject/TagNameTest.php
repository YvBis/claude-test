<?php

declare(strict_types=1);

namespace App\Tests\Domain\Tag\ValueObject;

use App\Domain\Tag\ValueObject\TagName;
use PHPUnit\Framework\TestCase;

final class TagNameTest extends TestCase
{
    public function testFromStringCreatesValidName(): void
    {
        $name = TagName::fromString('Books');

        $this->assertSame('Books', $name->value());
    }

    public function testFromStringPreservesCase(): void
    {
        $name = TagName::fromString('Books');

        $this->assertSame('Books', $name->value());
    }

    public function testFromStringTrimsWhitespace(): void
    {
        $name = TagName::fromString('  Books  ');

        $this->assertSame('Books', $name->value());
    }

    public function testFromStringCollapsesInternalWhitespace(): void
    {
        $name = TagName::fromString('Sci   Fi');

        $this->assertSame('Sci Fi', $name->value());
    }

    public function testFromStringStripsControlCharacters(): void
    {
        $name = TagName::fromString("Detective\nNovel");

        $this->assertSame('DetectiveNovel', $name->value());
    }

    public function testFromStringAcceptsUnicode(): void
    {
        $name = TagName::fromString('Фантастика');

        $this->assertSame('Фантастика', $name->value());
    }

    public function testFromStringAcceptsHyphen(): void
    {
        $name = TagName::fromString('sci-fi');

        $this->assertSame('sci-fi', $name->value());
    }

    public function testFromStringAcceptsSlash(): void
    {
        $name = TagName::fromString('fiction/sci');

        $this->assertSame('fiction/sci', $name->value());
    }

    public function testFromStringAcceptsExactMinLength(): void
    {
        $name = TagName::fromString('ab');

        $this->assertSame('ab', $name->value());
    }

    public function testFromStringThrowsOnTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least');

        TagName::fromString('a');
    }

    public function testFromStringAcceptsExactMaxLength(): void
    {
        $max = \str_repeat('a', 30);

        $name = TagName::fromString($max);

        $this->assertSame($max, $name->value());
    }

    public function testFromStringThrowsOnTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        TagName::fromString(\str_repeat('a', 31));
    }

    public function testFromStringThrowsOnOnlyWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TagName::fromString('   ');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TagName::fromString('');
    }

    public function testFromStringThrowsOnInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        TagName::fromString('tag!name');
    }

    public function testFromStringThrowsOnAtSymbol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        TagName::fromString('tag@name');
    }

    public function testEqualsReturnsTrueForSameName(): void
    {
        $a = TagName::fromString('Books');
        $b = TagName::fromString('Books');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentName(): void
    {
        $a = TagName::fromString('Books');
        $b = TagName::fromString('Games');

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsIsCaseSensitiveAtDomainLevel(): void
    {
        // Case-insensitive uniqueness is enforced by the DB collation (utf8mb4_0900_ai_ci),
        // not by the value object: "Books" and "books" are different TagName values.
        $a = TagName::fromString('Books');
        $b = TagName::fromString('books');

        $this->assertFalse($a->equals($b));
    }

    public function testCastToStringWorks(): void
    {
        $name = TagName::fromString('Books');

        $this->assertSame('Books', (string) $name);
    }
}
