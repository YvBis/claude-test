<?php

declare(strict_types=1);

namespace App\Domain\Collection\ValueObject;

use App\Infrastructure\Doctrine\Type\ThemeEnumType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
final readonly class Theme
{
    #[ORM\Column(name: 'theme', type: ThemeEnumType::NAME, length: 20)]
    #[Assert\Choice(choices: [
        ThemeEnum::BOOKS->value,
        ThemeEnum::GAMES->value,
        ThemeEnum::MOVIES->value,
        ThemeEnum::DRINKS->value,
    ])]
    private ThemeEnum $theme;

    private function __construct(ThemeEnum $theme)
    {
        $this->theme = $theme;
    }

    public static function books(): self
    {
        return new self(ThemeEnum::BOOKS);
    }

    public static function games(): self
    {
        return new self(ThemeEnum::GAMES);
    }

    public static function movies(): self
    {
        return new self(ThemeEnum::MOVIES);
    }

    public static function drinks(): self
    {
        return new self(ThemeEnum::DRINKS);
    }

    public static function fromString(string $theme): self
    {
        try {
            $enum = ThemeEnum::from(\strtolower($theme));
        } catch (\ValueError $valueError) {
            throw new \InvalidArgumentException(\sprintf('Invalid theme: %s. Allowed: books, games, movies, drinks', $theme), $valueError->getCode(), $valueError);
        }

        return new self($enum);
    }

    public function value(): string
    {
        return $this->theme->value;
    }

    public function isBooks(): bool
    {
        return ThemeEnum::BOOKS === $this->theme;
    }

    public function isGames(): bool
    {
        return ThemeEnum::GAMES === $this->theme;
    }

    public function isMovies(): bool
    {
        return ThemeEnum::MOVIES === $this->theme;
    }

    public function isDrinks(): bool
    {
        return ThemeEnum::DRINKS === $this->theme;
    }

    /** @return array<string> */
    public static function values(): array
    {
        return [
            ThemeEnum::BOOKS->value,
            ThemeEnum::GAMES->value,
            ThemeEnum::MOVIES->value,
            ThemeEnum::DRINKS->value,
        ];
    }

    public function equals(self $other): bool
    {
        return $this->theme === $other->theme;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->theme->value;
    }
}

enum ThemeEnum: string
{
    case BOOKS = 'books';
    case GAMES = 'games';
    case MOVIES = 'movies';
    case DRINKS = 'drinks';
}
