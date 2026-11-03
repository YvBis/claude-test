<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\FieldName;
use PHPUnit\Framework\TestCase;

final class FieldNameTest extends TestCase
{
    public function testFromStringCreatesValidName(): void
    {
        $name = FieldName::fromString('Pages');

        $this->assertSame('Pages', $name->value());
    }

    public function testFromStringTrimsWhitespace(): void
    {
        $name = FieldName::fromString('  Pages  ');

        $this->assertSame('Pages', $name->value());
    }

    public function testFromStringAcceptsUnicode(): void
    {
        $name = FieldName::fromString('ISBN-10');

        $this->assertSame('ISBN-10', $name->value());
    }

    public function testFromStringAcceptsSlash(): void
    {
        $name = FieldName::fromString('Author/Full');

        $this->assertSame('Author/Full', $name->value());
    }

    public function testFromStringAcceptsDot(): void
    {
        $name = FieldName::fromString('Version 1.0');

        $this->assertSame('Version 1.0', $name->value());
    }

    public function testFromStringAcceptsUnderscore(): void
    {
        $name = FieldName::fromString('field_name');

        $this->assertSame('field_name', $name->value());
    }

    public function testFromStringAcceptsHyphen(): void
    {
        $name = FieldName::fromString('field-name');

        $this->assertSame('field-name', $name->value());
    }

    public function testFromStringThrowsOnTooShort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least');

        FieldName::fromString('a');
    }

    public function testFromStringThrowsOnTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        FieldName::fromString(\str_repeat('a', 51));
    }

    public function testFromStringThrowsOnOnlyWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FieldName::fromString('   ');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FieldName::fromString('');
    }

    public function testFromStringThrowsOnInvalidChars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        FieldName::fromString('field!name');
    }

    public function testFromStringThrowsOnAtSymbol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid characters');

        FieldName::fromString('field@name');
    }

    public function testFromStringAcceptsExactMinLength(): void
    {
        $name = FieldName::fromString('ab');

        $this->assertSame('ab', $name->value());
    }

    public function testFromStringAcceptsExactMaxLength(): void
    {
        $max = \str_repeat('a', 50);

        $name = FieldName::fromString($max);

        $this->assertSame($max, $name->value());
    }

    public function testFromStringAcceptsMaxCodePointsOfMultibyteText(): void
    {
        // 50 Cyrillic letters are 50 code points but 100 bytes: a byte-based
        // bound would reject a value inside the contract (review-19).
        $max = \str_repeat('б', 50);

        $name = FieldName::fromString($max);

        $this->assertSame($max, $name->value());
    }

    public function testFromStringRejectsSingleLetterBelowMinCodePoints(): void
    {
        // one Cyrillic letter is 1 code point but 2 bytes: a byte-based minimum would
        // accept what the contract rejects (review-19).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least');

        FieldName::fromString('б');
    }

    public function testMultibyteCharactersPassValidation(): void
    {
        // 8 Cyrillic chars = 24 bytes, should pass (min 2, max 50 chars)
        $name = FieldName::fromString('Название');
        $this->assertSame('Название', $name->value());
    }

    public function testEqualsReturnsTrueForSameName(): void
    {
        $a = FieldName::fromString('Pages');
        $b = FieldName::fromString('Pages');

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsReturnsFalseForDifferentName(): void
    {
        $a = FieldName::fromString('Pages');
        $b = FieldName::fromString('Author');

        $this->assertFalse($a->equals($b));
    }

    public function testCastToStringWorks(): void
    {
        $name = FieldName::fromString('Pages');

        $this->assertSame('Pages', (string) $name);
    }
}
