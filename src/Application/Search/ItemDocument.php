<?php

declare(strict_types=1);

namespace App\Application\Search;

use App\Domain\Item\Entity\Item;
use App\Domain\Tag\Entity\Tag;

/**
 * Search document for the `items` index.
 *
 * `collectionName` and `ownerId` are denormalized copies, not references:
 * Meilisearch has no joins, so an item document must carry everything a query
 * can match or filter on. `collectionName` is why renaming a collection forces
 * a bulk reindex of its items (6.5). `ownerId` is immutable — a collection has
 * no changeOwner — so it never triggers a reindex on its own.
 *
 * Slots are deliberately absent: no requirement searches or filters them
 * (artifacts/prd-taskflow-ru.md:27,38,54 — the searchable fields are item name,
 * tags and collection name). Revisit only if such a requirement appears.
 */
final readonly class ItemDocument
{
    /**
     * @param array<int, string> $tags
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $tags,
        public string $collectionId,
        public string $collectionName,
        public string $ownerId,
    ) {
    }

    public static function fromEntity(Item $item): self
    {
        return new self(
            id: $item->getId()->toString(),
            name: $item->getName(),
            tags: \array_map(
                static fn (Tag $tag): string => $tag->getName()->value(),
                $item->getTags(),
            ),
            collectionId: $item->getCollection()->getId()->toString(),
            collectionName: $item->getCollection()->getName()->value(),
            ownerId: $item->getCollection()->getOwnerId()->toString(),
        );
    }

    /** @return array{id: string, name: string, tags: array<int, string>, collection_id: string, collection_name: string, owner_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tags' => $this->tags,
            'collection_id' => $this->collectionId,
            'collection_name' => $this->collectionName,
            'owner_id' => $this->ownerId,
        ];
    }
}
