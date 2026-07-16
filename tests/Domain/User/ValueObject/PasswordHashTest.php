<?php

declare(strict_types=1);

namespace App\Tests\Domain\User\ValueObject;

use App\Domain\User\ValueObject\PasswordHash;
use PHPUnit\Framework\TestCase;

final class PasswordHashTest extends TestCase
{
    public function testCreateFromPlainCreatesValidBcryptHash(): void
    {
        $hash = PasswordHash::createFromPlain('password123');

        $this->assertInstanceOf(PasswordHash::class, $hash);
        $this->assertMatchesRegularExpression('/^\$2[aby]\$\d{2}\$/', $hash->value());
        $this->assertEquals(60, \strlen($hash->value()));
    }

    public function testCreateFromPlainThrowsOnShortPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('8 characters');

        PasswordHash::createFromPlain('short');
    }

    public function testCreateFromPlainThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordHash::createFromPlain('');
    }

    public function testFromHashAcceptsValidBcryptHash(): void
    {
        $hash = PasswordHash::createFromPlain('password123');
        $restored = PasswordHash::fromHash($hash->value());

        $this->assertEquals($hash->value(), $restored->value());
    }

    public function testFromHashThrowsOnInvalidFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid password hash format');

        PasswordHash::fromHash('invalid-hash');
    }

    public function testFromHashThrowsOnMd5(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordHash::fromHash(\md5('password'));
    }

    public function testVerifyReturnsTrueForCorrectPassword(): void
    {
        $plain = 'mySecurePassword123';
        $hash = PasswordHash::createFromPlain($plain);

        $this->assertTrue($hash->verify($plain));
    }

    public function testVerifyReturnsFalseForWrongPassword(): void
    {
        $hash = PasswordHash::createFromPlain('correctPassword123');

        $this->assertFalse($hash->verify('wrongPassword123'));
    }

    public function testVerifyWorksWithFromHash(): void
    {
        $plain = 'testPassword123';
        $hash = PasswordHash::createFromPlain($plain);
        $restored = PasswordHash::fromHash($hash->value());

        $this->assertTrue($restored->verify($plain));
    }

    public function testNeedsRehashReturnsFalseForCurrentCost(): void
    {
        $hash = PasswordHash::createFromPlain('password123');

        $this->assertFalse($hash->needsRehash());
    }

    public function testValueReturnsHash(): void
    {
        $hash = PasswordHash::createFromPlain('password123');

        $this->assertMatchesRegularExpression('/^\$2[aby]\$\d{2}\$/', $hash->value());
    }

    public function testEqualsReturnsTrueForSameHash(): void
    {
        $plain = 'samePassword123';
        $hash1 = PasswordHash::createFromPlain($plain);
        $hash2 = PasswordHash::createFromPlain($plain);

        // Different hashes because bcrypt uses salt, but equal() uses constant-time comparison
        // We need to use the same hash object or fromHash with same value to test equals()
        $restored = PasswordHash::fromHash($hash1->value());
        $this->assertTrue($hash1->equals($restored));
    }

    public function testEqualsReturnsFalseForDifferentHash(): void
    {
        $hash1 = PasswordHash::createFromPlain('password123');
        $hash2 = PasswordHash::createFromPlain('differentPassword123');

        $this->assertFalse($hash1->equals($hash2));
    }

    public function testCastToStringHidesHash(): void
    {
        $hash = PasswordHash::createFromPlain('password123');

        $this->assertEquals('***', (string) $hash);
    }
}
