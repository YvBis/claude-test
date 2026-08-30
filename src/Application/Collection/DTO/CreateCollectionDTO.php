<?php

declare(strict_types=1);

namespace App\Application\Collection\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateCollectionDTO
{
    #[Assert\NotBlank(message: 'Collection name cannot be empty')]
    #[Assert\Length(
        min: 3,
        max: 100,
        minMessage: 'Collection name must be at least {{ limit }} characters',
        maxMessage: 'Collection name cannot exceed {{ limit }} characters',
    )]
    public string $name;

    #[Assert\NotBlank(message: 'Theme cannot be empty')]
    #[Assert\Choice(
        choices: ['books', 'games', 'movies', 'drinks'],
        message: 'Invalid theme: {{ value }}. Allowed: books, games, movies, drinks',
    )]
    public string $theme;

    #[Assert\Length(max: 500)]
    public ?string $description;

    #[Assert\Length(max: 500)]
    public ?string $image;

    public function __construct(
        string $name,
        string $theme,
        ?string $description = null,
        ?string $image = null,
    ) {
        $this->name = \trim($name);
        $this->theme = \strtolower(\trim($theme));
        $this->description = null !== $description && '' !== $description ? \trim($description) : null;
        $this->image = null !== $image && '' !== $image ? \trim($image) : null;
    }
}
