<?php

declare(strict_types=1);

namespace App\Application\Comment\DTO;

use App\Application\Common\DTO\ArrayableInterface;
use App\Domain\Comment\Entity\Comment;

final readonly class CommentDTO implements ArrayableInterface
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $ownerName,
        public string $itemId,
        public string $content,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromEntity(Comment $comment): self
    {
        return new self(
            id: $comment->getId()->toString(),
            ownerId: $comment->getOwner()->getId()->toString(),
            ownerName: $comment->getOwner()->getName(),
            itemId: $comment->getItem()->getId()->toString(),
            content: $comment->getContent()->value(),
            createdAt: $comment->getCreatedAt(),
            updatedAt: $comment->getUpdatedAt(),
        );
    }

    /** @return array{id: string, owner_id: string, owner_name: string, item_id: string, content: string, created_at: string, updated_at: string} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner_id' => $this->ownerId,
            'owner_name' => $this->ownerName,
            'item_id' => $this->itemId,
            'content' => $this->content,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
