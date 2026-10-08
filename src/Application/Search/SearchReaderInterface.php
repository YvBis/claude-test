<?php

declare(strict_types=1);

namespace App\Application\Search;

/**
 * Read side of search. Deliberately separate from SearchIndexerInterface:
 * writes fail open and silent, reads cannot — a dead engine must surface as
 * an error, so implementations must NOT catch engine failures.
 */
interface SearchReaderInterface
{
    /**
     * @param array<string, string|array<string>> $filter engine-agnostic filter map;
     *                                                    supported keys: owner_id, collection_id, tags
     *
     * @return list<ItemSearchHit>
     */
    public function searchItems(string $query, array $filter, int $limit, int $offset): array;

    /**
     * @param array<string, string|array<string>> $filter supported keys: owner_id, theme
     *
     * @return list<CollectionSearchHit>
     */
    public function searchCollections(string $query, array $filter, int $limit, int $offset): array;
}
