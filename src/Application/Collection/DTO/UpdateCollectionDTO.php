<?php

declare(strict_types=1);

namespace App\Application\Collection\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateCollectionDTO
{
    #[Assert\Length(
        min: 3,
        max: 100,
        minMessage: 'Collection name must be at least {{ limit }} characters',
        maxMessage: 'Collection name cannot exceed {{ limit }} characters',
    )]
    public ?string $name;

    #[Assert\Length(max: 500)]
    public ?string $description;

    #[Assert\Length(max: 500)]
    public ?string $image;

    public function __construct(
        ?string $name = null,
        ?string $description = null,
        ?string $image = null,
    ) {
        if (null !== $name) {
            $name = \trim($name);
        }

        $this->name = ('' === $name) ? null : $name;
        if (null !== $description) {
            $description = \trim($description);
        }

        $this->description = ('' === $description) ? null : $description;
        if (null !== $image) {
            $image = \trim($image);
        }

        $this->image = ('' === $image) ? null : $image;
    }

    public function hasChanges(): bool
    {
        return null !== $this->name || null !== $this->description || null !== $this->image;
    }
}
