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

    public function testFromStringThrowsOnMultibyteNameShorterThanMinInCodePoints(): void
    {
        // 'аб' is 4 UTF-8 bytes but only 2 code points. Measuring bytes would
        // accept it while the DTO's Assert\Length(min: 3) rejects — the same
        // bytes-vs-code-points class as review-17, on the min side.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least');

        CollectionName::fromString('аб');
    }

    public function testFromStringAcceptsMultibyteNameAtMinCodePoints(): void
    {
        $name = CollectionName::fromString('абв');

        $this->assertSame('абв', $name->value());
    }

    public function testFromStringAcceptsMultibyteNameWithinContractualLimit(): void
    {
        // The reachable defect: 60 Cyrillic letters are 60 code points (the DTO
        // and the spec accept them) but 120 UTF-8 bytes, so a byte-based check
        // rejected a name the contract promised to accept.
        $name = \str_repeat('б', 60);

        $this->assertSame($name, CollectionName::fromString($name)->value());
    }

    public function testFromStringAcceptsAstralNameAtMaxCodePoints(): void
    {
        // 100 astral code points = 400 UTF-8 bytes: bytes and code points differ
        // by the widest factor this check can see.
        $name = \str_repeat("\u{1F600}", CollectionName::MAX_NAME_LENGTH);

        $this->assertSame($name, CollectionName::fromString($name)->value());
    }

    public function testFromStringRejectsZwjSequenceOverMaxCodePoints(): void
    {
        // 21 ZWJ family sequences are 21 grapheme clusters but 105 code points.
        // Counting graphemes would accept this; the contract counts code points.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed');

        CollectionName::fromString(\str_repeat("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 21));
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
