<?php

declare(strict_types=1);

namespace App\Application\Like\DTO;

use App\Application\Common\DTO\ArrayableInterface;
use App\Domain\Like\Entity\Like;

final readonly class LikeDTO implements ArrayableInterface
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $itemId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public static function fromEntity(Like $like): self
    {
        return new self(
            id: $like->getId()->toString(),
            ownerId: $like->getOwner()->getId()->toString(),
            itemId: $like->getItem()->getId()->toString(),
            createdAt: $like->getCreatedAt(),
        );
    }

    /** @return array{id: string, owner_id: string, item_id: string, created_at: string} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'item_id' => $this->itemId,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
