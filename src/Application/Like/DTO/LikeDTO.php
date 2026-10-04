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
        public ?string $ownerName,
        public string $itemId,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * fwd-5: `ownerName` is resolved by the caller through a batch lookup rather
     * than read off the entity, so it is nullable here. The `users(id)` foreign
     * key means it is always present in practice; nullable describes the code,
     * not an observed response.
     */
    public static function fromEntity(Like $like, ?string $ownerName): self
    {
        return new self(
            id: $like->getId()->toString(),
            ownerId: $like->getOwnerId()->toString(),
            ownerName: $ownerName,
            itemId: $like->getItem()->getId()->toString(),
            createdAt: $like->getCreatedAt(),
        );
    }

    /** @return array{id: string, owner_id: string, owner_name: ?string, item_id: string, created_at: string} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'owner_name' => $this->ownerName,
            'item_id' => $this->itemId,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
