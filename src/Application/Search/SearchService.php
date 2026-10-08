<?php

declare(strict_types=1);

namespace App\Application\Search;

/**
 * Read side entry point for the HTTP layer. Builds the engine-agnostic filter
 * map from validated scalars and delegates to the read port; the Meilisearch
 * filter syntax lives in the adapter, not here.
 */
final readonly class SearchService
{
    public function __construct(
        private SearchReaderInterface $searchReader,
    ) {
    }

    /**
     * @param array<string> $tagNames
     *
     * @return list<ItemSearchHit>
     */
    public function searchItems(
        string $query,
        ?string $ownerId,
        ?string $collectionId,
        array $tagNames,
        int $limit,
        int $offset,
    ): array {
        $filter = [];

        if (null !== $ownerId) {
            $filter['owner_id'] = $ownerId;
        }

        if (null !== $collectionId) {
            $filter['collection_id'] = $collectionId;
        }

        if ([] !== $tagNames) {
            $filter['tags'] = \array_values($tagNames);
        }

        return $this->searchReader->searchItems($query, $filter, $limit, $offset);
    }

    /**
     * @return list<CollectionSearchHit>
     */
    public function searchCollections(
        string $query,
        ?string $ownerId,
        ?string $theme,
        int $limit,
        int $offset,
    ): array {
        $filter = [];

        if (null !== $ownerId) {
            $filter['owner_id'] = $ownerId;
        }

        if (null !== $theme) {
            $filter['theme'] = $theme;
        }

        return $this->searchReader->searchCollections($query, $filter, $limit, $offset);
    }
}
