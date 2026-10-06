<?php

declare(strict_types=1);

namespace App\Infrastructure\Search;

/**
 * Meilisearch index settings, as data.
 *
 * Engine-specific, so it lives in Infrastructure, not Application. Only the
 * attributes the requirements actually need are declared:
 *
 * - items: searchable name/tags/collection_name; filterable collection_id,
 *   owner_id (the "my items" filter of 6.6) and tags.
 * - collections: searchable name/description; filterable owner_id and theme.
 *
 * No `sortableAttributes` and no custom `rankingRules`: nothing requires a sort
 * or a hand-tuned ranking yet, and the engine default is a better starting
 * point than a guess. Add them when a requirement names one.
 */
final class IndexSettings
{
    public const array ITEMS = [
        'searchableAttributes' => ['name', 'tags', 'collection_name'],
        'filterableAttributes' => ['collection_id', 'owner_id', 'tags'],
    ];

    public const array COLLECTIONS = [
        'searchableAttributes' => ['name', 'description'],
        'filterableAttributes' => ['owner_id', 'theme'],
    ];
}
