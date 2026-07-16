<?php

declare(strict_types=1);

namespace App\Tests\Domain\User\ValueObject;

use App\Domain\User\ValueObject\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testUserCreatesUserRole(): void
    {
        $role = Role::user();

        $this->assertEquals('user', $role->value());
        $this->assertTrue($role->isUser());
        $this->assertFalse($role->isAdmin());
    }

    public function testAdminCreatesAdminRole(): void
    {
        $role = Role::admin();

        $this->assertEquals('admin', $role->value());
        $this->assertTrue($role->isAdmin());
        $this->assertFalse($role->isUser());
    }

    public function testFromStringCreatesUserRole(): void
    {
        $role = Role::fromString('user');

        $this->assertTrue($role->isUser());
    }

    public function testFromStringCreatesAdminRole(): void
    {
        $role = Role::fromString('admin');

        $this->assertTrue($role->isAdmin());
    }

    public function testFromStringCaseInsensitive(): void
    {
        $role1 = Role::fromString('USER');
        $role2 = Role::fromString('Admin');

        $this->assertTrue($role1->isUser());
        $this->assertTrue($role2->isAdmin());
    }

    public function testFromStringThrowsOnInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid role');

        Role::fromString('guest');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Role::fromString('');
    }

    public function testValueReturnsRoleString(): void
    {
        $role = Role::user();

        $this->assertEquals('user', $role->value());
    }

    public function testEqualsReturnsTrueForSameRole(): void
    {
        $role1 = Role::user();
        $role2 = Role::user();

        $this->assertTrue($role1->equals($role2));
    }

    public function testEqualsReturnsFalseForDifferentRole(): void
    {
        $role1 = Role::user();
        $role2 = Role::admin();

        $this->assertFalse($role1->equals($role2));
    }

    public function testValuesReturnsAllowedRoles(): void
    {
        $values = Role::values();

        $this->assertContains('user', $values);
        $this->assertContains('admin', $values);
        $this->assertCount(2, $values);
    }

    public function testCastToStringWorks(): void
    {
        $role = Role::admin();

        $this->assertEquals('admin', (string) $role);
    }
}
