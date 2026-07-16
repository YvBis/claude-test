<?php

declare(strict_types=1);

namespace App\Tests\Domain\User\ValueObject;

use App\Domain\User\ValueObject\Email;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    public function testFromStringCreatesValidEmail(): void
    {
        $email = Email::fromString('Test@Example.COM');

        $this->assertEquals('test@example.com', $email->value());
    }

    public function testFromStringNormalizesCase(): void
    {
        $email = Email::fromString('USER@DOMAIN.COM');

        $this->assertEquals('user@domain.com', $email->value());
    }

    public function testFromStringTrimsWhitespace(): void
    {
        $email = Email::fromString('  user@example.com  ');

        $this->assertEquals('user@example.com', $email->value());
    }

    public function testFromStringThrowsOnInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email');

        Email::fromString('not-an-email');
    }

    public function testFromStringThrowsOnMissingAt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Email::fromString('userexample.com');
    }

    public function testFromStringThrowsOnMissingDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Email::fromString('user@');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Email::fromString('');
    }

    public function testValueReturnsEmail(): void
    {
        $email = Email::fromString('test@example.com');

        $this->assertEquals('test@example.com', $email->value());
    }

    public function testEqualsReturnsTrueForSameEmail(): void
    {
        $email1 = Email::fromString('test@example.com');
        $email2 = Email::fromString('test@example.com');

        $this->assertTrue($email1->equals($email2));
    }

    public function testEqualsReturnsFalseForDifferentEmail(): void
    {
        $email1 = Email::fromString('test1@example.com');
        $email2 = Email::fromString('test2@example.com');

        $this->assertFalse($email1->equals($email2));
    }

    public function testCastToStringWorks(): void
    {
        $email = Email::fromString('test@example.com');

        $this->assertEquals('test@example.com', (string) $email);
    }
}
