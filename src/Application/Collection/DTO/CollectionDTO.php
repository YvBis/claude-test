<?php

declare(strict_types=1);

namespace App\Application\Collection\DTO;

use App\Application\Common\DTO\ArrayableInterface;
use App\Domain\Collection\ValueObject\CollectionId;
use App\Domain\User\ValueObject\UserId;

final readonly class CollectionDTO implements ArrayableInterface
{
    public function __construct(
        public CollectionId $id,
        public string $name,
        public string $theme,
        public ?string $description,
        public ?string $image,
        public UserId $ownerId,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(\App\Domain\Collection\Entity\Collection $collection): self
    {
        return new self(
            id: $collection->getId(),
            name: $collection->getName()->value(),
            theme: $collection->getTheme()->value(),
            description: $collection->getDescription(),
            image: $collection->getImage(),
            ownerId: $collection->getOwner()->getId(),
            createdAt: $collection->getCreatedAt(),
            updatedAt: $collection->getUpdatedAt(),
        );
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'name' => $this->name,
            'theme' => $this->theme,
            'description' => $this->description,
            'image' => $this->image,
            'owner_id' => $this->ownerId->toString(),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
