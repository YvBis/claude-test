<?php

declare(strict_types=1);

namespace App\Tests\Domain\Collection\ValueObject;

use App\Domain\Collection\ValueObject\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTest extends TestCase
{
    public function testFactoryMethodsCreateExpectedThemes(): void
    {
        $this->assertTrue(Theme::books()->isBooks());
        $this->assertTrue(Theme::games()->isGames());
        $this->assertTrue(Theme::movies()->isMovies());
        $this->assertTrue(Theme::drinks()->isDrinks());
    }

    public function testIsChecksReturnFalseForOtherThemes(): void
    {
        $theme = Theme::books();

        $this->assertFalse($theme->isGames());
        $this->assertFalse($theme->isMovies());
        $this->assertFalse($theme->isDrinks());
    }

    public function testFromStringAcceptsAllAllowedValues(): void
    {
        $this->assertTrue(Theme::fromString('books')->isBooks());
        $this->assertTrue(Theme::fromString('games')->isGames());
        $this->assertTrue(Theme::fromString('movies')->isMovies());
        $this->assertTrue(Theme::fromString('drinks')->isDrinks());
    }

    public function testFromStringCaseInsensitive(): void
    {
        $this->assertTrue(Theme::fromString('BOOKS')->isBooks());
        $this->assertTrue(Theme::fromString('Games')->isGames());
    }

    public function testFromStringThrowsOnInvalidTheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid theme');

        Theme::fromString('cars');
    }

    public function testFromStringThrowsOnEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Theme::fromString('');
    }

    public function testValueReturnsString(): void
    {
        $this->assertSame('books', Theme::books()->value());
        $this->assertSame('games', Theme::games()->value());
        $this->assertSame('movies', Theme::movies()->value());
        $this->assertSame('drinks', Theme::drinks()->value());
    }

    public function testEqualsReturnsTrueForSameTheme(): void
    {
        $this->assertTrue(Theme::books()->equals(Theme::books()));
        $this->assertFalse(Theme::books()->equals(Theme::games()));
    }

    public function testValuesReturnsAllFour(): void
    {
        $values = Theme::values();

        $this->assertSame(['books', 'games', 'movies', 'drinks'], $values);
        $this->assertCount(4, $values);
    }

    public function testCastToStringWorks(): void
    {
        $this->assertSame('movies', (string) Theme::movies());
    }
}
