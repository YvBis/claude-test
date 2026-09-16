<?php

declare(strict_types=1);

namespace App\Tests\Domain\Comment\ValueObject;

use App\Domain\Comment\ValueObject\CommentContent;
use PHPUnit\Framework\TestCase;

final class CommentContentTest extends TestCase
{
    public function testFromStringAcceptsPlainText(): void
    {
        $this->assertSame('Hello world', CommentContent::fromString('Hello world')->value());
    }

    public function testFromStringTrimsSurroundingWhitespace(): void
    {
        $this->assertSame('Hello', CommentContent::fromString("  \n Hello \t \n")->value());
    }

    public function testFromStringPreservesInternalSpaces(): void
    {
        $this->assertSame('a    b', CommentContent::fromString('a    b')->value());
    }

    public function testFromStringPreservesNewlinesAndTabs(): void
    {
        $value = "line 1\n\tindented\nline 3";

        $this->assertSame($value, CommentContent::fromString($value)->value());
    }

    public function testFromStringNormalisesCrLfAndCrToLf(): void
    {
        $this->assertSame("a\nb\nc", CommentContent::fromString("a\r\nb\rc")->value());
    }

    public function testFromStringStripsControlCharactersButKeepsNewlineAndTab(): void
    {
        $this->assertSame("ok\n\tkept", CommentContent::fromString("o\x00k\x07\n\tk\x1Fept")->value());
    }

    public function testFromStringPreservesMarkdownCharacters(): void
    {
        $markdown = "# Title\n\n- item **bold** `code` [link](https://x.y) > quote";

        $this->assertSame($markdown, CommentContent::fromString($markdown)->value());
    }

    public function testFromStringAcceptsExactlyMaxLength(): void
    {
        $value = \str_repeat('a', CommentContent::MAX_LENGTH);

        $this->assertSame(3000, \mb_strlen(CommentContent::fromString($value)->value(), 'UTF-8'));
    }

    public function testFromStringCountsMultibyteCharacters(): void
    {
        $value = \str_repeat('я', CommentContent::MAX_LENGTH);

        $this->assertSame($value, CommentContent::fromString($value)->value());
    }

    public function testFromStringThrowsOnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        CommentContent::fromString('   ');
    }

    public function testFromStringThrowsOnInvalidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid UTF-8');

        CommentContent::fromString("\xC3\x28");
    }

    public function testFromStringThrowsWhenLongerThanMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 3000 characters');

        CommentContent::fromString(\str_repeat('a', CommentContent::MAX_LENGTH + 1));
    }

    public function testEqualsComparesValue(): void
    {
        $a = CommentContent::fromString('same');
        $b = CommentContent::fromString('same');
        $c = CommentContent::fromString('other');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testCastToStringReturnsValue(): void
    {
        $content = CommentContent::fromString('some text');

        $this->assertSame('some text', (string) $content);
    }
}
