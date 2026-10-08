<?php

declare(strict_types=1);

namespace App\Application\Search;

/**
 * One item hit, exactly as the engine returned it. A named type instead of a
 * raw array so the HTTP layer depends on a contract, not on the document
 * shape — while staying hydration-free by design (6.6): no DB roundtrip, no
 * orphan policy forced early.
 */
final readonly class ItemSearchHit
{
    /**
     * @param array<string> $tags
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

    /**
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(
            (string) ($document['id'] ?? ''),
            (string) ($document['name'] ?? ''),
            \array_values(\array_map(strval(...), (array) ($document['tags'] ?? []))),
            (string) ($document['collection_id'] ?? ''),
            (string) ($document['collection_name'] ?? ''),
            (string) ($document['owner_id'] ?? ''),
        );
    }

    /**
     * @return array{id: string, name: string, tags: array<string>, collection_id: string, collection_name: string, owner_id: string}
     */
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
