<?php

declare(strict_types=1);

namespace App\Application\Item\DTO;

use App\Domain\Item\Entity\Item;

final readonly class ItemDetailDTO
{
    /**
     * @param array<string, mixed> $item
     */
    private function __construct(
        private array $item,
        private int $likesCount,
        private int $commentsCount,
        private bool $likedByMe,
    ) {
    }

    public static function fromItem(Item $item, int $likesCount, int $commentsCount, bool $likedByMe): self
    {
        return new self(
            item: ItemDTO::fromEntity($item)->toArray(),
            likesCount: $likesCount,
            commentsCount: $commentsCount,
            likedByMe: $likedByMe,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->item + [
            'likes_count' => $this->likesCount,
            'comments_count' => $this->commentsCount,
            'liked_by_me' => $this->likedByMe,
        ];
    }
}
