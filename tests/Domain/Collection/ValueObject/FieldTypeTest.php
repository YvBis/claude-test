<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\FieldType;
use PHPUnit\Framework\TestCase;

final class FieldTypeTest extends TestCase
{
    public function testFactoryMethodsCreateExpectedTypes(): void
    {
        $this->assertTrue(FieldType::text()->isText());
        $this->assertTrue(FieldType::number()->isNumber());
        $this->assertTrue(FieldType::date()->isDate());
        $this->assertTrue(FieldType::bool()->isBool());
    }

    public function testIsChecksReturnFalseForOtherTypes(): void
    {
        $type = FieldType::text();

        $this->assertFalse($type->isNumber());
        $this->assertFalse($type->isDate());
        $this->assertFalse($type->isBool());
    }

    public function testFromStringAcceptsAllAllowedValues(): void
    {
        $this->assertTrue(FieldType::fromString('text')->isText());
        $this->assertTrue(FieldType::fromString('number')->isNumber());
        $this->assertTrue(FieldType::fromString('date')->isDate());
        $this->assertTrue(FieldType::fromString('bool')->isBool());
    }

    public function testFromStringCaseInsensitive(): void
    {
        $this->assertTrue(FieldType::fromString('TEXT')->isText());
        $this->assertTrue(FieldType::fromString('Number')->isNumber());
    }

    public function testFromStringThrowsOnInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid field type');

        FieldType::fromString('invalid');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FieldType::fromString('');
    }

    public function testValueReturnsString(): void
    {
        $this->assertSame('text', FieldType::text()->value());
        $this->assertSame('number', FieldType::number()->value());
        $this->assertSame('date', FieldType::date()->value());
        $this->assertSame('bool', FieldType::bool()->value());
    }

    public function testEqualsReturnsTrueForSameType(): void
    {
        $this->assertTrue(FieldType::text()->equals(FieldType::text()));
        $this->assertFalse(FieldType::text()->equals(FieldType::number()));
    }

    public function testValuesReturnsAllFour(): void
    {
        $values = FieldType::values();

        $this->assertSame(['text', 'number', 'date', 'bool'], $values);
        $this->assertCount(4, $values);
    }

    public function testCastToStringWorks(): void
    {
        $this->assertSame('date', (string) FieldType::date());
    }
}
