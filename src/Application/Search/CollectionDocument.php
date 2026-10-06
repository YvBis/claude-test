<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Collection\Entity\Collection;

/**
 * Search document for the `collections` index.
 *
 * `description` is a free-text field, so null becomes an empty string rather
 * than being omitted: Meilisearch treats a missing attribute and an empty one
 * the same way at query time, but a stable document shape is easier to assert
 * on and to reindex.
 */
final readonly class CollectionDocument
{
    public function __construct(
        public string $id,
        public string $name,
        public string $theme,
        public string $description,
        public string $ownerId,
    ) {
    }

    public static function fromEntity(Collection $collection): self
    {
        return new self(
            id: $collection->getId()->toString(),
            name: $collection->getName()->value(),
            theme: $collection->getTheme()->value(),
            description: $collection->getDescription() ?? '',
            ownerId: $collection->getOwnerId()->toString(),
        );
    }

    /** @return array{id: string, name: string, theme: string, description: string, owner_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'theme' => $this->theme,
            'description' => $this->description,
            'owner_id' => $this->ownerId,
        ];
    }
}
