<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Doctrine\Type;

use App\Domain\Collection\ValueObject\FieldTypeEnum;
use App\Infrastructure\Doctrine\Type\FieldTypeEnumType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use PHPUnit\Framework\TestCase;

final class FieldTypeEnumTypeTest extends TestCase
{
    private FieldTypeEnumType $type;
    private MySQLPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new FieldTypeEnumType();
        $this->platform = new MySQLPlatform();
    }

    public function testGetNameReturnsExpected(): void
    {
        $this->assertSame('field_type_enum', $this->type->getName());
    }

    public function testConvertToDatabaseValueText(): void
    {
        $value = FieldTypeEnum::TEXT;
        $result = $this->type->convertToDatabaseValue($value, $this->platform);

        $this->assertSame('text', $result);
    }

    public function testConvertToDatabaseValueNumber(): void
    {
        $value = FieldTypeEnum::NUMBER;
        $result = $this->type->convertToDatabaseValue($value, $this->platform);

        $this->assertSame('number', $result);
    }

    public function testConvertToDatabaseValueDate(): void
    {
        $value = FieldTypeEnum::DATE;
        $result = $this->type->convertToDatabaseValue($value, $this->platform);

        $this->assertSame('date', $result);
    }

    public function testConvertToDatabaseValueBool(): void
    {
        $value = FieldTypeEnum::BOOL;
        $result = $this->type->convertToDatabaseValue($value, $this->platform);

        $this->assertSame('bool', $result);
    }

    public function testConvertToDatabaseValueNull(): void
    {
        $result = $this->type->convertToDatabaseValue(null, $this->platform);

        $this->assertNull($result);
    }

    public function testConvertToPHPValueText(): void
    {
        $value = 'text';
        $result = $this->type->convertToPHPValue($value, $this->platform);

        $this->assertInstanceOf(FieldTypeEnum::class, $result);
        $this->assertEquals(FieldTypeEnum::TEXT, $result);
    }

    public function testConvertToPHPValueNumber(): void
    {
        $value = 'number';
        $result = $this->type->convertToPHPValue($value, $this->platform);

        $this->assertEquals(FieldTypeEnum::NUMBER, $result);
    }

    public function testConvertToPHPValueDate(): void
    {
        $value = 'date';
        $result = $this->type->convertToPHPValue($value, $this->platform);

        $this->assertEquals(FieldTypeEnum::DATE, $result);
    }

    public function testConvertToPHPValueBool(): void
    {
        $value = 'bool';
        $result = $this->type->convertToPHPValue($value, $this->platform);

        $this->assertEquals(FieldTypeEnum::BOOL, $result);
    }

    public function testConvertToPHPValueNullOrEmpty(): void
    {
        $this->assertNull($this->type->convertToPHPValue(null, $this->platform));
        $this->assertNull($this->type->convertToPHPValue('', $this->platform));
    }

    public function testRequiresSQLCommentHint(): void
    {
        $this->assertTrue($this->type->requiresSQLCommentHint($this->platform));
    }

    public function testRoundTripText(): void
    {
        $phpValue = FieldTypeEnum::TEXT;
        $dbValue = $this->type->convertToDatabaseValue($phpValue, $this->platform);
        $restored = $this->type->convertToPHPValue($dbValue, $this->platform);

        $this->assertEquals($phpValue, $restored);
    }

    public function testRoundTripAllValues(): void
    {
        foreach ([
            FieldTypeEnum::TEXT,
            FieldTypeEnum::NUMBER,
            FieldTypeEnum::DATE,
            FieldTypeEnum::BOOL,
        ] as $enumValue) {
            $dbValue = $this->type->convertToDatabaseValue($enumValue, $this->platform);
            $restored = $this->type->convertToPHPValue($dbValue, $this->platform);

            $this->assertEquals($enumValue, $restored);
        }
    }
}
