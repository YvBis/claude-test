<?php

declare(strict_types=1);

namespace App\Application\Tag\DTO;

use App\Application\Common\DTO\ArrayableInterface;
use App\Domain\Tag\Entity\Tag;

final readonly class TagDTO implements ArrayableInterface
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }

    public static function fromEntity(Tag $tag): self
    {
        return new self(
            id: $tag->getId()->toString(),
            name: $tag->getName()->value(),
        );
    }

    /** @return array{id: string, name: string} */
    #[\Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}
