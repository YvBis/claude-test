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
     * Validates the untrusted engine document: every scalar written by
     * ItemDocument is required, so absence or a wrong type means the index
     * drifted and the read must fail instead of serving filler values.
     *
     * @param array<string, mixed> $document
     */
    public static function fromArray(array $document): self
    {
        return new self(
            SearchDocumentFields::nonEmptyString($document, self::class, 'id'),
            SearchDocumentFields::nonEmptyString($document, self::class, 'name'),
            SearchDocumentFields::tags($document, self::class),
            SearchDocumentFields::nonEmptyString($document, self::class, 'collection_id'),
            SearchDocumentFields::nonEmptyString($document, self::class, 'collection_name'),
            SearchDocumentFields::nonEmptyString($document, self::class, 'owner_id'),
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
