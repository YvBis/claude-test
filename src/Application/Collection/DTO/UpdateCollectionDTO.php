<?php

declare(strict_types=1);

namespace App\Application\Collection\DTO;

use App\Domain\Collection\Entity\Collection;
use App\Domain\Collection\ValueObject\CollectionName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateCollectionDTO
{
    #[Assert\Length(
        min: CollectionName::MIN_NAME_LENGTH,
        max: CollectionName::MAX_NAME_LENGTH,
        minMessage: 'Collection name must be at least {{ limit }} characters',
        maxMessage: 'Collection name cannot exceed {{ limit }} characters',
    )]
    public ?string $name;

    #[Assert\Length(max: Collection::MAX_DESCRIPTION_LENGTH, maxMessage: 'Collection description cannot exceed {{ limit }} characters')]
    public ?string $description;

    #[Assert\Length(max: Collection::MAX_IMAGE_LENGTH, maxMessage: 'Collection image cannot exceed {{ limit }} characters')]
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
