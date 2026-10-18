<?php

declare(strict_types=1);

namespace App\Application\Collection\DTO;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use App\Domain\Collection\ValueObject\Theme;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateCollectionDTO
{
    #[Assert\NotBlank(message: 'Collection name cannot be empty')]
    #[Assert\Length(
        min: CollectionName::MIN_NAME_LENGTH,
        max: CollectionName::MAX_NAME_LENGTH,
        minMessage: 'Collection name must be at least {{ limit }} characters',
        maxMessage: 'Collection name cannot exceed {{ limit }} characters',
    )]
    public string $name;

    #[Assert\NotBlank(message: 'Theme cannot be empty')]
    #[Assert\Choice(
        callback: [Theme::class, 'values'],
        message: 'Invalid theme: {{ value }}. Allowed: books, games, movies, drinks',
    )]
    public string $theme;

    #[Assert\Length(max: Collection::MAX_DESCRIPTION_LENGTH, maxMessage: 'Collection description cannot exceed {{ limit }} characters')]
    public ?string $description;

    #[Assert\Length(max: Collection::MAX_IMAGE_LENGTH, maxMessage: 'Collection image cannot exceed {{ limit }} characters')]
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
